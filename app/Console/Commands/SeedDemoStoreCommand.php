<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Inventory;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SalesChannel;
use App\Models\Store;
use App\Models\StoreMarketplace;
use App\Models\Warehouse;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SeedDemoStoreCommand extends Command
{
    protected $signature = 'store:seed-demo
                            {store : The store ID to seed}
                            {--categories=5 : Number of categories to create}
                            {--products=20 : Number of products to create}
                            {--customers=10 : Number of customers to create}
                            {--orders=15 : Number of orders to create}
                            {--with-marketplaces : Create mock marketplace connections}
                            {--edition= : Set the store edition (e.g., shopmata-public)}';

    protected $description = 'Seed a store with demo data (categories, products, customers, orders, payments)';

    protected Store $store;

    protected ?Warehouse $warehouse = null;

    protected array $categories = [];

    protected array $products = [];

    protected array $customers = [];

    public function handle(): int
    {
        $storeId = $this->argument('store');
        $this->store = Store::find($storeId);

        if (! $this->store) {
            $this->error("Store with ID {$storeId} not found.");

            return self::FAILURE;
        }

        $this->info("Seeding demo data for store: {$this->store->name} (ID: {$this->store->id})");

        // Update edition if specified
        if ($edition = $this->option('edition')) {
            $this->store->update(['edition' => $edition]);
            $this->info("Updated store edition to: {$edition}");
        }

        DB::transaction(function () {
            $this->createWarehouse();
            $this->createCategories();
            $this->createProducts();
            $this->createCustomers();
            $this->createOrders();

            if ($this->option('with-marketplaces')) {
                $this->createMarketplaces();
            }
        });

        $this->newLine();
        $this->info('Demo data seeded successfully!');

        $this->table(
            ['Type', 'Count'],
            [
                ['Categories', count($this->categories)],
                ['Products', count($this->products)],
                ['Customers', count($this->customers)],
                ['Orders', (int) $this->option('orders')],
            ]
        );

        return self::SUCCESS;
    }

    protected function createWarehouse(): void
    {
        $this->warehouse = Warehouse::firstOrCreate(
            ['store_id' => $this->store->id, 'name' => 'Main Warehouse'],
            [
                'address' => '123 Demo Street',
                'city' => 'Demo City',
                'state' => 'CA',
                'zip' => '90210',
                'country' => 'US',
                'is_default' => true,
            ]
        );

        $this->components->task('Creating warehouse', fn () => true);
    }

    protected function createCategories(): void
    {
        $categoryNames = [
            'Electronics',
            'Jewelry',
            'Watches',
            'Accessories',
            'Collectibles',
            'Clothing',
            'Home & Garden',
            'Sports',
            'Toys',
            'Books',
        ];

        $count = min((int) $this->option('categories'), count($categoryNames));

        $this->components->task("Creating {$count} categories", function () use ($categoryNames, $count) {
            for ($i = 0; $i < $count; $i++) {
                $category = Category::create([
                    'store_id' => $this->store->id,
                    'name' => $categoryNames[$i],
                    'slug' => Str::slug($categoryNames[$i]),
                    'sort_order' => $i,
                ]);
                $this->categories[] = $category;
            }
        });
    }

    protected function createProducts(): void
    {
        $count = (int) $this->option('products');

        $productTemplates = [
            ['name' => 'Gold Ring', 'price' => 299.99, 'cost' => 150],
            ['name' => 'Silver Necklace', 'price' => 149.99, 'cost' => 75],
            ['name' => 'Diamond Earrings', 'price' => 599.99, 'cost' => 300],
            ['name' => 'Vintage Watch', 'price' => 450.00, 'cost' => 200],
            ['name' => 'Pearl Bracelet', 'price' => 199.99, 'cost' => 100],
            ['name' => 'Sapphire Pendant', 'price' => 349.99, 'cost' => 175],
            ['name' => 'Rose Gold Band', 'price' => 279.99, 'cost' => 140],
            ['name' => 'Emerald Ring', 'price' => 499.99, 'cost' => 250],
            ['name' => 'Titanium Cufflinks', 'price' => 89.99, 'cost' => 45],
            ['name' => 'Crystal Brooch', 'price' => 129.99, 'cost' => 65],
            ['name' => 'Leather Watch Band', 'price' => 59.99, 'cost' => 30],
            ['name' => 'Sterling Silver Chain', 'price' => 179.99, 'cost' => 90],
            ['name' => 'Antique Pocket Watch', 'price' => 750.00, 'cost' => 400],
            ['name' => 'Platinum Wedding Band', 'price' => 899.99, 'cost' => 500],
            ['name' => 'Opal Earrings', 'price' => 249.99, 'cost' => 125],
            ['name' => 'Gold Chain Necklace', 'price' => 399.99, 'cost' => 200],
            ['name' => 'Ruby Pendant', 'price' => 549.99, 'cost' => 275],
            ['name' => 'Silver Anklet', 'price' => 79.99, 'cost' => 40],
            ['name' => 'Diamond Stud', 'price' => 799.99, 'cost' => 400],
            ['name' => 'Vintage Brooch', 'price' => 199.99, 'cost' => 100],
        ];

        $this->components->task("Creating {$count} products", function () use ($productTemplates, $count) {
            for ($i = 0; $i < $count; $i++) {
                $template = $productTemplates[$i % count($productTemplates)];
                $category = $this->categories[array_rand($this->categories)] ?? null;

                $product = Product::create([
                    'store_id' => $this->store->id,
                    'title' => $template['name'].($i >= count($productTemplates) ? ' #'.($i + 1) : ''),
                    'handle' => Str::slug($template['name']).'-'.$i,
                    'description' => "High quality {$template['name']} for your collection.",
                    'category_id' => $category?->id,
                    'is_published' => true,
                    'is_draft' => false,
                    'has_variants' => false,
                    'track_quantity' => true,
                    'sell_out_of_stock' => false,
                    'charge_taxes' => true,
                    'quantity' => rand(5, 50),
                ]);

                $sku = strtoupper(substr($category?->name ?? 'PROD', 0, 3)).'-'.str_pad($product->id, 5, '0', STR_PAD_LEFT);

                $variant = ProductVariant::create([
                    'product_id' => $product->id,
                    'sku' => $sku,
                    'barcode' => '123456'.str_pad($product->id, 6, '0', STR_PAD_LEFT),
                    'price' => $template['price'],
                    'cost' => $template['cost'],
                    'quantity' => $product->quantity,
                ]);

                if ($this->warehouse) {
                    Inventory::create([
                        'product_variant_id' => $variant->id,
                        'warehouse_id' => $this->warehouse->id,
                        'quantity' => $product->quantity,
                    ]);
                }

                $this->products[] = $product;
            }
        });
    }

    protected function createCustomers(): void
    {
        $count = (int) $this->option('customers');

        $firstNames = ['John', 'Jane', 'Michael', 'Sarah', 'David', 'Emily', 'Robert', 'Lisa', 'William', 'Emma'];
        $lastNames = ['Smith', 'Johnson', 'Williams', 'Brown', 'Jones', 'Garcia', 'Miller', 'Davis', 'Martinez', 'Wilson'];

        $this->components->task("Creating {$count} customers", function () use ($firstNames, $lastNames, $count) {
            for ($i = 0; $i < $count; $i++) {
                $firstName = $firstNames[$i % count($firstNames)];
                $lastName = $lastNames[$i % count($lastNames)];

                $customer = Customer::create([
                    'store_id' => $this->store->id,
                    'first_name' => $firstName,
                    'last_name' => $lastName.($i >= 10 ? ' '.chr(65 + ($i % 26)) : ''),
                    'email' => strtolower($firstName).'.'.strtolower($lastName).$i.'@demo.com',
                    'phone_number' => '555-'.str_pad(rand(100, 999), 3, '0', STR_PAD_LEFT).'-'.str_pad(rand(1000, 9999), 4, '0', STR_PAD_LEFT),
                ]);

                $this->customers[] = $customer;
            }
        });
    }

    protected function createOrders(): void
    {
        $count = (int) $this->option('orders');
        $statuses = [
            Order::STATUS_COMPLETED,
            Order::STATUS_COMPLETED,
            Order::STATUS_SHIPPED,
            Order::STATUS_PROCESSING,
            Order::STATUS_PENDING,
            Order::STATUS_CONFIRMED,
        ];

        $salesChannel = SalesChannel::where('store_id', $this->store->id)
            ->where('is_local', true)
            ->first();

        $this->components->task("Creating {$count} orders with payments", function () use ($count, $statuses, $salesChannel) {
            for ($i = 0; $i < $count; $i++) {
                $customer = $this->customers[array_rand($this->customers)] ?? null;
                $status = $statuses[array_rand($statuses)];
                $itemCount = rand(1, 4);

                // Create order
                $order = Order::create([
                    'store_id' => $this->store->id,
                    'customer_id' => $customer?->id,
                    'warehouse_id' => $this->warehouse?->id,
                    'sales_channel_id' => $salesChannel?->id,
                    'invoice_number' => 'INV-'.str_pad($i + 1, 6, '0', STR_PAD_LEFT),
                    'status' => $status,
                    'sub_total' => 0,
                    'sales_tax' => 0,
                    'tax_rate' => 0.0825,
                    'shipping_cost' => rand(0, 1) ? rand(5, 25) : 0,
                    'discount_cost' => rand(0, 1) ? rand(5, 50) : 0,
                    'total' => 0,
                    'total_paid' => 0,
                    'date_of_purchase' => now()->subDays(rand(0, 60)),
                ]);

                $subTotal = 0;

                // Add items
                $usedProducts = [];
                for ($j = 0; $j < $itemCount; $j++) {
                    $product = $this->products[array_rand($this->products)] ?? null;
                    if (! $product || in_array($product->id, $usedProducts)) {
                        continue;
                    }
                    $usedProducts[] = $product->id;

                    $variant = $product->variants->first();
                    $quantity = rand(1, 3);
                    $price = $variant?->price ?? rand(50, 500);
                    $lineTotal = $price * $quantity;
                    $subTotal += $lineTotal;

                    OrderItem::create([
                        'order_id' => $order->id,
                        'product_id' => $product->id,
                        'product_variant_id' => $variant?->id,
                        'title' => $product->title,
                        'sku' => $variant?->sku,
                        'quantity' => $quantity,
                        'price' => $price,
                        'cost' => $variant?->cost ?? $price * 0.5,
                        'discount' => 0,
                        'tax' => $lineTotal * 0.0825,
                    ]);
                }

                // Calculate totals
                $salesTax = $subTotal * $order->tax_rate;
                $total = $subTotal + $salesTax + $order->shipping_cost - $order->discount_cost;

                // Determine payment (some orders have outstanding balance)
                $totalPaid = match ($status) {
                    Order::STATUS_COMPLETED, Order::STATUS_SHIPPED => $total,
                    Order::STATUS_PENDING => 0,
                    default => rand(0, 1) ? $total : $total * (rand(30, 70) / 100),
                };

                $order->update([
                    'sub_total' => $subTotal,
                    'sales_tax' => $salesTax,
                    'total' => $total,
                    'total_paid' => $totalPaid,
                    'balance_due' => max(0, $total - $totalPaid),
                ]);

                // Create invoice
                Invoice::create([
                    'store_id' => $this->store->id,
                    'invoiceable_type' => Order::class,
                    'invoiceable_id' => $order->id,
                    'customer_id' => $customer?->id,
                    'invoice_number' => $order->invoice_number,
                    'status' => $totalPaid >= $total ? 'paid' : ($totalPaid > 0 ? 'partial' : 'pending'),
                    'subtotal' => $subTotal,
                    'discount' => $order->discount_cost,
                    'tax' => $salesTax,
                    'shipping' => $order->shipping_cost,
                    'total' => $total,
                    'total_paid' => $totalPaid,
                    'balance_due' => max(0, $total - $totalPaid),
                ]);

                // Create payment if paid
                if ($totalPaid > 0) {
                    Payment::create([
                        'store_id' => $this->store->id,
                        'payable_type' => Order::class,
                        'payable_id' => $order->id,
                        'user_id' => $this->store->user_id,
                        'amount' => $totalPaid,
                        'payment_method' => ['cash', 'credit_card', 'debit_card'][array_rand(['cash', 'credit_card', 'debit_card'])],
                        'status' => 'completed',
                        'paid_at' => $order->date_of_purchase,
                    ]);
                }
            }
        });
    }

    protected function createMarketplaces(): void
    {
        $platforms = [
            ['platform' => 'shopify', 'name' => 'Shopify Store', 'domain' => 'demo-store.myshopify.com'],
            ['platform' => 'ebay', 'name' => 'eBay Store', 'domain' => null],
            ['platform' => 'amazon', 'name' => 'Amazon Seller', 'domain' => null],
            ['platform' => 'etsy', 'name' => 'Etsy Shop', 'domain' => null],
        ];

        $this->components->task('Creating mock marketplaces', function () use ($platforms) {
            $sortOrder = SalesChannel::where('store_id', $this->store->id)->max('sort_order') ?? 0;

            foreach ($platforms as $platform) {
                // Create marketplace connection
                $marketplace = StoreMarketplace::firstOrCreate(
                    [
                        'store_id' => $this->store->id,
                        'platform' => $platform['platform'],
                    ],
                    [
                        'name' => $platform['name'],
                        'shop_domain' => $platform['domain'],
                        'status' => 'active',
                        'credentials' => ['demo' => true],
                        'last_synced_at' => now(),
                    ]
                );

                // Create sales channel for marketplace
                SalesChannel::firstOrCreate(
                    [
                        'store_id' => $this->store->id,
                        'code' => strtoupper($platform['platform']),
                    ],
                    [
                        'name' => $platform['name'],
                        'type' => 'marketplace',
                        'store_marketplace_id' => $marketplace->id,
                        'is_active' => true,
                        'sort_order' => ++$sortOrder,
                    ]
                );
            }
        });
    }
}
