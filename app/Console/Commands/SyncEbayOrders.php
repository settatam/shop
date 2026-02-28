<?php

namespace App\Console\Commands;

use App\Enums\Platform;
use App\Models\StoreMarketplace;
use App\Services\Platforms\Ebay\EbayService;
use Illuminate\Console\Command;

class SyncEbayOrders extends Command
{
    protected $signature = 'ebay:sync-orders {--store= : Sync a specific store ID}';

    protected $description = 'Poll eBay for recent orders across all active eBay connections';

    public function handle(EbayService $ebayService): int
    {
        $query = StoreMarketplace::query()
            ->where('platform', Platform::Ebay->value)
            ->where('status', 'active')
            ->where('connected_successfully', true);

        if ($storeId = $this->option('store')) {
            $query->where('store_id', $storeId);
        }

        $connections = $query->get();

        if ($connections->isEmpty()) {
            $this->info('No active eBay connections found.');

            return self::SUCCESS;
        }

        $this->info("Syncing orders for {$connections->count()} eBay connection(s)...");

        foreach ($connections as $connection) {
            $this->syncConnectionOrders($ebayService, $connection);
        }

        $this->info('eBay order sync complete.');

        return self::SUCCESS;
    }

    protected function syncConnectionOrders(EbayService $ebayService, StoreMarketplace $connection): void
    {
        $since = $connection->last_sync_at
            ? $connection->last_sync_at->toIso8601String()
            : now()->subDay()->toIso8601String();

        try {
            $orders = $ebayService->pullOrders($connection, $since);

            $this->info("  Store #{$connection->store_id}: imported {$orders->count()} order(s).");
        } catch (\Throwable $e) {
            $this->error("  Store #{$connection->store_id}: {$e->getMessage()}");
        }
    }
}
