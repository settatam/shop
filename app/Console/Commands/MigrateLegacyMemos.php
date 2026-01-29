<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Models\Memo;
use App\Models\MemoItem;
use App\Models\Store;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Warehouse;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MigrateLegacyMemos extends Command
{
    protected $signature = 'migrate:legacy-memos
                            {--store-id=63 : Legacy store ID to migrate}
                            {--new-store-id= : New store ID (if different from legacy)}
                            {--limit=0 : Number of memos to migrate (0 for all)}
                            {--dry-run : Show what would be migrated without making changes}
                            {--fresh : Delete existing memos and start fresh}
                            {--with-invoices : Create invoices for migrated memos}';

    protected $description = 'Migrate memos and memo items from the legacy database';

    protected array $memoMap = [];

    protected array $vendorMap = [];

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

        $this->info("Starting memo migration from legacy store ID: {$legacyStoreId}");

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

        $this->info("Migrating memos to store: {$newStore->name} (ID: {$newStore->id})");

        // Load mapping files from previous migrations
        $this->loadMappingFiles($legacyStoreId);

        if ($this->option('fresh') && ! $isDryRun) {
            if ($this->confirm('This will delete all existing memos for this store. Continue?')) {
                $this->cleanupExistingMemos($newStore);
            }
        }

        try {
            DB::beginTransaction();

            // Build vendor mapping
            $this->buildVendorMapping($legacyStoreId, $newStore);

            // Build user mapping
            $this->buildUserMapping($legacyStoreId, $newStore);

            // Migrate memos
            $this->migrateMemos($legacyStoreId, $newStore, $isDryRun, $limit, $withInvoices);

            if ($isDryRun) {
                DB::rollBack();
                $this->info('Dry run complete - no changes made');
            } else {
                DB::commit();
                $this->info('Memo migration complete!');
            }

            $this->displaySummary($newStore);

            // Save mapping files
            if (! $isDryRun && count($this->memoMap) > 0) {
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

        // Save memo map
        $memoMapFile = "{$basePath}/memo_map_{$legacyStoreId}.json";
        file_put_contents($memoMapFile, json_encode($this->memoMap, JSON_PRETTY_PRINT));
        $this->line("  Memo map saved to: {$memoMapFile}");
    }

    protected function buildVendorMapping(int $legacyStoreId, Store $newStore): void
    {
        if (! empty($this->vendorMap)) {
            return;
        }

        $this->info('Building vendor mapping...');

        $legacyVendors = DB::connection('legacy')
            ->table('vendors')
            ->where('store_id', $legacyStoreId)

            ->get();

        $newVendors = Vendor::where('store_id', $newStore->id)->get();

        foreach ($legacyVendors as $legacy) {
            $legacyName = trim(($legacy->first_name ?? '').' '.($legacy->last_name ?? ''));
            if (empty($legacyName)) {
                $legacyName = $legacy->company;
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

    protected function migrateMemos(int $legacyStoreId, Store $newStore, bool $isDryRun, int $limit, bool $withInvoices): void
    {
        $this->info('Migrating memos...');

        $query = DB::connection('legacy')
            ->table('memos')
            ->where('store_id', $legacyStoreId)

            ->orderBy('id', 'asc');

        if ($limit > 0) {
            $query->limit($limit);
        }

        $legacyMemos = $query->get();
        $memoCount = 0;
        $itemCount = 0;
        $invoiceCount = 0;
        $skipped = 0;

        foreach ($legacyMemos as $legacyMemo) {
            // Check if memo already exists by invoice_number
            $existingMemo = null;
            if ($legacyMemo->invoice_number) {
                $existingMemo = Memo::where('store_id', $newStore->id)
                    ->where('memo_number', $legacyMemo->invoice_number)
                    ->first();
            }

            if ($existingMemo) {
                $this->memoMap[$legacyMemo->id] = $existingMemo->id;
                $skipped++;

                continue;
            }

            if ($isDryRun) {
                $this->line("  Would create memo: {$legacyMemo->invoice_number} (\${$legacyMemo->total})");
                $memoCount++;

                continue;
            }

            // Map vendor
            $vendorId = null;
            if ($legacyMemo->vendor_id && isset($this->vendorMap[$legacyMemo->vendor_id])) {
                $vendorId = $this->vendorMap[$legacyMemo->vendor_id];
            }

            // Map user
            $userId = null;
            if ($legacyMemo->user_id && isset($this->userMap[$legacyMemo->user_id])) {
                $userId = $this->userMap[$legacyMemo->user_id];
            }

            // Map status
            $status = $this->mapMemoStatus($legacyMemo->status);

            $newMemo = Memo::create([
                'store_id' => $newStore->id,
                'warehouse_id' => $this->warehouse?->id,
                'vendor_id' => $vendorId,
                'user_id' => $userId,
                'memo_number' => $legacyMemo->invoice_number,
                'status' => $status,
                'tenure' => $legacyMemo->tenure ?? $legacyMemo->tenor ?? 30,
                'subtotal' => $legacyMemo->subtotal ?? 0,
                'tax' => $legacyMemo->tax ?? 0,
                'tax_rate' => $legacyMemo->tax_rate ?? 0,
                'charge_taxes' => (bool) $legacyMemo->charge_taxes,
                'shipping_cost' => $legacyMemo->shipping_cost ?? 0,
                'total' => $legacyMemo->total ?? 0,
                'description' => $legacyMemo->description,
                'duration' => $legacyMemo->duration ?? 30,
                'created_at' => $legacyMemo->created_at,
                'updated_at' => $legacyMemo->updated_at,
            ]);

            $this->memoMap[$legacyMemo->id] = $newMemo->id;
            $memoCount++;

            // Find products that belong to this memo (via consignment_id)
            $legacyProducts = DB::connection('legacy')
                ->table('products')
                ->where('store_id', $legacyStoreId)
                ->where('consignment_id', $legacyMemo->id)

                ->get();

            foreach ($legacyProducts as $legacyProduct) {
                // Map product
                $productId = null;
                if (isset($this->productMap[$legacyProduct->id])) {
                    $productId = $this->productMap[$legacyProduct->id];
                }

                MemoItem::create([
                    'memo_id' => $newMemo->id,
                    'product_id' => $productId,
                    'sku' => $legacyProduct->sku,
                    'title' => $legacyProduct->title ?? $legacyProduct->product_name ?? 'Item',
                    'description' => $legacyProduct->description,
                    'price' => $legacyProduct->consignment_price ?? $legacyProduct->price ?? 0,
                    'cost' => $legacyProduct->cost_per_item,
                    'tenor' => $legacyProduct->consignment_tenor ?? $legacyMemo->tenure ?? 30,
                    'is_returned' => false,
                    'charge_taxes' => (bool) $legacyProduct->charge_taxes,
                    'created_at' => $legacyProduct->date_consigned ?? $legacyMemo->created_at,
                    'updated_at' => $legacyProduct->updated_at,
                ]);

                $itemCount++;
            }

            // Recalculate totals
            $newMemo->calculateTotals();

            // Create invoice if requested
            if ($withInvoices && $vendorId) {
                $vendor = Vendor::find($vendorId);
                if ($vendor) {
                    Invoice::create([
                        'store_id' => $newStore->id,
                        'invoiceable_type' => Memo::class,
                        'invoiceable_id' => $newMemo->id,
                        'invoice_number' => $legacyMemo->invoice_number ?? $newMemo->memo_number,
                        'type' => 'memo',
                        'status' => $this->mapInvoiceStatus($status),
                        'due_date' => $newMemo->due_date,
                        'subtotal' => $newMemo->subtotal,
                        'tax_amount' => $newMemo->tax,
                        'total_amount' => $newMemo->total,
                        'total_paid' => $status === Memo::STATUS_PAYMENT_RECEIVED ? $newMemo->total : 0,
                        'balance_due' => $status === Memo::STATUS_PAYMENT_RECEIVED ? 0 : $newMemo->total,
                        'store_name' => $newStore->business_name ?? $newStore->name,
                        'store_address' => $newStore->address,
                        'store_city' => $newStore->city,
                        'store_state' => $newStore->state,
                        'store_zip' => $newStore->zip,
                        'created_at' => $legacyMemo->created_at,
                        'updated_at' => $legacyMemo->updated_at,
                    ]);
                    $invoiceCount++;
                }
            }

            if ($memoCount % 25 === 0) {
                $this->line("  Processed {$memoCount} memos...");
            }
        }

        $this->line("  Created {$memoCount} memos with {$itemCount} items, {$invoiceCount} invoices, skipped {$skipped} existing");
    }

    protected function mapMemoStatus(?string $legacyStatus): string
    {
        if (! $legacyStatus) {
            return Memo::STATUS_PENDING;
        }

        return match (strtolower($legacyStatus)) {
            'pending', 'draft' => Memo::STATUS_PENDING,
            'sent_to_vendor', 'sent to vendor', 'shipped' => Memo::STATUS_SENT_TO_VENDOR,
            'vendor_received', 'vendor received', 'received' => Memo::STATUS_VENDOR_RECEIVED,
            'vendor_returned', 'vendor returned', 'returned' => Memo::STATUS_VENDOR_RETURNED,
            'payment_received', 'payment received', 'paid', 'payment processed' => Memo::STATUS_PAYMENT_RECEIVED,
            'archived', 'closed' => Memo::STATUS_ARCHIVED,
            'cancelled', 'canceled', 'voided' => Memo::STATUS_CANCELLED,
            default => Memo::STATUS_PENDING,
        };
    }

    protected function mapInvoiceStatus(string $memoStatus): string
    {
        return match ($memoStatus) {
            Memo::STATUS_PAYMENT_RECEIVED => Invoice::STATUS_PAID,
            Memo::STATUS_CANCELLED => Invoice::STATUS_VOID,
            default => Invoice::STATUS_PENDING,
        };
    }

    protected function cleanupExistingMemos(Store $newStore): void
    {
        $this->warn('Cleaning up existing memos...');

        $memoIds = Memo::where('store_id', $newStore->id)->pluck('id');

        Invoice::where('invoiceable_type', Memo::class)->whereIn('invoiceable_id', $memoIds)->forceDelete();
        MemoItem::whereIn('memo_id', $memoIds)->forceDelete();
        Memo::where('store_id', $newStore->id)->forceDelete();

        $this->line('  Cleanup complete');
    }

    protected function displaySummary(Store $newStore): void
    {
        $this->newLine();
        $this->info('=== Memo Migration Summary ===');
        $this->line('Store: '.$newStore->name.' (ID: '.$newStore->id.')');
        $this->line('Memos mapped: '.count($this->memoMap));

        $memoCount = Memo::where('store_id', $newStore->id)->count();
        $itemCount = MemoItem::whereIn('memo_id', Memo::where('store_id', $newStore->id)->pluck('id'))->count();
        $this->line("Total memos in store: {$memoCount}");
        $this->line("Total memo items in store: {$itemCount}");
    }
}
