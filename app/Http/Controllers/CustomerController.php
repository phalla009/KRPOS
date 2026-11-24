<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth; // <-- បន្ថែម Auth

class CustomerController extends Controller
{
    // Stamps needed for one free coffee
    private const LOYALTY_GOAL = 10;

    // Show list of customers
    public function index()
    {
        // withCount creates $customer->orders_count (the original code read it
        // without ever loading it, so every customer showed as "Inactive").
        $customers = Customer::with(['orders', 'creator', 'updater'])
            ->withCount('orders')
            ->get();

        foreach ($customers as $customer) {
            $customer->status = $customer->orders_count > 0 ? 'Active' : 'Inactive';
        }

        return view('pages/customers.index', compact('customers'));
    }

    // Show create form
    public function create()
    {
        return view('pages/customers.create');
    }

    // Store new customer
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'     => 'required|string|max:255',
            'gender'   => 'required|string|in:male,female',
            'phone'    => 'required|string|max:20|unique:customers,phone',
            'email'    => 'required|email|max:255|unique:customers,email',
            'password' => 'required|string|min:8|confirmed',
            'status'   => 'required|in:active,inactive',
        ], [
            'phone.unique' => 'This phone number already exists.',
            'email.unique' => 'This email address already exists.',
        ]);

        Customer::create([
            'name'       => $validated['name'],
            'gender'     => $validated['gender'],
            'phone'      => $validated['phone'],
            'email'      => $validated['email'],
            'password'   => bcrypt($validated['password']),
            'status'     => $validated['status'],
            'created_by' => Auth::id(), // កំណត់អ្នកបង្កើត
            'updated_by' => Auth::id(), // កំណត់អ្នកកែប្រែដំបូង
        ]);

        return redirect()->route('customers.create')->with('success', 'Customer added successfully.');
    }

    // Show a customer
    public function show(string $id)
    {
        $customer = Customer::with(['creator', 'updater'])->findOrFail($id);
        $loyalty  = $this->loyaltyFor($customer);

        return view('pages/customers.show', compact('customer', 'loyalty'));
    }

    // Show edit form
    public function edit(string $id)
    {
        $customer = Customer::findOrFail($id);
        return view('pages/customers.edit', compact('customer'));
    }

    // Update customer
    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'name'     => 'required|string|max:255',
            'gender'   => 'required|string|in:male,female',
            'phone'    => 'required|string|max:20|unique:customers,phone,' . $id,
            'email'    => 'required|email|max:255|unique:customers,email,' . $id,
            'password' => 'nullable|string|min:8|confirmed',
        ], [
            'phone.unique' => 'This phone number already exists.',
            'email.unique' => 'This email address already exists.',
        ]);

        $customer         = Customer::findOrFail($id);
        $customer->name   = $validated['name'];
        $customer->gender = $validated['gender'];
        $customer->phone  = $validated['phone'];
        $customer->email  = $validated['email'];

        if (!empty($validated['password'])) {
            $customer->password = bcrypt($validated['password']);
        }

        if ($request->has('status')) {
            $customer->status = $request->input('status');
        }

        // កំណត់អ្នកកែប្រែចុងក្រោយ
        $customer->updated_by = Auth::id();

        $customer->save();
        return redirect()->route('customers.index')->with('success', 'Customer updated successfully!');
    }

    // Delete customer
    public function destroy(string $id)
    {
        $customer = Customer::findOrFail($id);
        $customer->delete();
        return redirect()->route('customers.index')->with('success', 'Customer deleted successfully.');
    }

    /**
     * Loyalty card data for one customer.
     *
     * One stamp = one completed sale (invoice). A POS checkout with 3 cart
     * items creates 3 `orders` rows sharing one `pos_ref`, so rows are grouped
     * by pos_ref (or by their own id when pos_ref is null), the same rule
     * OrderController@index uses for "Total Invoice".
     */
    private function loyaltyFor(Customer $customer): array
    {
        $goal = self::LOYALTY_GOAL;

        $count = $customer->orders()
            ->where('status', 'completed')
            ->get(['id', 'pos_ref'])
            ->unique(fn ($order) => $order->pos_ref ?? 'single-' . $order->id)
            ->count();

        $cycle  = $count % $goal;
        // Show a full card right after the 10th order instead of an empty one.
        $filled = ($count > 0 && $cycle === 0) ? $goal : $cycle;

        return [
            'count'   => $count,
            'goal'    => $goal,
            'filled'  => $filled,
            'rewards' => intdiv($count, $goal),
            'left'    => $goal - $filled,
        ];
    }
}