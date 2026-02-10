<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ClearStoreData extends Command
{
    protected $signature = 'app:clear-store-data
                            {store : The store ID to clear data for}
                            {--transactions : Clear transactions and related data}
                            {--orders : Clear orders and related data}
                            {--repairs : Clear repairs and related data}
                            {--memos : Clear memos and related data}
                            {--buckets : Clear buckets and related data}
                            {--all : Clear all data types}
                            {--force : Skip confirmation prompt}';

    protected $description = 'Clear transactions, orders, repairs, memos, and/or buckets for a specific store. Use before re-running legacy migration commands.';

    public function handle(): int
    {
        $storeId = (int) $this->argument('store');

        // Verify store exists
        $store = DB::table('stores')->where('id', $storeId)->first();
        if (! $store) {
            $this->error("Store with ID {$storeId} not found.");

            return self::FAILURE;
        }

        $clearAll = $this->option('all');
        $clearTransactions = $clearAll || $this->option('transactions');
        $clearOrders = $clearAll || $this->option('orders');
        $clearRepairs = $clearAll || $this->option('repairs');
        $clearMemos = $clearAll || $this->option('memos');
        $clearBuckets = $clearAll || $this->option('buckets');

        if (! $clearTransactions && ! $clearOrders && ! $clearRepairs && ! $clearMemos && ! $clearBuckets) {
            $this->error('Please specify what to clear: --transactions, --orders, --repairs, --memos, --buckets, or --all');

            return self::FAILURE;
        }

        // Show what will be deleted
        $this->info("Store: {$store->name} (ID: {$storeId})");
        $this->newLine();
        $this->warn('The following data will be permanently deleted:');

        if ($clearTransactions) {
            $count = DB::table('transactions')->where('store_id', $storeId)->count();
            $this->line("  - Transactions: {$count}");
        }

        if ($clearOrders) {
            $count = DB::table('orders')->where('store_id', $storeId)->count();
            $this->line("  - Orders: {$count}");
        }

        if ($clearRepairs) {
            $count = DB::table('repairs')->where('store_id', $storeId)->count();
            $this->line("  - Repairs: {$count}");
        }

        if ($clearMemos) {
            $count = DB::table('memos')->where('store_id', $storeId)->count();
            $this->line("  - Memos: {$count}");
        }

        if ($clearBuckets) {
            $count = DB::table('buckets')->where('store_id', $storeId)->count();
            $this->line("  - Buckets: {$count}");
        }

        $this->newLine();

        if (! $this->option('force') && ! $this->confirm('Are you sure you want to proceed? This cannot be undone.')) {
            $this->info('Operation cancelled.');

            return self::SUCCESS;
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        try {
            // Clear in specific order due to dependencies
            if ($clearTransactions) {
                $this->clearTransactions($storeId);
            }

            if ($clearMemos) {
                $this->clearMemos($storeId);
            }

            if ($clearRepairs) {
                $this->clearRepairs($storeId);
            }

            if ($clearOrders) {
                $this->clearOrders($storeId);
            }

            if ($clearBuckets) {
                $this->clearBuckets($storeId);
            }

            DB::statement('SET FOREIGN_KEY_CHECKS=1');

            $this->newLine();
            $this->info('Data cleared successfully!');

            return self::SUCCESS;
        } catch (\Exception $e) {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
            $this->error('Error clearing data: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    protected function clearTransactions(int $storeId): void
    {
        $this->info('Clearing transactions...');

        $transactionIds = DB::table('transactions')
            ->where('store_id', $storeId)
            ->pluck('id');

        if ($transactionIds->isEmpty()) {
            $this->line('  No transactions to clear.');

            return;
        }

        $itemIds = DB::table('transaction_items')
            ->whereIn('transaction_id', $transactionIds)
            ->pluck('id');

        // Delete images for transaction items
        $imageCount = DB::table('images')
            ->where('imageable_type', 'App\\Models\\TransactionItem')
            ->whereIn('imageable_id', $itemIds)
            ->delete();
        $this->line("  Deleted {$imageCount} transaction item images");

        // Delete images for transactions
        $imageCount = DB::table('images')
            ->where('imageable_type', 'App\\Models\\Transaction')
            ->whereIn('imageable_id', $transactionIds)
            ->delete();
        $this->line("  Deleted {$imageCount} transaction images");

        // Delete notes for transactions
        $noteCount = DB::table('notes')
            ->where('notable_type', 'App\\Models\\Transaction')
            ->whereIn('notable_id', $transactionIds)
            ->delete();
        $this->line("  Deleted {$noteCount} transaction notes");

        // Delete transaction items
        $count = DB::table('transaction_items')->whereIn('transaction_id', $transactionIds)->delete();
        $this->line("  Deleted {$count} transaction items");

        // Delete transaction offers
        $count = DB::table('transaction_offers')->whereIn('transaction_id', $transactionIds)->delete();
        $this->line("  Deleted {$count} transaction offers");

        // Delete transaction payouts
        $count = DB::table('transaction_payouts')->whereIn('transaction_id', $transactionIds)->delete();
        $this->line("  Deleted {$count} transaction payouts");

        // Clear references in quick_evaluations
        DB::table('quick_evaluations')
            ->whereIn('transaction_id', $transactionIds)
            ->update(['transaction_id' => null]);

        // Clear trade-in reference from orders
        DB::table('orders')
            ->whereIn('trade_in_transaction_id', $transactionIds)
            ->update(['trade_in_transaction_id' => null]);

        // Delete transactions
        $count = DB::table('transactions')->where('store_id', $storeId)->delete();
        $this->line("  Deleted {$count} transactions");
    }

    protected function clearOrders(int $storeId): void
    {
        $this->info('Clearing orders...');

        $orderIds = DB::table('orders')
            ->where('store_id', $storeId)
            ->pluck('id');

        if ($orderIds->isEmpty()) {
            $this->line('  No orders to clear.');

            return;
        }

        // Delete notes for orders
        $noteCount = DB::table('notes')
            ->where('notable_type', 'App\\Models\\Order')
            ->whereIn('notable_id', $orderIds)
            ->delete();
        $this->line("  Deleted {$noteCount} order notes");

        // Delete invoices for orders
        $count = DB::table('invoices')
            ->where('invoiceable_type', 'App\\Models\\Order')
            ->whereIn('invoiceable_id', $orderIds)
            ->delete();
        $this->line("  Deleted {$count} order invoices");

        // Delete payments for orders
        $count = DB::table('payments')
            ->where('payable_type', 'App\\Models\\Order')
            ->whereIn('payable_id', $orderIds)
            ->delete();
        $this->line("  Deleted {$count} order payments");

        // Delete shipping labels for orders
        $count = DB::table('shipping_labels')
            ->where('shippable_type', 'App\\Models\\Order')
            ->whereIn('shippable_id', $orderIds)
            ->delete();
        $this->line("  Deleted {$count} shipping labels");

        // Delete order items
        $count = DB::table('order_items')->whereIn('order_id', $orderIds)->delete();
        $this->line("  Deleted {$count} order items");

        // Delete platform orders
        $count = DB::table('platform_orders')->whereIn('order_id', $orderIds)->delete();
        $this->line("  Deleted {$count} platform orders");

        // Delete returns
        $count = DB::table('returns')->whereIn('order_id', $orderIds)->delete();
        $this->line("  Deleted {$count} returns");

        // Delete layaways
        $count = DB::table('layaways')->whereIn('order_id', $orderIds)->delete();
        $this->line("  Deleted {$count} layaways");

        // Clear order reference from repairs
        DB::table('repairs')
            ->whereIn('order_id', $orderIds)
            ->update(['order_id' => null]);

        // Clear order reference from memos
        DB::table('memos')
            ->whereIn('order_id', $orderIds)
            ->update(['order_id' => null]);

        // Clear order reference from transactions
        DB::table('transactions')
            ->whereIn('order_id', $orderIds)
            ->update(['order_id' => null]);

        // Delete orders
        $count = DB::table('orders')->where('store_id', $storeId)->delete();
        $this->line("  Deleted {$count} orders");
    }

    protected function clearRepairs(int $storeId): void
    {
        $this->info('Clearing repairs...');

        $repairIds = DB::table('repairs')
            ->where('store_id', $storeId)
            ->pluck('id');

        if ($repairIds->isEmpty()) {
            $this->line('  No repairs to clear.');

            return;
        }

        // Delete notes for repairs
        $noteCount = DB::table('notes')
            ->where('notable_type', 'App\\Models\\Repair')
            ->whereIn('notable_id', $repairIds)
            ->delete();
        $this->line("  Deleted {$noteCount} repair notes");

        // Delete repair items
        $count = DB::table('repair_items')->whereIn('repair_id', $repairIds)->delete();
        $this->line("  Deleted {$count} repair items");

        // Delete repairs
        $count = DB::table('repairs')->where('store_id', $storeId)->delete();
        $this->line("  Deleted {$count} repairs");
    }

    protected function clearMemos(int $storeId): void
    {
        $this->info('Clearing memos...');

        $memoIds = DB::table('memos')
            ->where('store_id', $storeId)
            ->pluck('id');

        if ($memoIds->isEmpty()) {
            $this->line('  No memos to clear.');

            return;
        }

        // Delete notes for memos
        $noteCount = DB::table('notes')
            ->where('notable_type', 'App\\Models\\Memo')
            ->whereIn('notable_id', $memoIds)
            ->delete();
        $this->line("  Deleted {$noteCount} memo notes");

        // Delete payments for memos
        $count = DB::table('payments')
            ->where('payable_type', 'App\\Models\\Memo')
            ->whereIn('payable_id', $memoIds)
            ->delete();
        $this->line("  Deleted {$count} memo payments");

        // Delete memo items
        $count = DB::table('memo_items')->whereIn('memo_id', $memoIds)->delete();
        $this->line("  Deleted {$count} memo items");

        // Clear memo reference from orders
        DB::table('orders')
            ->whereIn('memo_id', $memoIds)
            ->update(['memo_id' => null]);

        // Delete memos
        $count = DB::table('memos')->where('store_id', $storeId)->delete();
        $this->line("  Deleted {$count} memos");
    }

    protected function clearBuckets(int $storeId): void
    {
        $this->info('Clearing buckets...');

        $bucketIds = DB::table('buckets')
            ->where('store_id', $storeId)
            ->pluck('id');

        if ($bucketIds->isEmpty()) {
            $this->line('  No buckets to clear.');

            return;
        }

        // Delete bucket items
        $count = DB::table('bucket_items')->whereIn('bucket_id', $bucketIds)->delete();
        $this->line("  Deleted {$count} bucket items");

        // Delete buckets
        $count = DB::table('buckets')->where('store_id', $storeId)->delete();
        $this->line("  Deleted {$count} buckets");
    }
}
