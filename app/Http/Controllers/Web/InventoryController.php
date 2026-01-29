<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Inventory;
use App\Models\InventoryAdjustment;
use App\Models\Warehouse;
use App\Services\StoreContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class InventoryController extends Controller
{
    public function __construct(protected StoreContext $storeContext) {}

    public function index(Request $request): Response|RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return redirect()->route('dashboard')
                ->with('error', 'Please select a store first.');
        }

        $warehouses = Warehouse::where('store_id', $store->id)
            ->where('is_active', true)
            ->orderBy('priority')
            ->get(['id', 'name', 'code', 'is_default']);

        $selectedWarehouseId = $request->input('warehouse_id', $warehouses->firstWhere('is_default')?->id ?? $warehouses->first()?->id);

        $query = Inventory::query()
            ->where('store_id', $store->id)
            ->with(['variant.product', 'warehouse']);

        if ($selectedWarehouseId) {
            $query->where('warehouse_id', $selectedWarehouseId);
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->whereHas('variant', function ($q) use ($search) {
                $q->where('sku', 'like', "%{$search}%")
                    ->orWhereHas('product', function ($q2) use ($search) {
                        $q2->where('title', 'like', "%{$search}%");
                    });
            });
        }

        if ($request->boolean('low_stock')) {
            $query->lowStock();
        }

        $sortField = $request->input('sort', 'quantity');
        $sortDirection = $request->input('direction', 'asc');
        $query->orderBy($sortField, $sortDirection);

        $inventory = $query->paginate(50)->through(fn ($item) => [
            'id' => $item->id,
            'product_title' => $item->variant->product->title,
            'product_id' => $item->variant->product->id,
            'variant_id' => $item->variant->id,
            'variant_title' => $item->variant->options_title,
            'sku' => $item->variant->sku,
            'warehouse_id' => $item->warehouse_id,
            'warehouse_name' => $item->warehouse->name,
            'quantity' => $item->quantity,
            'reserved_quantity' => $item->reserved_quantity,
            'available_quantity' => $item->available_quantity,
            'incoming_quantity' => $item->incoming_quantity,
            'reorder_point' => $item->reorder_point,
            'bin_location' => $item->bin_location,
            'unit_cost' => $item->unit_cost,
            'needs_reorder' => $item->quantity <= ($item->reorder_point ?? 0),
        ]);

        // Get summary stats
        $stats = [
            'total_skus' => Inventory::where('store_id', $store->id)
                ->when($selectedWarehouseId, fn ($q) => $q->where('warehouse_id', $selectedWarehouseId))
                ->count(),
            'total_units' => Inventory::where('store_id', $store->id)
                ->when($selectedWarehouseId, fn ($q) => $q->where('warehouse_id', $selectedWarehouseId))
                ->sum('quantity'),
            'low_stock_count' => Inventory::where('store_id', $store->id)
                ->when($selectedWarehouseId, fn ($q) => $q->where('warehouse_id', $selectedWarehouseId))
                ->lowStock()
                ->count(),
            'total_value' => Inventory::where('store_id', $store->id)
                ->when($selectedWarehouseId, fn ($q) => $q->where('warehouse_id', $selectedWarehouseId))
                ->selectRaw('SUM(quantity * COALESCE(unit_cost, 0)) as total')
                ->value('total') ?? 0,
        ];

        return Inertia::render('inventory/Index', [
            'inventory' => $inventory,
            'warehouses' => $warehouses,
            'selectedWarehouseId' => $selectedWarehouseId,
            'stats' => $stats,
            'filters' => [
                'search' => $request->input('search', ''),
                'low_stock' => $request->boolean('low_stock'),
            ],
        ]);
    }

    public function adjust(Request $request): RedirectResponse|JsonResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return response()->json(['error' => 'Please select a store first.'], 400);
        }

        $validated = $request->validate([
            'inventory_id' => 'required|integer|exists:inventory,id',
            'adjustment' => 'required|integer',
            'type' => 'required|string|in:'.implode(',', InventoryAdjustment::TYPES),
            'reason' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
        ]);

        $inventory = Inventory::where('store_id', $store->id)
            ->findOrFail($validated['inventory_id']);

        $adjustment = $inventory->adjustQuantity(
            $validated['adjustment'],
            $validated['type'],
            $request->user()?->id,
            $validated['reason'] ?? null,
            $validated['notes'] ?? null
        );

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'inventory' => $inventory->fresh(),
                'adjustment' => $adjustment,
            ]);
        }

        return redirect()->back()
            ->with('success', 'Inventory adjusted successfully.');
    }
}
