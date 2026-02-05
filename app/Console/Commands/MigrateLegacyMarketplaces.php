<?php

namespace App\Console\Commands;

use App\Enums\Platform;
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
                            {--fresh : Delete existing marketplaces and sales channels first}';

    protected $description = 'Migrate store marketplaces from the legacy database and create sales channels';

    protected ?Store $newStore = null;

    protected array $marketplaceMap = [];

    protected array $salesChannelMap = [];

    public function handle(): int
    {
        $legacyStoreId = (int) $this->option('store-id');
        $newStoreId = $this->option('new-store-id') ? (int) $this->option('new-store-id') : null;
        $isDryRun = $this->option('dry-run');

        $this->info("Starting marketplace migration from legacy store ID: {$legacyStoreId}");

        if ($isDryRun) {
            $this->warn('DRY RUN MODE - No changes will be made');
        }

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

    protected function migrateMarketplaces(int $legacyStoreId, bool $isDryRun): void
    {
        $this->info('Migrating marketplaces...');

        $legacyMarketplaces = DB::connection('legacy')
            ->table('store_market_places')
            ->where('store_id', $legacyStoreId)
            ->whereNull('deleted_at')
            ->get();

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
                    'is_default' => $isLocal && $legacy->marketplace === 'POS',
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
            $this->line("  - {$channel->name} [{$channel->code}] - {$type}{$default}");
        }
    }
}
