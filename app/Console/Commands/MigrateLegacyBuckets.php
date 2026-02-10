<?php

namespace App\Console\Commands;

use App\Models\Bucket;
use App\Models\BucketItem;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MigrateLegacyBuckets extends Command
{
    protected $signature = 'migrate:legacy-buckets
                            {store_id : The legacy store ID to migrate}
                            {--new-store-id= : The new store ID to migrate to (defaults to same as legacy)}
                            {--dry-run : Run without making any changes}
                            {--skip-items : Skip migrating bucket items}
                            {--force : Skip confirmation prompt}';

    protected $description = 'Migrate legacy buckets from the legacy database for a specific store';

    /**
     * Map legacy bucket IDs to new bucket IDs.
     *
     * @var array<int, int>
     */
    protected array $bucketMap = [];

    /**
     * Map legacy transaction_item IDs to new transaction_item IDs.
     *
     * @var array<int, int>
     */
    protected array $transactionItemMap = [];

    /**
     * Map legacy order_item IDs to new order_item IDs.
     *
     * @var array<int, int>
     */
    protected array $orderItemMap = [];

    protected bool $dryRun = false;

    protected int $legacyStoreId;

    protected int $newStoreId;

    public function handle(): int
    {
        $this->legacyStoreId = (int) $this->argument('store_id');
        $this->newStoreId = (int) ($this->option('new-store-id') ?? $this->legacyStoreId);
        $this->dryRun = (bool) $this->option('dry-run');

        if ($this->dryRun) {
            $this->warn('Running in DRY RUN mode - no changes will be made');
        }

        // Get legacy buckets count
        $legacyBucketCount = DB::connection('legacy')
            ->table('buckets')
            ->where('store_id', $this->legacyStoreId)
            ->count();

        $legacyItemCount = DB::connection('legacy')
            ->table('bucket_items')
            ->whereIn('bucket_id', function ($query) {
                $query->select('id')
                    ->from('buckets')
                    ->where('store_id', $this->legacyStoreId);
            })
            ->whereNull('deleted_at')
            ->count();

        $this->info("Found {$legacyBucketCount} buckets with {$legacyItemCount} items for legacy store {$this->legacyStoreId}");

        if ($legacyBucketCount === 0) {
            $this->warn('No buckets found to migrate.');

            return self::SUCCESS;
        }

        // Check for existing buckets in new store
        $existingCount = Bucket::where('store_id', $this->newStoreId)->count();
        if ($existingCount > 0) {
            $this->warn("New store {$this->newStoreId} already has {$existingCount} buckets.");
            if (! $this->option('force') && ! $this->confirm('Do you want to continue? This may create duplicates.')) {
                $this->info('Migration cancelled.');

                return self::SUCCESS;
            }
        }

        // Build transaction item mapping (legacy_id => new_id)
        $this->buildTransactionItemMap();

        // Build order item mapping (legacy_id => new_id)
        $this->buildOrderItemMap();

        // Migrate buckets
        $this->migrateBuckets();

        // Migrate bucket items
        if (! $this->option('skip-items')) {
            $this->migrateBucketItems();
        }

        // Recalculate totals
        $this->recalculateTotals();

        $this->newLine();
        $this->info('Bucket migration completed!');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Buckets Migrated', count($this->bucketMap)],
                ['Items Migrated', $this->option('skip-items') ? 'Skipped' : $this->getNewItemCount()],
            ]
        );

        return self::SUCCESS;
    }

    protected function buildTransactionItemMap(): void
    {
        $this->info('Building transaction item mapping...');

        // Get transaction items that have legacy references in payment_details
        $transactionItems = DB::table('transaction_items')
            ->join('transactions', 'transactions.id', '=', 'transaction_items.transaction_id')
            ->where('transactions.store_id', $this->newStoreId)
            ->whereNotNull('transactions.payment_details')
            ->select('transaction_items.id', 'transactions.payment_details')
            ->get();

        foreach ($transactionItems as $item) {
            $paymentDetails = json_decode($item->payment_details, true);
            if (isset($paymentDetails['legacy_id'])) {
                // Get legacy transaction items for this transaction
                $legacyItems = DB::connection('legacy')
                    ->table('transaction_items')
                    ->where('transaction_id', $paymentDetails['legacy_id'])
                    ->pluck('id');

                // This is a rough mapping - assumes items are in same order
                // A more accurate approach would need additional identifying info
            }
        }

        // Alternative: map by SKU if available
        $newItems = DB::table('transaction_items')
            ->join('transactions', 'transactions.id', '=', 'transaction_items.transaction_id')
            ->where('transactions.store_id', $this->newStoreId)
            ->whereNotNull('transaction_items.sku')
            ->select('transaction_items.id', 'transaction_items.sku')
            ->get()
            ->keyBy('sku');

        $legacyItems = DB::connection('legacy')
            ->table('bucket_items')
            ->whereIn('bucket_id', function ($query) {
                $query->select('id')
                    ->from('buckets')
                    ->where('store_id', $this->legacyStoreId);
            })
            ->whereNotNull('transaction_item_id')
            ->get();

        foreach ($legacyItems as $legacyItem) {
            if ($legacyItem->sku && isset($newItems[$legacyItem->sku])) {
                $this->transactionItemMap[$legacyItem->transaction_item_id] = $newItems[$legacyItem->sku]->id;
            }
        }

        $this->line('  Mapped '.count($this->transactionItemMap).' transaction items');
    }

    protected function buildOrderItemMap(): void
    {
        $this->info('Building order item mapping...');

        // Map order items by invoice_number and SKU combination
        $newOrderItems = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.store_id', $this->newStoreId)
            ->select('order_items.id', 'order_items.sku', 'orders.invoice_number')
            ->get();

        $itemsByInvoiceAndSku = [];
        foreach ($newOrderItems as $item) {
            $key = $item->invoice_number.'|'.$item->sku;
            $itemsByInvoiceAndSku[$key] = $item->id;
        }

        // Get legacy bucket transactions with order_item_id
        $legacySales = DB::connection('legacy')
            ->table('bucket_transactions')
            ->whereIn('bucket_id', function ($query) {
                $query->select('id')
                    ->from('buckets')
                    ->where('store_id', $this->legacyStoreId);
            })
            ->whereNotNull('order_item_id')
            ->whereNull('deleted_at')
            ->get();

        foreach ($legacySales as $sale) {
            // Try to find matching new order item
            if ($sale->invoice_number) {
                // Get legacy order item SKU
                $legacyOrderItem = DB::connection('legacy')
                    ->table('order_items')
                    ->where('id', $sale->order_item_id)
                    ->first();

                if ($legacyOrderItem && $legacyOrderItem->sku) {
                    $key = $sale->invoice_number.'|'.$legacyOrderItem->sku;
                    if (isset($itemsByInvoiceAndSku[$key])) {
                        $this->orderItemMap[$sale->order_item_id] = $itemsByInvoiceAndSku[$key];
                    }
                }
            }
        }

        $this->line('  Mapped '.count($this->orderItemMap).' order items');
    }

    protected function migrateBuckets(): void
    {
        $this->info('Migrating buckets...');

        $legacyBuckets = DB::connection('legacy')
            ->table('buckets')
            ->where('store_id', $this->legacyStoreId)
            ->orderBy('id')
            ->get();

        $bar = $this->output->createProgressBar($legacyBuckets->count());
        $bar->start();

        foreach ($legacyBuckets as $legacyBucket) {
            if ($this->dryRun) {
                $this->bucketMap[$legacyBucket->id] = 0;
                $bar->advance();

                continue;
            }

            try {
                // Check if bucket with same name already exists
                $existingBucket = Bucket::where('store_id', $this->newStoreId)
                    ->where('name', $legacyBucket->name)
                    ->first();

                if ($existingBucket) {
                    $this->bucketMap[$legacyBucket->id] = $existingBucket->id;
                    $bar->advance();

                    continue;
                }

                // Create new bucket - use DB insert to preserve timestamps
                $newBucketId = DB::table('buckets')->insertGetId([
                    'store_id' => $this->newStoreId,
                    'name' => $legacyBucket->name,
                    'description' => $legacyBucket->description,
                    'total_value' => $legacyBucket->value ?? 0,
                    'created_at' => $legacyBucket->created_at,
                    'updated_at' => $legacyBucket->updated_at,
                ]);

                $this->bucketMap[$legacyBucket->id] = $newBucketId;
            } catch (\Exception $e) {
                $this->newLine();
                $this->error("Failed to migrate bucket {$legacyBucket->id}: ".$e->getMessage());
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
    }

    protected function migrateBucketItems(): void
    {
        $this->info('Migrating bucket items...');

        $legacyItems = DB::connection('legacy')
            ->table('bucket_items')
            ->whereIn('bucket_id', function ($query) {
                $query->select('id')
                    ->from('buckets')
                    ->where('store_id', $this->legacyStoreId);
            })
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get();

        if ($legacyItems->isEmpty()) {
            $this->line('  No bucket items to migrate.');

            return;
        }

        // Get sold items from bucket_transactions
        $soldItems = $this->getSoldItemsMap();

        $bar = $this->output->createProgressBar($legacyItems->count());
        $bar->start();

        $migratedCount = 0;
        $skippedCount = 0;

        foreach ($legacyItems as $legacyItem) {
            $newBucketId = $this->bucketMap[$legacyItem->bucket_id] ?? null;

            if (! $newBucketId) {
                $skippedCount++;
                $bar->advance();

                continue;
            }

            if ($this->dryRun) {
                $migratedCount++;
                $bar->advance();

                continue;
            }

            try {
                // Build title from available fields
                $titleParts = array_filter([
                    $legacyItem->brand,
                    $legacyItem->model,
                    $legacyItem->product_name,
                ]);
                $title = ! empty($titleParts) ? implode(' - ', $titleParts) : ($legacyItem->sku ?? 'Item #'.$legacyItem->id);

                // Check if this item was sold
                $soldInfo = $soldItems[$legacyItem->id] ?? null;

                // Map transaction_item_id if available
                $newTransactionItemId = null;
                if ($legacyItem->transaction_item_id) {
                    $newTransactionItemId = $this->transactionItemMap[$legacyItem->transaction_item_id] ?? null;
                }

                // Map order_item_id if sold
                $newOrderItemId = null;
                $soldAt = null;
                if ($soldInfo) {
                    $newOrderItemId = $this->orderItemMap[$soldInfo->order_item_id] ?? null;
                    $soldAt = $soldInfo->created_at;
                }

                // Calculate value
                $value = $legacyItem->total ?? ($legacyItem->unit_cost * ($legacyItem->quantity ?? 1)) ?? 0;

                DB::table('bucket_items')->insert([
                    'bucket_id' => $newBucketId,
                    'transaction_item_id' => $newTransactionItemId,
                    'title' => $title,
                    'description' => null,
                    'value' => $value,
                    'sold_at' => $soldAt,
                    'order_item_id' => $newOrderItemId,
                    'created_at' => $legacyItem->created_at,
                    'updated_at' => $legacyItem->updated_at,
                ]);

                $migratedCount++;
            } catch (\Exception $e) {
                $this->newLine();
                $this->error("Failed to migrate bucket item {$legacyItem->id}: ".$e->getMessage());
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->line("  Migrated: {$migratedCount}, Skipped: {$skippedCount}");
    }

    /**
     * Get map of bucket_item_id => sold info from bucket_transactions.
     */
    protected function getSoldItemsMap(): array
    {
        $soldItems = DB::connection('legacy')
            ->table('bucket_transactions')
            ->whereIn('bucket_id', function ($query) {
                $query->select('id')
                    ->from('buckets')
                    ->where('store_id', $this->legacyStoreId);
            })
            ->whereNotNull('order_item_id')
            ->whereNull('deleted_at')
            ->get();

        $map = [];

        // We need to match bucket_transactions to bucket_items
        // The relationship is: bucket_transactions has order_item_id, bucket_items has the item data
        // They share bucket_id but not a direct foreign key
        // We'll use bucket_id + created_at proximity to match

        foreach ($soldItems as $sale) {
            // Find the bucket item that was likely sold in this transaction
            // This is heuristic-based since there's no direct link
            $bucketItem = DB::connection('legacy')
                ->table('bucket_items')
                ->where('bucket_id', $sale->bucket_id)
                ->whereNull('deleted_at')
                ->orderBy('created_at')
                ->first();

            if ($bucketItem) {
                $map[$bucketItem->id] = $sale;
            }
        }

        return $map;
    }

    protected function recalculateTotals(): void
    {
        if ($this->dryRun) {
            return;
        }

        $this->info('Recalculating bucket totals...');

        $newBucketIds = array_values(array_filter($this->bucketMap));

        foreach ($newBucketIds as $bucketId) {
            $bucket = Bucket::find($bucketId);
            if ($bucket) {
                $bucket->recalculateTotal();
            }
        }

        $this->line('  Done.');
    }

    protected function getNewItemCount(): int
    {
        $newBucketIds = array_values(array_filter($this->bucketMap));

        if (empty($newBucketIds)) {
            return 0;
        }

        return BucketItem::whereIn('bucket_id', $newBucketIds)->count();
    }
}
