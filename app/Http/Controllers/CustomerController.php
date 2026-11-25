<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CustomerController extends Controller
{
    // Stamps needed for one free coffee
    private const LOYALTY_GOAL = 10;

    // Show list of customers
    public function index()
    {
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
            'name'        => 'required|string|max:255',
            'gender'      => 'required|string|in:male,female',
            'phone'       => 'required|string|max:20|unique:customers,phone',
            'email'       => 'required|email|max:255|unique:customers,email',
            'password'    => 'required|string|min:8|confirmed',
            'description' => 'nullable|string|max:1000',
        ], [
            'phone.unique' => 'This phone number already exists.',
            'email.unique' => 'This email address already exists.',
        ]);

        Customer::create([
            'name'        => $validated['name'],
            'gender'      => $validated['gender'],
            'phone'       => $validated['phone'],
            'email'       => $validated['email'],
            'password'    => bcrypt($validated['password']),
            'status'      => 'inactive',
            'description' => $validated['description'] ?? null,
            'created_by'  => Auth::id(),
            'updated_by'  => Auth::id(),
        ]);

        return redirect()->route('customers.create')->with('success', 'Customer added successfully.');
    }

    // Show a customer
    public function show(string $id)
    {
        $customer = Customer::with(['creator', 'updater'])
            ->withCount('orders')
            ->findOrFail($id);

        $customer->status = $customer->orders_count > 0 ? 'active' : 'inactive';

        $loyalty = $this->loyaltyFor($customer);

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
            'name'        => 'required|string|max:255',
            'gender'      => 'required|string|in:male,female',
            'phone'       => 'required|string|max:20|unique:customers,phone,' . $id,
            'email'       => 'required|email|max:255|unique:customers,email,' . $id,
            'password'    => 'nullable|string|min:8|confirmed',
            'description' => 'nullable|string|max:1000',
        ], [
            'phone.unique' => 'This phone number already exists.',
            'email.unique' => 'This email address already exists.',
        ]);

        $customer              = Customer::findOrFail($id);
        $customer->name        = $validated['name'];
        $customer->gender      = $validated['gender'];
        $customer->phone       = $validated['phone'];
        $customer->email       = $validated['email'];
        $customer->description = $validated['description'] ?? null;

        if (!empty($validated['password'])) {
            $customer->password = bcrypt($validated['password']);
        }

        if ($request->has('status')) {
            $customer->status = $request->input('status');
        }

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
     * One stamp = one completed sale (invoice), grouped by pos_ref.
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