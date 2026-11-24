<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Services\TelegramNotifier;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PosController extends Controller
{
    /** Allowed sugar levels (whitelist) */
    private const SUGAR_LEVELS = ['0%', '25%', '50%', '75%', '100%'];

    /* ─────────────────────────────────────────────
       POS SCREEN
       Route: GET /pos  (name: pos.index)
    ───────────────────────────────────────────── */
    public function index()
    {
        $products = Product::with(['category', 'images'])
            ->where('status', 'active')
            ->get();

        $customers = Customer::orderBy('name')->get(['id', 'name', 'phone', 'status']);

        return view('pos.index', compact('products', 'customers'));
    }

    /* ─────────────────────────────────────────────
       CUSTOMER DISPLAY (second screen)
       Route: GET /pos/display  (name: pos.display)
    ───────────────────────────────────────────── */
    public function display()
    {
        return view('pages.pos.display');
    }

    /* ─────────────────────────────────────────────
       CHECKOUT
       Route: POST /pos/checkout  (name: pos.checkout)
    ───────────────────────────────────────────── */
    public function checkout(Request $request)
    {
        $request->validate([
            'items'          => 'required|json',
            'payment_method' => 'required|in:khqr,cash',
            'cash_given'     => 'nullable|numeric|min:0',
            'customer_id'    => 'nullable|exists:customers,id', // empty = Walk-in
        ]);

        $items = json_decode($request->input('items'), true);

        if (!is_array($items) || empty($items)) {
            return back()->withErrors(['items' => 'Cart is empty.']);
        }

        $customerId    = $request->filled('customer_id') ? (int) $request->customer_id : null;
        $paymentMethod = $request->payment_method;
        $cashGiven     = (float) $request->input('cash_given', 0);
        $posRef        = 'POS-' . now()->format('YmdHis') . '-' . random_int(100, 999);

        try {
            $result = DB::transaction(function () use ($items, $customerId, $paymentMethod, $cashGiven, $posRef) {

                // ── 1) Lock products + validate stock (sum per product) ──
                $needed = [];
                foreach ($items as $row) {
                    $pid          = (int) ($row['id'] ?? 0);
                    $needed[$pid] = ($needed[$pid] ?? 0) + max(1, (int) ($row['qty'] ?? 1));
                }

                $products = Product::whereIn('id', array_keys($needed))
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                foreach ($needed as $pid => $qty) {
                    $product = $products->get($pid);

                    if (!$product || $product->status !== 'active') {
                        throw new \RuntimeException('A product in the cart is no longer available.');
                    }
                    if ($product->stock < $qty) {
                        throw new \RuntimeException("Not enough stock for \"{$product->name}\" (available: {$product->stock}).");
                    }
                }

                // ── 2) Compute server-side total (never trust browser prices) ──
                $lines      = [];
                $grandTotal = 0;

                foreach ($items as $row) {
                    $product = $products->get((int) $row['id']);
                    $qty     = max(1, (int) ($row['qty'] ?? 1));

                    // whitelist discount: "0", "10%", "$2"
                    $discount = (string) ($row['discount'] ?? '0');
                    if (!preg_match('/^(0|\d{1,3}(\.\d+)?%|\$\d+(\.\d+)?)$/', $discount)) {
                        $discount = '0';
                    }

                    // whitelist sugar
                    $sugar = (string) ($row['sugar'] ?? '100%');
                    if (!in_array($sugar, self::SUGAR_LEVELS, true)) {
                        $sugar = '100%';
                    }

                    $lineTotal   = $this->applyDiscount((float) $product->price * $qty, $discount);
                    $grandTotal += $lineTotal;

                    $lines[] = compact('product', 'qty', 'discount', 'sugar', 'lineTotal');
                }

                $grandTotal = round($grandTotal, 2);

                // ── 3) Cash validation ──
                if ($paymentMethod === 'cash' && $cashGiven + 0.001 < $grandTotal) {
                    throw new \RuntimeException('Cash given is less than the total amount.');
                }

                // ── 4) Create one order row per cart line (share pos_ref) ──
                $orderNo  = $this->nextOrderNumberStart();
                $orderIds = [];

                foreach ($lines as $line) {
                    $order = Order::create([
                        'order_number'   => 'ORD-KR' . str_pad($orderNo++, 3, '0', STR_PAD_LEFT),
                        'pos_ref'        => $posRef,
                        'customer_id'    => $customerId,
                        'product_id'     => $line['product']->id,
                        'quantity'       => $line['qty'],
                        'total_amount'   => $line['lineTotal'],
                        'status'         => 'completed',
                        'payment_method' => $paymentMethod,
                        'order_date'     => now(),
                        'note'           => 'POS sale · ref: ' . $posRef
                                          . ' · sugar: ' . $line['sugar']
                                          . ' · discount: ' . $line['discount'],
                    ]);

                    $orderIds[] = $order->id;

                    Payment::create([
                        'order_id'       => $order->id,
                        'amount'         => $line['lineTotal'],
                        'payment_method' => $paymentMethod,
                        'paid_at'        => now(),
                    ]);
                }

                // ── 5) Deduct stock ──
                foreach ($needed as $pid => $qty) {
                    $products->get($pid)->decrement('stock', $qty);
                }

                return [
                    'total'    => $grandTotal,
                    'lines'    => $lines,
                    'orderIds' => $orderIds,
                ];
            });
        } catch (\Throwable $e) {
            Log::warning('POS checkout failed: ' . $e->getMessage());

            return redirect()->route('pos.index')
                ->withErrors(['checkout' => $e->getMessage()])
                ->withInput();
        }

        $grandTotal = $result['total'];
        $lines      = $result['lines'];
        $orderIds   = $result['orderIds'];

        // ── Telegram alert (after commit; must never break checkout) ──
        $this->notifyTelegram($lines, $grandTotal, $paymentMethod, $cashGiven, $customerId);

        $message = 'Order placed successfully! Total: $' . number_format($grandTotal, 2);

        if ($customerId) {
            $customer = Customer::find($customerId);
            $message .= ' · Customer: ' . ($customer->name ?? '');
        }

        return redirect()->route('pos.index')
            ->with('pos_success', $message)
            ->with('pos_order_ids', $orderIds)
            ->with('pos_cash_given', $cashGiven);
    }

    /* ─────────────────────────────────────────────
       RECEIPT (80mm PDF) for ONE checkout
       URL: /pos/receipt?ids=12,13,14&cash=5.00
    ───────────────────────────────────────────── */
    public function receipt(Request $request)
    {
        $ids = collect(explode(',', (string) $request->query('ids', '')))
            ->map(fn ($id) => (int) trim($id))
            ->filter()
            ->unique()
            ->values();

        abort_if($ids->isEmpty(), 404);

        $orders = Order::with('product')
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->get();

        abort_if($orders->isEmpty(), 404);

        $first = $orders->first();

        $items = $orders->map(function ($o) {
            $unit  = (float) optional($o->product)->price;
            $notes = [];

            if (preg_match('/discount:\s*([^\s·]+)/u', (string) $o->note, $m) && $m[1] !== '0') {
                $notes[] = 'Discount ' . $m[1];
            }
            if (preg_match('/sugar:\s*(\d+%)/u', (string) $o->note, $m)) {
                $notes[] = 'Sugar ' . $m[1];
            }

            return [
                'name'  => optional($o->product)->name ?? 'Item',
                'qty'   => (int) $o->quantity,
                'price' => $unit,
                'total' => (float) $o->total_amount,
                'note'  => $notes ? implode(' · ', $notes) : null,
            ];
        })->values()->all();

        $total    = (float) $orders->sum('total_amount');
        $totalkhr = round(($total * 4000) / 100) * 100;
        $subtotal = (float) collect($items)->sum(fn ($i) => $i['price'] * $i['qty']);
        $discount = max(0, round($subtotal - $total, 2));

        $paymentMethod = $first->payment_method;
        $cashGiven     = (float) $request->query('cash', session('pos_cash_given', 0));

        $data = [
            'ref'           => $first->pos_ref ?? $first->order_number,
            'date'          => $first->created_at->format('d/m/Y h:i A'),
            'cashier'       => optional(auth()->user())->name ?? 'Administrator',
            'paymentMethod' => $paymentMethod,
            'paymentLabel'  => match ($paymentMethod) {
                'cash'  => 'Cash',
                'khqr'  => 'Bank',
                default => '-',
            },
            'items'         => $items,
            'subtotal'      => $subtotal,
            'discount'      => $discount,
            'total'         => $total,
            'totalkhr'      => $totalkhr,
            'cashGiven'     => $cashGiven,
        ];

        // 80mm = 226.77pt wide. Height grows with the number of lines.
        $height = 310 + count($items) * 32
            + ($discount > 0 ? 28 : 0)
            + ($paymentMethod === 'cash' && $cashGiven > 0 ? 40 : 0);

        $pdf = Pdf::loadView('pos.receipt', $data)
            ->setPaper([0, 0, 226.77, $height], 'portrait');

        // Font paths for Khmer
        $pdf->getDomPDF()->set_option('fontDir', storage_path('fonts/'));
        $pdf->getDomPDF()->set_option('fontCache', storage_path('fonts/'));
        $pdf->getDomPDF()->set_option('defaultFont', 'Khmer OS Battambang');

        $filename = 'receipt-' . $data['ref'] . '.pdf';

        return $request->boolean('download')
            ? $pdf->download($filename)
            : $pdf->stream($filename);
    }

    /* ─────────────────────────────────────────────
       KHQR — GENERATE
       Route: POST /pos/khqr/generate  (name: pos.khqr.generate)
    ───────────────────────────────────────────── */
    public function generateKhqr(Request $request)
    {
        $request->validate([
            'amount' => 'required|numeric|min:0.01',
        ]);

        $accountId = env('BAKONG_MERCHANT_ID');
        $name      = env('BAKONG_MERCHANT_NAME', 'My Shop');
        $city      = env('BAKONG_MERCHANT_CITY', 'Phnom Penh');

        if (!$accountId) {
            return response()->json([
                'success' => false,
                'message' => 'BAKONG_MERCHANT_ID is not configured.',
            ], 500);
        }

        $amount    = number_format((float) $request->amount, 2, '.', '');
        $createdMs = (int) round(microtime(true) * 1000);
        $expiresMs = $createdMs + (5 * 60 * 1000); // QR valid for 5 minutes

        $qr  = $this->buildKhqr($accountId, $name, $city, $amount, $createdMs, $expiresMs);
        $md5 = md5($qr);

        return response()->json([
            'success'    => true,
            'qr'         => $qr,
            'md5'        => $md5,
            'order_ref'  => 'KH-' . now()->format('ymdHis') . '-' . random_int(10, 99),
            'expires_at' => \Carbon\Carbon::createFromTimestampMs($expiresMs)->toIso8601String(),
        ]);
    }

    /* ─────────────────────────────────────────────
       KHQR — CHECK PAYMENT (by md5)
       Route: POST /pos/khqr/check  (name: pos.khqr.check)
    ───────────────────────────────────────────── */
    public function checkKhqr(Request $request)
    {
        $request->validate(['md5' => 'required|string|size:32']);

        // .env uses BAKONG_API_TOKEN (fallback to BAKONG_TOKEN)
        $token = env('BAKONG_API_TOKEN', env('BAKONG_TOKEN'));
        $url   = rtrim(env('BAKONG_API_URL', 'https://api-bakong.nbc.gov.kh'), '/')
               . '/v1/check_transaction_by_md5';

        if (!$token) {
            return response()->json(['paid' => false, 'message' => 'BAKONG_API_TOKEN is not configured.'], 500);
        }

        try {
            $res = Http::withToken($token)
                ->timeout(10)
                ->acceptJson()
                ->post($url, ['md5' => $request->md5]);

            $body = $res->json();

            // responseCode 0 + data present = paid
            $paid = $res->successful()
                && (($body['responseCode'] ?? 1) === 0)
                && !empty($body['data']);

            return response()->json(['paid' => $paid]);
        } catch (\Throwable $e) {
            Log::warning('Bakong check failed: ' . $e->getMessage());

            return response()->json(['paid' => false, 'message' => 'Unable to reach Bakong.']);
        }
    }

    /* ═════════════════════════════════════════════
       HELPERS
    ═════════════════════════════════════════════ */

    /**
     * Build + send the Telegram message. Never throws.
     */
    private function notifyTelegram(
        array $lines,
        float $grandTotal,
        string $paymentMethod,
        float $cashGiven,
        ?int $customerId
    ): void {
        try {
            // Today's checkout sequence (this checkout is already saved)
            $seq = Order::whereDate('order_date', now()->toDateString())
                ->whereNotNull('pos_ref')
                ->distinct()
                ->count('pos_ref');

            $invoice = 'N' . now()->format('Ymd') . '-' . str_pad($seq, 3, '0', STR_PAD_LEFT);
            $line    = '━━━━━━━━━━━━━━━━━━';

            $subtotal  = 0;
            $itemsText = '';

            foreach ($lines as $l) {
                $unit      = (float) $l['product']->price;
                $subtotal += $unit * $l['qty'];

                $itemsText .= '• ' . e($l['product']->name) . " x{$l['qty']}\n";
                $itemsText .= "🍬 ស្ករ: {$l['sugar']}\n";
                if ($l['discount'] !== '0') {
                    $itemsText .= '🏷️ បញ្ចុះ: ' . e($l['discount']) . "\n";
                }
                $itemsText .= '💵 តម្លៃ: $' . number_format($unit, 2) . "\n";
                $itemsText .= '💰 សរុប: $' . number_format($l['lineTotal'], 2) . "\n\n";
            }

            $discount = max(0, round($subtotal - $grandTotal, 2));
            $totalKhr = round(($grandTotal * 4000) / 100) * 100;
            $payLabel = $paymentMethod === 'cash' ? 'Cash' : 'Bank';

            $msg  = "🧾 <b>NEW ORDER RECEIVED</b>\n{$line}\n";
            $msg .= "<b>INVOICE: {$invoice}</b>\n{$line}\n";
            $msg .= "🔢 <b>Order No: {$seq}</b>\n{$line}\n";
            $msg .= $itemsText . "{$line}\n";
            $msg .= '💵 Subtotal: $' . number_format($subtotal, 2) . "\n";
            $msg .= '🏷️ Discount: $' . number_format($discount, 2) . "\n";
            $msg .= '💰 Grand Total: $' . number_format($grandTotal, 2) . "\n";
            $msg .= '🇰🇭 Grand Total (KHR): ៛' . number_format($totalKhr) . "\n";
            $msg .= "💳 Payment: {$payLabel}\n";

            if ($paymentMethod === 'cash' && $cashGiven > 0) {
                $msg .= '💵 Cash given: $' . number_format($cashGiven, 2) . "\n";
                $msg .= '🔁 Change: $' . number_format(max(0, $cashGiven - $grandTotal), 2) . "\n";
            }

            if ($customerId && ($c = Customer::find($customerId))) {
                $msg .= '👤 Customer: ' . e($c->name) . "\n";
            }

            $msg .= "{$line}\n🕒 " . now()->format('d/m/Y h:i A');

            TelegramNotifier::send($msg);
        } catch (\Throwable $e) {
            Log::warning('Telegram failed: ' . $e->getMessage());
        }
    }

    /**
     * Same rule as the JS calculateItemTotal(): "10%" or "$2" style discounts.
     */
    private function applyDiscount(float $amount, ?string $discount): float
    {
        if (!$discount || $discount === '0') {
            return round($amount, 2);
        }

        if (str_contains($discount, '%')) {
            $amount -= $amount * (floatval($discount) / 100);
        } elseif (str_contains($discount, '$')) {
            $amount -= floatval(str_replace('$', '', $discount));
        }

        return max(0, round($amount, 2));
    }

    /**
     * First free number for ORD-KR### (same format as OrderController@store).
     */
    private function nextOrderNumberStart(): int
    {
        $last = Order::where('order_number', 'like', 'ORD-KR%')
            ->orderBy('id', 'desc')
            ->lockForUpdate()
            ->first();

        return $last ? ((int) substr($last->order_number, 6)) + 1 : 1;
    }

    /**
     * Build a dynamic KHQR (EMV QR) string for a Bakong individual account, in USD.
     */
    private function buildKhqr(
        string $accountId,
        string $name,
        string $city,
        string $amount,
        int $createdMs,
        int $expiresMs
    ): string {
        $tlv = fn (string $tag, string $value) =>
            $tag . str_pad((string) mb_strlen($value), 2, '0', STR_PAD_LEFT) . $value;

        $payload  = $tlv('00', '01');                       // payload format
        $payload .= $tlv('01', '12');                       // 12 = dynamic QR
        $payload .= $tlv('29', $tlv('00', $accountId));     // Bakong account
        $payload .= $tlv('52', '5999');                     // merchant category
        $payload .= $tlv('53', '840');                      // currency: USD
        $payload .= $tlv('54', $amount);                    // amount
        $payload .= $tlv('58', 'KH');                       // country
        $payload .= $tlv('59', mb_substr($name, 0, 25));    // merchant name
        $payload .= $tlv('60', mb_substr($city, 0, 15));    // merchant city
        $payload .= $tlv('99', $tlv('00', (string) $createdMs) . $tlv('01', (string) $expiresMs));

        $payload .= '6304';                                  // CRC tag + length

        return $payload . $this->crc16($payload);
    }

    /**
     * CRC-16/CCITT-FALSE (poly 0x1021, init 0xFFFF) — required by EMV QR.
     */
    private function crc16(string $data): string
    {
        $crc = 0xFFFF;

        for ($i = 0, $len = strlen($data); $i < $len; $i++) {
            $crc ^= (ord($data[$i]) << 8);
            for ($b = 0; $b < 8; $b++) {
                $crc = ($crc & 0x8000) ? (($crc << 1) ^ 0x1021) : ($crc << 1);
                $crc &= 0xFFFF;
            }
        }

        return strtoupper(str_pad(dechex($crc), 4, '0', STR_PAD_LEFT));
    }
}