<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MigrateLegacyOrders extends Command
{
    protected $signature = 'migrate:legacy-orders
                            {--store-id=63 : Legacy store ID to migrate}
                            {--new-store-id= : New store ID (if different from legacy)}
                            {--limit=0 : Number of orders to migrate (0 for all)}
                            {--dry-run : Show what would be migrated without making changes}
                            {--fresh : Delete existing orders and start fresh}
                            {--with-invoices : Create invoices for migrated orders}';

    protected $description = 'Migrate orders and order items from the legacy database';

    protected array $orderMap = [];

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

        $this->info("Starting order migration from legacy store ID: {$legacyStoreId}");

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

        $this->info("Migrating orders to store: {$newStore->name} (ID: {$newStore->id})");

        // Load mapping files from previous migrations
        $this->loadMappingFiles($legacyStoreId);

        if ($this->option('fresh') && ! $isDryRun) {
            if ($this->confirm('This will delete all existing orders for this store. Continue?')) {
                $this->cleanupExistingOrders($newStore);
            }
        }

        try {
            DB::beginTransaction();

            // Build customer mapping
            $this->buildCustomerMapping($legacyStoreId, $newStore);

            // Build user mapping
            $this->buildUserMapping($legacyStoreId, $newStore);

            // Migrate orders
            $this->migrateOrders($legacyStoreId, $newStore, $isDryRun, $limit, $withInvoices);

            if ($isDryRun) {
                DB::rollBack();
                $this->info('Dry run complete - no changes made');
            } else {
                DB::commit();
                $this->info('Order migration complete!');
            }

            $this->displaySummary($newStore);

            // Save mapping files
            if (! $isDryRun && count($this->orderMap) > 0) {
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

        // Save order map
        $orderMapFile = "{$basePath}/order_map_{$legacyStoreId}.json";
        file_put_contents($orderMapFile, json_encode($this->orderMap, JSON_PRETTY_PRINT));
        $this->line("  Order map saved to: {$orderMapFile}");
    }

    protected function buildCustomerMapping(int $legacyStoreId, Store $newStore): void
    {
        if (! empty($this->customerMap)) {
            return;
        }

        $this->info('Building customer mapping...');

        $legacyCustomers = DB::connection('legacy')
            ->table('customers')
            ->where('store_id', $legacyStoreId)

            ->get();

        $newCustomers = Customer::where('store_id', $newStore->id)->get();
        $newCustomersByEmail = $newCustomers->filter(fn ($c) => $c->email)->keyBy(fn ($c) => strtolower($c->email));

        foreach ($legacyCustomers as $legacy) {
            if ($legacy->email && $newCustomersByEmail->has(strtolower($legacy->email))) {
                $this->customerMap[$legacy->id] = $newCustomersByEmail->get(strtolower($legacy->email))->id;
            }
        }

        $this->line('  Mapped '.count($this->customerMap).' customers');
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

    protected function migrateOrders(int $legacyStoreId, Store $newStore, bool $isDryRun, int $limit, bool $withInvoices): void
    {
        $this->info('Migrating orders...');

        $query = DB::connection('legacy')
            ->table('orders')
            ->where('store_id', $legacyStoreId)

            ->orderBy('id', 'asc');

        if ($limit > 0) {
            $query->limit($limit);
        }

        $legacyOrders = $query->get();
        $orderCount = 0;
        $itemCount = 0;
        $invoiceCount = 0;
        $skipped = 0;

        foreach ($legacyOrders as $legacyOrder) {
            // Check if order already exists by invoice_number
            $existingOrder = null;
            if ($legacyOrder->invoice_number) {
                $existingOrder = Order::where('store_id', $newStore->id)
                    ->where('invoice_number', $legacyOrder->invoice_number)
                    ->first();
            }

            if ($existingOrder) {
                $this->orderMap[$legacyOrder->id] = $existingOrder->id;
                $skipped++;

                continue;
            }

            if ($isDryRun) {
                $this->line("  Would create order: {$legacyOrder->invoice_number} (\${$legacyOrder->total})");
                $orderCount++;

                continue;
            }

            // Map customer
            $customerId = null;
            if ($legacyOrder->customer_id && isset($this->customerMap[$legacyOrder->customer_id])) {
                $customerId = $this->customerMap[$legacyOrder->customer_id];
            }

            // Map user
            $userId = null;
            if ($legacyOrder->user_id && isset($this->userMap[$legacyOrder->user_id])) {
                $userId = $this->userMap[$legacyOrder->user_id];
            }

            // Map status
            $status = $this->mapOrderStatus($legacyOrder->status);

            $newOrder = Order::create([
                'store_id' => $newStore->id,
                'customer_id' => $customerId,
                'user_id' => $userId,
                'warehouse_id' => $this->warehouse?->id,
                'total' => $legacyOrder->total ?? 0,
                'sub_total' => $legacyOrder->sub_total ?? 0,
                'status' => $status,
                'sales_tax' => $legacyOrder->sales_tax ?? 0,
                'tax_rate' => $legacyOrder->tax_rate ?? 0,
                'shipping_cost' => $legacyOrder->shipping_cost ?? 0,
                'discount_cost' => $legacyOrder->discount_cost ?? 0,
                'service_fee_value' => $legacyOrder->service_fee_value ?? 0,
                'service_fee_unit' => $this->mapServiceFeeUnit($legacyOrder->service_fee_unit),
                'service_fee_reason' => $legacyOrder->service_fee_reason ?? '',
                'invoice_number' => $legacyOrder->invoice_number,
                'order_id' => $legacyOrder->order_id,
                'external_marketplace_id' => $legacyOrder->external_marketplace_id,
                'square_order_id' => $legacyOrder->square_order_id,
                'date_of_purchase' => $legacyOrder->date_of_purchase,
                'notes' => $legacyOrder->customer_note,
                'created_at' => $legacyOrder->created_at,
                'updated_at' => $legacyOrder->updated_at,
            ]);

            $this->orderMap[$legacyOrder->id] = $newOrder->id;
            $orderCount++;

            // Migrate order items
            $legacyItems = DB::connection('legacy')
                ->table('order_items')
                ->where('order_id', $legacyOrder->id)

                ->get();

            foreach ($legacyItems as $legacyItem) {
                // Map product
                $productId = null;
                if ($legacyItem->product_id && isset($this->productMap[$legacyItem->product_id])) {
                    $productId = $this->productMap[$legacyItem->product_id];
                }

                OrderItem::create([
                    'order_id' => $newOrder->id,
                    'product_id' => $productId,
                    'sku' => $legacyItem->sku,
                    'title' => $legacyItem->title ?? 'Item',
                    'quantity' => $legacyItem->quantity ?? 1,
                    'price' => $legacyItem->price ?? 0,
                    'cost' => $legacyItem->cost_per_item,
                    'discount' => $legacyItem->discount ?? 0,
                    'tax' => $legacyItem->sales_tax ?? 0,
                    'created_at' => $legacyItem->created_at,
                    'updated_at' => $legacyItem->updated_at,
                ]);

                $itemCount++;
            }

            // Create invoice if requested
            if ($withInvoices && $customerId) {
                $customer = Customer::find($customerId);
                if ($customer) {
                    Invoice::create([
                        'store_id' => $newStore->id,
                        'invoiceable_type' => Order::class,
                        'invoiceable_id' => $newOrder->id,
                        'customer_id' => $customerId,
                        'invoice_number' => $legacyOrder->invoice_number ?: ('INV-'.strtoupper(substr(md5($newOrder->id), 0, 8))),
                        'type' => 'sale',
                        'status' => $this->mapInvoiceStatus($status),
                        'due_date' => now()->addDays(30),
                        'subtotal' => $newOrder->sub_total ?? 0,
                        'tax_amount' => $newOrder->sales_tax ?? 0,
                        'discount_amount' => $newOrder->discount_cost ?? 0,
                        'total_amount' => $newOrder->total ?? 0,
                        'total_paid' => in_array($status, Order::PAID_STATUSES) ? ($newOrder->total ?? 0) : 0,
                        'balance_due' => in_array($status, Order::PAID_STATUSES) ? 0 : ($newOrder->total ?? 0),
                        'customer_name' => $customer->full_name ?? '',
                        'customer_email' => $customer->email ?? '',
                        'store_name' => $newStore->business_name ?? $newStore->name ?? '',
                        'store_address' => $newStore->address ?? '',
                        'store_city' => $newStore->city ?? '',
                        'store_state' => $newStore->state ?? '',
                        'store_zip' => $newStore->zip ?? '',
                        'created_at' => $legacyOrder->created_at,
                        'updated_at' => $legacyOrder->updated_at,
                    ]);
                    $invoiceCount++;
                }
            }

            if ($orderCount % 50 === 0) {
                $this->line("  Processed {$orderCount} orders...");
            }
        }

        $this->line("  Created {$orderCount} orders with {$itemCount} items, {$invoiceCount} invoices, skipped {$skipped} existing");
    }

    protected function mapOrderStatus(?string $legacyStatus): string
    {
        if (! $legacyStatus) {
            return Order::STATUS_PENDING;
        }

        return match (strtolower($legacyStatus)) {
            'draft' => Order::STATUS_DRAFT,
            'pending', 'awaiting_payment' => Order::STATUS_PENDING,
            'confirmed', 'paid', 'payment_received' => Order::STATUS_CONFIRMED,
            'processing', 'in_progress' => Order::STATUS_PROCESSING,
            'shipped', 'in_transit' => Order::STATUS_SHIPPED,
            'delivered' => Order::STATUS_DELIVERED,
            'completed', 'complete', 'fulfilled' => Order::STATUS_COMPLETED,
            'cancelled', 'canceled', 'voided' => Order::STATUS_CANCELLED,
            'refunded' => Order::STATUS_REFUNDED,
            'partial', 'partial_payment' => Order::STATUS_PARTIAL_PAYMENT,
            default => Order::STATUS_PENDING,
        };
    }

    protected function mapInvoiceStatus(string $orderStatus): string
    {
        return match ($orderStatus) {
            Order::STATUS_CONFIRMED, Order::STATUS_PROCESSING, Order::STATUS_SHIPPED,
            Order::STATUS_DELIVERED, Order::STATUS_COMPLETED => Invoice::STATUS_PAID,
            Order::STATUS_PARTIAL_PAYMENT => Invoice::STATUS_PARTIAL,
            Order::STATUS_CANCELLED => Invoice::STATUS_VOID,
            Order::STATUS_REFUNDED => Invoice::STATUS_REFUNDED,
            default => Invoice::STATUS_PENDING,
        };
    }

    protected function mapServiceFeeUnit(?string $legacyUnit): string
    {
        if (! $legacyUnit) {
            return 'fixed';
        }

        return match (strtolower($legacyUnit)) {
            'percent', 'percentage', '%' => 'percent',
            default => 'fixed',
        };
    }

    protected function cleanupExistingOrders(Store $newStore): void
    {
        $this->warn('Cleaning up existing orders...');

        $orderIds = Order::where('store_id', $newStore->id)->pluck('id');

        Invoice::where('invoiceable_type', Order::class)->whereIn('invoiceable_id', $orderIds)->forceDelete();
        OrderItem::whereIn('order_id', $orderIds)->forceDelete();
        Order::where('store_id', $newStore->id)->forceDelete();

        $this->line('  Cleanup complete');
    }

    protected function displaySummary(Store $newStore): void
    {
        $this->newLine();
        $this->info('=== Order Migration Summary ===');
        $this->line('Store: '.$newStore->name.' (ID: '.$newStore->id.')');
        $this->line('Orders mapped: '.count($this->orderMap));

        $orderCount = Order::where('store_id', $newStore->id)->count();
        $itemCount = OrderItem::whereIn('order_id', Order::where('store_id', $newStore->id)->pluck('id'))->count();
        $this->line("Total orders in store: {$orderCount}");
        $this->line("Total order items in store: {$itemCount}");
    }
}
