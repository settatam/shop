<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Repair;
use App\Models\RepairItem;
use App\Models\Store;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Warehouse;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MigrateLegacyRepairs extends Command
{
    protected $signature = 'migrate:legacy-repairs
                            {--store-id=63 : Legacy store ID to migrate}
                            {--new-store-id= : New store ID (if different from legacy)}
                            {--limit=0 : Number of repairs to migrate (0 for all)}
                            {--dry-run : Show what would be migrated without making changes}
                            {--fresh : Delete existing repairs and start fresh}
                            {--with-invoices : Create invoices for migrated repairs}';

    protected $description = 'Migrate repairs and repair items from the legacy database';

    protected array $repairMap = [];

    protected array $vendorMap = [];

    protected array $customerMap = [];

    protected array $userMap = [];

    protected array $productMap = [];

    protected ?Warehouse $warehouse = null;

    public function handle(): int
    {
        $legacyStoreId = (int) $this->option('store-id');
        $newStoreId = $this->option('new-store-id') ? (int) $this->option('new-store-id') : null;
        $limit = (int) $this->option('limit');
        $isDryRun = $this->option('dry-run');
        $withInvoices = $this->option('with-invoices');

        $this->info("Starting repair migration from legacy store ID: {$legacyStoreId}");

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

        // Get default warehouse
        $this->warehouse = Warehouse::where('store_id', $newStore->id)->where('is_default', true)->first();

        $this->info("Migrating repairs to store: {$newStore->name} (ID: {$newStore->id})");

        // Load mapping files from previous migrations
        $this->loadMappingFiles($legacyStoreId);

        if ($this->option('fresh') && ! $isDryRun) {
            if ($this->confirm('This will delete all existing repairs for this store. Continue?')) {
                $this->cleanupExistingRepairs($newStore);
            }
        }

        try {
            DB::beginTransaction();

            // Build mappings
            $this->buildVendorMapping($legacyStoreId, $newStore);
            $this->buildCustomerMapping($legacyStoreId, $newStore);
            $this->buildUserMapping($legacyStoreId, $newStore);

            // Migrate repairs
            $this->migrateRepairs($legacyStoreId, $newStore, $isDryRun, $limit, $withInvoices);

            if ($isDryRun) {
                DB::rollBack();
                $this->info('Dry run complete - no changes made');
            } else {
                DB::commit();
                $this->info('Repair migration complete!');
            }

            $this->displaySummary($newStore);

            // Save mapping files
            if (! $isDryRun && count($this->repairMap) > 0) {
                $this->saveMappingFiles($legacyStoreId);
            }

            return 0;
        } catch (\Exception $e) {
            DB::rollBack();
            $this->error("Migration failed: {$e->getMessage()}");
            $this->error($e->getTraceAsString());

            return 1;
        }
    }

    protected function loadMappingFiles(int $legacyStoreId): void
    {
        $basePath = storage_path('app/migration_maps');

        // Load vendor map
        $vendorMapFile = "{$basePath}/vendor_map_{$legacyStoreId}.json";
        if (file_exists($vendorMapFile)) {
            $this->vendorMap = json_decode(file_get_contents($vendorMapFile), true) ?? [];
            $this->line('  Loaded '.count($this->vendorMap).' vendor mappings');
        }

        // Load customer map
        $customerMapFile = "{$basePath}/customer_map_{$legacyStoreId}.json";
        if (file_exists($customerMapFile)) {
            $this->customerMap = json_decode(file_get_contents($customerMapFile), true) ?? [];
            $this->line('  Loaded '.count($this->customerMap).' customer mappings');
        }

        // Load user map
        $userMapFile = "{$basePath}/user_map_{$legacyStoreId}.json";
        if (file_exists($userMapFile)) {
            $this->userMap = json_decode(file_get_contents($userMapFile), true) ?? [];
            $this->line('  Loaded '.count($this->userMap).' user mappings');
        }

        // Load product map
        $productMapFile = "{$basePath}/product_map_{$legacyStoreId}.json";
        if (file_exists($productMapFile)) {
            $this->productMap = json_decode(file_get_contents($productMapFile), true) ?? [];
            $this->line('  Loaded '.count($this->productMap).' product mappings');
        }
    }

    protected function saveMappingFiles(int $legacyStoreId): void
    {
        $basePath = storage_path('app/migration_maps');
        if (! is_dir($basePath)) {
            mkdir($basePath, 0755, true);
        }

        // Save repair map
        $repairMapFile = "{$basePath}/repair_map_{$legacyStoreId}.json";
        file_put_contents($repairMapFile, json_encode($this->repairMap, JSON_PRETTY_PRINT));
        $this->line("  Repair map saved to: {$repairMapFile}");
    }

    protected function buildVendorMapping(int $legacyStoreId, Store $newStore): void
    {
        if (! empty($this->vendorMap)) {
            return;
        }

        $this->info('Building vendor mapping...');

        // Get legacy vendors from customers table (where is_vendor = 1)
        $legacyVendors = DB::connection('legacy')
            ->table('customers')
            ->where('store_id', $legacyStoreId)
            ->where('is_vendor', true)
            ->whereNull('deleted_at')
            ->get();

        $newVendors = Vendor::where('store_id', $newStore->id)->get();

        foreach ($legacyVendors as $legacy) {
            $legacyName = trim(($legacy->first_name ?? '').' '.($legacy->last_name ?? ''));
            if (empty($legacyName)) {
                $legacyName = $legacy->company_name ?? '';
            }

            foreach ($newVendors as $new) {
                if (strtolower($new->name) === strtolower($legacyName) ||
                    ($legacy->email && strtolower($new->email) === strtolower($legacy->email))) {
                    $this->vendorMap[$legacy->id] = $new->id;
                    break;
                }
            }
        }

        $this->line('  Mapped '.count($this->vendorMap).' vendors');
    }

    protected function buildCustomerMapping(int $legacyStoreId, Store $newStore): void
    {
        if (! empty($this->customerMap)) {
            return;
        }

        $this->info('Building customer mapping (ID-preserving mode)...');

        // Since we preserve customer IDs during migration, just verify which exist
        $count = 0;
        DB::connection('legacy')
            ->table('customers')
            ->where('store_id', $legacyStoreId)
            ->select('id')
            ->orderBy('id')
            ->chunk(1000, function ($legacyCustomers) use (&$count) {
                foreach ($legacyCustomers as $legacy) {
                    if (Customer::where('id', $legacy->id)->exists()) {
                        $this->customerMap[$legacy->id] = $legacy->id;
                        $count++;
                    }
                }
            });

        $this->line("  Mapped {$count} customers (ID-preserved)");
    }

    protected function buildUserMapping(int $legacyStoreId, Store $newStore): void
    {
        if (! empty($this->userMap)) {
            return;
        }

        $this->info('Building user mapping...');

        $legacyStoreUsers = DB::connection('legacy')
            ->table('store_users')
            ->where('store_id', $legacyStoreId)
            ->get();

        foreach ($legacyStoreUsers as $legacyStoreUser) {
            $legacyUser = DB::connection('legacy')
                ->table('users')
                ->where('id', $legacyStoreUser->user_id)
                ->first();

            if ($legacyUser) {
                $newUser = User::where('email', $legacyUser->email)->first();
                if ($newUser) {
                    $this->userMap[$legacyStoreUser->user_id] = $newUser->id;
                }
            }
        }

        $this->line('  Mapped '.count($this->userMap).' users');
    }

    protected function migrateRepairs(int $legacyStoreId, Store $newStore, bool $isDryRun, int $limit, bool $withInvoices): void
    {
        $this->info('Migrating repairs...');

        $query = DB::connection('legacy')
            ->table('repairs')
            ->where('store_id', $legacyStoreId)
            ->whereNotNull('customer_id') // Skip repairs without customers
            ->orderBy('id', 'asc');

        if ($limit > 0) {
            $query->limit($limit);
        }

        $legacyRepairs = $query->get();
        $repairCount = 0;
        $itemCount = 0;
        $invoiceCount = 0;
        $skipped = 0;

        foreach ($legacyRepairs as $legacyRepair) {
            // Check if repair already exists by invoice_number
            $existingRepair = null;
            if ($legacyRepair->invoice_number) {
                $existingRepair = Repair::where('store_id', $newStore->id)
                    ->where('repair_number', $legacyRepair->invoice_number)
                    ->first();
            }

            if ($existingRepair) {
                $this->repairMap[$legacyRepair->id] = $existingRepair->id;
                $skipped++;

                continue;
            }

            if ($isDryRun) {
                $this->line("  Would create repair: {$legacyRepair->invoice_number} (\${$legacyRepair->total})");
                $repairCount++;

                continue;
            }

            // Map vendor
            $vendorId = null;
            if ($legacyRepair->vendor_id && isset($this->vendorMap[$legacyRepair->vendor_id])) {
                $vendorId = $this->vendorMap[$legacyRepair->vendor_id];
            }

            // Map customer
            $customerId = null;
            if ($legacyRepair->customer_id && isset($this->customerMap[$legacyRepair->customer_id])) {
                $customerId = $this->customerMap[$legacyRepair->customer_id];
            }

            // Map user
            $userId = null;
            if ($legacyRepair->user_id && isset($this->userMap[$legacyRepair->user_id])) {
                $userId = $this->userMap[$legacyRepair->user_id];
            }

            // Map status
            $status = $this->mapRepairStatus($legacyRepair->status);

            // Check if repair with this ID already exists
            $existingRepair = Repair::withTrashed()->find($legacyRepair->id);
            if ($existingRepair) {
                $this->repairMap[$legacyRepair->id] = $existingRepair->id;
                $skipped++;

                continue;
            }

            // Use DB::table to preserve timestamps and original ID
            DB::table('repairs')->insert([
                'id' => $legacyRepair->id, // Preserve original ID
                'store_id' => $newStore->id,
                'warehouse_id' => $this->warehouse?->id,
                'customer_id' => $customerId,
                'vendor_id' => $vendorId,
                'user_id' => $userId,
                'repair_number' => $legacyRepair->invoice_number,
                'status' => $status,
                'service_fee' => $legacyRepair->service_fee ?? 0,
                'subtotal' => $legacyRepair->sub_total ?? 0,
                'tax' => $legacyRepair->sales_tax ?? 0,
                'tax_rate' => $legacyRepair->tax_rate ?? 0,
                'discount' => $legacyRepair->discount ?? 0,
                'shipping_cost' => $legacyRepair->shipping_cost ?? 0,
                'total' => $legacyRepair->total ?? 0,
                'description' => $legacyRepair->description,
                'repair_days' => $legacyRepair->repair_days ?? 7,
                'is_appraisal' => (bool) $legacyRepair->is_appraisal,
                'date_sent_to_vendor' => $legacyRepair->vendor_sent_date_time ?? $legacyRepair->date_sent_by_vendor,
                'date_received_by_vendor' => $legacyRepair->vendor_received_date_time ?? $legacyRepair->date_received_by_vendor,
                'created_at' => $legacyRepair->created_at,
                'updated_at' => $legacyRepair->updated_at,
            ]);

            $newRepair = Repair::find($legacyRepair->id);
            $this->repairMap[$legacyRepair->id] = $legacyRepair->id;
            $repairCount++;

            // Check if there's a repair_items table in legacy
            // If not, repairs might be linked to products differently
            $hasRepairItems = DB::connection('legacy')->getSchemaBuilder()->hasTable('repair_items');

            if ($hasRepairItems) {
                $legacyItems = DB::connection('legacy')
                    ->table('repair_items')
                    ->where('repair_id', $legacyRepair->id)
                    ->get();

                foreach ($legacyItems as $legacyItem) {
                    // Map product
                    $productId = null;
                    if (isset($legacyItem->product_id) && isset($this->productMap[$legacyItem->product_id])) {
                        $productId = $this->productMap[$legacyItem->product_id];
                    }

                    // Use DB::table to preserve timestamps and original ID
                    DB::table('repair_items')->insert([
                        'id' => $legacyItem->id, // Preserve original ID
                        'repair_id' => $newRepair->id,
                        'product_id' => $productId,
                        'sku' => $legacyItem->sku ?? null,
                        'title' => $legacyItem->title ?? $legacyItem->description ?? 'Repair Item',
                        'description' => $legacyItem->description,
                        'vendor_cost' => $legacyItem->vendor_cost ?? $legacyItem->cost ?? 0,
                        'customer_cost' => $legacyItem->customer_cost ?? $legacyItem->price ?? 0,
                        'created_at' => $legacyItem->created_at ?? $legacyRepair->created_at,
                        'updated_at' => $legacyItem->updated_at ?? $legacyRepair->updated_at,
                    ]);

                    $itemCount++;
                }
            }

            // Recalculate totals if there are items
            if ($newRepair->items()->exists()) {
                $newRepair->calculateTotals();
            }

            // Create invoice if requested
            if ($withInvoices && $customerId) {
                $customer = Customer::find($customerId);
                if ($customer) {
                    Invoice::create([
                        'store_id' => $newStore->id,
                        'invoiceable_type' => Repair::class,
                        'invoiceable_id' => $newRepair->id,
                        'customer_id' => $customerId,
                        'invoice_number' => $legacyRepair->invoice_number ?? $newRepair->repair_number,
                        'type' => 'repair',
                        'status' => $this->mapInvoiceStatus($status),
                        'due_date' => now()->addDays(30),
                        'subtotal' => $newRepair->subtotal,
                        'tax_amount' => $newRepair->tax,
                        'total_amount' => $newRepair->total,
                        'total_paid' => $status === Repair::STATUS_PAYMENT_RECEIVED ? $newRepair->total : 0,
                        'balance_due' => $status === Repair::STATUS_PAYMENT_RECEIVED ? 0 : $newRepair->total,
                        'customer_name' => $customer->full_name,
                        'customer_email' => $customer->email,
                        'store_name' => $newStore->business_name ?? $newStore->name,
                        'store_address' => $newStore->address,
                        'store_city' => $newStore->city,
                        'store_state' => $newStore->state,
                        'store_zip' => $newStore->zip,
                        'created_at' => $legacyRepair->created_at,
                        'updated_at' => $legacyRepair->updated_at,
                    ]);
                    $invoiceCount++;
                }
            }

            if ($repairCount % 25 === 0) {
                $this->line("  Processed {$repairCount} repairs...");
            }
        }

        $this->line("  Created {$repairCount} repairs with {$itemCount} items, {$invoiceCount} invoices, skipped {$skipped} existing");
    }

    protected function mapRepairStatus(?string $legacyStatus): string
    {
        if (! $legacyStatus) {
            return Repair::STATUS_PENDING;
        }

        return match (strtolower($legacyStatus)) {
            'pending', 'draft' => Repair::STATUS_PENDING,
            'sent_to_vendor', 'sent to vendor', 'shipped' => Repair::STATUS_SENT_TO_VENDOR,
            'received_by_vendor', 'received by vendor', 'received' => Repair::STATUS_RECEIVED_BY_VENDOR,
            'completed', 'complete', 'completed / received' => Repair::STATUS_COMPLETED,
            'payment_received', 'payment received', 'paid' => Repair::STATUS_PAYMENT_RECEIVED,
            'refunded' => Repair::STATUS_REFUNDED,
            'cancelled', 'canceled', 'voided' => Repair::STATUS_CANCELLED,
            'archived', 'closed' => Repair::STATUS_ARCHIVED,
            default => Repair::STATUS_PENDING,
        };
    }

    protected function mapInvoiceStatus(string $repairStatus): string
    {
        return match ($repairStatus) {
            Repair::STATUS_PAYMENT_RECEIVED => Invoice::STATUS_PAID,
            Repair::STATUS_CANCELLED => Invoice::STATUS_VOID,
            Repair::STATUS_REFUNDED => Invoice::STATUS_REFUNDED,
            default => Invoice::STATUS_PENDING,
        };
    }

    protected function cleanupExistingRepairs(Store $newStore): void
    {
        $this->warn('Cleaning up existing repairs...');

        $repairIds = Repair::where('store_id', $newStore->id)->pluck('id');

        Invoice::where('invoiceable_type', Repair::class)->whereIn('invoiceable_id', $repairIds)->forceDelete();
        RepairItem::whereIn('repair_id', $repairIds)->forceDelete();
        Repair::where('store_id', $newStore->id)->forceDelete();

        $this->line('  Cleanup complete');
    }

    protected function displaySummary(Store $newStore): void
    {
        $this->newLine();
        $this->info('=== Repair Migration Summary ===');
        $this->line('Store: '.$newStore->name.' (ID: '.$newStore->id.')');
        $this->line('Repairs mapped: '.count($this->repairMap));

        $repairCount = Repair::where('store_id', $newStore->id)->count();
        $itemCount = RepairItem::whereIn('repair_id', Repair::where('store_id', $newStore->id)->pluck('id'))->count();
        $this->line("Total repairs in store: {$repairCount}");
        $this->line("Total repair items in store: {$itemCount}");
    }
}
