<?php

namespace App\Console\Commands;

use App\Enums\Platform;
use App\Models\PlatformListing;
use App\Models\Product;
use App\Models\SalesChannel;
use App\Models\Store;
use App\Models\StoreMarketplace;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MigrateLegacyMarketplaces extends Command
{
    protected $signature = 'migrate:legacy-marketplaces
                            {--store-id=63 : Legacy store ID to migrate}
                            {--new-store-id= : New store ID (if different from legacy)}
                            {--dry-run : Show what would be migrated without making changes}
                            {--fresh : Delete existing marketplaces and sales channels first}
                            {--remove-all : Remove all marketplaces, sales channels, and listings then exit}
                            {--create-listings : Create platform listings for all products after migration}';

    protected $description = 'Migrate store marketplaces from the legacy database and create sales channels';

    protected ?Store $newStore = null;

    protected array $marketplaceMap = [];

    protected array $salesChannelMap = [];

    public function handle(): int
    {
        $legacyStoreId = (int) $this->option('store-id');
        $newStoreId = $this->option('new-store-id') ? (int) $this->option('new-store-id') : null;
        $isDryRun = $this->option('dry-run');

        // Get legacy store info
        $legacyStore = DB::connection('legacy')
            ->table('stores')
            ->where('id', $legacyStoreId)
            ->first();

        if (! $legacyStore) {
            $this->error("Legacy store with ID {$legacyStoreId} not found");

            return 1;
        }

        // Find the new store
        if ($newStoreId) {
            $this->newStore = Store::find($newStoreId);
        } else {
            $this->newStore = Store::where('name', $legacyStore->name)->first();
        }

        if (! $this->newStore) {
            $this->error('New store not found. Run migrate:legacy first to create the store.');

            return 1;
        }

        // Handle --remove-all option
        if ($this->option('remove-all')) {
            return $this->removeAll();
        }

        $this->info("Starting marketplace migration from legacy store ID: {$legacyStoreId}");

        if ($isDryRun) {
            $this->warn('DRY RUN MODE - No changes will be made');
        }

        $this->info("Migrating marketplaces to store: {$this->newStore->name} (ID: {$this->newStore->id})");

        if ($this->option('fresh') && ! $isDryRun) {
            if ($this->confirm('This will delete existing marketplaces and sales channels. Continue?')) {
                $this->cleanupExisting();
            }
        }

        try {
            DB::beginTransaction();

            // Migrate marketplaces and create sales channels
            $this->migrateMarketplaces($legacyStoreId, $isDryRun);

            if ($isDryRun) {
                DB::rollBack();
                $this->info('Dry run complete - no changes made');
            } else {
                DB::commit();
                $this->info('Marketplace migration complete!');

                // Save mapping file
                $this->saveMappingFile($legacyStoreId);

                // Create listings for all products if requested
                if ($this->option('create-listings')) {
                    $this->createListingsForAllProducts();
                }
            }

            $this->displaySummary();

            return 0;
        } catch (\Exception $e) {
            DB::rollBack();
            $this->error("Migration failed: {$e->getMessage()}");
            $this->error($e->getTraceAsString());

            return 1;
        }
    }

    /**
     * Remove all marketplaces, sales channels, and listings for the store.
     */
    protected function removeAll(): int
    {
        $this->warn("This will remove ALL marketplaces, sales channels, and platform listings for store: {$this->newStore->name}");

        if (! $this->confirm('Are you sure you want to continue?')) {
            $this->info('Operation cancelled.');

            return 0;
        }

        $this->info('Removing all data...');

        // Delete platform listings first (they reference sales channels)
        $listingsCount = PlatformListing::whereHas('salesChannel', function ($q) {
            $q->where('store_id', $this->newStore->id);
        })->count();
        PlatformListing::whereHas('salesChannel', function ($q) {
            $q->where('store_id', $this->newStore->id);
        })->forceDelete();
        $this->line("  Deleted {$listingsCount} platform listings");

        // Delete sales channels
        $channelsCount = SalesChannel::where('store_id', $this->newStore->id)->count();
        SalesChannel::where('store_id', $this->newStore->id)->forceDelete();
        $this->line("  Deleted {$channelsCount} sales channels");

        // Delete marketplaces
        $marketplacesCount = StoreMarketplace::where('store_id', $this->newStore->id)->count();
        StoreMarketplace::where('store_id', $this->newStore->id)->forceDelete();
        $this->line("  Deleted {$marketplacesCount} store marketplaces");

        $this->info('All marketplace data removed successfully.');

        return 0;
    }

    protected function migrateMarketplaces(int $legacyStoreId, bool $isDryRun): void
    {
        $this->info('Migrating marketplaces (filtering: is_app=false, connected_successfully=true)...');

        $legacyMarketplaces = DB::connection('legacy')
            ->table('store_market_places')
            ->where('store_id', $legacyStoreId)
            ->where('is_app', false)
            ->where('connected_successfully', true)
            ->whereNull('deleted_at')
            ->get();

        $this->line("  Found {$legacyMarketplaces->count()} marketplaces matching criteria");

        $marketplaceCount = 0;
        $channelCount = 0;

        foreach ($legacyMarketplaces as $legacy) {
            $platform = $this->mapPlatform($legacy->marketplace);
            $isLocal = $this->isLocalMarketplace($legacy->marketplace);

            // Determine a good name
            $name = $legacy->name
                ?? $legacy->external_marketplace_name
                ?? ucfirst($legacy->marketplace);

            if ($isDryRun) {
                $this->line("  Would create marketplace: {$name} ({$legacy->marketplace}) - Platform: ".($platform?->value ?? 'local'));
                $marketplaceCount++;

                continue;
            }

            // For platform marketplaces (Shopify, eBay, etc.), create StoreMarketplace
            $storeMarketplaceId = null;
            if ($platform !== null) {
                $storeMarketplace = StoreMarketplace::updateOrCreate(
                    [
                        'store_id' => $this->newStore->id,
                        'platform' => $platform,
                        'shop_domain' => $this->extractDomain($legacy->url ?? $legacy->api_url_prefix),
                    ],
                    [
                        'name' => $name,
                        'external_store_id' => $legacy->external_marketplace_id,
                        'access_token' => $legacy->access_token,
                        'refresh_token' => $legacy->refresh_token,
                        'token_expires_at' => $legacy->access_token_expires_on,
                        // Note: credentials skipped - important values are in access_token/refresh_token
                        'settings' => [
                            'legacy_id' => $legacy->id,
                            'markup' => $legacy->markup,
                            'shipstation_store_id' => $legacy->shipstation_store_id,
                            'legacy_client_id' => $legacy->client_id,
                            'legacy_api_token' => $legacy->api_token,
                        ],
                        'status' => $legacy->connected_successfully ? 'active' : 'pending',
                        'last_sync_at' => $legacy->updated_at,
                    ]
                );

                $storeMarketplaceId = $storeMarketplace->id;
                $this->marketplaceMap[$legacy->id] = $storeMarketplaceId;
                $marketplaceCount++;

                $this->line("  Created marketplace: {$name} (ID: {$storeMarketplace->id})");
            }

            // Create a sales channel for this marketplace
            $channelCode = $this->generateChannelCode($legacy->marketplace, $name);
            $channelType = $platform?->value ?? 'pos';

            $salesChannel = SalesChannel::updateOrCreate(
                [
                    'store_id' => $this->newStore->id,
                    'code' => $channelCode,
                ],
                [
                    'name' => $name,
                    'type' => $channelType,
                    'is_local' => $isLocal,
                    'store_marketplace_id' => $storeMarketplaceId,
                    'is_active' => true,
                    'is_default' => $isLocal && strtolower($legacy->marketplace) === 'pos',
                    'settings' => [
                        'legacy_marketplace_id' => $legacy->id,
                    ],
                ]
            );

            $this->salesChannelMap[$legacy->id] = $salesChannel->id;
            $channelCount++;

            $this->line("  Created sales channel: {$salesChannel->name} (code: {$salesChannel->code})");
        }

        $this->newLine();
        $this->info("Created {$marketplaceCount} marketplaces and {$channelCount} sales channels");
    }

    protected function mapPlatform(?string $marketplace): ?Platform
    {
        if (! $marketplace) {
            return null;
        }

        return match (strtolower($marketplace)) {
            'shopify' => Platform::Shopify,
            'ebay' => Platform::Ebay,
            'amazon' => Platform::Amazon,
            'etsy' => Platform::Etsy,
            'walmart' => Platform::Walmart,
            'woocommerce' => Platform::WooCommerce,
            default => null, // POS, Square, Dejavoo, Rapnet, Shipstation are not platform marketplaces
        };
    }

    protected function isLocalMarketplace(?string $marketplace): bool
    {
        if (! $marketplace) {
            return true;
        }

        // These are considered local/in-store sales channels
        return in_array(strtolower($marketplace), [
            'pos',
            'square',
            'dejavoo',
        ]);
    }

    protected function extractDomain(?string $url): ?string
    {
        if (! $url) {
            return null;
        }

        $domain = preg_replace('#^https?://#', '', $url);
        $domain = rtrim($domain, '/');

        return $domain ?: null;
    }

    protected function buildCredentials(object $legacy): ?array
    {
        $credentials = [];

        if ($legacy->client_id) {
            $credentials['client_id'] = $legacy->client_id;
        }
        if ($legacy->secret) {
            $credentials['secret'] = $legacy->secret;
        }
        if ($legacy->api_token) {
            $credentials['api_token'] = $legacy->api_token;
        }

        return ! empty($credentials) ? $credentials : null;
    }

    protected function generateChannelCode(string $marketplace, string $name): string
    {
        // Use marketplace type for common ones, otherwise use name
        $base = match (strtolower($marketplace)) {
            'pos' => 'in_store',
            'shopify' => 'shopify',
            'ebay' => 'ebay',
            'amazon' => 'amazon',
            'etsy' => 'etsy',
            'walmart' => 'walmart',
            'woocommerce' => 'woocommerce',
            'square' => 'square',
            default => strtolower(preg_replace('/[^a-zA-Z0-9]/', '_', $name)),
        };

        // Check if code already exists for this store
        $code = $base;
        $counter = 1;
        while (SalesChannel::where('store_id', $this->newStore->id)->where('code', $code)->exists()) {
            $code = $base.'_'.$counter;
            $counter++;
        }

        return $code;
    }

    protected function cleanupExisting(): void
    {
        $this->warn('Cleaning up existing marketplaces and sales channels...');

        // Delete sales channels first (they reference marketplaces)
        SalesChannel::where('store_id', $this->newStore->id)->forceDelete();

        // Delete marketplaces
        StoreMarketplace::where('store_id', $this->newStore->id)->forceDelete();

        $this->line('  Cleanup complete');
    }

    protected function saveMappingFile(int $legacyStoreId): void
    {
        $basePath = storage_path('app/migration_maps');
        if (! is_dir($basePath)) {
            mkdir($basePath, 0755, true);
        }

        // Save marketplace map (legacy marketplace ID -> new store_marketplace ID)
        $marketplaceMapFile = "{$basePath}/marketplace_map_{$legacyStoreId}.json";
        file_put_contents($marketplaceMapFile, json_encode($this->marketplaceMap, JSON_PRETTY_PRINT));
        $this->line("  Marketplace map saved to: {$marketplaceMapFile}");

        // Save sales channel map (legacy marketplace ID -> new sales_channel ID)
        $salesChannelMapFile = "{$basePath}/sales_channel_map_{$legacyStoreId}.json";
        file_put_contents($salesChannelMapFile, json_encode($this->salesChannelMap, JSON_PRETTY_PRINT));
        $this->line("  Sales channel map saved to: {$salesChannelMapFile}");
    }

    protected function displaySummary(): void
    {
        $this->newLine();
        $this->info('=== Marketplace Migration Summary ===');
        $this->line('Store: '.$this->newStore->name.' (ID: '.$this->newStore->id.')');

        $marketplaceCount = StoreMarketplace::where('store_id', $this->newStore->id)->count();
        $channelCount = SalesChannel::where('store_id', $this->newStore->id)->count();

        $this->line("Total marketplaces: {$marketplaceCount}");
        $this->line("Total sales channels: {$channelCount}");

        $this->newLine();
        $this->info('Sales Channels:');
        $channels = SalesChannel::where('store_id', $this->newStore->id)->get();
        foreach ($channels as $channel) {
            $type = $channel->is_local ? 'Local' : 'Online';
            $default = $channel->is_default ? ' (Default)' : '';
            $listingCount = PlatformListing::where('sales_channel_id', $channel->id)->count();
            $this->line("  - {$channel->name} [{$channel->code}] - {$type}{$default} ({$listingCount} listings)");
        }
    }

    /**
     * Create platform listings for all products in the store.
     */
    protected function createListingsForAllProducts(): void
    {
        $this->newLine();
        $this->info('Creating platform listings for all products...');

        $channels = SalesChannel::where('store_id', $this->newStore->id)
            ->where('is_active', true)
            ->get();

        if ($channels->isEmpty()) {
            $this->warn('No active sales channels found. Skipping listing creation.');

            return;
        }

        $products = Product::where('store_id', $this->newStore->id)->get();
        $this->line("  Found {$products->count()} products");

        $listingsCreated = 0;
        $bar = $this->output->createProgressBar($products->count() * $channels->count());
        $bar->start();

        foreach ($products as $product) {
            foreach ($channels as $channel) {
                // Determine status: local channels get 'listed', external get 'not_listed'
                $status = $channel->is_local
                    ? PlatformListing::STATUS_LISTED
                    : PlatformListing::STATUS_NOT_LISTED;

                // Create or update the listing
                $listing = PlatformListing::firstOrCreate(
                    [
                        'product_id' => $product->id,
                        'sales_channel_id' => $channel->id,
                    ],
                    [
                        'store_marketplace_id' => $channel->store_marketplace_id,
                        'status' => $status,
                        'platform_price' => $product->variants()->first()?->price ?? 0,
                        'platform_quantity' => $product->total_quantity ?? 0,
                    ]
                );

                if ($listing->wasRecentlyCreated) {
                    $listingsCreated++;
                }

                $bar->advance();
            }
        }

        $bar->finish();
        $this->newLine();
        $this->info("Created {$listingsCreated} new platform listings");
    }
}
