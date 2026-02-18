<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
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
                            {--with-payments : Migrate payments for orders}
                            {--sync-deletes : Soft-delete new records if legacy record is soft-deleted}';

    protected $description = 'Migrate orders, order items, and payments from the legacy database';

    protected array $orderMap = [];

    protected array $userMap = [];

    protected array $productMap = [];

    protected array $categoryMap = [];

    protected array $salesChannelMap = [];

    protected ?Store $newStore = null;

    protected ?Warehouse $warehouse = null;

    protected int $paymentCount = 0;

    protected int $invoiceCount = 0;

    public function handle(): int
    {
        $legacyStoreId = (int) $this->option('store-id');
        $newStoreId = $this->option('new-store-id') ? (int) $this->option('new-store-id') : null;
        $limit = (int) $this->option('limit');
        $isDryRun = $this->option('dry-run');
        $withPayments = $this->option('with-payments');

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
        if ($newStoreId) {
            $this->newStore = Store::find($newStoreId);
        } else {
            $this->newStore = Store::where('name', $legacyStore->name)->first();
        }

        if (! $this->newStore) {
            $this->error('New store not found. Run migrate:legacy first to create the store.');

            return 1;
        }

        // Get default warehouse
        $this->warehouse = Warehouse::where('store_id', $this->newStore->id)->where('is_default', true)->first();

        $this->info("Migrating orders to store: {$this->newStore->name} (ID: {$this->newStore->id})");

        // Load mapping files from previous migrations
        $this->loadMappingFiles($legacyStoreId);

        if ($this->option('fresh') && ! $isDryRun) {
            if ($this->confirm('This will delete all existing orders for this store. Continue?')) {
                $this->cleanupExistingOrders();
            }
        }

        try {
            DB::beginTransaction();

            // Build user mapping
            $this->buildUserMapping($legacyStoreId);

            $syncDeletes = $this->option('sync-deletes');
            if ($syncDeletes) {
                $this->info('Sync deletes enabled - will soft-delete orders if legacy is soft-deleted');
            }

            // Migrate orders
            $this->migrateOrders($legacyStoreId, $isDryRun, $limit, $withPayments, $syncDeletes);

            if ($isDryRun) {
                DB::rollBack();
                $this->info('Dry run complete - no changes made');
            } else {
                DB::commit();
                $this->info('Order migration complete!');
            }

            $this->displaySummary();

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

        // Load sales channel map (legacy marketplace ID -> sales_channel ID)
        $salesChannelMapFile = "{$basePath}/sales_channel_map_{$legacyStoreId}.json";
        if (file_exists($salesChannelMapFile)) {
            $this->salesChannelMap = json_decode(file_get_contents($salesChannelMapFile), true) ?? [];
            $this->line('  Loaded '.count($this->salesChannelMap).' sales channel mappings');
        } else {
            $this->warn('  No sales channel map found. Run migrate:legacy-marketplaces first for channel data.');
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

    /**
     * Normalize customer name for comparison.
     */
    protected function normalizeCustomerName(?string $firstName, ?string $lastName): ?string
    {
        $name = trim(strtolower(($firstName ?? '').' '.($lastName ?? '')));

        return $name !== '' ? $name : null;
    }

    protected ?int $walkInCustomerId = null;

    /**
     * Get or create a customer for the order.
     * Searches by email first, then phone. Creates from legacy if not found.
     */
    protected function getOrCreateCustomer(?int $legacyCustomerId, int $legacyStoreId): int
    {
        // If legacy order had no customer, use walk-in customer
        if (! $legacyCustomerId) {
            return $this->getOrCreateWalkInCustomer();
        }

        // Get legacy customer data
        $legacy = DB::connection('legacy')
            ->table('customers')
            ->where('id', $legacyCustomerId)
            ->first();

        if (! $legacy) {
            // Legacy customer doesn't exist - use walk-in
            return $this->getOrCreateWalkInCustomer();
        }

        // Search by email first (if email exists and is not empty)
        if (! empty($legacy->email)) {
            $existingByEmail = Customer::where('store_id', $this->newStore->id)
                ->where('email', $legacy->email)
                ->first();

            if ($existingByEmail) {
                return $existingByEmail->id;
            }
        }

        // Search by phone number (if phone exists and is not empty)
        if (! empty($legacy->phone_number)) {
            $normalizedPhone = $this->normalizePhoneNumber($legacy->phone_number);
            if ($normalizedPhone) {
                $existingByPhone = Customer::where('store_id', $this->newStore->id)
                    ->where(function ($query) use ($legacy, $normalizedPhone) {
                        $query->where('phone_number', $legacy->phone_number)
                            ->orWhere('phone_number', $normalizedPhone);
                    })
                    ->first();

                if ($existingByPhone) {
                    return $existingByPhone->id;
                }
            }
        }

        // Customer not found - create from legacy data with all fields
        return $this->createCustomerFromLegacy($legacy);
    }

    /**
     * Normalize phone number for comparison (digits only).
     */
    protected function normalizePhoneNumber(?string $phone): ?string
    {
        if (! $phone) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $phone);

        return strlen($digits) >= 10 ? $digits : null;
    }

    /**
     * Create a customer from legacy data with all fields including addresses and images.
     */
    protected function createCustomerFromLegacy(object $legacy): int
    {
        // Get state code if state_id is set
        $stateCode = null;
        if ($legacy->state_id) {
            $state = DB::connection('legacy')
                ->table('states')
                ->where('id', $legacy->state_id)
                ->first();
            $stateCode = $state?->code;
        }

        $customer = Customer::create([
            'store_id' => $this->newStore->id,
            'first_name' => $legacy->first_name ?: 'Unknown',
            'last_name' => $legacy->last_name ?: 'Customer',
            'email' => $legacy->email ?: null,
            'phone_number' => $legacy->phone_number ?: null,
            'address' => $legacy->street_address ?? null,
            'address2' => $legacy->street_address2 ?? null,
            'city' => $legacy->city ?? null,
            'state' => $stateCode,
            'zip' => $legacy->zip ?? null,
            'country_id' => $legacy->country_id ?? null,
            'state_id' => $legacy->state_id ?? null,
            'company_name' => $legacy->company_name ?? null,
            'ethnicity' => $legacy->ethnicity ?? null,
            'photo' => $legacy->drivers_license_photo ?? $legacy->photo ?? null,
            'accepts_marketing' => $legacy->accepts_marketing ?? false,
            'is_active' => $legacy->is_active ?? true,
            'number_of_sales' => $legacy->number_of_sales ?? 0,
            'number_of_buys' => $legacy->number_of_buys ?? 0,
            'last_sales_date' => $legacy->last_sales_date ?? null,
            'created_at' => $legacy->created_at,
            'updated_at' => $legacy->updated_at,
        ]);

        // Migrate customer addresses
        $this->migrateCustomerAddresses($legacy->id, $customer);

        return $customer->id;
    }

    /**
     * Migrate addresses for a customer from legacy database.
     */
    protected function migrateCustomerAddresses(int $legacyCustomerId, Customer $customer): void
    {
        $legacyAddresses = DB::connection('legacy')
            ->table('addresses')
            ->where('addressable_type', 'App\\Models\\Customer')
            ->where('addressable_id', $legacyCustomerId)
            ->get();

        foreach ($legacyAddresses as $legacyAddress) {
            // Check if address already exists
            $existingAddress = DB::table('addresses')
                ->where('addressable_type', Customer::class)
                ->where('addressable_id', $customer->id)
                ->where('address', $legacyAddress->address)
                ->first();

            if ($existingAddress) {
                continue;
            }

            DB::table('addresses')->insert([
                'store_id' => $this->newStore->id,
                'addressable_type' => Customer::class,
                'addressable_id' => $customer->id,
                'type' => $legacyAddress->type ?? 'primary',
                'first_name' => $legacyAddress->first_name ?? $customer->first_name,
                'last_name' => $legacyAddress->last_name ?? $customer->last_name,
                'company' => $legacyAddress->company ?? $legacyAddress->company_name ?? null,
                'address' => $legacyAddress->address,
                'address2' => $legacyAddress->address2,
                'city' => $legacyAddress->city,
                'state_id' => $legacyAddress->state_id,
                'country_id' => $legacyAddress->country_id ?? 1,
                'zip' => $legacyAddress->zip,
                'phone' => $legacyAddress->phone ?? null,
                'is_default' => (bool) ($legacyAddress->is_default ?? false),
                'is_shipping' => (bool) ($legacyAddress->is_shipping ?? false),
                'is_billing' => (bool) ($legacyAddress->is_billing ?? false),
                'created_at' => $legacyAddress->created_at,
                'updated_at' => $legacyAddress->updated_at,
            ]);
        }
    }

    /**
     * Get or create a generic "Walk-in Customer" for orders with no customer data.
     */
    protected function getOrCreateWalkInCustomer(): int
    {
        if ($this->walkInCustomerId) {
            return $this->walkInCustomerId;
        }

        // Check if one already exists
        $existing = Customer::where('store_id', $this->newStore->id)
            ->where('first_name', 'Walk-in')
            ->where('last_name', 'Customer')
            ->first();

        if ($existing) {
            $this->walkInCustomerId = $existing->id;

            return $existing->id;
        }

        // Create new
        $customer = Customer::create([
            'store_id' => $this->newStore->id,
            'first_name' => 'Walk-in',
            'last_name' => 'Customer',
            'is_active' => true,
        ]);

        $this->walkInCustomerId = $customer->id;

        return $customer->id;
    }

    protected function buildUserMapping(int $legacyStoreId): void
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

    protected function migrateOrders(int $legacyStoreId, bool $isDryRun, int $limit, bool $withPayments, bool $syncDeletes = false): void
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
        $skipped = 0;
        $synced = 0;

        foreach ($legacyOrders as $legacyOrder) {
            // Check if order already exists by order_id (maintain the original)
            $existingOrder = null;
            if ($legacyOrder->order_id) {
                $existingOrder = Order::where('store_id', $this->newStore->id)
                    ->where('order_id', $legacyOrder->order_id)
                    ->first();
            }

            if ($existingOrder) {
                $this->orderMap[$legacyOrder->id] = $existingOrder->id;

                // Sync soft-delete status if enabled
                if ($syncDeletes && $legacyOrder->deleted_at && ! $existingOrder->deleted_at) {
                    if (! $isDryRun) {
                        $existingOrder->delete();
                        $synced++;
                    } else {
                        $this->line("  Would soft-delete order #{$existingOrder->id} (legacy was deleted)");
                    }
                }

                $skipped++;

                continue;
            }

            if ($isDryRun) {
                $this->line("  Would create order: {$legacyOrder->order_id} (\${$legacyOrder->total})");
                $orderCount++;

                continue;
            }

            // Map customer - create if doesn't exist
            $customerId = $this->getOrCreateCustomer($legacyOrder->customer_id, $legacyStoreId);

            // Map user
            $userId = null;
            if ($legacyOrder->user_id && isset($this->userMap[$legacyOrder->user_id])) {
                $userId = $this->userMap[$legacyOrder->user_id];
            }

            // Map status
            $status = $this->mapOrderStatus($legacyOrder->status);

            // Map sales channel from legacy store_marketplace_id
            $salesChannelId = null;
            if ($legacyOrder->store_marketplace_id && isset($this->salesChannelMap[$legacyOrder->store_marketplace_id])) {
                $mappedChannelId = $this->salesChannelMap[$legacyOrder->store_marketplace_id];
                // Verify the sales channel exists and belongs to this store
                $salesChannel = \App\Models\SalesChannel::where('id', $mappedChannelId)
                    ->where('store_id', $this->newStore->id)
                    ->first();
                $salesChannelId = $salesChannel?->id;
            }

            // Check if order with this ID already exists
            $existingOrder = Order::withTrashed()->find($legacyOrder->id);
            if ($existingOrder) {
                $this->orderMap[$legacyOrder->id] = $existingOrder->id;
                $skipped++;

                continue;
            }

            // Use DB::table to preserve timestamps and original ID
            DB::table('orders')->insert([
                'id' => $legacyOrder->id, // Preserve original ID
                'store_id' => $this->newStore->id,
                'customer_id' => $customerId,
                'user_id' => $userId,
                'warehouse_id' => $this->warehouse?->id,
                'sales_channel_id' => $salesChannelId,
                'total' => $this->toDecimal($legacyOrder->total),
                'sub_total' => $this->toDecimal($legacyOrder->sub_total),
                'status' => $status,
                'sales_tax' => $this->toDecimal($legacyOrder->sales_tax),
                'tax_rate' => $this->toDecimal($legacyOrder->tax_rate),
                'shipping_cost' => $this->toDecimal($legacyOrder->shipping_cost),
                'discount_cost' => $this->toDecimal($legacyOrder->discount_cost),
                'service_fee_value' => $this->toDecimal($legacyOrder->service_fee_value),
                'service_fee_unit' => $this->mapServiceFeeUnit($legacyOrder->service_fee_unit ?? null),
                'service_fee_reason' => $legacyOrder->service_fee_reason ?? '',
                'invoice_number' => $this->getInvoiceNumber($legacyOrder),
                'order_id' => $legacyOrder->order_id, // Maintain original order_id
                'external_marketplace_id' => $legacyOrder->external_marketplace_id,
                'square_order_id' => $legacyOrder->square_order_id,
                'date_of_purchase' => $legacyOrder->date_of_purchase ?: null,
                'notes' => $legacyOrder->customer_note,
                'created_at' => $legacyOrder->created_at,
                'updated_at' => $legacyOrder->updated_at,
            ]);

            $newOrderId = $legacyOrder->id; // Use original ID
            $this->orderMap[$legacyOrder->id] = $newOrderId;
            $orderCount++;

            // Migrate order items
            $itemCount += $this->migrateOrderItems($legacyOrder->id, $newOrderId, $legacyStoreId);

            // Calculate total paid from legacy payments
            $totalPaid = 0;
            if ($withPayments) {
                $totalPaid = $this->migrateOrderPayments($legacyOrder, $newOrderId, $customerId, $userId);
            }

            // Create invoice for this order
            $this->createOrderInvoice($legacyOrder, $newOrderId, $customerId, $userId, $totalPaid);

            if ($orderCount % 50 === 0) {
                $this->line("  Processed {$orderCount} orders...");
            }
        }

        $this->line("  Created {$orderCount} orders with {$itemCount} items, {$this->invoiceCount} invoices, {$this->paymentCount} payments, skipped {$skipped} existing, synced {$synced} deletes");
    }

    protected function migrateOrderItems(int $legacyOrderId, int $newOrderId, int $legacyStoreId): int
    {
        $legacyItems = DB::connection('legacy')
            ->table('order_items')
            ->where('order_id', $legacyOrderId)
            ->get();

        $itemCount = 0;

        foreach ($legacyItems as $legacyItem) {
            // Find product by multiple methods:
            // 1. Via product map (legacy product_id -> new product_id)
            // 2. By SKU in new store
            $productId = null;
            $variantId = null;

            // Method 1: Product map
            if ($legacyItem->product_id && isset($this->productMap[$legacyItem->product_id])) {
                $productId = $this->productMap[$legacyItem->product_id];
                // Try to find variant
                $variant = ProductVariant::where('product_id', $productId)->first();
                $variantId = $variant?->id;
            }

            // Method 2: Find by SKU if no product found yet
            if (! $productId && $legacyItem->sku) {
                $variant = ProductVariant::whereHas('product', function ($q) {
                    $q->where('store_id', $this->newStore->id);
                })->where('sku', $legacyItem->sku)->first();

                if ($variant) {
                    $productId = $variant->product_id;
                    $variantId = $variant->id;
                }
            }

            // Method 3: Try to find product by legacy product_id's SKU
            if (! $productId && $legacyItem->product_id) {
                $legacyProduct = DB::connection('legacy')
                    ->table('products')
                    ->where('id', $legacyItem->product_id)
                    ->first();

                if ($legacyProduct && $legacyProduct->sku) {
                    $variant = ProductVariant::whereHas('product', function ($q) {
                        $q->where('store_id', $this->newStore->id);
                    })->where('sku', $legacyProduct->sku)->first();

                    if ($variant) {
                        $productId = $variant->product_id;
                        $variantId = $variant->id;
                    }
                }
            }

            // Find category by name if order item has category
            $categoryId = null;
            if ($legacyItem->category) {
                $categoryId = $this->findCategoryByName($legacyItem->category);
            }

            // Get wholesale value from variant if available
            $wholesaleValue = null;
            if ($variantId) {
                $variant = ProductVariant::find($variantId);
                $wholesaleValue = $variant?->wholesale_price;
            }

            // Check if order_item with this ID already exists
            $existingItem = \App\Models\OrderItem::find($legacyItem->id);
            if ($existingItem) {
                continue;
            }

            // Use DB::table to preserve timestamps and original ID
            DB::table('order_items')->insert([
                'id' => $legacyItem->id, // Preserve original ID
                'order_id' => $newOrderId,
                'product_id' => $productId,
                'product_variant_id' => $variantId,
                'sku' => $legacyItem->sku ?: null,
                'title' => $legacyItem->title ?: 'Item',
                'quantity' => (int) ($legacyItem->quantity ?: 1),
                'price' => $this->toDecimal($legacyItem->price),
                'cost' => $this->toDecimal($legacyItem->cost_per_item),
                'wholesale_value' => $wholesaleValue,
                'discount' => $this->toDecimal($legacyItem->discount),
                'tax' => $this->toDecimal($legacyItem->sales_tax),
                'notes' => null,
                'created_at' => $legacyItem->created_at,
                'updated_at' => $legacyItem->updated_at,
            ]);

            $itemCount++;
        }

        return $itemCount;
    }

    protected function migrateOrderPayments(object $legacyOrder, int $newOrderId, ?int $customerId, ?int $userId): float
    {
        // Get payments from legacy database for this order
        $legacyPayments = DB::connection('legacy')
            ->table('payments')
            ->where(function ($q) use ($legacyOrder) {
                $q->where('order_id', $legacyOrder->id)
                    ->orWhere(function ($q2) use ($legacyOrder) {
                        $q2->where('paymentable_type', 'App\\Models\\Order')
                            ->where('paymentable_id', $legacyOrder->id);
                    });
            })
            ->whereNull('deleted_at')
            ->get();

        $totalPaid = 0;

        foreach ($legacyPayments as $legacyPayment) {
            // Map user
            $paymentUserId = $userId;
            if ($legacyPayment->user_id && isset($this->userMap[$legacyPayment->user_id])) {
                $paymentUserId = $this->userMap[$legacyPayment->user_id];
            }

            // Map payment method
            $paymentMethod = $this->mapPaymentMethod($legacyPayment->short_payment_type ?? $legacyPayment->type);

            // Map status
            $paymentStatus = $this->mapPaymentStatus($legacyPayment->status);

            // Check if payment with this ID already exists
            $existingPayment = Payment::find($legacyPayment->id);
            if ($existingPayment) {
                if ($paymentStatus === Payment::STATUS_COMPLETED) {
                    $totalPaid += (float) ($legacyPayment->amount ?? 0);
                }

                continue;
            }

            // Use DB::table to preserve timestamps and original ID
            DB::table('payments')->insert([
                'id' => $legacyPayment->id, // Preserve original ID
                'store_id' => $this->newStore->id,
                'payable_type' => Order::class,
                'payable_id' => $newOrderId,
                'order_id' => $newOrderId,
                'customer_id' => $customerId,
                'user_id' => $paymentUserId,
                'payment_method' => $paymentMethod,
                'status' => $paymentStatus,
                'amount' => $this->toDecimal($legacyPayment->amount),
                'service_fee_value' => $this->toDecimal($legacyPayment->service_fee_value),
                'service_fee_unit' => $this->mapServiceFeeUnit($legacyPayment->service_fee_unit),
                'service_fee_amount' => $this->toDecimal($legacyPayment->service_fee),
                'currency' => $legacyPayment->currency ?: 'USD',
                'reference' => $legacyPayment->reference_id ?: null,
                'transaction_id' => $legacyPayment->payment_gateway_transaction_id ?: null,
                'gateway' => $this->mapPaymentGateway($legacyPayment->payment_gateway_id),
                'gateway_payment_id' => $legacyPayment->payment_gateway_transaction_id ?: null,
                'gateway_response' => $legacyPayment->payment_gateway_data ? json_encode(['legacy' => $legacyPayment->payment_gateway_data]) : null,
                'notes' => $legacyPayment->description ?: null,
                'metadata' => json_encode([
                    'card_type' => $legacyPayment->card_type ?? null,
                    'last_4' => $legacyPayment->last_4 ?? null,
                    'entry_type' => $legacyPayment->entry_type ?? null,
                ]),
                'paid_at' => $paymentStatus === Payment::STATUS_COMPLETED ? ($legacyPayment->created_at ?? now()) : null,
                'created_at' => $legacyPayment->created_at,
                'updated_at' => $legacyPayment->updated_at,
            ]);

            if ($paymentStatus === Payment::STATUS_COMPLETED) {
                $totalPaid += (float) ($legacyPayment->amount ?? 0);
            }

            $this->paymentCount++;
        }

        return $totalPaid;
    }

    protected function createOrderInvoice(object $legacyOrder, int $newOrderId, ?int $customerId, ?int $userId, float $totalPaid): void
    {
        $orderTotal = $this->toDecimal($legacyOrder->total);
        $balanceDue = max(0, $orderTotal - $totalPaid);

        // Determine invoice status
        $invoiceStatus = match (true) {
            $balanceDue <= 0 => Invoice::STATUS_PAID,
            $totalPaid > 0 => Invoice::STATUS_PARTIAL,
            default => Invoice::STATUS_PENDING,
        };

        // Use DB::table to preserve timestamps
        $invoiceId = DB::table('invoices')->insertGetId([
            'store_id' => $this->newStore->id,
            'customer_id' => $customerId,
            'user_id' => $userId,
            'invoice_number' => $this->getInvoiceNumber($legacyOrder, $newOrderId),
            'invoiceable_type' => Order::class,
            'invoiceable_id' => $newOrderId,
            'subtotal' => $this->toDecimal($legacyOrder->sub_total),
            'tax' => $this->toDecimal($legacyOrder->sales_tax),
            'shipping' => $this->toDecimal($legacyOrder->shipping_cost),
            'discount' => $this->toDecimal($legacyOrder->discount_cost),
            'total' => $orderTotal,
            'total_paid' => $totalPaid,
            'balance_due' => $balanceDue,
            'status' => $invoiceStatus,
            'currency' => 'USD',
            'due_date' => $legacyOrder->created_at ? date('Y-m-d', strtotime($legacyOrder->created_at.' +30 days')) : null,
            'paid_at' => $balanceDue <= 0 ? $legacyOrder->updated_at : null,
            'notes' => null,
            'created_at' => $legacyOrder->created_at,
            'updated_at' => $legacyOrder->updated_at,
        ]);

        // Update payments to link to invoice
        DB::table('payments')
            ->where('order_id', $newOrderId)
            ->update(['invoice_id' => $invoiceId]);

        $this->invoiceCount++;
    }

    protected function findCategoryByName(string $categoryName): ?int
    {
        if (isset($this->categoryMap[$categoryName])) {
            return $this->categoryMap[$categoryName];
        }

        $category = Category::where('store_id', $this->newStore->id)
            ->where('name', $categoryName)
            ->first();

        if ($category) {
            $this->categoryMap[$categoryName] = $category->id;

            return $category->id;
        }

        return null;
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

    protected function mapPaymentMethod(?string $legacyMethod): string
    {
        if (! $legacyMethod) {
            return Payment::METHOD_CASH;
        }

        return match (strtolower($legacyMethod)) {
            'cash' => Payment::METHOD_CASH,
            'card', 'credit', 'credit_card', 'debit', 'debit_card' => Payment::METHOD_CARD,
            'store_credit', 'credit' => Payment::METHOD_STORE_CREDIT,
            'layaway' => Payment::METHOD_LAYAWAY,
            'check', 'cheque' => Payment::METHOD_CHECK,
            'bank_transfer', 'wire', 'ach' => Payment::METHOD_BANK_TRANSFER,
            'external', 'other' => Payment::METHOD_EXTERNAL,
            default => Payment::METHOD_CASH,
        };
    }

    protected function mapPaymentStatus(?string $legacyStatus): string
    {
        if (! $legacyStatus) {
            return Payment::STATUS_COMPLETED;
        }

        return match (strtolower($legacyStatus)) {
            'pending', 'processing' => Payment::STATUS_PENDING,
            'completed', 'complete', 'success', 'approved', 'paid' => Payment::STATUS_COMPLETED,
            'failed', 'declined', 'error' => Payment::STATUS_FAILED,
            'refunded' => Payment::STATUS_REFUNDED,
            'partially_refunded', 'partial_refund' => Payment::STATUS_PARTIALLY_REFUNDED,
            default => Payment::STATUS_COMPLETED,
        };
    }

    /**
     * Get invoice number from legacy order.
     * Maintains the original order number exactly as is.
     */
    protected function getInvoiceNumber(object $legacyOrder, ?int $newOrderId = null): string
    {
        // Use order_id if it's set and not "0"
        if (! empty($legacyOrder->order_id) && $legacyOrder->order_id !== '0') {
            return $legacyOrder->order_id;
        }

        // Fall back to invoice_number if set
        if (! empty($legacyOrder->invoice_number)) {
            return $legacyOrder->invoice_number;
        }

        // Generate a unique invoice number as last resort
        $id = $newOrderId ?? $legacyOrder->id;

        return 'INV-'.strtoupper(substr(md5((string) $id), 0, 8));
    }

    protected function mapPaymentGateway(?int $legacyGatewayId): ?string
    {
        if (! $legacyGatewayId) {
            return null;
        }

        // Map common gateway IDs (adjust based on your legacy data)
        return match ($legacyGatewayId) {
            1 => 'square',
            2 => 'stripe',
            3 => 'paypal',
            default => 'other',
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

    protected function cleanupExistingOrders(): void
    {
        $this->warn('Cleaning up existing orders...');

        $orderIds = Order::where('store_id', $this->newStore->id)->pluck('id');

        // Delete payments linked to orders
        Payment::where('payable_type', Order::class)->whereIn('payable_id', $orderIds)->forceDelete();

        // Delete invoices linked to orders
        Invoice::where('invoiceable_type', Order::class)->whereIn('invoiceable_id', $orderIds)->forceDelete();

        // Delete order items
        OrderItem::whereIn('order_id', $orderIds)->forceDelete();

        // Delete orders
        Order::where('store_id', $this->newStore->id)->forceDelete();

        $this->line('  Cleanup complete');
    }

    /**
     * Safely convert a value to float, handling empty strings and nulls.
     */
    protected function toDecimal(mixed $value, float $default = 0): float
    {
        if ($value === null || $value === '') {
            return $default;
        }

        return (float) $value;
    }

    protected function displaySummary(): void
    {
        $this->newLine();
        $this->info('=== Order Migration Summary ===');
        $this->line('Store: '.$this->newStore->name.' (ID: '.$this->newStore->id.')');
        $this->line('Orders mapped: '.count($this->orderMap));

        $orderCount = Order::where('store_id', $this->newStore->id)->count();
        $itemCount = OrderItem::whereIn('order_id', Order::where('store_id', $this->newStore->id)->pluck('id'))->count();
        $paymentCount = Payment::where('store_id', $this->newStore->id)
            ->where('payable_type', Order::class)
            ->count();
        $invoiceCount = Invoice::where('store_id', $this->newStore->id)
            ->where('invoiceable_type', Order::class)
            ->count();

        $this->line("Total orders in store: {$orderCount}");
        $this->line("Total order items in store: {$itemCount}");
        $this->line("Total payments in store: {$paymentCount}");
        $this->line("Total invoices in store: {$invoiceCount}");
    }
}
