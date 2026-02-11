<?php

namespace App\Console\Commands;

use App\Models\Store;
use App\Models\StoreIntegration;
use App\Services\Rapnet\RapnetPriceService;
use Illuminate\Console\Command;

class SyncRapnetPrices extends Command
{
    protected $signature = 'sync:rapnet-prices
                            {--store-id= : Sync prices for a specific store}
                            {--update-products : Also update current_rap_price on all diamond products}
                            {--dry-run : Show what would be done without making changes}';

    protected $description = 'Sync diamond prices from Rapnet API and optionally update product rap prices';

    public function handle(RapnetPriceService $priceService): int
    {
        $storeId = $this->option('store-id') ? (int) $this->option('store-id') : null;
        $updateProducts = $this->option('update-products');
        $isDryRun = $this->option('dry-run');

        if ($isDryRun) {
            $this->warn('DRY RUN MODE - No changes will be made');
        }

        // Find stores with active Rapnet integration
        $query = StoreIntegration::where('provider', StoreIntegration::PROVIDER_RAPNET)
            ->where('status', StoreIntegration::STATUS_ACTIVE);

        if ($storeId) {
            $query->where('store_id', $storeId);
        }

        $integrations = $query->get();

        if ($integrations->isEmpty()) {
            $this->error('No active Rapnet integrations found.');

            return 1;
        }

        // We only need to sync prices once (they're global, not per-store)
        // but we use the first integration for authentication
        $integration = $integrations->first();

        $this->info('Syncing Rapnet prices...');

        if (! $isDryRun) {
            $counts = $priceService->syncPricesFromApi($integration);
            $this->info("Synced {$counts['round']} Round prices and {$counts['pear']} Pear/Fancy prices.");
        } else {
            $this->info('Would sync Round and Pear/Fancy prices from Rapnet API.');
        }

        // Update product prices if requested
        if ($updateProducts) {
            $this->newLine();
            $this->info('Updating product rap prices...');

            foreach ($integrations as $integration) {
                $store = Store::find($integration->store_id);
                if (! $store) {
                    continue;
                }

                $this->line("Processing store: {$store->name} (ID: {$store->id})");

                if (! $isDryRun) {
                    $results = $priceService->updateStoreProductPrices($store);
                    $this->line("  Updated: {$results['updated']}, Skipped: {$results['skipped']}, Errors: {$results['errors']}");
                } else {
                    $this->line('  Would update current_rap_price for all diamond products.');
                }
            }
        }

        $this->newLine();
        $this->info('Rapnet sync complete!');

        return 0;
    }
}
