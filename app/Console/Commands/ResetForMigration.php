<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class ResetForMigration extends Command
{
    protected $signature = 'migrate:reset-all
                            {--keep-users : Keep user accounts (only delete store_users)}
                            {--force : Skip confirmation prompt}';

    protected $description = 'Truncate all tables to prepare for fresh legacy migration. Use before running migrate:legacy commands.';

    public function handle(): int
    {
        $this->warn('This will DELETE ALL DATA from the following tables:');
        $this->newLine();

        $tables = $this->getTablesToTruncate();

        foreach ($tables as $table) {
            $count = DB::table($table)->count();
            $this->line("  - {$table}: {$count} records");
        }

        $this->newLine();

        if (! $this->option('force') && ! $this->confirm('Are you sure you want to proceed? This cannot be undone.')) {
            $this->info('Operation cancelled.');

            return self::SUCCESS;
        }

        $this->info('Truncating tables...');

        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        try {
            foreach ($tables as $table) {
                DB::table($table)->truncate();
                $this->line("  Truncated: {$table}");
            }

            // Handle users separately based on --keep-users flag
            if (! $this->option('keep-users')) {
                // Keep only the first user (usually the super admin)
                $keepUserId = DB::table('users')->min('id');
                if ($keepUserId) {
                    $deleted = DB::table('users')->where('id', '!=', $keepUserId)->delete();
                    $this->line("  Deleted {$deleted} users (kept ID: {$keepUserId})");
                }
            } else {
                $this->line('  Kept all user accounts');
            }

            DB::statement('SET FOREIGN_KEY_CHECKS=1');

            // Clear mapping files
            $mapPath = storage_path('app/migration_maps');
            if (File::isDirectory($mapPath)) {
                $files = File::files($mapPath);
                foreach ($files as $file) {
                    File::delete($file);
                }
                $this->line('  Cleared '.count($files).' mapping files');
            }

            $this->newLine();
            $this->info('Reset complete! Ready for fresh migration.');
            $this->newLine();
            $this->line('Next steps:');
            $this->line('  1. php artisan migrate:legacy --store-id=<ID> --limit=0');
            $this->line('  2. php artisan migrate:legacy-vendors --store-id=<ID> --new-store-id=<NEW_ID>');
            $this->line('  3. php artisan migrate:legacy-products --store-id=<ID> --new-store-id=<NEW_ID> --limit=0');
            $this->line('  4. php artisan migrate:legacy-marketplaces --store-id=<ID> --new-store-id=<NEW_ID>');
            $this->line('  5. php artisan migrate:legacy-orders --store-id=<ID> --new-store-id=<NEW_ID> --limit=0 --with-payments');
            $this->line('  6. php artisan migrate:legacy-repairs --store-id=<ID> --new-store-id=<NEW_ID> --limit=0');
            $this->line('  7. php artisan migrate:legacy-memos --store-id=<ID> --new-store-id=<NEW_ID> --limit=0');

            return self::SUCCESS;
        } catch (\Exception $e) {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
            $this->error('Error: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    protected function getTablesToTruncate(): array
    {
        return [
            // Transactions
            'transactions',
            'transaction_items',
            'transaction_offers',
            'transaction_payouts',

            // Orders
            'orders',
            'order_items',
            'payments',
            'invoices',
            'layaways',
            'returns',
            'platform_orders',

            // Repairs & Memos
            'repairs',
            'repair_items',
            'memos',
            'memo_items',

            // Products
            'products',
            'product_variants',
            'product_images',
            'product_videos',
            'product_attribute_values',
            'product_vendor',
            'platform_listings',
            'certifications',

            // Inventory
            'inventory',
            'inventory_adjustments',
            'inventory_transfer_items',
            'inventory_transfers',

            // Buckets
            'buckets',
            'bucket_items',

            // Customers & Vendors
            'customers',
            'vendors',

            // Categories & Templates
            'categories',
            'product_templates',
            'product_template_fields',
            'product_template_field_options',

            // Store infrastructure
            'stores',
            'store_users',
            'roles',
            'warehouses',
            'sales_channels',
            'store_marketplaces',
            'statuses',
            'status_transitions',
            'lead_sources',

            // Supporting tables
            'addresses',
            'notes',
            'images',
            'activity_logs',
            'status_histories',
            'shipping_labels',
            'quick_evaluations',
        ];
    }
}
