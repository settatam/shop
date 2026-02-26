<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Inventory;
use App\Models\InventoryTransfer;
use App\Models\Warehouse;
use App\Services\StoreContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class InventoryAllocationController extends Controller
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

        // Stock distribution: inventory grouped by variant, pivoted by warehouse
        $distributionQuery = Inventory::query()
            ->where('store_id', $store->id)
            ->with(['variant.product' => fn ($q) => $q->withTrashed(), 'warehouse']);

        if ($request->filled('search')) {
            $search = $request->input('search');
            $distributionQuery->whereHas('variant', function ($q) use ($search) {
                $q->where('sku', 'like', "%{$search}%")
                    ->orWhereHas('product', function ($q2) use ($search) {
                        $q2->where('title', 'like', "%{$search}%");
                    });
            });
        }

        $distribution = $distributionQuery->get()
            ->groupBy('product_variant_id')
            ->map(function ($items) use ($warehouses) {
                $first = $items->first();

                $warehouseQuantities = [];
                foreach ($warehouses as $warehouse) {
                    $inv = $items->firstWhere('warehouse_id', $warehouse->id);
                    $warehouseQuantities[$warehouse->id] = $inv ? $inv->quantity : 0;
                }

                return [
                    'variant_id' => $first->variant->id,
                    'product_id' => $first->variant->product->id,
                    'product_title' => $first->variant->product->title,
                    'variant_title' => $first->variant->options_title,
                    'sku' => $first->variant->sku,
                    'warehouse_quantities' => $warehouseQuantities,
                    'total_quantity' => $items->sum('quantity'),
                ];
            })
            ->values();

        // Active transfers
        $transfers = InventoryTransfer::where('store_id', $store->id)
            ->with(['fromWarehouse', 'toWarehouse', 'items.variant.product' => fn ($q) => $q->withTrashed(), 'createdByUser'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('transfer_search'), fn ($q) => $q->where('reference', 'like', '%'.$request->input('transfer_search').'%'))
            ->latest()
            ->paginate(15)
            ->through(fn ($transfer) => [
                'id' => $transfer->id,
                'reference' => $transfer->reference,
                'from_warehouse' => $transfer->fromWarehouse ? [
                    'id' => $transfer->fromWarehouse->id,
                    'name' => $transfer->fromWarehouse->name,
                ] : null,
                'to_warehouse' => $transfer->toWarehouse ? [
                    'id' => $transfer->toWarehouse->id,
                    'name' => $transfer->toWarehouse->name,
                ] : null,
                'status' => $transfer->status,
                'total_items' => $transfer->items->sum('quantity_requested'),
                'items' => $transfer->items->map(fn ($item) => [
                    'id' => $item->id,
                    'variant_id' => $item->product_variant_id,
                    'product_title' => $item->variant?->product?->title,
                    'variant_title' => $item->variant?->options_title,
                    'sku' => $item->variant?->sku,
                    'quantity_requested' => $item->quantity_requested,
                    'quantity_shipped' => $item->quantity_shipped,
                    'quantity_received' => $item->quantity_received,
                ]),
                'notes' => $transfer->notes,
                'expected_at' => $transfer->expected_at?->toDateString(),
                'shipped_at' => $transfer->shipped_at?->toDateString(),
                'received_at' => $transfer->received_at?->toDateString(),
                'created_by' => $transfer->createdByUser?->name,
                'created_at' => $transfer->created_at->toDateString(),
            ]);

        // Stats
        $stats = [
            'total_warehouses' => $warehouses->count(),
            'items_in_transit' => InventoryTransfer::where('store_id', $store->id)
                ->where('status', InventoryTransfer::STATUS_IN_TRANSIT)->count(),
            'pending_transfers' => InventoryTransfer::where('store_id', $store->id)
                ->whereIn('status', [InventoryTransfer::STATUS_DRAFT, InventoryTransfer::STATUS_PENDING])->count(),
            'total_allocated_value' => (float) (Inventory::where('store_id', $store->id)
                ->selectRaw('SUM(quantity * COALESCE(unit_cost, 0)) as total')
                ->value('total') ?? 0),
        ];

        return Inertia::render('inventory/Allocations', [
            'distribution' => $distribution,
            'warehouses' => $warehouses,
            'transfers' => $transfers,
            'stats' => $stats,
            'filters' => [
                'search' => $request->input('search', ''),
                'status' => $request->input('status', ''),
                'transfer_search' => $request->input('transfer_search', ''),
            ],
        ]);
    }
}
