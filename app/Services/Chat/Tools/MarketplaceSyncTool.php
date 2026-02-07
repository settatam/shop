<?php

namespace App\Services\Chat\Tools;

use App\Models\PlatformListing;
use App\Models\PlatformOrder;
use App\Models\StoreMarketplace;
use App\Services\Marketplace\PlatformConnectorManager;

class MarketplaceSyncTool implements ChatToolInterface
{
    public function __construct(
        protected PlatformConnectorManager $connectorManager
    ) {}

    public function name(): string
    {
        return 'marketplace_sync';
    }

    public function definition(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'Check marketplace sync status, trigger syncs, or view recent sync activity. Use this when users ask about marketplace connections, sync status, or want to sync products/orders with Amazon, Walmart, Shopify, etc.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'action' => [
                        'type' => 'string',
                        'enum' => ['status', 'sync_products', 'sync_orders', 'sync_inventory', 'test_connection'],
                        'description' => 'The action to perform: status (check sync status), sync_products (import products), sync_orders (import orders), sync_inventory (push inventory), test_connection (verify connection)',
                    ],
                    'platform' => [
                        'type' => 'string',
                        'enum' => ['all', 'amazon', 'walmart', 'shopify', 'bigcommerce', 'ebay'],
                        'description' => 'Which platform to check/sync. Use "all" for all connected platforms.',
                    ],
                ],
                'required' => ['action'],
            ],
        ];
    }

    public function execute(array $params, int $storeId): array
    {
        $action = $params['action'] ?? 'status';
        $platform = $params['platform'] ?? 'all';

        return match ($action) {
            'status' => $this->getSyncStatus($storeId, $platform),
            'sync_products' => $this->syncProducts($storeId, $platform),
            'sync_orders' => $this->syncOrders($storeId, $platform),
            'sync_inventory' => $this->syncInventory($storeId, $platform),
            'test_connection' => $this->testConnections($storeId, $platform),
            default => ['error' => 'Unknown action: '.$action],
        };
    }

    protected function getSyncStatus(int $storeId, string $platform): array
    {
        $query = StoreMarketplace::where('store_id', $storeId);

        if ($platform !== 'all') {
            $query->where('platform', $platform);
        }

        $marketplaces = $query->get();

        if ($marketplaces->isEmpty()) {
            return [
                'message' => 'No marketplace connections found.',
                'connected_platforms' => [],
            ];
        }

        $statuses = [];

        foreach ($marketplaces as $marketplace) {
            $listingCount = PlatformListing::where('store_marketplace_id', $marketplace->id)->count();
            $activeListings = PlatformListing::where('store_marketplace_id', $marketplace->id)
                ->where('status', 'active')
                ->count();
            $recentOrders = PlatformOrder::where('store_marketplace_id', $marketplace->id)
                ->where('ordered_at', '>=', now()->subDays(7))
                ->count();

            $statuses[] = [
                'platform' => $marketplace->platform->label(),
                'platform_slug' => $marketplace->platform->value,
                'status' => $marketplace->status,
                'last_synced_at' => $marketplace->last_synced_at?->diffForHumans() ?? 'Never',
                'total_listings' => $listingCount,
                'active_listings' => $activeListings,
                'orders_last_7_days' => $recentOrders,
            ];
        }

        return [
            'message' => 'Marketplace sync status retrieved.',
            'platforms' => $statuses,
        ];
    }

    protected function syncProducts(int $storeId, string $platform): array
    {
        $marketplaces = $this->getMarketplaces($storeId, $platform);

        if ($marketplaces->isEmpty()) {
            return ['error' => 'No active marketplace connections found for sync.'];
        }

        $results = [];

        foreach ($marketplaces as $marketplace) {
            try {
                $syncResult = $this->connectorManager->syncProducts($marketplace);

                $results[] = [
                    'platform' => $marketplace->platform->label(),
                    'synced' => $syncResult['synced'],
                    'errors' => $syncResult['errors'],
                ];
            } catch (\Throwable $e) {
                $results[] = [
                    'platform' => $marketplace->platform->label(),
                    'error' => $e->getMessage(),
                ];
            }
        }

        return [
            'message' => 'Product sync completed.',
            'results' => $results,
        ];
    }

    protected function syncOrders(int $storeId, string $platform): array
    {
        $marketplaces = $this->getMarketplaces($storeId, $platform);

        if ($marketplaces->isEmpty()) {
            return ['error' => 'No active marketplace connections found for sync.'];
        }

        $results = [];
        $since = now()->subDays(7);

        foreach ($marketplaces as $marketplace) {
            try {
                $syncResult = $this->connectorManager->syncOrders($marketplace, $since);

                $results[] = [
                    'platform' => $marketplace->platform->label(),
                    'synced' => $syncResult['synced'],
                    'errors' => $syncResult['errors'],
                ];
            } catch (\Throwable $e) {
                $results[] = [
                    'platform' => $marketplace->platform->label(),
                    'error' => $e->getMessage(),
                ];
            }
        }

        return [
            'message' => 'Order sync completed.',
            'results' => $results,
        ];
    }

    protected function syncInventory(int $storeId, string $platform): array
    {
        $marketplaces = $this->getMarketplaces($storeId, $platform);

        if ($marketplaces->isEmpty()) {
            return ['error' => 'No active marketplace connections found for sync.'];
        }

        $results = [];

        foreach ($marketplaces as $marketplace) {
            $listings = PlatformListing::where('store_marketplace_id', $marketplace->id)
                ->whereIn('status', ['active', 'pending'])
                ->with('product')
                ->get();

            $updates = [];

            foreach ($listings as $listing) {
                if ($listing->product && $listing->platform_quantity !== $listing->product->quantity) {
                    $updates[] = [
                        'sku' => $listing->product->sku,
                        'old_qty' => $listing->platform_quantity,
                        'new_qty' => $listing->product->quantity,
                    ];
                }
            }

            $results[] = [
                'platform' => $marketplace->platform->label(),
                'listings_checked' => $listings->count(),
                'updates_needed' => count($updates),
            ];
        }

        return [
            'message' => 'Inventory sync check completed. Use the Channel Sync Agent to perform the actual sync.',
            'results' => $results,
        ];
    }

    protected function testConnections(int $storeId, string $platform): array
    {
        $marketplaces = $this->getMarketplaces($storeId, $platform);

        if ($marketplaces->isEmpty()) {
            return ['error' => 'No marketplace connections found.'];
        }

        $results = [];

        foreach ($marketplaces as $marketplace) {
            $connected = $this->connectorManager->testConnection($marketplace);

            $results[] = [
                'platform' => $marketplace->platform->label(),
                'connected' => $connected,
                'status' => $connected ? 'OK' : 'Failed',
            ];
        }

        return [
            'message' => 'Connection test completed.',
            'results' => $results,
        ];
    }

    protected function getMarketplaces(int $storeId, string $platform)
    {
        $query = StoreMarketplace::where('store_id', $storeId)
            ->where('status', 'active');

        if ($platform !== 'all') {
            $query->where('platform', $platform);
        }

        return $query->get();
    }
}
