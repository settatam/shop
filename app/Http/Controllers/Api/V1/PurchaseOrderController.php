<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ReceivePurchaseOrderRequest;
use App\Http\Requests\Api\StorePurchaseOrderRequest;
use App\Http\Requests\Api\UpdatePurchaseOrderRequest;
use App\Models\Inventory;
use App\Models\InventoryAdjustment;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseOrderReceipt;
use App\Models\PurchaseOrderReceiptItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PurchaseOrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = PurchaseOrder::with(['vendor:id,name,code', 'warehouse:id,name,code', 'createdBy:id,name'])
            ->withCount('items');

        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->has('vendor_id')) {
            $query->where('vendor_id', $request->input('vendor_id'));
        }

        if ($request->has('warehouse_id')) {
            $query->where('warehouse_id', $request->input('warehouse_id'));
        }

        if ($request->has('search')) {
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

        if ($request->boolean('all')) {
            return response()->json(['data' => $query->get()]);
        }

        $purchaseOrders = $query->paginate($request->input('per_page', 15));

        return response()->json($purchaseOrders);
    }

    public function store(StorePurchaseOrderRequest $request): JsonResponse
    {
        $purchaseOrder = DB::transaction(function () use ($request) {
            $po = PurchaseOrder::create([
                'vendor_id' => $request->input('vendor_id'),
                'warehouse_id' => $request->input('warehouse_id'),
                'created_by' => $request->user()->id,
                'status' => PurchaseOrder::STATUS_DRAFT,
                'order_date' => $request->input('order_date', now()),
                'expected_date' => $request->input('expected_date'),
                'shipping_method' => $request->input('shipping_method'),
                'vendor_notes' => $request->input('vendor_notes'),
                'internal_notes' => $request->input('internal_notes'),
                'tax_amount' => $request->input('tax_amount', 0),
                'shipping_cost' => $request->input('shipping_cost', 0),
                'discount_amount' => $request->input('discount_amount', 0),
                'subtotal' => 0,
                'total' => 0,
            ]);

            foreach ($request->input('items') as $itemData) {
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

        $purchaseOrder->load(['vendor', 'warehouse', 'items.productVariant']);

        return response()->json($purchaseOrder, 201);
    }

    public function show(PurchaseOrder $purchaseOrder): JsonResponse
    {
        $purchaseOrder->load([
            'vendor',
            'warehouse',
            'createdBy:id,name',
            'approvedBy:id,name',
            'items.productVariant.product:id,title',
            'receipts.items',
        ]);

        return response()->json($purchaseOrder);
    }

    public function update(UpdatePurchaseOrderRequest $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $purchaseOrder->update($request->validated());

        $purchaseOrder->load(['vendor', 'warehouse', 'items.productVariant']);

        return response()->json($purchaseOrder);
    }

    public function destroy(PurchaseOrder $purchaseOrder): JsonResponse
    {
        if (! $purchaseOrder->isDraft()) {
            return response()->json([
                'message' => 'Only draft purchase orders can be deleted',
            ], 422);
        }

        $purchaseOrder->items()->delete();
        $purchaseOrder->delete();

        return response()->json(null, 204);
    }

    public function addItem(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        if (! $purchaseOrder->isDraft()) {
            return response()->json([
                'message' => 'Items can only be added to draft purchase orders',
            ], 422);
        }

        $validated = $request->validate([
            'product_variant_id' => ['required', 'exists:product_variants,id'],
            'vendor_sku' => ['nullable', 'string', 'max:191'],
            'description' => ['nullable', 'string'],
            'quantity_ordered' => ['required', 'integer', 'min:1'],
            'unit_cost' => ['required', 'numeric', 'min:0'],
            'discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'notes' => ['nullable', 'string'],
        ]);

        $item = PurchaseOrderItem::create([
            'purchase_order_id' => $purchaseOrder->id,
            'product_variant_id' => $validated['product_variant_id'],
            'vendor_sku' => $validated['vendor_sku'] ?? null,
            'description' => $validated['description'] ?? null,
            'quantity_ordered' => $validated['quantity_ordered'],
            'quantity_received' => 0,
            'unit_cost' => $validated['unit_cost'],
            'discount_percent' => $validated['discount_percent'] ?? 0,
            'tax_rate' => $validated['tax_rate'] ?? 0,
            'notes' => $validated['notes'] ?? null,
        ]);

        $item->load('productVariant.product:id,title');

        return response()->json($item, 201);
    }

    public function updateItem(Request $request, PurchaseOrder $purchaseOrder, PurchaseOrderItem $item): JsonResponse
    {
        if (! $purchaseOrder->isDraft()) {
            return response()->json([
                'message' => 'Items can only be updated on draft purchase orders',
            ], 422);
        }

        if ($item->purchase_order_id !== $purchaseOrder->id) {
            return response()->json(['message' => 'Item not found'], 404);
        }

        $validated = $request->validate([
            'vendor_sku' => ['nullable', 'string', 'max:191'],
            'description' => ['nullable', 'string'],
            'quantity_ordered' => ['sometimes', 'integer', 'min:1'],
            'unit_cost' => ['sometimes', 'numeric', 'min:0'],
            'discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'notes' => ['nullable', 'string'],
        ]);

        $item->update($validated);

        return response()->json($item);
    }

    public function removeItem(PurchaseOrder $purchaseOrder, PurchaseOrderItem $item): JsonResponse
    {
        if (! $purchaseOrder->isDraft()) {
            return response()->json([
                'message' => 'Items can only be removed from draft purchase orders',
            ], 422);
        }

        if ($item->purchase_order_id !== $purchaseOrder->id) {
            return response()->json(['message' => 'Item not found'], 404);
        }

        $item->delete();

        return response()->json(['message' => 'Item removed successfully']);
    }

    public function submit(PurchaseOrder $purchaseOrder): JsonResponse
    {
        try {
            $purchaseOrder->submit();
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($purchaseOrder);
    }

    public function approve(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        try {
            $purchaseOrder->approve($request->user());
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $purchaseOrder->load(['items.productVariant']);

        return response()->json($purchaseOrder);
    }

    public function cancel(PurchaseOrder $purchaseOrder): JsonResponse
    {
        try {
            $purchaseOrder->cancel();
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($purchaseOrder);
    }

    public function close(PurchaseOrder $purchaseOrder): JsonResponse
    {
        try {
            $purchaseOrder->close();
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($purchaseOrder);
    }

    public function receive(ReceivePurchaseOrderRequest $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $receipt = DB::transaction(function () use ($request, $purchaseOrder) {
            $receipt = PurchaseOrderReceipt::create([
                'store_id' => $purchaseOrder->store_id,
                'purchase_order_id' => $purchaseOrder->id,
                'received_by' => $request->user()->id,
                'received_at' => now(),
                'notes' => $request->input('notes'),
            ]);

            foreach ($request->input('items') as $itemData) {
                $poItem = PurchaseOrderItem::findOrFail($itemData['purchase_order_item_id']);

                // Validate item belongs to this PO
                if ($poItem->purchase_order_id !== $purchaseOrder->id) {
                    throw new \RuntimeException('Item does not belong to this purchase order');
                }

                // Check if we're receiving more than remaining
                $remainingQty = $poItem->quantity_ordered - $poItem->quantity_received;
                $qtyToReceive = min($itemData['quantity_received'], $remainingQty);

                if ($qtyToReceive <= 0) {
                    continue;
                }

                $unitCost = $itemData['unit_cost'] ?? $poItem->unit_cost;

                // Update PO item's received quantity
                $poItem->increment('quantity_received', $qtyToReceive);

                // Get or create inventory record
                $inventory = Inventory::getOrCreate(
                    $purchaseOrder->store_id,
                    $poItem->product_variant_id,
                    $purchaseOrder->warehouse_id
                );

                $quantityBefore = $inventory->quantity;
                $costBefore = $inventory->unit_cost;

                // Receive inventory (updates qty, incoming_qty, and weighted avg cost)
                $inventory->receive($qtyToReceive, $unitCost);

                // Create inventory adjustment for audit
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

                // Create receipt item
                PurchaseOrderReceiptItem::create([
                    'purchase_order_receipt_id' => $receipt->id,
                    'purchase_order_item_id' => $poItem->id,
                    'inventory_adjustment_id' => $adjustment->id,
                    'quantity_received' => $qtyToReceive,
                    'unit_cost' => $unitCost,
                    'notes' => $itemData['notes'] ?? null,
                ]);
            }

            // Update PO receiving status
            $purchaseOrder->updateReceivingStatus();

            return $receipt;
        });

        $receipt->load(['items.purchaseOrderItem.productVariant', 'receivedBy:id,name']);
        $purchaseOrder->refresh()->load(['items.productVariant', 'receipts']);

        return response()->json([
            'receipt' => $receipt,
            'purchase_order' => $purchaseOrder,
        ]);
    }
}
