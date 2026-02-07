<?php

namespace App\Services\Agents\Agents;

use App\Enums\AgentType;
use App\Models\AgentAction;
use App\Models\AgentRun;
use App\Models\PlatformListing;
use App\Models\Product;
use App\Models\StoreAgent;
use App\Models\StoreMarketplace;
use App\Services\Agents\Contracts\AgentInterface;
use App\Services\Agents\Results\AgentRunResult;
use App\Services\Marketplace\PlatformConnectorManager;

class ChannelSyncAgent implements AgentInterface
{
    public function __construct(
        protected PlatformConnectorManager $connectorManager
    ) {}

    public function getName(): string
    {
        return 'Channel Sync Agent';
    }

    public function getSlug(): string
    {
        return 'channel-sync';
    }

    public function getType(): AgentType
    {
        return AgentType::Reactive;
    }

    public function getDescription(): string
    {
        return 'Keeps inventory, pricing, and orders synchronized across all connected marketplaces. Monitors stock levels, imports orders, and ensures consistency across channels.';
    }

    public function getDefaultConfig(): array
    {
        return [
            'sync_inventory' => true,
            'sync_orders' => true,
            'sync_pricing' => false,
            'inventory_buffer' => 2,
            'order_lookback_hours' => 24,
            'sync_frequency_minutes' => 15,
            'low_stock_threshold' => 5,
            'notify_on_out_of_stock' => true,
        ];
    }

    public function getConfigSchema(): array
    {
        return [
            'sync_inventory' => [
                'type' => 'boolean',
                'label' => 'Sync Inventory',
                'description' => 'Automatically sync inventory levels to all channels',
                'default' => true,
            ],
            'sync_orders' => [
                'type' => 'boolean',
                'label' => 'Sync Orders',
                'description' => 'Import orders from all connected marketplaces',
                'default' => true,
            ],
            'sync_pricing' => [
                'type' => 'boolean',
                'label' => 'Sync Pricing',
                'description' => 'Keep prices consistent across channels',
                'default' => false,
            ],
            'inventory_buffer' => [
                'type' => 'number',
                'label' => 'Inventory Buffer',
                'description' => 'Reserve this many units to prevent overselling',
                'default' => 2,
            ],
            'order_lookback_hours' => [
                'type' => 'number',
                'label' => 'Order Lookback (Hours)',
                'description' => 'How far back to look for new orders',
                'default' => 24,
            ],
            'low_stock_threshold' => [
                'type' => 'number',
                'label' => 'Low Stock Threshold',
                'description' => 'Alert when inventory falls below this level',
                'default' => 5,
            ],
            'notify_on_out_of_stock' => [
                'type' => 'boolean',
                'label' => 'Out of Stock Notifications',
                'description' => 'Send notifications when items go out of stock',
                'default' => true,
            ],
        ];
    }

    public function run(AgentRun $run, StoreAgent $storeAgent): AgentRunResult
    {
        $config = $storeAgent->getMergedConfig();
        $storeId = $storeAgent->store_id;

        $results = [
            'inventory_synced' => 0,
            'orders_imported' => 0,
            'price_updates' => 0,
            'low_stock_alerts' => 0,
            'out_of_stock' => 0,
            'errors' => [],
            'by_platform' => [],
        ];

        $actionsCreated = 0;

        // Get all active marketplaces for this store
        $marketplaces = StoreMarketplace::where('store_id', $storeId)
            ->where('status', 'active')
            ->get();

        if ($marketplaces->isEmpty()) {
            return AgentRunResult::success([
                'message' => 'No active marketplace connections found',
            ], 0);
        }

        foreach ($marketplaces as $marketplace) {
            $platformResults = [
                'inventory_synced' => 0,
                'orders_imported' => 0,
                'errors' => [],
            ];

            try {
                // Sync inventory if enabled
                if ($config['sync_inventory'] ?? true) {
                    $inventoryResult = $this->syncInventory($run, $marketplace, $config);
                    $platformResults['inventory_synced'] = $inventoryResult['synced'];
                    $results['inventory_synced'] += $inventoryResult['synced'];
                    $results['low_stock_alerts'] += $inventoryResult['low_stock'];
                    $results['out_of_stock'] += $inventoryResult['out_of_stock'];
                    $actionsCreated += $inventoryResult['actions'];
                }

                // Import orders if enabled
                if ($config['sync_orders'] ?? true) {
                    $orderResult = $this->syncOrders($run, $marketplace, $storeId, $config);
                    $platformResults['orders_imported'] = $orderResult['imported'];
                    $results['orders_imported'] += $orderResult['imported'];
                    $actionsCreated += $orderResult['actions'];
                }

                // Sync pricing if enabled
                if ($config['sync_pricing'] ?? false) {
                    $pricingResult = $this->syncPricing($run, $marketplace);
                    $results['price_updates'] += $pricingResult['updated'];
                    $actionsCreated += $pricingResult['actions'];
                }
            } catch (\Throwable $e) {
                $platformResults['errors'][] = $e->getMessage();
                $results['errors'][] = "{$marketplace->platform->label()}: {$e->getMessage()}";
            }

            $results['by_platform'][$marketplace->platform->value] = $platformResults;
        }

        return AgentRunResult::success($results, $actionsCreated);
    }

    /**
     * Sync inventory levels to a marketplace.
     *
     * @return array{synced: int, low_stock: int, out_of_stock: int, actions: int}
     */
    protected function syncInventory(AgentRun $run, StoreMarketplace $marketplace, array $config): array
    {
        $buffer = $config['inventory_buffer'] ?? 2;
        $lowStockThreshold = $config['low_stock_threshold'] ?? 5;
        $notifyOutOfStock = $config['notify_on_out_of_stock'] ?? true;

        $synced = 0;
        $lowStock = 0;
        $outOfStock = 0;
        $actions = 0;

        // Get all active listings for this marketplace
        $listings = PlatformListing::where('store_marketplace_id', $marketplace->id)
            ->whereIn('status', ['active', 'pending'])
            ->with('product')
            ->get();

        $inventoryUpdates = [];

        foreach ($listings as $listing) {
            $product = $listing->product;

            if (! $product) {
                continue;
            }

            // Calculate available quantity with buffer
            $availableQty = max(0, $product->quantity - $buffer);

            // Check if update is needed
            if ($listing->platform_quantity !== $availableQty) {
                $inventoryUpdates[] = [
                    'listing' => $listing,
                    'product' => $product,
                    'old_quantity' => $listing->platform_quantity,
                    'new_quantity' => $availableQty,
                ];

                // Track low stock and out of stock
                if ($availableQty === 0) {
                    $outOfStock++;

                    if ($notifyOutOfStock) {
                        AgentAction::create([
                            'agent_run_id' => $run->id,
                            'store_id' => $marketplace->store_id,
                            'action_type' => 'send_notification',
                            'actionable_type' => Product::class,
                            'actionable_id' => $product->id,
                            'status' => 'pending',
                            'requires_approval' => false,
                            'payload' => [
                                'type' => 'out_of_stock',
                                'platform' => $marketplace->platform->value,
                                'product_title' => $product->title,
                                'message' => "Product '{$product->title}' is now out of stock on {$marketplace->platform->label()}",
                            ],
                        ]);
                        $actions++;
                    }
                } elseif ($availableQty <= $lowStockThreshold) {
                    $lowStock++;
                }
            }
        }

        // Create sync action if there are updates
        if (! empty($inventoryUpdates)) {
            AgentAction::create([
                'agent_run_id' => $run->id,
                'store_id' => $marketplace->store_id,
                'action_type' => 'sync_inventory',
                'actionable_type' => StoreMarketplace::class,
                'actionable_id' => $marketplace->id,
                'status' => 'pending',
                'requires_approval' => false,
                'payload' => [
                    'platform' => $marketplace->platform->value,
                    'updates' => array_map(fn ($u) => [
                        'listing_id' => $u['listing']->id,
                        'product_id' => $u['product']->id,
                        'sku' => $u['product']->sku,
                        'external_id' => $u['listing']->external_listing_id,
                        'old_quantity' => $u['old_quantity'],
                        'new_quantity' => $u['new_quantity'],
                    ], $inventoryUpdates),
                ],
            ]);
            $actions++;
            $synced = count($inventoryUpdates);
        }

        return [
            'synced' => $synced,
            'low_stock' => $lowStock,
            'out_of_stock' => $outOfStock,
            'actions' => $actions,
        ];
    }

    /**
     * Import orders from a marketplace.
     *
     * @return array{imported: int, actions: int}
     */
    protected function syncOrders(AgentRun $run, StoreMarketplace $marketplace, int $storeId, array $config): array
    {
        $lookbackHours = $config['order_lookback_hours'] ?? 24;
        $since = now()->subHours($lookbackHours);

        $imported = 0;
        $actions = 0;

        try {
            $connector = $this->connectorManager->getConnectorForMarketplace($marketplace);
            $orders = $connector->getOrders($since);

            foreach ($orders as $order) {
                // Check if order already exists
                $existingOrder = $marketplace->platformOrders()
                    ->where('external_order_id', $order->externalId)
                    ->exists();

                if (! $existingOrder) {
                    AgentAction::create([
                        'agent_run_id' => $run->id,
                        'store_id' => $storeId,
                        'action_type' => 'sync_order',
                        'actionable_type' => StoreMarketplace::class,
                        'actionable_id' => $marketplace->id,
                        'status' => 'pending',
                        'requires_approval' => false,
                        'payload' => [
                            'platform' => $marketplace->platform->value,
                            'order_data' => [
                                'external_id' => $order->externalId,
                                'order_number' => $order->orderNumber,
                                'status' => $order->status,
                                'fulfillment_status' => $order->fulfillmentStatus,
                                'payment_status' => $order->paymentStatus,
                                'total' => $order->total,
                                'subtotal' => $order->subtotal,
                                'shipping_cost' => $order->shippingCost,
                                'tax' => $order->tax,
                                'discount' => $order->discount,
                                'currency' => $order->currency,
                                'customer' => $order->customer,
                                'shipping_address' => $order->shippingAddress,
                                'billing_address' => $order->billingAddress,
                                'line_items' => $order->lineItems,
                                'ordered_at' => $order->orderedAt?->toIso8601String(),
                                'metadata' => $order->metadata,
                            ],
                        ],
                    ]);
                    $actions++;
                    $imported++;
                }
            }
        } catch (\Throwable) {
            // Log but don't fail the entire sync
        }

        return [
            'imported' => $imported,
            'actions' => $actions,
        ];
    }

    /**
     * Sync pricing across channels.
     *
     * @return array{updated: int, actions: int}
     */
    protected function syncPricing(AgentRun $run, StoreMarketplace $marketplace): array
    {
        $updated = 0;
        $actions = 0;

        // Get listings with price differences
        $listings = PlatformListing::where('store_marketplace_id', $marketplace->id)
            ->whereIn('status', ['active', 'pending'])
            ->with('product')
            ->get();

        $priceUpdates = [];

        foreach ($listings as $listing) {
            $product = $listing->product;

            if (! $product) {
                continue;
            }

            // Check if price differs
            if (abs($listing->platform_price - $product->price) > 0.01) {
                $priceUpdates[] = [
                    'listing_id' => $listing->id,
                    'product_id' => $product->id,
                    'external_id' => $listing->external_listing_id,
                    'old_price' => $listing->platform_price,
                    'new_price' => $product->price,
                ];
            }
        }

        if (! empty($priceUpdates)) {
            AgentAction::create([
                'agent_run_id' => $run->id,
                'store_id' => $marketplace->store_id,
                'action_type' => 'sync_pricing',
                'actionable_type' => StoreMarketplace::class,
                'actionable_id' => $marketplace->id,
                'status' => 'pending',
                'requires_approval' => true, // Price changes should require approval
                'payload' => [
                    'platform' => $marketplace->platform->value,
                    'updates' => $priceUpdates,
                ],
            ]);
            $actions++;
            $updated = count($priceUpdates);
        }

        return [
            'updated' => $updated,
            'actions' => $actions,
        ];
    }

    public function canRun(StoreAgent $storeAgent): bool
    {
        if (! $storeAgent->canRun()) {
            return false;
        }

        // Check if store has any marketplace connections
        return StoreMarketplace::where('store_id', $storeAgent->store_id)
            ->where('status', 'active')
            ->exists();
    }

    public function getSubscribedEvents(): array
    {
        return [
            'product.inventory_updated',
            'product.price_updated',
            'order.created',
            'order.fulfilled',
            'marketplace.connected',
        ];
    }

    public function handleEvent(string $event, array $payload, StoreAgent $storeAgent): void
    {
        // Handle immediate inventory sync on stock changes
        if ($event === 'product.inventory_updated') {
            // Could trigger immediate sync for the specific product
        }
    }
}
