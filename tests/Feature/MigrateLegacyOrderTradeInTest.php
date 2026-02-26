<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MigrateLegacyOrderTradeInTest extends TestCase
{
    use RefreshDatabase;

    protected Store $store;

    protected User $user;

    protected Customer $customer;

    protected bool $legacyConnectionConfigured = false;

    protected int $legacyStoreId = 63;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = Store::factory()->create(['name' => 'Test Store']);
        $this->user = User::factory()->create();
        $this->customer = Customer::factory()->create(['store_id' => $this->store->id]);

        Warehouse::factory()->create([
            'store_id' => $this->store->id,
            'is_default' => true,
        ]);

        $this->setupLegacyConnection();
        $this->createLegacyTables();
    }

    protected function setupLegacyConnection(): void
    {
        $defaultConnection = config('database.default');
        $defaultConfig = config("database.connections.{$defaultConnection}");

        $legacyConfig = array_merge($defaultConfig, [
            'prefix' => 'legacy_',
        ]);

        config(['database.connections.legacy' => $legacyConfig]);
        DB::purge('legacy');
        $this->legacyConnectionConfigured = true;
    }

    protected function tearDown(): void
    {
        if ($this->legacyConnectionConfigured && config('database.connections.legacy')) {
            DB::connection('legacy')->disconnect();
        }

        parent::tearDown();
    }

    protected function createLegacyTables(): void
    {
        Schema::connection('legacy')->create('stores', function ($table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::connection('legacy')->create('customers', function ($table) {
            $table->id();
            $table->unsignedBigInteger('store_id');
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone_number')->nullable();
            $table->string('street_address')->nullable();
            $table->string('street_address2')->nullable();
            $table->string('city')->nullable();
            $table->unsignedBigInteger('state_id')->nullable();
            $table->unsignedBigInteger('country_id')->nullable();
            $table->string('zip')->nullable();
            $table->string('ethnicity')->nullable();
            $table->string('company_name')->nullable();
            $table->string('drivers_license_photo')->nullable();
            $table->string('photo')->nullable();
            $table->boolean('accepts_marketing')->default(false);
            $table->boolean('is_active')->default(true);
            $table->integer('number_of_sales')->default(0);
            $table->integer('number_of_buys')->default(0);
            $table->timestamp('last_sales_date')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::connection('legacy')->create('users', function ($table) {
            $table->id();
            $table->string('email');
            $table->timestamps();
        });

        Schema::connection('legacy')->create('store_users', function ($table) {
            $table->id();
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('user_id');
            $table->timestamps();
        });

        Schema::connection('legacy')->create('orders', function ($table) {
            $table->id();
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('store_marketplace_id')->nullable();
            $table->string('status')->default('pending');
            $table->string('order_id')->nullable();
            $table->string('invoice_number')->nullable();
            $table->decimal('total', 10, 2)->default(0);
            $table->decimal('sub_total', 10, 2)->default(0);
            $table->decimal('sales_tax', 10, 2)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('shipping_cost', 10, 2)->default(0);
            $table->decimal('discount_cost', 10, 2)->default(0);
            $table->decimal('credit_card_fees', 10, 2)->nullable();
            $table->string('service_fee_unit')->nullable();
            $table->string('service_fee_reason')->nullable();
            $table->decimal('service_fee_value', 10, 2)->nullable();
            $table->string('external_marketplace_id')->nullable();
            $table->string('square_order_id')->nullable();
            $table->timestamp('date_of_purchase')->nullable();
            $table->text('customer_note')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::connection('legacy')->create('order_items', function ($table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('variant_id')->nullable();
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->string('sku')->nullable();
            $table->decimal('price', 10, 2)->default(0);
            $table->decimal('cost', 10, 2)->default(0);
            $table->integer('quantity')->default(1);
            $table->decimal('total', 10, 2)->default(0);
            $table->decimal('discount', 10, 2)->default(0);
            $table->unsignedBigInteger('category_id')->nullable();
            $table->timestamps();
        });

        Schema::connection('legacy')->create('payments', function ($table) {
            $table->id();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->string('paymentable_type')->nullable();
            $table->unsignedBigInteger('paymentable_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('type')->nullable();
            $table->string('short_payment_type')->nullable();
            $table->string('status')->default('completed');
            $table->decimal('amount', 10, 2)->default(0);
            $table->string('currency')->default('USD');
            $table->string('reference_id')->nullable();
            $table->string('payment_gateway_transaction_id')->nullable();
            $table->unsignedBigInteger('payment_gateway_id')->nullable();
            $table->text('payment_gateway_data')->nullable();
            $table->text('description')->nullable();
            $table->string('card_type')->nullable();
            $table->string('last_4')->nullable();
            $table->string('entry_type')->nullable();
            $table->decimal('service_fee_value', 10, 2)->nullable();
            $table->string('service_fee_unit')->nullable();
            $table->decimal('service_fee', 10, 2)->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::connection('legacy')->create('order_tradeins', function ($table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('transaction_id')->nullable();
            $table->unsignedBigInteger('transaction_item_id')->nullable();
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->decimal('price', 10, 2)->default(0);
            $table->decimal('cost', 10, 2)->default(0);
            $table->integer('quantity')->default(1);
            $table->decimal('total', 10, 2)->default(0);
            $table->timestamps();
        });

        Schema::connection('legacy')->create('addresses', function ($table) {
            $table->id();
            $table->string('addressable_type');
            $table->unsignedBigInteger('addressable_id');
            $table->string('type')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('company')->nullable();
            $table->string('company_name')->nullable();
            $table->string('address')->nullable();
            $table->string('address2')->nullable();
            $table->string('city')->nullable();
            $table->unsignedBigInteger('state_id')->nullable();
            $table->unsignedBigInteger('country_id')->nullable();
            $table->string('zip')->nullable();
            $table->string('phone')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_shipping')->default(false);
            $table->boolean('is_billing')->default(false);
            $table->timestamps();
        });

        Schema::connection('legacy')->create('states', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('code');
            $table->timestamps();
        });

        Schema::connection('legacy')->create('order_shipping_addresses', function ($table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('address')->nullable();
            $table->string('address2')->nullable();
            $table->string('city')->nullable();
            $table->unsignedBigInteger('state_id')->nullable();
            $table->string('zip')->nullable();
            $table->string('phone')->nullable();
            $table->timestamps();
        });

        Schema::connection('legacy')->create('order_billing_addresses', function ($table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('address')->nullable();
            $table->string('address2')->nullable();
            $table->string('city')->nullable();
            $table->unsignedBigInteger('state_id')->nullable();
            $table->string('zip')->nullable();
            $table->string('phone')->nullable();
            $table->timestamps();
        });

        // Seed legacy store
        DB::connection('legacy')->table('stores')->insert([
            'id' => $this->legacyStoreId,
            'name' => 'Test Store',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Seed a legacy order with a store credit payment AND trade-in items.
     * This reproduces the duplication bug: migrateOrderPayments imports the
     * store credit payment, then migrateOrderTradeIns creates a second one.
     */
    protected function seedLegacyOrderWithStoreCreditAndTradeIn(): Transaction
    {
        $legacyOrderId = 1001;

        // Create a transaction in the new system (already migrated)
        $transaction = Transaction::factory()->paymentProcessed()->create([
            'store_id' => $this->store->id,
        ]);

        // Seed legacy customer
        DB::connection('legacy')->table('customers')->insert([
            'id' => 1,
            'store_id' => $this->legacyStoreId,
            'first_name' => $this->customer->first_name,
            'last_name' => $this->customer->last_name,
            'email' => $this->customer->email,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Seed legacy order
        DB::connection('legacy')->table('orders')->insert([
            'id' => $legacyOrderId,
            'store_id' => $this->legacyStoreId,
            'customer_id' => 1,
            'status' => 'completed',
            'order_id' => 'ORD-1001',
            'total' => 500.00,
            'sub_total' => 500.00,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Seed a legacy store credit payment for this order (the trade-in payment from legacy)
        DB::connection('legacy')->table('payments')->insert([
            'id' => 5001,
            'order_id' => $legacyOrderId,
            'paymentable_type' => 'App\\Models\\Order',
            'paymentable_id' => $legacyOrderId,
            'user_id' => null,
            'short_payment_type' => 'store_credit',
            'status' => 'completed',
            'amount' => 200.00,
            'currency' => 'USD',
            'reference_id' => 'legacy_tradein_ref',
            'description' => 'Trade-in credit',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Seed a cash payment too
        DB::connection('legacy')->table('payments')->insert([
            'id' => 5002,
            'order_id' => $legacyOrderId,
            'paymentable_type' => 'App\\Models\\Order',
            'paymentable_id' => $legacyOrderId,
            'user_id' => null,
            'short_payment_type' => 'cash',
            'status' => 'completed',
            'amount' => 300.00,
            'currency' => 'USD',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Seed legacy trade-in item
        DB::connection('legacy')->table('order_tradeins')->insert([
            'id' => 1,
            'order_id' => $legacyOrderId,
            'transaction_id' => $transaction->id,
            'transaction_item_id' => null,
            'title' => 'Gold Ring Trade-In',
            'description' => 'A gold ring',
            'price' => 200.00,
            'cost' => 100.00,
            'quantity' => 1,
            'total' => 200.00,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $transaction;
    }

    public function test_does_not_duplicate_store_credit_payment_for_trade_in(): void
    {
        $this->seedLegacyOrderWithStoreCreditAndTradeIn();

        $this->artisan('migrate:legacy-orders', [
            '--store-id' => $this->legacyStoreId,
            '--new-store-id' => $this->store->id,
            '--no-interaction' => true,
        ])->assertExitCode(0);

        // Verify the order was created
        $order = Order::find(1001);
        $this->assertNotNull($order, 'Order should have been migrated');

        // Should have exactly ONE store credit payment, not two
        $storeCreditPayments = Payment::where('order_id', 1001)
            ->where('payment_method', Payment::METHOD_STORE_CREDIT)
            ->get();

        $this->assertCount(1, $storeCreditPayments, 'Store credit payment should not be duplicated');
        $this->assertEquals(200.00, (float) $storeCreditPayments->first()->amount);
    }

    public function test_creates_store_credit_payment_when_not_already_imported(): void
    {
        $legacyOrderId = 1002;

        $transaction = Transaction::factory()->paymentProcessed()->create([
            'store_id' => $this->store->id,
        ]);

        DB::connection('legacy')->table('customers')->insert([
            'id' => 2,
            'store_id' => $this->legacyStoreId,
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => 'jane@example.com',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::connection('legacy')->table('orders')->insert([
            'id' => $legacyOrderId,
            'store_id' => $this->legacyStoreId,
            'customer_id' => 2,
            'status' => 'completed',
            'order_id' => 'ORD-1002',
            'total' => 500.00,
            'sub_total' => 500.00,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Only a cash payment in legacy (no store credit)
        DB::connection('legacy')->table('payments')->insert([
            'id' => 6001,
            'order_id' => $legacyOrderId,
            'paymentable_type' => 'App\\Models\\Order',
            'paymentable_id' => $legacyOrderId,
            'short_payment_type' => 'cash',
            'status' => 'completed',
            'amount' => 350.00,
            'currency' => 'USD',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Trade-in exists
        DB::connection('legacy')->table('order_tradeins')->insert([
            'id' => 2,
            'order_id' => $legacyOrderId,
            'transaction_id' => $transaction->id,
            'title' => 'Silver Bracelet Trade-In',
            'price' => 150.00,
            'cost' => 75.00,
            'quantity' => 1,
            'total' => 150.00,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('migrate:legacy-orders', [
            '--store-id' => $this->legacyStoreId,
            '--new-store-id' => $this->store->id,
            '--no-interaction' => true,
        ])->assertExitCode(0);

        // Should create exactly one store credit payment for the trade-in
        $storeCreditPayments = Payment::where('order_id', $legacyOrderId)
            ->where('payment_method', Payment::METHOD_STORE_CREDIT)
            ->get();

        $this->assertCount(1, $storeCreditPayments);
        $this->assertEquals(150.00, (float) $storeCreditPayments->first()->amount);
        $this->assertEquals('trade_in_'.$transaction->id, $storeCreditPayments->first()->reference);
    }

    public function test_trade_in_credit_is_set_on_order(): void
    {
        $this->seedLegacyOrderWithStoreCreditAndTradeIn();

        $this->artisan('migrate:legacy-orders', [
            '--store-id' => $this->legacyStoreId,
            '--new-store-id' => $this->store->id,
            '--no-interaction' => true,
        ])->assertExitCode(0);

        $order = Order::find(1001);
        $this->assertNotNull($order);
        $this->assertEquals(200.00, (float) $order->trade_in_credit);
        $this->assertNotNull($order->trade_in_transaction_id);
    }
}
