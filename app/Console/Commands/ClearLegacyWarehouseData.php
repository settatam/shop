<?php

namespace App\Console\Commands;

use App\Models\TransactionWarehouse;
use Illuminate\Console\Command;

class ClearLegacyWarehouseData extends Command
{
    protected $signature = 'clear:legacy-warehouse
        {--store= : Comma-separated list of legacy store IDs to clear (defaults to all configured)}
        {--force : Skip confirmation prompt}';

    protected $description = 'Clear transaction warehouse data for specified legacy stores';

    public function handle(): int
    {
        if (! config('legacy-sync.enabled')) {
            $this->error('Legacy sync is disabled. Set LEGACY_SYNC_ENABLED=true to enable.');

            return self::FAILURE;
        }

        $storeMapping = TransactionWarehouse::getStoreMapping();

        if (empty($storeMapping)) {
            $this->error('No store mappings configured. Set LEGACY_STORE_MAPPING env variable.');

            return self::FAILURE;
        }

        $legacyStoreIds = $this->getLegacyStoreIds($storeMapping);

        if (empty($legacyStoreIds)) {
            $this->error('No valid store IDs to clear.');

            return self::FAILURE;
        }

        $this->info('Clearing legacy warehouse data for stores:');
        $this->table(
            ['Legacy Store ID', 'New Store ID'],
            collect($legacyStoreIds)->map(fn ($id) => [$id, $storeMapping[$id]])->toArray()
        );

        // Show counts
        $counts = [];
        foreach ($legacyStoreIds as $legacyStoreId) {
            $count = TransactionWarehouse::where('legacy_store_id', $legacyStoreId)->count();
            $counts[] = ['Legacy Store '.$legacyStoreId, $count];
        }

        $this->newLine();
        $this->info('Records to be deleted:');
        $this->table(['Store', 'Count'], $counts);

        if (! $this->option('force') && ! $this->confirm('Are you sure you want to clear this data?')) {
            $this->info('Operation cancelled.');

            return self::SUCCESS;
        }

        $totalDeleted = 0;

        foreach ($legacyStoreIds as $legacyStoreId) {
            $deleted = TransactionWarehouse::where('legacy_store_id', $legacyStoreId)->delete();
            $this->line("  Deleted {$deleted} records for legacy store {$legacyStoreId}");
            $totalDeleted += $deleted;
        }

        $this->newLine();
        $this->info("Total records deleted: {$totalDeleted}");

        return self::SUCCESS;
    }

    /**
     * @return array<int>
     */
    protected function getLegacyStoreIds(array $storeMapping): array
    {
        $storeOption = $this->option('store');

        if ($storeOption) {
            $requestedIds = array_map('intval', explode(',', $storeOption));

            return array_filter($requestedIds, fn ($id) => isset($storeMapping[$id]));
        }

        return array_keys($storeMapping);
    }
}
