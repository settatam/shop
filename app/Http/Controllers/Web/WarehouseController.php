<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Warehouse;
use App\Services\StoreContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WarehouseController extends Controller
{
    public function __construct(protected StoreContext $storeContext) {}

    public function index(): Response|RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return redirect()->route('dashboard')
                ->with('error', 'Please select a store first.');
        }

        $warehouses = Warehouse::where('store_id', $store->id)
            ->withCount('inventories')
            ->orderBy('priority')
            ->orderBy('name')
            ->get()
            ->map(fn ($w) => [
                'id' => $w->id,
                'name' => $w->name,
                'code' => $w->code,
                'description' => $w->description,
                'full_address' => $w->full_address,
                'city' => $w->city,
                'state' => $w->state,
                'country' => $w->country,
                'phone' => $w->phone,
                'email' => $w->email,
                'is_default' => $w->is_default,
                'is_active' => $w->is_active,
                'accepts_transfers' => $w->accepts_transfers,
                'fulfills_orders' => $w->fulfills_orders,
                'priority' => $w->priority,
                'tax_rate' => $w->tax_rate !== null ? (float) $w->tax_rate * 100 : null,
                'inventories_count' => $w->inventories_count,
                'total_quantity' => $w->total_quantity,
            ]);

        return Inertia::render('warehouses/Index', [
            'warehouses' => $warehouses,
        ]);
    }

    public function create(): Response|RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return redirect()->route('dashboard')
                ->with('error', 'Please select a store first.');
        }

        return Inertia::render('warehouses/Create');
    }

    public function store(Request $request): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return redirect()->route('dashboard')
                ->with('error', 'Please select a store first.');
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:50|unique:warehouses,code,NULL,id,store_id,'.$store->id,
            'description' => 'nullable|string|max:1000',
            'address_line1' => 'nullable|string|max:255',
            'address_line2' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:100',
            'postal_code' => 'nullable|string|max:20',
            'country' => 'nullable|string|max:100',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'contact_name' => 'nullable|string|max:255',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'accepts_transfers' => 'boolean',
            'fulfills_orders' => 'boolean',
            'priority' => 'nullable|integer|min:0',
            'tax_rate' => 'nullable|numeric|min:0|max:100',
        ]);

        // Convert tax_rate from percentage to decimal
        $validated['tax_rate'] = isset($validated['tax_rate']) ? $validated['tax_rate'] / 100 : null;

        $warehouse = Warehouse::create([
            ...$validated,
            'store_id' => $store->id,
            'priority' => $validated['priority'] ?? 10,
        ]);

        if ($request->boolean('is_default')) {
            $warehouse->makeDefault();
        }

        return redirect()->route('warehouses.index')
            ->with('success', 'Warehouse created successfully.');
    }

    public function edit(Warehouse $warehouse): Response|RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store || $warehouse->store_id !== $store->id) {
            abort(404);
        }

        return Inertia::render('warehouses/Edit', [
            'warehouse' => [
                'id' => $warehouse->id,
                'name' => $warehouse->name,
                'code' => $warehouse->code,
                'description' => $warehouse->description,
                'address_line1' => $warehouse->address_line1,
                'address_line2' => $warehouse->address_line2,
                'city' => $warehouse->city,
                'state' => $warehouse->state,
                'postal_code' => $warehouse->postal_code,
                'country' => $warehouse->country,
                'phone' => $warehouse->phone,
                'email' => $warehouse->email,
                'contact_name' => $warehouse->contact_name,
                'is_default' => $warehouse->is_default,
                'is_active' => $warehouse->is_active,
                'accepts_transfers' => $warehouse->accepts_transfers,
                'fulfills_orders' => $warehouse->fulfills_orders,
                'priority' => $warehouse->priority,
                'tax_rate' => $warehouse->tax_rate !== null ? (float) $warehouse->tax_rate * 100 : null,
            ],
        ]);
    }

    public function update(Request $request, Warehouse $warehouse): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store || $warehouse->store_id !== $store->id) {
            abort(404);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:50|unique:warehouses,code,'.$warehouse->id.',id,store_id,'.$store->id,
            'description' => 'nullable|string|max:1000',
            'address_line1' => 'nullable|string|max:255',
            'address_line2' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:100',
            'postal_code' => 'nullable|string|max:20',
            'country' => 'nullable|string|max:100',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'contact_name' => 'nullable|string|max:255',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'accepts_transfers' => 'boolean',
            'fulfills_orders' => 'boolean',
            'priority' => 'nullable|integer|min:0',
            'tax_rate' => 'nullable|numeric|min:0|max:100',
        ]);

        // Convert tax_rate from percentage to decimal
        $validated['tax_rate'] = isset($validated['tax_rate']) ? $validated['tax_rate'] / 100 : null;

        $warehouse->update($validated);

        if ($request->boolean('is_default') && ! $warehouse->is_default) {
            $warehouse->makeDefault();
        }

        return redirect()->route('warehouses.index')
            ->with('success', 'Warehouse updated successfully.');
    }

    public function destroy(Warehouse $warehouse): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store || $warehouse->store_id !== $store->id) {
            abort(404);
        }

        // Check if warehouse has inventory
        if ($warehouse->inventories()->where('quantity', '>', 0)->exists()) {
            return redirect()->back()
                ->with('error', 'Cannot delete warehouse with existing inventory.');
        }

        // Check if it's the default warehouse
        if ($warehouse->is_default) {
            return redirect()->back()
                ->with('error', 'Cannot delete the default warehouse. Set another warehouse as default first.');
        }

        $warehouse->delete();

        return redirect()->route('warehouses.index')
            ->with('success', 'Warehouse deleted successfully.');
    }

    public function makeDefault(Warehouse $warehouse): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store || $warehouse->store_id !== $store->id) {
            abort(404);
        }

        $warehouse->makeDefault();

        return redirect()->route('warehouses.index')
            ->with('success', $warehouse->name.' is now the default warehouse.');
    }
}
