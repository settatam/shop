<?php

namespace App\Console\Commands;

use App\Models\Transaction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillTransactionTimestamps extends Command
{
    protected $signature = 'transactions:backfill-timestamps
                            {--store-id= : Only backfill for a specific store}
                            {--dry-run : Show what would be updated without making changes}';

    protected $description = 'Backfill payment_processed_at timestamp for transactions with payment_processed status';

    public function handle(): int
    {
        $storeId = $this->option('store-id');
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->warn('Running in DRY RUN mode - no changes will be made');
        }

        $query = Transaction::where('status', 'payment_processed')
            ->whereNull('payment_processed_at');

        if ($storeId) {
            $query->where('store_id', $storeId);
            $this->info("Filtering by store ID: {$storeId}");
        }

        $count = $query->count();

        if ($count === 0) {
            $this->info('No transactions need to be updated.');

            return self::SUCCESS;
        }

        $this->info("Found {$count} transactions with payment_processed status but no payment_processed_at timestamp.");

        if ($dryRun) {
            $this->info("Would update {$count} transactions.");

            return self::SUCCESS;
        }

        if (! $this->confirm("Do you want to update {$count} transactions?")) {
            $this->info('Operation cancelled.');

            return self::SUCCESS;
        }

        $updated = Transaction::where('status', 'payment_processed')
            ->whereNull('payment_processed_at')
            ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
            ->update(['payment_processed_at' => DB::raw('COALESCE(updated_at, created_at)')]);

        $this->info("Successfully updated {$updated} transactions with payment_processed_at timestamp.");

        return self::SUCCESS;
    }
}
