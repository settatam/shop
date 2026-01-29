<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Inventory;
use App\Models\InventoryAdjustment;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseOrderReceipt;
use App\Models\PurchaseOrderReceiptItem;
use App\Models\Vendor;
use App\Models\Warehouse;
use App\Services\StoreContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class PurchaseOrderController extends Controller
{
    public function __construct(protected StoreContext $storeContext) {}

    public function index(Request $request): Response|RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return redirect()->route('dashboard')
                ->with('error', 'Please select a store first.');
        }

        $query = PurchaseOrder::where('store_id', $store->id)
            ->with(['vendor:id,name,code', 'warehouse:id,name,code', 'createdBy:id,name'])
            ->withCount('items');

        if ($request->has('status') && $request->input('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->has('vendor_id') && $request->input('vendor_id')) {
            $query->where('vendor_id', $request->input('vendor_id'));
        }

        if ($request->has('search') && $request->input('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('po_number', 'like', "%{$search}%")
                    ->orWhereHas('vendor', function ($q) use ($search) {
                        $q->where('name', 'like', "%{$search}%");
                    });
            });
        }

        $sortField = $request->input('sort', 'created_at');
        $sortDirection = $request->input('direction', 'desc');
        $query->orderBy($sortField, $sortDirection);

        $purchaseOrders = $query->paginate($request->input('per_page', 15))
            ->through(fn ($po) => [
                'id' => $po->id,
                'po_number' => $po->po_number,
                'status' => $po->status,
                'vendor' => $po->vendor ? [
                    'id' => $po->vendor->id,
                    'name' => $po->vendor->name,
                    'code' => $po->vendor->code,
                ] : null,
                'warehouse' => $po->warehouse ? [
                    'id' => $po->warehouse->id,
                    'name' => $po->warehouse->name,
                ] : null,
                'created_by' => $po->createdBy ? [
                    'id' => $po->createdBy->id,
                    'name' => $po->createdBy->name,
                ] : null,
                'total' => $po->total,
                'items_count' => $po->items_count,
                'order_date' => $po->order_date?->format('M d, Y'),
                'expected_date' => $po->expected_date?->format('M d, Y'),
                'created_at' => $po->created_at->format('M d, Y'),
            ]);

        $vendors = Vendor::where('store_id', $store->id)
            ->active()
            ->orderBy('name')
            ->get(['id', 'name', 'code']);

        $warehouses = Warehouse::where('store_id', $store->id)
            ->active()
            ->orderBy('name')
            ->get(['id', 'name', 'code']);

        return Inertia::render('purchase-orders/Index', [
            'purchaseOrders' => $purchaseOrders,
            'vendors' => $vendors,
            'warehouses' => $warehouses,
            'statuses' => PurchaseOrder::STATUSES,
            'filters' => [
                'search' => $request->input('search', ''),
                'status' => $request->input('status'),
                'vendor_id' => $request->input('vendor_id'),
            ],
        ]);
    }

    public function create(): Response|RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return redirect()->route('dashboard')
                ->with('error', 'Please select a store first.');
        }

        $vendors = Vendor::where('store_id', $store->id)
            ->active()
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'payment_terms', 'lead_time_days']);

        $warehouses = Warehouse::where('store_id', $store->id)
            ->active()
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'is_default']);

        return Inertia::render('purchase-orders/Create', [
            'vendors' => $vendors,
            'warehouses' => $warehouses,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return redirect()->route('dashboard')
                ->with('error', 'Please select a store first.');
        }

        $validated = $request->validate([
            'vendor_id' => ['required', 'exists:vendors,id'],
            'warehouse_id' => ['required', 'exists:warehouses,id'],
            'order_date' => ['nullable', 'date'],
            'expected_date' => ['nullable', 'date', 'after_or_equal:order_date'],
            'shipping_method' => ['nullable', 'string', 'max:100'],
            'vendor_notes' => ['nullable', 'string'],
            'internal_notes' => ['nullable', 'string'],
            'tax_amount' => ['nullable', 'numeric', 'min:0'],
            'shipping_cost' => ['nullable', 'numeric', 'min:0'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_variant_id' => ['required', 'exists:product_variants,id'],
            'items.*.vendor_sku' => ['nullable', 'string', 'max:191'],
            'items.*.description' => ['nullable', 'string'],
            'items.*.quantity_ordered' => ['required', 'integer', 'min:1'],
            'items.*.unit_cost' => ['required', 'numeric', 'min:0'],
            'items.*.discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.notes' => ['nullable', 'string'],
        ]);

        $purchaseOrder = DB::transaction(function () use ($validated, $store, $request) {
            $po = PurchaseOrder::create([
                'store_id' => $store->id,
                'vendor_id' => $validated['vendor_id'],
                'warehouse_id' => $validated['warehouse_id'],
                'created_by' => $request->user()->id,
                'status' => PurchaseOrder::STATUS_DRAFT,
                'order_date' => $validated['order_date'] ?? now(),
                'expected_date' => $validated['expected_date'] ?? null,
                'shipping_method' => $validated['shipping_method'] ?? null,
                'vendor_notes' => $validated['vendor_notes'] ?? null,
                'internal_notes' => $validated['internal_notes'] ?? null,
                'tax_amount' => $validated['tax_amount'] ?? 0,
                'shipping_cost' => $validated['shipping_cost'] ?? 0,
                'discount_amount' => $validated['discount_amount'] ?? 0,
                'subtotal' => 0,
                'total' => 0,
            ]);

            foreach ($validated['items'] as $itemData) {
                PurchaseOrderItem::create([
                    'purchase_order_id' => $po->id,
                    'product_variant_id' => $itemData['product_variant_id'],
                    'vendor_sku' => $itemData['vendor_sku'] ?? null,
                    'description' => $itemData['description'] ?? null,
                    'quantity_ordered' => $itemData['quantity_ordered'],
                    'quantity_received' => 0,
                    'unit_cost' => $itemData['unit_cost'],
                    'discount_percent' => $itemData['discount_percent'] ?? 0,
                    'tax_rate' => $itemData['tax_rate'] ?? 0,
                    'notes' => $itemData['notes'] ?? null,
                ]);
            }

            return $po;
        });

        return redirect()->route('web.purchase-orders.show', $purchaseOrder)
            ->with('success', 'Purchase order created successfully.');
    }

    public function show(PurchaseOrder $purchaseOrder): Response|RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store || $purchaseOrder->store_id !== $store->id) {
            abort(404);
        }

        $purchaseOrder->load([
            'vendor',
            'warehouse',
            'createdBy:id,name',
            'approvedBy:id,name',
            'items.productVariant.product:id,title',
            'receipts' => fn ($q) => $q->with(['receivedBy:id,name', 'items'])->latest(),
        ]);

        return Inertia::render('purchase-orders/Show', [
            'purchaseOrder' => [
                'id' => $purchaseOrder->id,
                'po_number' => $purchaseOrder->po_number,
                'status' => $purchaseOrder->status,
                'vendor' => $purchaseOrder->vendor,
                'warehouse' => $purchaseOrder->warehouse,
                'created_by' => $purchaseOrder->createdBy,
                'approved_by' => $purchaseOrder->approvedBy,
                'subtotal' => $purchaseOrder->subtotal,
                'tax_amount' => $purchaseOrder->tax_amount,
                'shipping_cost' => $purchaseOrder->shipping_cost,
                'discount_amount' => $purchaseOrder->discount_amount,
                'total' => $purchaseOrder->total,
                'order_date' => $purchaseOrder->order_date?->format('Y-m-d'),
                'expected_date' => $purchaseOrder->expected_date?->format('Y-m-d'),
                'approved_at' => $purchaseOrder->approved_at?->format('M d, Y g:i A'),
                'submitted_at' => $purchaseOrder->submitted_at?->format('M d, Y g:i A'),
                'closed_at' => $purchaseOrder->closed_at?->format('M d, Y g:i A'),
                'cancelled_at' => $purchaseOrder->cancelled_at?->format('M d, Y g:i A'),
                'shipping_method' => $purchaseOrder->shipping_method,
                'tracking_number' => $purchaseOrder->tracking_number,
                'vendor_notes' => $purchaseOrder->vendor_notes,
                'internal_notes' => $purchaseOrder->internal_notes,
                'receiving_progress' => $purchaseOrder->receiving_progress,
                'total_ordered_quantity' => $purchaseOrder->total_ordered_quantity,
                'total_received_quantity' => $purchaseOrder->total_received_quantity,
                'items' => $purchaseOrder->items->map(fn ($item) => [
                    'id' => $item->id,
                    'product_variant' => $item->productVariant ? [
                        'id' => $item->productVariant->id,
                        'sku' => $item->productVariant->sku,
                        'title' => $item->productVariant->product?->title,
                    ] : null,
                    'vendor_sku' => $item->vendor_sku,
                    'description' => $item->description,
                    'quantity_ordered' => $item->quantity_ordered,
                    'quantity_received' => $item->quantity_received,
                    'remaining_quantity' => $item->remaining_quantity,
                    'unit_cost' => $item->unit_cost,
                    'discount_percent' => $item->discount_percent,
                    'tax_rate' => $item->tax_rate,
                    'line_total' => $item->line_total,
                    'notes' => $item->notes,
                    'is_fully_received' => $item->isFullyReceived(),
                ]),
                'receipts' => $purchaseOrder->receipts->map(fn ($receipt) => [
                    'id' => $receipt->id,
                    'receipt_number' => $receipt->receipt_number,
                    'received_by' => $receipt->receivedBy,
                    'received_at' => $receipt->received_at?->format('M d, Y g:i A'),
                    'total_quantity' => $receipt->total_quantity,
                    'notes' => $receipt->notes,
                ]),
                'created_at' => $purchaseOrder->created_at->format('M d, Y g:i A'),
            ],
            'statuses' => PurchaseOrder::STATUSES,
        ]);
    }

    public function edit(PurchaseOrder $purchaseOrder): Response|RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store || $purchaseOrder->store_id !== $store->id) {
            abort(404);
        }

        if (! $purchaseOrder->isDraft()) {
            return redirect()->route('web.purchase-orders.show', $purchaseOrder)
                ->with('error', 'Only draft purchase orders can be edited.');
        }

        $purchaseOrder->load(['vendor', 'warehouse', 'items.productVariant.product:id,title']);

        $vendors = Vendor::where('store_id', $store->id)
            ->active()
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'payment_terms', 'lead_time_days']);

        $warehouses = Warehouse::where('store_id', $store->id)
            ->active()
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'is_default']);

        return Inertia::render('purchase-orders/Edit', [
            'purchaseOrder' => [
                'id' => $purchaseOrder->id,
                'po_number' => $purchaseOrder->po_number,
                'vendor_id' => $purchaseOrder->vendor_id,
                'warehouse_id' => $purchaseOrder->warehouse_id,
                'order_date' => $purchaseOrder->order_date?->format('Y-m-d'),
                'expected_date' => $purchaseOrder->expected_date?->format('Y-m-d'),
                'shipping_method' => $purchaseOrder->shipping_method,
                'vendor_notes' => $purchaseOrder->vendor_notes,
                'internal_notes' => $purchaseOrder->internal_notes,
                'tax_amount' => $purchaseOrder->tax_amount,
                'shipping_cost' => $purchaseOrder->shipping_cost,
                'discount_amount' => $purchaseOrder->discount_amount,
                'items' => $purchaseOrder->items->map(fn ($item) => [
                    'id' => $item->id,
                    'product_variant_id' => $item->product_variant_id,
                    'product_variant' => $item->productVariant ? [
                        'id' => $item->productVariant->id,
                        'sku' => $item->productVariant->sku,
                        'title' => $item->productVariant->product?->title,
                    ] : null,
                    'vendor_sku' => $item->vendor_sku,
                    'description' => $item->description,
                    'quantity_ordered' => $item->quantity_ordered,
                    'unit_cost' => $item->unit_cost,
                    'discount_percent' => $item->discount_percent,
                    'tax_rate' => $item->tax_rate,
                    'notes' => $item->notes,
                ]),
            ],
            'vendors' => $vendors,
            'warehouses' => $warehouses,
        ]);
    }

    public function update(Request $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store || $purchaseOrder->store_id !== $store->id) {
            abort(404);
        }

        if (! $purchaseOrder->isDraft()) {
            return redirect()->route('web.purchase-orders.show', $purchaseOrder)
                ->with('error', 'Only draft purchase orders can be updated.');
        }

        $validated = $request->validate([
            'vendor_id' => ['sometimes', 'exists:vendors,id'],
            'warehouse_id' => ['sometimes', 'exists:warehouses,id'],
            'order_date' => ['nullable', 'date'],
            'expected_date' => ['nullable', 'date', 'after_or_equal:order_date'],
            'shipping_method' => ['nullable', 'string', 'max:100'],
            'vendor_notes' => ['nullable', 'string'],
            'internal_notes' => ['nullable', 'string'],
            'tax_amount' => ['nullable', 'numeric', 'min:0'],
            'shipping_cost' => ['nullable', 'numeric', 'min:0'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'items' => ['sometimes', 'array', 'min:1'],
            'items.*.id' => ['nullable', 'integer'],
            'items.*.product_variant_id' => ['required', 'exists:product_variants,id'],
            'items.*.vendor_sku' => ['nullable', 'string', 'max:191'],
            'items.*.description' => ['nullable', 'string'],
            'items.*.quantity_ordered' => ['required', 'integer', 'min:1'],
            'items.*.unit_cost' => ['required', 'numeric', 'min:0'],
            'items.*.discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.notes' => ['nullable', 'string'],
        ]);

        DB::transaction(function () use ($validated, $purchaseOrder) {
            // Update PO header fields (excluding items)
            $headerFields = collect($validated)->except('items')->toArray();
            $purchaseOrder->update($headerFields);

            // Handle items if provided
            if (isset($validated['items'])) {
                $existingItemIds = $purchaseOrder->items()->pluck('id')->toArray();
                $updatedItemIds = [];

                foreach ($validated['items'] as $itemData) {
                    if (! empty($itemData['id']) && in_array($itemData['id'], $existingItemIds)) {
                        // Update existing item
                        $purchaseOrder->items()
                            ->where('id', $itemData['id'])
                            ->update([
                                'product_variant_id' => $itemData['product_variant_id'],
                                'vendor_sku' => $itemData['vendor_sku'] ?? null,
                                'description' => $itemData['description'] ?? null,
                                'quantity_ordered' => $itemData['quantity_ordered'],
                                'unit_cost' => $itemData['unit_cost'],
                                'discount_percent' => $itemData['discount_percent'] ?? 0,
                                'tax_rate' => $itemData['tax_rate'] ?? 0,
                                'notes' => $itemData['notes'] ?? null,
                            ]);
                        $updatedItemIds[] = $itemData['id'];
                    } else {
                        // Create new item
                        $newItem = PurchaseOrderItem::create([
                            'purchase_order_id' => $purchaseOrder->id,
                            'product_variant_id' => $itemData['product_variant_id'],
                            'vendor_sku' => $itemData['vendor_sku'] ?? null,
                            'description' => $itemData['description'] ?? null,
                            'quantity_ordered' => $itemData['quantity_ordered'],
                            'quantity_received' => 0,
                            'unit_cost' => $itemData['unit_cost'],
                            'discount_percent' => $itemData['discount_percent'] ?? 0,
                            'tax_rate' => $itemData['tax_rate'] ?? 0,
                            'notes' => $itemData['notes'] ?? null,
                        ]);
                        $updatedItemIds[] = $newItem->id;
                    }
                }

                // Delete items that were removed
                $itemsToDelete = array_diff($existingItemIds, $updatedItemIds);
                if (! empty($itemsToDelete)) {
                    $purchaseOrder->items()->whereIn('id', $itemsToDelete)->delete();
                }
            }
        });

        return redirect()->route('web.purchase-orders.show', $purchaseOrder)
            ->with('success', 'Purchase order updated successfully.');
    }

    public function destroy(PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store || $purchaseOrder->store_id !== $store->id) {
            abort(404);
        }

        if (! $purchaseOrder->isDraft()) {
            return redirect()->back()
                ->with('error', 'Only draft purchase orders can be deleted.');
        }

        $purchaseOrder->items()->delete();
        $purchaseOrder->delete();

        return redirect()->route('web.purchase-orders.index')
            ->with('success', 'Purchase order deleted successfully.');
    }

    public function submit(PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store || $purchaseOrder->store_id !== $store->id) {
            abort(404);
        }

        try {
            $purchaseOrder->submit();
        } catch (\RuntimeException $e) {
            return redirect()->back()
                ->with('error', $e->getMessage());
        }

        return redirect()->back()
            ->with('success', 'Purchase order submitted successfully.');
    }

    public function approve(Request $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store || $purchaseOrder->store_id !== $store->id) {
            abort(404);
        }

        try {
            $purchaseOrder->approve($request->user());
        } catch (\RuntimeException $e) {
            return redirect()->back()
                ->with('error', $e->getMessage());
        }

        return redirect()->back()
            ->with('success', 'Purchase order approved successfully.');
    }

    public function cancel(PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store || $purchaseOrder->store_id !== $store->id) {
            abort(404);
        }

        try {
            $purchaseOrder->cancel();
        } catch (\RuntimeException $e) {
            return redirect()->back()
                ->with('error', $e->getMessage());
        }

        return redirect()->back()
            ->with('success', 'Purchase order cancelled successfully.');
    }

    public function close(PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store || $purchaseOrder->store_id !== $store->id) {
            abort(404);
        }

        try {
            $purchaseOrder->close();
        } catch (\RuntimeException $e) {
            return redirect()->back()
                ->with('error', $e->getMessage());
        }

        return redirect()->back()
            ->with('success', 'Purchase order closed successfully.');
    }

    public function showReceive(PurchaseOrder $purchaseOrder): Response|RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store || $purchaseOrder->store_id !== $store->id) {
            abort(404);
        }

        if (! $purchaseOrder->isReceivable()) {
            return redirect()->route('web.purchase-orders.show', $purchaseOrder)
                ->with('error', 'This purchase order cannot receive items.');
        }

        $purchaseOrder->load([
            'vendor:id,name,code',
            'warehouse:id,name,code',
            'items.productVariant.product:id,title',
        ]);

        return Inertia::render('purchase-orders/Receive', [
            'purchaseOrder' => [
                'id' => $purchaseOrder->id,
                'po_number' => $purchaseOrder->po_number,
                'status' => $purchaseOrder->status,
                'vendor' => $purchaseOrder->vendor,
                'warehouse' => $purchaseOrder->warehouse,
                'items' => $purchaseOrder->items->map(fn ($item) => [
                    'id' => $item->id,
                    'product_variant' => $item->productVariant ? [
                        'id' => $item->productVariant->id,
                        'sku' => $item->productVariant->sku,
                        'title' => $item->productVariant->product?->title,
                    ] : null,
                    'vendor_sku' => $item->vendor_sku,
                    'quantity_ordered' => $item->quantity_ordered,
                    'quantity_received' => $item->quantity_received,
                    'remaining_quantity' => $item->remaining_quantity,
                    'unit_cost' => $item->unit_cost,
                    'is_fully_received' => $item->isFullyReceived(),
                ]),
            ],
        ]);
    }

    public function receive(Request $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store || $purchaseOrder->store_id !== $store->id) {
            abort(404);
        }

        if (! $purchaseOrder->isReceivable()) {
            return redirect()->route('web.purchase-orders.show', $purchaseOrder)
                ->with('error', 'This purchase order cannot receive items.');
        }

        $validated = $request->validate([
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.purchase_order_item_id' => ['required', 'integer', 'exists:purchase_order_items,id'],
            'items.*.quantity_received' => ['required', 'integer', 'min:1'],
            'items.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
            'items.*.notes' => ['nullable', 'string'],
        ]);

        DB::transaction(function () use ($validated, $purchaseOrder, $request) {
            $receipt = PurchaseOrderReceipt::create([
                'store_id' => $purchaseOrder->store_id,
                'purchase_order_id' => $purchaseOrder->id,
                'received_by' => $request->user()->id,
                'received_at' => now(),
                'notes' => $validated['notes'] ?? null,
            ]);

            foreach ($validated['items'] as $itemData) {
                $poItem = PurchaseOrderItem::findOrFail($itemData['purchase_order_item_id']);

                if ($poItem->purchase_order_id !== $purchaseOrder->id) {
                    continue;
                }

                $remainingQty = $poItem->quantity_ordered - $poItem->quantity_received;
                $qtyToReceive = min($itemData['quantity_received'], $remainingQty);

                if ($qtyToReceive <= 0) {
                    continue;
                }

                $unitCost = $itemData['unit_cost'] ?? $poItem->unit_cost;

                $poItem->increment('quantity_received', $qtyToReceive);

                $inventory = Inventory::getOrCreate(
                    $purchaseOrder->store_id,
                    $poItem->product_variant_id,
                    $purchaseOrder->warehouse_id
                );

                $quantityBefore = $inventory->quantity;
                $inventory->receive($qtyToReceive, $unitCost);

                $adjustment = InventoryAdjustment::create([
                    'store_id' => $purchaseOrder->store_id,
                    'inventory_id' => $inventory->id,
                    'user_id' => $request->user()->id,
                    'reference' => InventoryAdjustment::generateReference($purchaseOrder->store_id),
                    'type' => InventoryAdjustment::TYPE_PURCHASE_ORDER,
                    'quantity_before' => $quantityBefore,
                    'quantity_change' => $qtyToReceive,
                    'quantity_after' => $inventory->quantity,
                    'unit_cost' => $unitCost,
                    'total_cost_impact' => $qtyToReceive * $unitCost,
                    'reason' => "Received from PO #{$purchaseOrder->po_number}",
                ]);

                PurchaseOrderReceiptItem::create([
                    'purchase_order_receipt_id' => $receipt->id,
                    'purchase_order_item_id' => $poItem->id,
                    'inventory_adjustment_id' => $adjustment->id,
                    'quantity_received' => $qtyToReceive,
                    'unit_cost' => $unitCost,
                    'notes' => $itemData['notes'] ?? null,
                ]);
            }

            $purchaseOrder->updateReceivingStatus();
        });

        return redirect()->route('web.purchase-orders.show', $purchaseOrder)
            ->with('success', 'Items received successfully.');
    }
}
