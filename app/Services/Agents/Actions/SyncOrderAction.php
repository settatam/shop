<?php

namespace App\Services\Agents\Actions;

use App\Models\AgentAction;
use App\Models\StoreAgent;
use App\Models\StoreMarketplace;
use App\Services\Agents\Contracts\AgentActionInterface;
use App\Services\Agents\Results\ActionResult;

class SyncOrderAction implements AgentActionInterface
{
    public function getType(): string
    {
        return 'sync_order';
    }

    public function getDescription(): string
    {
        return 'Import an order from an external marketplace';
    }

    public function requiresApproval(StoreAgent $storeAgent, array $payload): bool
    {
        // Order imports typically don't require approval
        return false;
    }

    public function execute(AgentAction $action): ActionResult
    {
        $marketplace = $action->actionable;

        if (! $marketplace instanceof StoreMarketplace) {
            return ActionResult::failure('Action target is not a marketplace');
        }

        $payload = $action->payload;
        $orderData = $payload['order_data'] ?? null;

        if (! $orderData) {
            return ActionResult::failure('No order data in payload');
        }

        try {
            // Check if order already exists
            $existingOrder = $marketplace->platformOrders()
                ->where('external_order_id', $orderData['external_id'])
                ->first();

            if ($existingOrder) {
                // Update existing order
                $existingOrder->update([
                    'status' => $orderData['status'],
                    'fulfillment_status' => $orderData['fulfillment_status'],
                    'payment_status' => $orderData['payment_status'],
                    'last_synced_at' => now(),
                ]);

                return ActionResult::success(
                    "Order {$orderData['order_number']} updated",
                    [
                        'platform_order_id' => $existingOrder->id,
                        'external_order_id' => $orderData['external_id'],
                        'action' => 'updated',
                    ]
                );
            }

            // Create new platform order
            $platformOrder = $marketplace->platformOrders()->create([
                'external_order_id' => $orderData['external_id'],
                'external_order_number' => $orderData['order_number'],
                'status' => $orderData['status'],
                'fulfillment_status' => $orderData['fulfillment_status'],
                'payment_status' => $orderData['payment_status'],
                'total' => $orderData['total'],
                'subtotal' => $orderData['subtotal'],
                'shipping_cost' => $orderData['shipping_cost'],
                'tax' => $orderData['tax'],
                'discount' => $orderData['discount'] ?? 0,
                'currency' => $orderData['currency'],
                'customer_data' => $orderData['customer'],
                'shipping_address' => $orderData['shipping_address'],
                'billing_address' => $orderData['billing_address'],
                'line_items' => $orderData['line_items'],
                'platform_data' => $orderData['metadata'] ?? [],
                'ordered_at' => $orderData['ordered_at'] ? \Carbon\Carbon::parse($orderData['ordered_at']) : null,
                'last_synced_at' => now(),
            ]);

            // Match line items to local products and create order if needed
            $this->matchAndCreateLocalOrder($marketplace, $platformOrder, $orderData);

            return ActionResult::success(
                "Order {$orderData['order_number']} imported from {$marketplace->platform->label()}",
                [
                    'platform_order_id' => $platformOrder->id,
                    'external_order_id' => $orderData['external_id'],
                    'order_number' => $orderData['order_number'],
                    'total' => $orderData['total'],
                    'action' => 'created',
                ]
            );
        } catch (\Throwable $e) {
            return ActionResult::failure("Failed to import order: {$e->getMessage()}");
        }
    }

    /**
     * Match platform order line items to local products and create a local order.
     */
    protected function matchAndCreateLocalOrder(
        StoreMarketplace $marketplace,
        $platformOrder,
        array $orderData
    ): void {
        // This could be expanded to:
        // 1. Match line items by SKU to local products
        // 2. Create a local Order record linked to the platform order
        // 3. Deduct inventory if needed
        // 4. Trigger any order-related workflows

        // For now, we just update inventory based on line items
        foreach ($orderData['line_items'] as $item) {
            $sku = $item['sku'] ?? null;

            if (! $sku) {
                continue;
            }

            // Find local product by SKU
            $product = \App\Models\Product::where('store_id', $marketplace->store_id)
                ->where('sku', $sku)
                ->first();

            if ($product && ($item['quantity'] ?? 0) > 0) {
                $qty = (int) $item['quantity'];

                // Deduct from inventory (first variant, first warehouse)
                $variant = $product->variants()->first();
                if ($variant) {
                    $inventory = \App\Models\Inventory::where('product_variant_id', $variant->id)->first();
                    if ($inventory) {
                        $inventory->decrement('quantity', $qty);
                        \App\Models\Inventory::syncVariantQuantity($variant->id);
                    }
                }
                \App\Models\Inventory::syncProductQuantity($product->id);

                // Sync inventory to all platform listings
                $product->syncInventoryToAllPlatforms('external_order_synced');
            }
        }
    }

    public function rollback(AgentAction $action): bool
    {
        $result = $action->result ?? [];
        $platformOrderId = $result['platform_order_id'] ?? null;
        $actionType = $result['action'] ?? null;

        if (! $platformOrderId || $actionType !== 'created') {
            return false;
        }

        try {
            $marketplace = $action->actionable;

            if (! $marketplace instanceof StoreMarketplace) {
                return false;
            }

            $platformOrder = $marketplace->platformOrders()->find($platformOrderId);

            if ($platformOrder) {
                // Restore inventory for line items
                $lineItems = $platformOrder->line_items ?? [];
                foreach ($lineItems as $item) {
                    $sku = $item['sku'] ?? null;

                    if (! $sku) {
                        continue;
                    }

                    $product = \App\Models\Product::where('store_id', $marketplace->store_id)
                        ->where('sku', $sku)
                        ->first();

                    if ($product && ($item['quantity'] ?? 0) > 0) {
                        $qty = (int) $item['quantity'];
                        $variant = $product->variants()->first();
                        if ($variant) {
                            $inventory = \App\Models\Inventory::where('product_variant_id', $variant->id)->first();
                            if ($inventory) {
                                $inventory->increment('quantity', $qty);
                                \App\Models\Inventory::syncVariantQuantity($variant->id);
                            }
                        }
                        \App\Models\Inventory::syncProductQuantity($product->id);
                    }
                }

                $platformOrder->delete();
            }

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function validatePayload(array $payload): bool
    {
        if (! isset($payload['order_data'])) {
            return false;
        }

        $orderData = $payload['order_data'];

        return isset($orderData['external_id'])
            && isset($orderData['status'])
            && isset($orderData['total']);
    }
}
