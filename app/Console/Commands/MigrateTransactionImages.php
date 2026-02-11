<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MigrateTransactionImages extends Command
{
    protected $signature = 'migrate:transaction-images
                            {store : The store ID to migrate images for}
                            {--dry-run : Show what would be migrated without actually migrating}
                            {--batch-size=1000 : Number of images to process per batch}';

    protected $description = 'Migrate transaction and transaction item images from legacy database';

    public function handle(): int
    {
        $storeId = (int) $this->argument('store');
        $dryRun = $this->option('dry-run');
        $batchSize = (int) $this->option('batch-size');

        // Verify store exists
        $store = DB::table('stores')->where('id', $storeId)->first();
        if (! $store) {
            $this->error("Store with ID {$storeId} not found");

            return Command::FAILURE;
        }

        $this->info("Migrating images for store: {$store->name} (ID: {$storeId})");

        if ($dryRun) {
            $this->info('DRY RUN - No data will be modified');
        }

        // Migrate Transaction images
        $this->migrateTransactionImages($storeId, $dryRun, $batchSize);

        // Migrate TransactionItem images
        $this->migrateTransactionItemImages($storeId, $dryRun, $batchSize);

        $this->newLine();
        $this->info('Migration completed!');

        return Command::SUCCESS;
    }

    protected function migrateTransactionImages(int $storeId, bool $dryRun, int $batchSize): void
    {
        $this->info('Migrating Transaction images...');

        // Get transaction IDs for this store
        $transactionIds = DB::table('transactions')
            ->where('store_id', $storeId)
            ->pluck('id')
            ->toArray();

        if (empty($transactionIds)) {
            $this->info('No transactions found for this store');

            return;
        }

        // Count total
        $total = DB::connection('legacy')
            ->table('images')
            ->where('imageable_type', 'App\\Models\\Transaction')
            ->whereIn('imageable_id', $transactionIds)
            ->whereNull('deleted_at')
            ->count();

        $this->info("Found {$total} Transaction images to migrate");

        if ($total === 0) {
            return;
        }

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $migrated = 0;
        $skipped = 0;

        DB::connection('legacy')
            ->table('images')
            ->where('imageable_type', 'App\\Models\\Transaction')
            ->whereIn('imageable_id', $transactionIds)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->chunk($batchSize, function ($legacyImages) use ($storeId, $dryRun, &$migrated, &$skipped, $bar) {
                $inserts = [];

                foreach ($legacyImages as $legacyImage) {
                    // Check if already migrated
                    $exists = DB::table('images')
                        ->where('imageable_type', 'App\\Models\\Transaction')
                        ->where('imageable_id', $legacyImage->imageable_id)
                        ->where('url', $legacyImage->url)
                        ->exists();

                    if ($exists) {
                        $skipped++;
                        $bar->advance();

                        continue;
                    }

                    $inserts[] = [
                        'store_id' => $storeId,
                        'imageable_type' => 'App\\Models\\Transaction',
                        'imageable_id' => $legacyImage->imageable_id,
                        'path' => '',
                        'url' => $legacyImage->url,
                        'thumbnail_url' => $legacyImage->thumbnail,
                        'alt_text' => null,
                        'disk' => 'do',
                        'size' => null,
                        'mime_type' => 'image/webp',
                        'width' => null,
                        'height' => null,
                        'sort_order' => $legacyImage->rank ?? 0,
                        'is_primary' => ($legacyImage->rank ?? 0) === 0 ? 1 : 0,
                        'is_internal' => 0,
                        'created_at' => $this->safeDateTime($legacyImage->created_at),
                        'updated_at' => $this->safeDateTime($legacyImage->updated_at),
                    ];

                    $migrated++;
                    $bar->advance();
                }

                if (! $dryRun && count($inserts) > 0) {
                    // Disable strict mode for DST edge cases
                    DB::statement("SET SESSION sql_mode = ''");
                    DB::table('images')->insert($inserts);
                }
            });

        $bar->finish();
        $this->newLine();
        $this->info("Transaction images: {$migrated} migrated, {$skipped} skipped");
    }

    protected function migrateTransactionItemImages(int $storeId, bool $dryRun, int $batchSize): void
    {
        $this->info('Migrating TransactionItem images...');

        // Get transaction item IDs for this store
        $transactionItemIds = DB::table('transaction_items')
            ->join('transactions', 'transactions.id', '=', 'transaction_items.transaction_id')
            ->where('transactions.store_id', $storeId)
            ->pluck('transaction_items.id')
            ->toArray();

        if (empty($transactionItemIds)) {
            $this->info('No transaction items found for this store');

            return;
        }

        // Count total
        $total = DB::connection('legacy')
            ->table('images')
            ->where('imageable_type', 'App\\Models\\TransactionItem')
            ->whereIn('imageable_id', $transactionItemIds)
            ->whereNull('deleted_at')
            ->count();

        $this->info("Found {$total} TransactionItem images to migrate");

        if ($total === 0) {
            return;
        }

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $migrated = 0;
        $skipped = 0;

        DB::connection('legacy')
            ->table('images')
            ->where('imageable_type', 'App\\Models\\TransactionItem')
            ->whereIn('imageable_id', $transactionItemIds)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->chunk($batchSize, function ($legacyImages) use ($storeId, $dryRun, &$migrated, &$skipped, $bar) {
                $inserts = [];

                foreach ($legacyImages as $legacyImage) {
                    // Check if already migrated
                    $exists = DB::table('images')
                        ->where('imageable_type', 'App\\Models\\TransactionItem')
                        ->where('imageable_id', $legacyImage->imageable_id)
                        ->where('url', $legacyImage->url)
                        ->exists();

                    if ($exists) {
                        $skipped++;
                        $bar->advance();

                        continue;
                    }

                    $inserts[] = [
                        'store_id' => $storeId,
                        'imageable_type' => 'App\\Models\\TransactionItem',
                        'imageable_id' => $legacyImage->imageable_id,
                        'path' => '',
                        'url' => $legacyImage->url,
                        'thumbnail_url' => $legacyImage->thumbnail,
                        'alt_text' => null,
                        'disk' => 'do',
                        'size' => null,
                        'mime_type' => 'image/webp',
                        'width' => null,
                        'height' => null,
                        'sort_order' => $legacyImage->rank ?? 0,
                        'is_primary' => ($legacyImage->rank ?? 0) === 0 ? 1 : 0,
                        'is_internal' => 0,
                        'created_at' => $this->safeDateTime($legacyImage->created_at),
                        'updated_at' => $this->safeDateTime($legacyImage->updated_at),
                    ];

                    $migrated++;
                    $bar->advance();
                }

                if (! $dryRun && count($inserts) > 0) {
                    // Disable strict mode for DST edge cases
                    DB::statement("SET SESSION sql_mode = ''");
                    DB::table('images')->insert($inserts);
                }
            });

        $bar->finish();
        $this->newLine();
        $this->info("TransactionItem images: {$migrated} migrated, {$skipped} skipped");
    }

    /**
     * Safely parse datetime, handling DST edge cases.
     */
    protected function safeDateTime(?string $datetime): ?string
    {
        if (! $datetime) {
            return null;
        }

        try {
            // Parse the datetime assuming it's already in UTC format from the legacy DB
            // Don't apply timezone conversion, just use the value as-is
            $date = Carbon::createFromFormat('Y-m-d H:i:s', $datetime);

            return $date->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            // If parsing fails (e.g., DST gap), adjust the time by adding 1 hour
            try {
                // Try parsing and adding an hour for DST gaps
                preg_match('/(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2}):(\d{2})/', $datetime, $matches);
                if ($matches) {
                    $adjusted = Carbon::create($matches[1], $matches[2], $matches[3], (int) $matches[4] + 1, $matches[5], $matches[6], 'UTC');

                    return $adjusted->format('Y-m-d H:i:s');
                }
            } catch (\Exception $e2) {
                // Fallback to current time
            }

            return now()->format('Y-m-d H:i:s');
        }
    }
}
