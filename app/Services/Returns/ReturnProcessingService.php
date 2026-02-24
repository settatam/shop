<?php

namespace App\Services\Returns;

use App\Enums\Platform;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductReturn;
use App\Models\ReturnItem;
use App\Services\Platforms\Shopify\ShopifyService;
use Illuminate\Support\Facades\DB;

class ReturnProcessingService
{
    public function __construct(
        protected ShopifyService $shopifyService
    ) {}

    /**
     * Process a return for specific order items.
     *
     * @param  array  $items  Array of ['order_item_id' => ..., 'quantity' => ..., 'reason' => ..., 'restock' => bool]
     */
    public function processItemReturn(
        Order $order,
        array $items,
        string $returnMethod = ProductReturn::METHOD_IN_STORE,
        ?string $reason = null,
        ?int $processedBy = null
    ): ProductReturn {
        return DB::transaction(function () use ($order, $items, $returnMethod, $reason, $processedBy) {
            // Create the local return record
            $return = $this->createLocalReturn($order, $items, $returnMethod, $reason, $processedBy);

            // If this is a platform order, process the refund on the platform
            if ($order->platformOrder && $order->platformOrder->marketplace) {
                $this->processExternalReturn($return, $order, $items);
            }

            // Check if all items have been returned and update order status
            $this->checkAndUpdateOrderStatus($order);

            return $return->fresh(['items']);
        });
    }

    /**
     * Create a local ProductReturn record with items.
     */
    protected function createLocalReturn(
        Order $order,
        array $items,
        string $returnMethod,
        ?string $reason,
        ?int $processedBy
    ): ProductReturn {
        $return = ProductReturn::create([
            'store_id' => $order->store_id,
            'order_id' => $order->id,
            'customer_id' => $order->customer_id,
            'processed_by' => $processedBy,
            'status' => ProductReturn::STATUS_PROCESSING,
            'type' => ProductReturn::TYPE_RETURN,
            'return_method' => $returnMethod,
            'reason' => $reason,
            'source_platform' => $order->source_platform,
            'store_marketplace_id' => $order->platformOrder?->store_marketplace_id,
        ]);

        $subtotal = 0;

        foreach ($items as $itemData) {
            $orderItem = OrderItem::find($itemData['order_item_id']);
            if (! $orderItem) {
                continue;
            }

            $quantity = $itemData['quantity'] ?? $orderItem->quantity;
            $unitPrice = $orderItem->price - ($orderItem->discount ?? 0);
            $lineTotal = $unitPrice * $quantity;

            ReturnItem::create([
                'return_id' => $return->id,
                'order_item_id' => $orderItem->id,
                'product_variant_id' => $orderItem->product_variant_id,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'line_total' => $lineTotal,
                'reason' => $itemData['reason'] ?? $reason,
                'restock' => $itemData['restock'] ?? true,
                'restocked' => false,
            ]);

            $subtotal += $lineTotal;
        }

        $return->update([
            'subtotal' => $subtotal,
            'refund_amount' => $subtotal,
        ]);

        return $return;
    }

    /**
     * Process the refund on the external platform.
     */
    protected function processExternalReturn(ProductReturn $return, Order $order, array $items): void
    {
        $platformOrder = $order->platformOrder;
        $marketplace = $platformOrder->marketplace;
        $platform = $marketplace->platform;

        if ($platform->value !== Platform::Shopify->value) {
            return;
        }

        // Map order items to Shopify line item IDs
        $lineItems = $this->mapToShopifyLineItems($platformOrder, $items);

        if (empty($lineItems)) {
            return;
        }

        try {
            $refundData = $this->shopifyService->createRefund(
                $platformOrder,
                $lineItems,
                notify: true,
                note: $return->reason
            );

            // Update return with external reference
            $return->update([
                'external_return_id' => (string) ($refundData['id'] ?? ''),
                'sync_status' => ProductReturn::SYNC_STATUS_SYNCED,
                'synced_at' => now(),
            ]);
        } catch (\Throwable $e) {
            $return->update([
                'sync_status' => ProductReturn::SYNC_STATUS_FAILED,
                'internal_notes' => 'Platform sync failed: '.$e->getMessage(),
            ]);
        }
    }

    /**
     * Map order items to Shopify line item IDs.
     */
    protected function mapToShopifyLineItems(mixed $platformOrder, array $items): array
    {
        $platformLineItems = $platformOrder->line_items ?? [];
        $orderItemIds = collect($items)->pluck('order_item_id')->toArray();

        // Load order items with their variants
        $orderItems = OrderItem::whereIn('id', $orderItemIds)
            ->with('variant')
            ->get()
            ->keyBy('id');

        $shopifyLineItems = [];

        foreach ($items as $itemData) {
            $orderItem = $orderItems[$itemData['order_item_id']] ?? null;
            if (! $orderItem) {
                continue;
            }

            // Try to find matching Shopify line item by SKU or variant ID
            $shopifyLineItem = $this->findMatchingShopifyLineItem(
                $platformLineItems,
                $orderItem
            );

            if ($shopifyLineItem) {
                $shopifyLineItems[] = [
                    'line_item_id' => $shopifyLineItem['id'],
                    'quantity' => $itemData['quantity'] ?? $orderItem->quantity,
                    'restock_type' => ($itemData['restock'] ?? true) ? 'return' : 'no_restock',
                ];
            }
        }

        return $shopifyLineItems;
    }

    /**
     * Find matching Shopify line item for an order item.
     */
    protected function findMatchingShopifyLineItem(array $platformLineItems, OrderItem $orderItem): ?array
    {
        foreach ($platformLineItems as $lineItem) {
            // Match by SKU
            if (! empty($orderItem->sku) && ($lineItem['sku'] ?? '') === $orderItem->sku) {
                return $lineItem;
            }

            // Match by variant external ID if stored
            if ($orderItem->variant && ! empty($lineItem['variant_id'])) {
                // Check if the variant has a platform listing that matches
                // For now, we'll match by SKU or title
                if (($lineItem['title'] ?? '') === $orderItem->title) {
                    return $lineItem;
                }
            }
        }

        return null;
    }

    /**
     * Check if all order items have been returned and update order status.
     */
    protected function checkAndUpdateOrderStatus(Order $order): void
    {
        $order->load(['items', 'returns.items']);

        // Calculate total quantity ordered
        $totalOrdered = $order->items->sum('quantity');

        // Calculate total quantity returned across all returns
        $totalReturned = $order->returns
            ->whereIn('status', [
                ProductReturn::STATUS_PROCESSING,
                ProductReturn::STATUS_COMPLETED,
            ])
            ->flatMap->items
            ->sum('quantity');

        // If all items returned, mark order as refunded
        if ($totalReturned >= $totalOrdered) {
            $order->update(['status' => Order::STATUS_REFUNDED]);
        }
    }

    /**
     * Complete a return and process restocking if needed.
     */
    public function completeReturn(ProductReturn $return): ProductReturn
    {
        // For in-store returns, restock immediately on completion
        if ($return->isInStore()) {
            $this->restockReturnItems($return);
        }

        $return->complete(
            $return->refund_method ?? ProductReturn::REFUND_ORIGINAL,
            $return->refund_amount
        );

        return $return->fresh();
    }

    /**
     * Mark a return as received (for shipped returns) and process restocking.
     */
    public function markAsReceived(ProductReturn $return): ProductReturn
    {
        $return->markAsReceived();

        // For shipped returns, restock when items are received
        if ($return->isShipped()) {
            $this->restockReturnItems($return);
        }

        return $return->fresh();
    }

    /**
     * Restock all items in a return that are marked for restocking.
     */
    public function restockReturnItems(ProductReturn $return): void
    {
        $return->load('items.productVariant.inventories');

        foreach ($return->items as $item) {
            if (! $item->shouldRestock()) {
                continue;
            }

            $variant = $item->productVariant;
            if (! $variant) {
                continue;
            }

            // Add quantity back to inventory
            $variant->increment('quantity', $item->quantity);

            // If there's a specific inventory location, update that
            $inventory = $variant->inventories->first();
            if ($inventory) {
                $inventory->increment('quantity', $item->quantity);
            }

            $item->markAsRestocked();
        }

        $return->update([
            'items_restocked' => true,
            'restocked_at' => now(),
        ]);
    }
}
