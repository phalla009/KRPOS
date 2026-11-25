{{--
    resources/views/pages/customers/partials/loyalty-card.blade.php

    Bilingual (Khmer + English) loyalty card with FRONT / BACK flip.
    Expects $loyalty (from CustomerController@loyaltyFor) and $customer.
--}}
@php
    // Set to true to show Khmer numerals (១ ២ ៣) instead of 1 2 3
    $useKhmerNumerals = false;

    $kh = fn ($n) => strtr((string) $n, [
        '0' => '០', '1' => '១', '2' => '២', '3' => '៣', '4' => '៤',
        '5' => '៥', '6' => '៦', '7' => '៧', '8' => '៨', '9' => '៩',
    ]);
    $num = fn ($n) => $useKhmerNumerals ? $kh($n) : (string) $n;

    $shopName = config('app.name'); // change to your shop name if you like

    // Put your logo at public/images/logo.png (or change the path).
    // Set to null to hide the logo.
    $logo     = asset('image/logokr.png');

    $goal     = $loyalty['goal'];
    $isFull   = $loyalty['count'] > 0 && $loyalty['left'] === 0;

    // unique id so the partial can be included many times on one page
    $uid = $customer->id ?? uniqid();
@endphp

<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Khmer:wght@400;600;700&display=swap" rel="stylesheet">
<style>
    .lc-card {
        font-family: 'Noto Sans Khmer', 'Khmer OS Battambang', 'Battambang', sans-serif;
        background: #2c1b14;
        color: #fbf3e8;
        border-radius: 28px;
        padding: 40px 44px 30px;
    }
    .lc-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; flex-wrap: wrap; }
    .lc-shop { font-size: 28px; font-weight: 700; color: #d9a066; margin: 0; line-height: 1.3; }

    /* ── LOGO ── */
    .lc-brand { display: flex; align-items: center; gap: 14px; }
    .lc-logo {
        width: 64px;
        height: 64px;
        object-fit: contain;
        border-radius: 50%;
        background: #fbf3e8;   /* light circle so dark logos stay visible on the brown card */
        padding: 4px;
        flex-shrink: 0;
    }

    .lc-sub { font-size: 14px; color: #e6d3bf; margin-top: 6px; }
    .lc-sub .en, .lc-left-text .en { display: block; font-size: 12.5px; color: #c9b39c; margin-top: 2px; }
    .lc-type { text-align: right; }
    .lc-type .kh { font-size: 20px; font-weight: 700; color: #fff; line-height: 1.3; }
    .lc-type .en { font-size: 11px; font-weight: 700; letter-spacing: 0.22em; color: #e6d3bf; margin-top: 2px; }

    .lc-grid {
        display: grid;
        grid-template-columns: repeat(5, minmax(0, 1fr));
        gap: 12px 16px;
        margin: 26px 0 28px;
        justify-items: center;
    }
    .lc-stamp {
        width: 100%;
        max-width: 98px;
        aspect-ratio: 1 / 1;
        border-radius: 50%;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        border: 1.5px dashed #9c8470;
        color: #e0cdb8;
        font-size: 24px;
        font-weight: 700;
    }
    .lc-stamp.is-filled { background: #d9a066; border: 1.5px solid #d9a066; color: #2c1b14; font-size: 34px; }
    .lc-stamp.is-free   { border: 1.5px solid #d9a066; color: #d9a066; font-size: z14px; font-weight: 700; text-align: center; line-height: 1.25; }
    .lc-stamp.is-free small { font-size: 11px; letter-spacing: 0.12em; font-weight: 700; }
    .lc-stamp.is-free.is-filled { background: #d9a066; color: #2c1b14; font-size: 34px; }

    .lc-left-text { font-size: 14px; color: #e6d3bf; }
    .lc-name-row { display: flex; align-items: flex-end; gap: 10px; margin-top: 14px; font-size: 13px; color: #e6d3bf; }
    .lc-name-row .en { color: #c9b39c; font-size: 12px; }
    .lc-field-label { min-width: 150px; white-space: nowrap; }
    .lc-name-row + .lc-name-row { margin-top: 10px; }
    .lc-name-line { flex: 1; border-bottom: 1px solid #9c8470; padding: 0 6px 2px; color: #fff; font-weight: 600; min-height: 20px; }

    .lc-count-label { font-size: 13px; color: #e6d3bf; }
    .lc-count-label .en { color: #c9b39c; font-size: 12px; }
    .lc-count { line-height: 1.1; margin-top: 4px; white-space: nowrap; }
    .lc-count strong { font-size: 52px; font-weight: 700; color: #d9a066; }
    .lc-count span { font-size: 24px; color: #e6d3bf; }
    .lc-reward { display: inline-block; margin-top: 6px; background: #d9a066; color: #2c1b14; font-weight: 700; font-size: 12px; padding: 3px 12px; border-radius: 20px; }

    /* ── FLIP (FRONT / BACK) ── */
    .lc-wrap { margin: 16px 0 14px; }
    .lc-flip { perspective: 1400px; cursor: pointer; }
    .lc-flip-inner { display: grid; transition: transform .7s cubic-bezier(.4,.2,.2,1); transform-style: preserve-3d; }
    .lc-flip.is-back .lc-flip-inner { transform: rotateY(180deg); }
    .lc-face { grid-area: 1 / 1; backface-visibility: hidden; -webkit-backface-visibility: hidden; margin: 0; }
    .lc-face.lc-back { transform: rotateY(180deg); display: flex; flex-direction: column; justify-content: space-between; gap: 20px; }

    .lc-toggle { display: flex; gap: 8px; justify-content: center; margin-bottom: 10px; }
    .lc-toggle button { border: 1px solid #9c8470; background: transparent; color: #2c1b14; padding: 6px 18px; border-radius: 20px; font-family: 'Noto Sans Khmer', sans-serif; font-size: 13px; cursor: pointer; }
    .lc-toggle button.active { background: #2c1b14; color: #fbf3e8; border-color: #2c1b14; }

    .lc-back-stats { display: flex; justify-content: space-between; align-items: flex-end; gap: 20px; flex-wrap: wrap; }
    .lc-terms { font-size: 12px; color: #c9b39c; line-height: 1.7; border-top: 1px dashed #9c8470; padding-top: 14px; }
    .lc-terms .en { display: block; font-size: 11px; }

    @media (max-width: 576px) {
        .lc-card { padding: 24px 18px 22px; border-radius: 22px; }
        .lc-shop { font-size: 22px; }
        .lc-logo { width: 48px; height: 48px; }
        .lc-brand { gap: 10px; }
        .lc-grid { gap: 10px; }
        .lc-stamp { font-size: 18px; }
        .lc-stamp.is-filled, .lc-stamp.is-free.is-filled { font-size: 22px; }
        .lc-stamp.is-free { font-size: 11px; }
        .lc-stamp.is-free small { font-size: 9px; }
        .lc-count strong { font-size: 38px; }
        .lc-field-label { min-width: 110px; }
    }

    /* Print: show FRONT (left) and BACK (right) side by side */
    @media print {
        .lc-toggle { display: none; }
        .lc-flip { perspective: none; }
        .lc-flip-inner { display: flex; gap: 16px; transform: none !important; }
        .lc-face, .lc-face.lc-back { transform: none; flex: 1; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    }
</style>

<div class="lc-wrap">

    <div class="lc-toggle" id="lcToggle-{{ $uid }}">
        <button type="button" class="active" data-side="front" onclick="lcFlip('{{ $uid }}','front',event)">ខាងមុខ / Front</button>
        <button type="button" data-side="back" onclick="lcFlip('{{ $uid }}','back',event)">ខាងក្រោយ / Back</button>
    </div>

    <div class="lc-flip" id="lcFlip-{{ $uid }}" onclick="lcFlip('{{ $uid }}')">
        <div class="lc-flip-inner">

            {{-- ═══════════ FRONT ═══════════ --}}
            <div class="lc-card lc-face lc-front" role="group" aria-label="កាតសមាជិក ខាងមុខ / Loyalty Card Front">
                <div class="lc-head">
                    <div class="lc-brand">
                        @if ($logo)
                            <img src="{{ $logo }}" alt="{{ $shopName }}" class="lc-logo">
                        @endif
                        <div>
                            <h3 class="lc-shop">{{ $shopName }}</h3>
                            <div class="lc-sub">
                                ទិញកាហ្វេ {{ $num($goal) }} ដង ទទួលបានកាហ្វេឥតគិតថ្លៃ ១ កែវ
                                <span class="en">Buy {{ $goal }}, get 1 free coffee</span>
                            </div>
                        </div>
                    </div>
                    <div class="lc-type">
                        <div class="kh">កាតសមាជិក</div>
                        <div class="en">LOYALTY CARD</div>
                    </div>
                </div>

                <div class="lc-grid">
                    @for ($i = 1; $i <= $goal; $i++)
                        @php
                            $isFilled = $i <= $loyalty['filled'];
                            $isFree   = $i === $goal;
                        @endphp
                        <div class="lc-stamp {{ $isFilled ? 'is-filled' : '' }} {{ $isFree ? 'is-free' : '' }}">
                            @if ($isFilled)
                                <i class="fas {{ $isFree ? 'fa-gift' : 'fa-mug-hot' }}"></i>
                            @elseif ($isFree)
                                ឥតគិតថ្លៃ
                                <small>FREE</small>
                            @else
                                {{ $num($i) }}
                            @endif
                        </div>
                    @endfor
                </div>

                <div class="lc-left-text">
                    @if ($isFull)
                        កាតពេញហើយ! អាចទទួលកាហ្វេឥតគិតថ្លៃ ១ កែវ
                        <span class="en">Card complete! A free coffee is ready.</span>
                    @else
                        នៅសល់ {{ $num($loyalty['left']) }} ដងទៀត ទទួលបានកាហ្វេឥតគិតថ្លៃ ១ កែវ
                        <span class="en">{{ $loyalty['left'] }} more {{ \Illuminate\Support\Str::plural('order', $loyalty['left']) }} until your free coffee</span>
                    @endif
                </div>
            </div>

            {{-- ═══════════ BACK ═══════════ --}}
            <div class="lc-card lc-face lc-back" role="group" aria-label="កាតសមាជិក ខាងក្រោយ / Loyalty Card Back">
                <div>
                    <div class="lc-head">
                        <div class="lc-brand">
                            @if ($logo)
                                <img src="{{ $logo }}" alt="{{ $shopName }}" class="lc-logo">
                            @endif
                            <h3 class="lc-shop">{{ $shopName }}</h3>
                        </div>
                        <div class="lc-type">
                            <div class="kh">ព័ត៌មានសមាជិក</div>
                            <div class="en">MEMBER INFO</div>
                        </div>
                    </div>

                    <div class="lc-name-row" style="margin-top:24px;">
                        <span class="lc-field-label">ឈ្មោះអតិថិជន / <span class="en">Customer Name :</span></span>
                        <span class="lc-name-line">{{ $customer->name ?? '' }}</span>
                    </div>
                    <div class="lc-name-row">
                        <span class="lc-field-label">លេខទូរស័ព្ទ / <span class="en">Phone Number :</span></span>
                        <span class="lc-name-line">{{ $customer->phone ?? '' }}</span>
                    </div>
                </div>

                <div class="lc-back-stats">
                    <div>
                        <div class="lc-count-label">ចំនួនការកម្ម៉ង់ <span class="en">/ Orders</span></div>
                        <div class="lc-count">
                            <strong>{{ $num($loyalty['filled']) }}</strong><span> / {{ $num($goal) }}</span>
                        </div>
                    </div>
                    @if ($loyalty['rewards'] > 0)
                        <span class="lc-reward">
                            <i class="fas fa-gift"></i>
                            ទទួលបាន {{ $num($loyalty['rewards']) }} កែវ ·
                            {{ $loyalty['rewards'] }} free {{ \Illuminate\Support\Str::plural('coffee', $loyalty['rewards']) }} earned
                        </span>
                    @endif
                </div>

                <div class="lc-terms">
                    ១. កាតនេះប្រើបានតែម្ចាស់កាតប៉ុណ្ណោះ។ ២. កាហ្វេឥតគិតថ្លៃមិនអាចប្តូរជាសាច់ប្រាក់បានទេ។
                    <span class="en">1. This card is non-transferable. 2. Free coffee cannot be exchanged for cash.</span>
                </div>
            </div>

        </div>
    </div>
</div>

<script>
    if (!window.lcFlip) {
        window.lcFlip = function (uid, side, ev) {
            if (ev) ev.stopPropagation();
            const box = document.getElementById('lcFlip-' + uid);
            if (!box) return;

            if (side) box.classList.toggle('is-back', side === 'back');
            else      box.classList.toggle('is-back');

            const isBack = box.classList.contains('is-back');
            document.querySelectorAll('#lcToggle-' + uid + ' button').forEach(function (b) {
                b.classList.toggle('active', (b.dataset.side === 'back') === isBack);
            });
        };
    }
</script>