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
                            {--products : Clear products and related data}
                            {--categories : Clear categories}
                            {--all : Clear all data types}
                            {--force : Skip confirmation prompt}';

    protected $description = 'Clear transactions, orders, repairs, memos, buckets, products, and/or categories for a specific store. Use before re-running legacy migration commands.';

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
        $clearProducts = $clearAll || $this->option('products');
        $clearCategories = $clearAll || $this->option('categories');

        if (! $clearTransactions && ! $clearOrders && ! $clearRepairs && ! $clearMemos && ! $clearBuckets && ! $clearProducts && ! $clearCategories) {
            $this->error('Please specify what to clear: --transactions, --orders, --repairs, --memos, --buckets, --products, --categories, or --all');

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

        if ($clearProducts) {
            $count = DB::table('products')->where('store_id', $storeId)->count();
            $this->line("  - Products: {$count}");
        }

        if ($clearCategories) {
            $count = DB::table('categories')->where('store_id', $storeId)->count();
            $this->line("  - Categories: {$count}");
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

            if ($clearProducts) {
                $this->clearProducts($storeId);
            }

            if ($clearCategories) {
                $this->clearCategories($storeId);
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

    protected function clearProducts(int $storeId): void
    {
        $this->info('Clearing products...');

        $productIds = DB::table('products')
            ->where('store_id', $storeId)
            ->pluck('id');

        if ($productIds->isEmpty()) {
            $this->line('  No products to clear.');

            return;
        }

        $variantIds = DB::table('product_variants')
            ->whereIn('product_id', $productIds)
            ->pluck('id');

        // Clear product variant references from order items
        $count = DB::table('order_items')
            ->whereIn('product_variant_id', $variantIds)
            ->update(['product_variant_id' => null]);
        $this->line("  Nullified {$count} order item variant references");

        // Clear product variant references from memo items
        $count = DB::table('memo_items')
            ->whereIn('product_variant_id', $variantIds)
            ->update(['product_variant_id' => null]);
        $this->line("  Nullified {$count} memo item variant references");

        // Clear product references from transaction items
        $count = DB::table('transaction_items')
            ->whereIn('product_id', $productIds)
            ->update(['product_id' => null]);
        $this->line("  Nullified {$count} transaction item product references");

        // Delete platform listings
        $count = DB::table('platform_listings')
            ->whereIn('product_id', $productIds)
            ->delete();
        $this->line("  Deleted {$count} platform listings");

        // Delete inventory adjustments (must be before inventory)
        $inventoryIds = DB::table('inventory')
            ->whereIn('product_variant_id', $variantIds)
            ->pluck('id');
        $count = DB::table('inventory_adjustments')
            ->whereIn('inventory_id', $inventoryIds)
            ->delete();
        $this->line("  Deleted {$count} inventory adjustments");

        // Delete inventory
        $count = DB::table('inventory')
            ->whereIn('product_variant_id', $variantIds)
            ->delete();
        $this->line("  Deleted {$count} inventory records");

        // Delete inventory transfer items
        $count = DB::table('inventory_transfer_items')
            ->whereIn('product_variant_id', $variantIds)
            ->delete();
        $this->line("  Deleted {$count} inventory transfer items");

        // Delete product vendor relationships
        $count = DB::table('product_vendor')
            ->whereIn('product_variant_id', $variantIds)
            ->delete();
        $this->line("  Deleted {$count} product vendor relationships");

        // Delete product images
        $count = DB::table('product_images')
            ->whereIn('product_id', $productIds)
            ->delete();
        $this->line("  Deleted {$count} product images");

        // Delete product videos
        $count = DB::table('product_videos')
            ->whereIn('product_id', $productIds)
            ->delete();
        $this->line("  Deleted {$count} product videos");

        // Delete product attribute values
        $count = DB::table('product_attribute_values')
            ->whereIn('product_id', $productIds)
            ->delete();
        $this->line("  Deleted {$count} product attribute values");

        // Delete product variants
        $count = DB::table('product_variants')
            ->whereIn('product_id', $productIds)
            ->delete();
        $this->line("  Deleted {$count} product variants");

        // Delete notes for products
        $noteCount = DB::table('notes')
            ->where('notable_type', 'App\\Models\\Product')
            ->whereIn('notable_id', $productIds)
            ->delete();
        $this->line("  Deleted {$noteCount} product notes");

        // Delete activity log for products
        $count = DB::table('activity_log')
            ->where('subject_type', 'App\\Models\\Product')
            ->whereIn('subject_id', $productIds)
            ->delete();
        $this->line("  Deleted {$count} product activity logs");

        // Delete products
        $count = DB::table('products')->where('store_id', $storeId)->delete();
        $this->line("  Deleted {$count} products");

        // Delete certifications
        $count = DB::table('certifications')->where('store_id', $storeId)->delete();
        $this->line("  Deleted {$count} certifications");

        // Delete product templates
        $templateIds = DB::table('product_templates')
            ->where('store_id', $storeId)
            ->pluck('id');

        if ($templateIds->isNotEmpty()) {
            // Delete template field options
            $fieldIds = DB::table('product_template_fields')
                ->whereIn('product_template_id', $templateIds)
                ->pluck('id');
            $count = DB::table('product_template_field_options')
                ->whereIn('product_template_field_id', $fieldIds)
                ->delete();
            $this->line("  Deleted {$count} template field options");

            // Delete template fields
            $count = DB::table('product_template_fields')
                ->whereIn('product_template_id', $templateIds)
                ->delete();
            $this->line("  Deleted {$count} template fields");

            // Delete templates
            $count = DB::table('product_templates')->where('store_id', $storeId)->delete();
            $this->line("  Deleted {$count} product templates");
        }
    }

    protected function clearCategories(int $storeId): void
    {
        $this->info('Clearing categories...');

        $categoryIds = DB::table('categories')
            ->where('store_id', $storeId)
            ->pluck('id');

        if ($categoryIds->isEmpty()) {
            $this->line('  No categories to clear.');

            return;
        }

        // Clear category references from products
        $count = DB::table('products')
            ->whereIn('category_id', $categoryIds)
            ->update(['category_id' => null]);
        $this->line("  Nullified {$count} product category references");

        // Clear parent_id references (self-referential)
        DB::table('categories')
            ->where('store_id', $storeId)
            ->update(['parent_id' => null]);

        // Clear default_bucket_id references
        DB::table('categories')
            ->where('store_id', $storeId)
            ->update(['default_bucket_id' => null]);

        // Delete categories
        $count = DB::table('categories')->where('store_id', $storeId)->delete();
        $this->line("  Deleted {$count} categories");
    }
}
