<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Warehouse;
use App\Services\StoreContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class WarehouseController extends Controller
{
    public function __construct(
        protected StoreContext $storeContext,
    ) {}

    public function index(): Response
    {
        $store = $this->storeContext->getCurrentStore();

        $warehouses = Warehouse::where('store_id', $store->id)
            ->withCount('inventories')
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get()
            ->map(fn ($warehouse) => [
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
                'full_address' => $warehouse->full_address,
                'inventories_count' => $warehouse->inventories_count,
            ]);

        return Inertia::render('settings/Warehouses', [
            'warehouses' => $warehouses,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:50'],
            'description' => ['nullable', 'string', 'max:1000'],
            'address_line1' => ['nullable', 'string', 'max:255'],
            'address_line2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'country' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
            'accepts_transfers' => ['boolean'],
            'fulfills_orders' => ['boolean'],
        ]);

        $store = $this->storeContext->getCurrentStore();

        // Generate code if not provided
        if (empty($validated['code'])) {
            $validated['code'] = Str::upper(Str::slug($validated['name'], '_'));
        }

        // Check if this is the first warehouse (make it default)
        $isFirst = ! Warehouse::where('store_id', $store->id)->exists();

        $warehouse = Warehouse::create([
            'store_id' => $store->id,
            'name' => $validated['name'],
            'code' => $validated['code'],
            'description' => $validated['description'] ?? null,
            'address_line1' => $validated['address_line1'] ?? null,
            'address_line2' => $validated['address_line2'] ?? null,
            'city' => $validated['city'] ?? null,
            'state' => $validated['state'] ?? null,
            'postal_code' => $validated['postal_code'] ?? null,
            'country' => $validated['country'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'email' => $validated['email'] ?? null,
            'contact_name' => $validated['contact_name'] ?? null,
            'is_default' => $isFirst,
            'is_active' => $validated['is_active'] ?? true,
            'accepts_transfers' => $validated['accepts_transfers'] ?? true,
            'fulfills_orders' => $validated['fulfills_orders'] ?? true,
        ]);

        return back()->with('success', 'Warehouse created successfully.');
    }

    public function update(Request $request, Warehouse $warehouse): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:50'],
            'description' => ['nullable', 'string', 'max:1000'],
            'address_line1' => ['nullable', 'string', 'max:255'],
            'address_line2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'country' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
            'accepts_transfers' => ['boolean'],
            'fulfills_orders' => ['boolean'],
        ]);

        $warehouse->update([
            'name' => $validated['name'],
            'code' => $validated['code'] ?: Str::upper(Str::slug($validated['name'], '_')),
            'description' => $validated['description'] ?? null,
            'address_line1' => $validated['address_line1'] ?? null,
            'address_line2' => $validated['address_line2'] ?? null,
            'city' => $validated['city'] ?? null,
            'state' => $validated['state'] ?? null,
            'postal_code' => $validated['postal_code'] ?? null,
            'country' => $validated['country'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'email' => $validated['email'] ?? null,
            'contact_name' => $validated['contact_name'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
            'accepts_transfers' => $validated['accepts_transfers'] ?? true,
            'fulfills_orders' => $validated['fulfills_orders'] ?? true,
        ]);

        return back()->with('success', 'Warehouse updated successfully.');
    }

    public function destroy(Warehouse $warehouse): RedirectResponse
    {
        // Don't allow deleting the default warehouse
        if ($warehouse->is_default) {
            return back()->with('error', 'Cannot delete the default warehouse. Set another warehouse as default first.');
        }

        // Don't allow deleting if it has inventory
        if ($warehouse->inventories()->exists()) {
            return back()->with('error', 'Cannot delete warehouse with existing inventory. Transfer inventory first.');
        }

        $warehouse->delete();

        return back()->with('success', 'Warehouse deleted successfully.');
    }

    public function makeDefault(Warehouse $warehouse): RedirectResponse
    {
        $warehouse->makeDefault();

        return back()->with('success', "{$warehouse->name} is now the default warehouse.");
    }
}
