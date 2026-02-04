<?php

namespace App\Console\Commands;

use App\Models\Inventory;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\Warehouse;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PopulateInventoryFromLegacy extends Command
{
    protected $signature = 'inventory:populate-from-legacy
                            {--store-id= : The new store ID}
                            {--legacy-store-id=63 : The legacy store ID}
                            {--dry-run : Show what would be created without making changes}';

    protected $description = 'Populate inventory records from legacy product_variant_location_quantities table';

    public function handle(): int
    {
        $storeId = $this->option('store-id');
        $legacyStoreId = $this->option('legacy-store-id');
        $dryRun = $this->option('dry-run');

        if (! $storeId) {
            $this->error('Please provide a store ID with --store-id');

            return self::FAILURE;
        }

        $store = Store::find($storeId);
        if (! $store) {
            $this->error("Store with ID {$storeId} not found");

            return self::FAILURE;
        }

        if ($dryRun) {
            $this->warn('DRY RUN - No records will be created');
        }

        $this->info("Populating inventory for store: {$store->name}");

        // Get legacy store location
        $legacyLocation = DB::connection('legacy')
            ->table('store_locations')
            ->where('store_id', $legacyStoreId)
            ->first();

        if (! $legacyLocation) {
            $this->error("No store location found for legacy store {$legacyStoreId}");

            return self::FAILURE;
        }

        $this->info("Using legacy store location: {$legacyLocation->name} (ID: {$legacyLocation->id})");

        // Get default warehouse
        $warehouse = Warehouse::where('store_id', $storeId)
            ->where('is_default', true)
            ->first() ?? Warehouse::where('store_id', $storeId)->first();

        if (! $warehouse) {
            $this->error('No warehouse found for this store');

            return self::FAILURE;
        }

        $this->info("Using warehouse: {$warehouse->name}");

        // Load variant map from migration
        $variantMapPath = storage_path("app/migration_maps/variant_map_{$legacyStoreId}.json");
        if (! file_exists($variantMapPath)) {
            $this->error("Variant map not found at {$variantMapPath}. Run migrate:legacy-products first.");

            return self::FAILURE;
        }

        $variantMap = json_decode(file_get_contents($variantMapPath), true);
        $this->info('Loaded '.count($variantMap).' variant mappings');

        // Get all location quantities for this store
        $locationQuantities = DB::connection('legacy')
            ->table('product_variant_location_quantities')
            ->where('store_location_id', $legacyLocation->id)
            ->whereNull('deleted_at')
            ->get()
            ->keyBy('product_variant_id');

        $this->info("Found {$locationQuantities->count()} location quantity records");

        $created = 0;
        $skipped = 0;
        $noQuantity = 0;

        $this->output->progressStart(count($variantMap));

        foreach ($variantMap as $legacyVariantId => $newVariantId) {
            $this->output->progressAdvance();

            // Check if inventory already exists
            $existing = Inventory::where('product_variant_id', $newVariantId)
                ->where('warehouse_id', $warehouse->id)
                ->exists();

            if ($existing) {
                $skipped++;

                continue;
            }

            // Get quantity from legacy locations
            $locationQty = $locationQuantities->get($legacyVariantId);
            $quantity = $locationQty?->quantity ?? 0;

            if ($quantity <= 0) {
                $noQuantity++;

                continue;
            }

            // Get variant for cost and created_at
            $variant = ProductVariant::find($newVariantId);
            if (! $variant) {
                continue;
            }

            if (! $dryRun) {
                Inventory::create([
                    'store_id' => $storeId,
                    'product_variant_id' => $newVariantId,
                    'warehouse_id' => $warehouse->id,
                    'quantity' => $quantity,
                    'reserved_quantity' => 0,
                    'incoming_quantity' => 0,
                    'safety_stock' => 0,
                    'unit_cost' => $variant->cost ?? 0,
                ]);
            }

            $created++;
        }

        $this->output->progressFinish();

        $this->newLine();
        $this->info('=== Summary ===');
        $this->info("Created: {$created} inventory records");
        $this->info("Skipped (already exists): {$skipped}");
        $this->info("No quantity in legacy: {$noQuantity}");

        if ($dryRun) {
            $this->warn('DRY RUN - No records were created');
        }

        return self::SUCCESS;
    }
}
