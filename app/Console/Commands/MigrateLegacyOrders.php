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
                            {--with-payments : Migrate payments for orders}';

    protected $description = 'Migrate orders, order items, and payments from the legacy database';

    protected array $orderMap = [];

    protected array $customerMap = [];

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

            // Build customer mapping
            $this->buildCustomerMapping($legacyStoreId);

            // Build user mapping
            $this->buildUserMapping($legacyStoreId);

            // Migrate orders
            $this->migrateOrders($legacyStoreId, $isDryRun, $limit, $withPayments);

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

    protected function buildCustomerMapping(int $legacyStoreId): void
    {
        if (! empty($this->customerMap)) {
            return;
        }

        $this->info('Building customer mapping...');

        $legacyCustomers = DB::connection('legacy')
            ->table('customers')
            ->where('store_id', $legacyStoreId)
            ->get();

        $newCustomers = Customer::where('store_id', $this->newStore->id)->get();
        $newCustomersByEmail = $newCustomers->filter(fn ($c) => $c->email)->keyBy(fn ($c) => strtolower($c->email));

        // Also index by normalized name for fallback matching
        $newCustomersByName = $newCustomers->keyBy(fn ($c) => $this->normalizeCustomerName($c->first_name, $c->last_name));

        $mappedByEmail = 0;
        $mappedByName = 0;
        $notMapped = 0;

        foreach ($legacyCustomers as $legacy) {
            // First try to match by email
            if ($legacy->email && $newCustomersByEmail->has(strtolower($legacy->email))) {
                $this->customerMap[$legacy->id] = $newCustomersByEmail->get(strtolower($legacy->email))->id;
                $mappedByEmail++;

                continue;
            }

            // Fall back to matching by name
            $normalizedName = $this->normalizeCustomerName($legacy->first_name, $legacy->last_name);
            if ($normalizedName && $newCustomersByName->has($normalizedName)) {
                $this->customerMap[$legacy->id] = $newCustomersByName->get($normalizedName)->id;
                $mappedByName++;

                continue;
            }

            $notMapped++;
        }

        $this->line("  Mapped ".count($this->customerMap)." customers ({$mappedByEmail} by email, {$mappedByName} by name, {$notMapped} not mapped)");
    }

    /**
     * Normalize customer name for comparison.
     */
    protected function normalizeCustomerName(?string $firstName, ?string $lastName): ?string
    {
        $name = trim(strtolower(($firstName ?? '').' '.($lastName ?? '')));

        return $name !== '' ? $name : null;
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

    protected function migrateOrders(int $legacyStoreId, bool $isDryRun, int $limit, bool $withPayments): void
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
                $skipped++;

                continue;
            }

            if ($isDryRun) {
                $this->line("  Would create order: {$legacyOrder->order_id} (\${$legacyOrder->total})");
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

            // Map sales channel from legacy store_marketplace_id
            $salesChannelId = null;
            if ($legacyOrder->store_marketplace_id && isset($this->salesChannelMap[$legacyOrder->store_marketplace_id])) {
                $salesChannelId = $this->salesChannelMap[$legacyOrder->store_marketplace_id];
            }

            // Use DB::table to preserve timestamps
            $newOrderId = DB::table('orders')->insertGetId([
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

        $this->line("  Created {$orderCount} orders with {$itemCount} items, {$this->invoiceCount} invoices, {$this->paymentCount} payments, skipped {$skipped} existing");
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

            // Use DB::table to preserve timestamps
            DB::table('order_items')->insert([
                'order_id' => $newOrderId,
                'product_id' => $productId,
                'product_variant_id' => $variantId,
                'sku' => $legacyItem->sku ?: null,
                'title' => $legacyItem->title ?: 'Item',
                'quantity' => (int) ($legacyItem->quantity ?: 1),
                'price' => $this->toDecimal($legacyItem->price),
                'cost' => $this->toDecimal($legacyItem->cost_per_item),
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

            // Use DB::table to preserve timestamps
            DB::table('payments')->insert([
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
                    'legacy_id' => $legacyPayment->id,
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
     * Uses order_id as invoice number if available, otherwise generates one.
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
