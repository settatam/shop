<?php

namespace App\Console\Commands;

use App\Enums\Platform;
use App\Models\StoreMarketplace;
use App\Services\Platforms\Shopify\ShopifyService;
use Illuminate\Console\Command;

class SyncShopifyMetafieldDefinitions extends Command
{
    protected $signature = 'shopify:sync-metafield-definitions
                            {--store= : Specific store marketplace ID to sync}';

    protected $description = 'Sync metafield definitions from Shopify for connected stores';

    public function handle(ShopifyService $shopifyService): int
    {
        $query = StoreMarketplace::where('platform', Platform::Shopify)
            ->connected();

        if ($storeId = $this->option('store')) {
            $query->where('id', $storeId);
        }

        $marketplaces = $query->get();

        if ($marketplaces->isEmpty()) {
            $this->warn('No connected Shopify stores found.');

            return 0;
        }

        foreach ($marketplaces as $marketplace) {
            $this->info("Syncing metafield definitions for: {$marketplace->name} (ID: {$marketplace->id})");

            try {
                $count = $shopifyService->syncMetafieldDefinitions($marketplace);
                $this->info("  Synced {$count} metafield definitions.");
            } catch (\Throwable $e) {
                $this->error("  Failed: {$e->getMessage()}");
            }
        }

        return 0;
    }
}
