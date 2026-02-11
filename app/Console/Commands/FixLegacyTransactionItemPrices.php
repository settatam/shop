<?php

namespace App\Console\Commands;

use App\Models\TransactionItem;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FixLegacyTransactionItemPrices extends Command
{
    protected $signature = 'fix:legacy-transaction-item-prices
                            {legacy_store_id : The legacy store ID to fix}
                            {--dry-run : Run without making any changes}';

    protected $description = 'Fix transaction item prices by re-importing price and buy_price from legacy database';

    public function handle(): int
    {
        $legacyStoreId = (int) $this->argument('legacy_store_id');
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('Running in DRY RUN mode - no changes will be made');
        }

        $this->info("Fixing transaction item prices for legacy store ID: {$legacyStoreId}");

        // Test legacy database connection
        try {
            DB::connection('legacy')->getPdo();
            $this->info('Connected to legacy database successfully.');
        } catch (\Exception $e) {
            $this->error('Could not connect to legacy database: '.$e->getMessage());

            return self::FAILURE;
        }

        // Get all transaction items from legacy for this store
        $legacyItems = DB::connection('legacy')
            ->table('transaction_items as ti')
            ->join('transactions as t', 'ti.transaction_id', '=', 't.id')
            ->where('t.store_id', $legacyStoreId)
            ->select('ti.id', 'ti.price', 'ti.buy_price')
            ->get();

        $this->info("Found {$legacyItems->count()} legacy transaction items");

        $bar = $this->output->createProgressBar($legacyItems->count());
        $bar->start();

        $updated = 0;
        $skipped = 0;
        $notFound = 0;

        foreach ($legacyItems as $legacyItem) {
            // Check if item exists in new database
            $newItem = TransactionItem::find($legacyItem->id);

            if (! $newItem) {
                $notFound++;
                $bar->advance();

                continue;
            }

            // Check if values are different
            $legacyPrice = $legacyItem->price ?? 0;
            $legacyBuyPrice = $legacyItem->buy_price ?? 0;

            if ((float) $newItem->price === (float) $legacyPrice && (float) $newItem->buy_price === (float) $legacyBuyPrice) {
                $skipped++;
                $bar->advance();

                continue;
            }

            if (! $dryRun) {
                $newItem->update([
                    'price' => $legacyPrice,
                    'buy_price' => $legacyBuyPrice,
                ]);
            }

            $updated++;
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info('Fix complete!');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Updated', $updated],
                ['Skipped (already correct)', $skipped],
                ['Not found in new DB', $notFound],
            ]
        );

        return self::SUCCESS;
    }
}
