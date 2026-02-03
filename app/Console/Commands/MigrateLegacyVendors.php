<?php

namespace App\Console\Commands;

use App\Models\Store;
use App\Models\Vendor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Migrate vendors from the legacy Laravel 8 application.
 *
 * In the legacy system, vendors are stored in the customers table with is_vendor = 1.
 * This command imports those records into the new dedicated vendors table.
 */
class MigrateLegacyVendors extends Command
{
    protected $signature = 'migrate:legacy-vendors
                            {--store-id=63 : Legacy store ID to migrate}
                            {--new-store-id= : New store ID (if different from legacy)}
                            {--dry-run : Show what would be migrated without making changes}
                            {--fresh : Delete existing vendors and start fresh}';

    protected $description = 'Migrate vendors from the legacy database (customers with is_vendor=1)';

    protected array $vendorMap = [];

    public function handle(): int
    {
        $legacyStoreId = (int) $this->option('store-id');
        $newStoreId = $this->option('new-store-id') ? (int) $this->option('new-store-id') : null;
        $isDryRun = $this->option('dry-run');

        $this->info("Starting vendor migration from legacy store ID: {$legacyStoreId}");
        $this->info('(Importing from customers table where is_vendor = 1)');

        if ($isDryRun) {
            $this->warn('DRY RUN MODE - No changes will be made');
        }

        // Get legacy store info
        $legacyStore = DB::connection('legacy')
            ->table('stores')
            ->where('id', $legacyStoreId)
            ->first();

        if (! $legacyStore) {
            $this->error("Legacy store with ID {$legacyStoreId} not found");

            return 1;
        }

        // Find the new store
        $newStore = null;
        if ($newStoreId) {
            $newStore = Store::find($newStoreId);
        } else {
            $newStore = Store::where('name', $legacyStore->name)->first();
        }

        if (! $newStore) {
            $this->error('New store not found. Run migrate:legacy first to create the store.');

            return 1;
        }

        $this->info("Migrating vendors to store: {$newStore->name} (ID: {$newStore->id})");

        if ($this->option('fresh') && ! $isDryRun) {
            if ($this->confirm('This will delete all existing vendors for this store. Continue?')) {
                Vendor::where('store_id', $newStore->id)->forceDelete();
                $this->line('  Deleted existing vendors');
            }
        }

        try {
            DB::beginTransaction();

            $this->migrateVendors($legacyStoreId, $newStore, $isDryRun);

            if ($isDryRun) {
                DB::rollBack();
                $this->info('Dry run complete - no changes made');
            } else {
                DB::commit();
                $this->info('Vendor migration complete!');
            }

            $this->displaySummary($newStore);

            // Output the vendor map for use by other migration scripts
            if (! $isDryRun && count($this->vendorMap) > 0) {
                $mapFile = storage_path("app/migration_maps/vendor_map_{$legacyStoreId}.json");
                if (! is_dir(dirname($mapFile))) {
                    mkdir(dirname($mapFile), 0755, true);
                }
                file_put_contents($mapFile, json_encode($this->vendorMap, JSON_PRETTY_PRINT));
                $this->line("  Vendor map saved to: {$mapFile}");
            }

            return 0;
        } catch (\Exception $e) {
            DB::rollBack();
            $this->error("Migration failed: {$e->getMessage()}");
            $this->error($e->getTraceAsString());

            return 1;
        }
    }

    protected function migrateVendors(int $legacyStoreId, Store $newStore, bool $isDryRun): void
    {
        $this->info('Migrating vendors from customers table...');

        // Get vendor customers from legacy database (customers with is_vendor = 1)
        $legacyVendors = DB::connection('legacy')
            ->table('customers')
            ->where('store_id', $legacyStoreId)
            ->where('is_vendor', true)
            ->whereNull('deleted_at')
            ->get();

        if ($legacyVendors->isEmpty()) {
            $this->line('  No vendors to migrate');

            return;
        }

        $this->line("  Found {$legacyVendors->count()} vendor customers to migrate");

        $count = 0;
        $skipped = 0;

        foreach ($legacyVendors as $legacyCustomer) {
            // Build vendor name from first_name and last_name
            $name = trim(($legacyCustomer->first_name ?? '').' '.($legacyCustomer->last_name ?? ''));
            if (empty($name)) {
                $name = $legacyCustomer->company_name ?? "Vendor #{$legacyCustomer->id}";
            }

            // Check if vendor already exists by email or name
            $existingVendor = null;
            if ($legacyCustomer->email) {
                $existingVendor = Vendor::where('store_id', $newStore->id)
                    ->where('email', $legacyCustomer->email)
                    ->first();
            }

            if (! $existingVendor && $name) {
                $existingVendor = Vendor::where('store_id', $newStore->id)
                    ->where('name', $name)
                    ->first();
            }

            if ($existingVendor) {
                $this->vendorMap[$legacyCustomer->id] = $existingVendor->id;
                $skipped++;

                continue;
            }

            if ($isDryRun) {
                $this->line("  Would create vendor: {$name} ({$legacyCustomer->email})");
                $count++;

                continue;
            }

            // Get customer's address from legacy addresses table (polymorphic)
            $legacyAddress = DB::connection('legacy')
                ->table('addresses')
                ->where('addressable_type', 'App\\Models\\Customer')
                ->where('addressable_id', $legacyCustomer->id)
                ->where('is_default', true)
                ->first();

            if (! $legacyAddress) {
                $legacyAddress = DB::connection('legacy')
                    ->table('addresses')
                    ->where('addressable_type', 'App\\Models\\Customer')
                    ->where('addressable_id', $legacyCustomer->id)
                    ->first();
            }

            // Generate a code from the name
            $code = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $name), 0, 6));

            // Use DB::table()->insertGetId() to preserve original timestamps
            // (Model::create() would override timestamps via Eloquent events)
            $newVendorId = DB::table('vendors')->insertGetId([
                'store_id' => $newStore->id,
                'name' => $name,
                'code' => $code ?: null,
                'company_name' => $legacyCustomer->company_name ?? null,
                'email' => $legacyCustomer->email ?? null,
                'phone' => $legacyCustomer->phone_number ?? null,
                'address_line1' => $legacyAddress?->address,
                'address_line2' => $legacyAddress?->address2,
                'city' => $legacyAddress?->city,
                'state' => $legacyAddress?->state,
                'postal_code' => $legacyAddress?->zip,
                'country' => 'US',
                'contact_name' => trim(($legacyCustomer->first_name ?? '').' '.($legacyCustomer->last_name ?? '')) ?: null,
                'is_active' => $legacyCustomer->is_active ?? true,
                'created_at' => $legacyCustomer->created_at ?? now(),
                'updated_at' => $legacyCustomer->updated_at ?? now(),
            ]);

            $this->vendorMap[$legacyCustomer->id] = $newVendorId;
            $count++;
        }

        $this->line("  Created {$count} vendors, skipped {$skipped} existing");
    }

    protected function displaySummary(Store $newStore): void
    {
        $this->newLine();
        $this->info('=== Vendor Migration Summary ===');
        $this->line('Store: '.$newStore->name.' (ID: '.$newStore->id.')');
        $this->line('Vendors mapped: '.count($this->vendorMap));

        $vendorCount = Vendor::where('store_id', $newStore->id)->count();
        $this->line("Total vendors in store: {$vendorCount}");
    }
}
