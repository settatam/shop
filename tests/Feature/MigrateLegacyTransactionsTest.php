<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Customer;
use App\Models\ProductTemplate;
use App\Models\ProductTemplateField;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\TransactionItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MigrateLegacyTransactionsTest extends TestCase
{
    use RefreshDatabase;

    protected Store $store;

    protected bool $legacyConnectionConfigured = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = Store::factory()->create();

        // Set up legacy database connection - use the same connection as the main database
        // but with a prefix to separate legacy tables
        $this->setupLegacyConnection();

        // Create legacy tables
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
        // Disconnect legacy connection if configured
        // Note: Tables are NOT dropped - they use a prefix on the test database
        // and will be cleaned up by RefreshDatabase trait
        if ($this->legacyConnectionConfigured && config('database.connections.legacy')) {
            DB::connection('legacy')->disconnect();
        }

        parent::tearDown();
    }

    protected function createLegacyTables(): void
    {
        Schema::connection('legacy')->create('transactions', function ($table) {
            $table->id();
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('status_id')->default(60);
            $table->decimal('preliminary_offer', 10, 2)->nullable();
            $table->decimal('final_offer', 10, 2)->nullable();
            $table->decimal('estimated_value', 10, 2)->nullable();
            $table->string('bin_location')->nullable();
            $table->boolean('is_in_house')->default(false);
            $table->text('pub_note')->nullable();
            $table->text('private_note')->nullable();
            $table->text('customer_description')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::connection('legacy')->create('customers', function ($table) {
            $table->id();
            $table->unsignedBigInteger('store_id');
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('company_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone_number')->nullable();
            $table->string('address')->nullable();
            $table->string('address2')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->unsignedBigInteger('state_id')->nullable();
            $table->string('zip')->nullable();
            $table->string('ethnicity')->nullable();
            $table->boolean('accepts_marketing')->default(false);
            $table->boolean('is_active')->default(true);
            $table->text('customer_notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::connection('legacy')->create('statuses', function ($table) {
            $table->id();
            $table->unsignedBigInteger('status_id');
            $table->string('name');
            $table->unsignedBigInteger('store_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::connection('legacy')->create('transaction_items', function ($table) {
            $table->id();
            $table->unsignedBigInteger('transaction_id');
            $table->string('item')->nullable();
            $table->string('sku')->nullable();
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->decimal('price', 10, 2)->nullable();
            $table->decimal('buy_price', 10, 2)->nullable();
            $table->decimal('dwt', 8, 4)->nullable();
            $table->string('precious_metal')->nullable();
            $table->string('condition')->nullable();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->unsignedBigInteger('product_type_id')->nullable();
            $table->unsignedBigInteger('html_form_id')->nullable();
            $table->boolean('is_added_to_inventory')->default(false);
            $table->timestamp('date_added_to_inventory')->nullable();
            $table->timestamp('reviewed_date_time')->nullable();
            $table->timestamps();
        });

        Schema::connection('legacy')->create('store_categories', function ($table) {
            $table->id();
            $table->unsignedBigInteger('store_id');
            $table->string('name');
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->unsignedBigInteger('html_form_id')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::connection('legacy')->create('html_forms', function ($table) {
            $table->id();
            $table->unsignedBigInteger('store_id');
            $table->string('title');
            $table->text('description')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::connection('legacy')->create('html_form_fields', function ($table) {
            $table->id();
            $table->unsignedBigInteger('html_form_id');
            $table->string('name');
            $table->string('label')->nullable();
            $table->string('type')->default('text');
            $table->string('placeholder')->nullable();
            $table->boolean('is_required')->default(false);
            $table->boolean('is_searchable')->default(true);
            $table->boolean('show_in_listing')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::connection('legacy')->create('html_form_field_options', function ($table) {
            $table->id();
            $table->unsignedBigInteger('html_form_field_id');
            $table->string('value');
            $table->string('label')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::connection('legacy')->create('metas', function ($table) {
            $table->id();
            $table->string('metaable_type');
            $table->unsignedBigInteger('metaable_id');
            $table->string('field');
            $table->text('value')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::connection('legacy')->create('images', function ($table) {
            $table->id();
            $table->string('imageable_type');
            $table->unsignedBigInteger('imageable_id');
            $table->string('url');
            $table->string('thumbnail')->nullable();
            $table->integer('rank')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::connection('legacy')->create('transaction_item_images', function ($table) {
            $table->id();
            $table->unsignedBigInteger('transaction_item_id');
            $table->string('url');
            $table->string('thumbnail_url')->nullable();
            $table->timestamps();
        });

        Schema::connection('legacy')->create('addresses', function ($table) {
            $table->id();
            $table->string('addressable_type');
            $table->unsignedBigInteger('addressable_id');
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('full_name')->nullable();
            $table->string('address')->nullable();
            $table->string('address2')->nullable();
            $table->string('city')->nullable();
            $table->unsignedBigInteger('state_id')->nullable();
            $table->string('zip')->nullable();
            $table->string('phone')->nullable();
            $table->timestamps();
        });

        Schema::connection('legacy')->create('states', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('code');
            $table->timestamps();
        });

        Schema::connection('legacy')->create('store_activities', function ($table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('activity');
            $table->string('activityable_type');
            $table->unsignedBigInteger('activityable_id');
            $table->string('creatable_type')->nullable();
            $table->unsignedBigInteger('creatable_id')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::connection('legacy')->create('activities', function ($table) {
            $table->id();
            $table->string('activityable_type');
            $table->unsignedBigInteger('activityable_id');
            $table->string('name')->nullable();
            $table->string('status')->nullable();
            $table->text('notes')->nullable();
            $table->decimal('offer', 10, 2)->nullable();
            $table->boolean('is_status')->default(0);
            $table->boolean('is_tag')->default(0);
            $table->boolean('is_from_admin')->default(0);
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamps();
        });

        Schema::connection('legacy')->create('shipping_labels', function ($table) {
            $table->id();
            $table->string('shippable_type');
            $table->unsignedBigInteger('shippable_id');
            $table->boolean('to_customer')->default(false);
            $table->boolean('is_return')->default(false);
            $table->string('tracking_number')->nullable();
            $table->timestamps();
        });

        Schema::connection('legacy')->create('transaction_payment_addresses', function ($table) {
            $table->id();
            $table->unsignedBigInteger('transaction_id');
            $table->unsignedBigInteger('payment_type_id')->nullable();
            $table->timestamps();
        });

        Schema::connection('legacy')->create('transaction_payment_types', function ($table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        // Seed default statuses
        DB::connection('legacy')->table('statuses')->insert([
            ['status_id' => 60, 'name' => 'Pending Kit Request', 'created_at' => now(), 'updated_at' => now()],
            ['status_id' => 2, 'name' => 'Kit Received', 'created_at' => now(), 'updated_at' => now()],
            ['status_id' => 4, 'name' => 'Offer Given', 'created_at' => now(), 'updated_at' => now()],
            ['status_id' => 5, 'name' => 'Offer Accepted', 'created_at' => now(), 'updated_at' => now()],
            ['status_id' => 8, 'name' => 'Payment Processed', 'created_at' => now(), 'updated_at' => now()],
        ]);

        // Seed payment types
        DB::connection('legacy')->table('transaction_payment_types')->insert([
            ['id' => 1, 'name' => 'Check', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'name' => 'PayPal', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 3, 'name' => 'ACH', 'created_at' => now(), 'updated_at' => now()],
        ]);

        // Seed states
        DB::connection('legacy')->table('states')->insert([
            ['id' => 1, 'name' => 'California', 'code' => 'CA', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'name' => 'New York', 'code' => 'NY', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    /**
     * Create a legacy customer and return its ID.
     * The migration command requires customer_id to be set for transactions.
     */
    protected function createLegacyCustomer(): int
    {
        return DB::connection('legacy')->table('customers')->insertGetId([
            'store_id' => $this->store->id,
            'first_name' => 'Test',
            'last_name' => 'Customer',
            'email' => 'test'.uniqid().'@example.com',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_command_fails_without_legacy_connection(): void
    {
        // Remove the legacy connection entirely and purge the cached connection
        DB::connection('legacy')->disconnect();
        config(['database.connections.legacy' => null]);
        DB::purge('legacy');

        // Mark that we deliberately removed the connection
        $this->legacyConnectionConfigured = false;

        $this->artisan('migrate:legacy-transactions', ['store_id' => 999])
            ->assertFailed();
    }

    public function test_command_shows_no_transactions_message(): void
    {
        $this->artisan('migrate:legacy-transactions', ['store_id' => $this->store->id])
            ->expectsOutput('No transactions found for this store')
            ->assertSuccessful();
    }

    public function test_migrates_transaction_with_customer(): void
    {
        // Create legacy customer
        $legacyCustomerId = DB::connection('legacy')->table('customers')->insertGetId([
            'store_id' => $this->store->id,
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john@example.com',
            'phone_number' => '555-1234',
            'city' => 'Los Angeles',
            'state_id' => 1,
            'zip' => '90001',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create legacy transaction
        $legacyTransactionId = DB::connection('legacy')->table('transactions')->insertGetId([
            'store_id' => $this->store->id,
            'customer_id' => $legacyCustomerId,
            'status_id' => 60,
            'preliminary_offer' => 100.00,
            'final_offer' => 150.00,
            'is_in_house' => false,
            'pub_note' => 'Customer note',
            'private_note' => 'Internal note',
            'created_at' => now()->subDays(5),
            'updated_at' => now()->subDays(3),
        ]);

        // Verify legacy data exists
        $legacyCount = DB::connection('legacy')->table('transactions')
            ->where('store_id', $this->store->id)
            ->count();
        $this->assertEquals(1, $legacyCount, 'Legacy transaction should exist');

        // Debug: Check customer count before command
        $customerCountBefore = Customer::count();

        // Run the command using Artisan facade to capture all output
        $exitCode = \Illuminate\Support\Facades\Artisan::call('migrate:legacy-transactions', [
            'store_id' => $this->store->id,
        ]);
        $output = \Illuminate\Support\Facades\Artisan::output();

        // Debug: Check customer count after command
        $customerCountAfter = Customer::count();
        $newCustomers = Customer::latest()->take(5)->get()->toArray();

        // Check command succeeded
        $this->assertEquals(0, $exitCode, "Command failed with output: {$output}");
        $this->assertStringContainsString('Found 1 transactions to migrate', $output, "Output: {$output}");

        // Debug: Check transaction count immediately after command
        $transactionCount = Transaction::count();
        $this->assertGreaterThan(
            0,
            $transactionCount,
            "Expected at least 1 transaction, found: {$transactionCount}. ".
            "Customers before: {$customerCountBefore}, after: {$customerCountAfter}. ".
            'New customers: '.json_encode($newCustomers)."\n".
            "Full output:\n{$output}"
        );

        // Verify customer was created
        $this->assertDatabaseHas('customers', [
            'store_id' => $this->store->id,
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john@example.com',
        ]);

        // Verify transaction was created
        $this->assertDatabaseHas('transactions', [
            'store_id' => $this->store->id,
            'status' => Transaction::STATUS_PENDING_KIT_REQUEST,
            'type' => Transaction::TYPE_MAIL_IN,
            'preliminary_offer' => 100.00,
            'final_offer' => 150.00,
            'customer_notes' => 'Customer note',
            'internal_notes' => 'Internal note',
        ]);
    }

    public function test_migrates_transaction_items(): void
    {
        $customerId = $this->createLegacyCustomer();

        // Create legacy transaction
        $legacyTransactionId = DB::connection('legacy')->table('transactions')->insertGetId([
            'store_id' => $this->store->id,
            'customer_id' => $customerId,
            'status_id' => 60,
            'is_in_house' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create legacy items (separate inserts for SQLite compatibility)
        DB::connection('legacy')->table('transaction_items')->insert([
            'transaction_id' => $legacyTransactionId,
            'sku' => 'ITEM-001',
            'title' => 'Gold Ring',
            'description' => 'Beautiful gold ring',
            'price' => 250.00,
            'buy_price' => 200.00,
            'dwt' => 2.5,
            'precious_metal' => '14k Gold',
            'condition' => 'Excellent',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('legacy')->table('transaction_items')->insert([
            'transaction_id' => $legacyTransactionId,
            'sku' => 'ITEM-002',
            'title' => 'Silver Bracelet',
            'description' => null,
            'price' => 100.00,
            'buy_price' => 75.00,
            'dwt' => 5.0,
            'precious_metal' => 'Sterling Silver',
            'condition' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('migrate:legacy-transactions', ['store_id' => $this->store->id])
            ->assertSuccessful();

        // Verify items were created
        $transaction = Transaction::where('store_id', $this->store->id)->first();
        $this->assertNotNull($transaction);
        $this->assertCount(2, $transaction->items);

        $this->assertDatabaseHas('transaction_items', [
            'transaction_id' => $transaction->id,
            'sku' => 'ITEM-001',
            'title' => 'Gold Ring',
            'precious_metal' => '14k Gold',
        ]);
    }

    public function test_migrates_item_images(): void
    {
        $customerId = $this->createLegacyCustomer();

        // Create legacy transaction
        $legacyTransactionId = DB::connection('legacy')->table('transactions')->insertGetId([
            'store_id' => $this->store->id,
            'customer_id' => $customerId,
            'status_id' => 60,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create legacy item
        $legacyItemId = DB::connection('legacy')->table('transaction_items')->insertGetId([
            'transaction_id' => $legacyTransactionId,
            'title' => 'Gold Ring',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create legacy images
        DB::connection('legacy')->table('images')->insert([
            'imageable_type' => 'App\\Models\\TransactionItem',
            'imageable_id' => $legacyItemId,
            'url' => 'https://example.com/image1.jpg',
            'thumbnail' => 'https://example.com/thumb1.jpg',
            'rank' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('migrate:legacy-transactions', ['store_id' => $this->store->id])
            ->assertSuccessful();

        // Verify images were created
        $item = TransactionItem::first();
        $this->assertNotNull($item);
        $this->assertCount(1, $item->images);
        $this->assertEquals('https://example.com/image1.jpg', $item->images->first()->url);
    }

    public function test_migrates_activities(): void
    {
        $customerId = $this->createLegacyCustomer();

        // Create legacy transaction
        $legacyTransactionId = DB::connection('legacy')->table('transactions')->insertGetId([
            'store_id' => $this->store->id,
            'customer_id' => $customerId,
            'status_id' => 60,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create legacy activities (individual inserts for SQLite compatibility)
        DB::connection('legacy')->table('store_activities')->insert([
            'activityable_type' => 'App\\Models\\Transaction',
            'activityable_id' => $legacyTransactionId,
            'activity' => 'created_transaction',
            'description' => 'Transaction created',
            'user_id' => null,
            'created_at' => now()->subHours(2),
            'updated_at' => now()->subHours(2),
        ]);
        DB::connection('legacy')->table('store_activities')->insert([
            'activityable_type' => 'App\\Models\\Transaction',
            'activityable_id' => $legacyTransactionId,
            'activity' => 'status_updated',
            'description' => 'Status changed to Kit Received',
            'user_id' => null,
            'created_at' => now()->subHour(),
            'updated_at' => now()->subHour(),
        ]);

        $this->artisan('migrate:legacy-transactions', ['store_id' => $this->store->id])
            ->assertSuccessful();

        // Verify activities were created
        $transaction = Transaction::where('store_id', $this->store->id)->first();
        $activities = ActivityLog::where('subject_type', Transaction::class)
            ->where('subject_id', $transaction->id)
            ->get();

        $this->assertCount(2, $activities);
        $this->assertEquals('transactions.create', $activities->first()->activity_slug);
    }

    public function test_dry_run_does_not_create_records(): void
    {
        $customerId = $this->createLegacyCustomer();

        // Create legacy transaction
        DB::connection('legacy')->table('transactions')->insert([
            'store_id' => $this->store->id,
            'customer_id' => $customerId,
            'status_id' => 60,
            'is_in_house' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('migrate:legacy-transactions', [
            'store_id' => $this->store->id,
            '--dry-run' => true,
        ])->assertSuccessful();

        // Verify no transactions were created
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_skips_already_migrated_transactions(): void
    {
        $customerId = $this->createLegacyCustomer();

        // Create legacy transaction
        $legacyTransactionId = DB::connection('legacy')->table('transactions')->insertGetId([
            'store_id' => $this->store->id,
            'customer_id' => $customerId,
            'status_id' => 60,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Run migration twice
        $this->artisan('migrate:legacy-transactions', ['store_id' => $this->store->id])
            ->assertSuccessful();

        $this->artisan('migrate:legacy-transactions', ['store_id' => $this->store->id])
            ->assertSuccessful();

        // Verify only one transaction was created
        $this->assertDatabaseCount('transactions', 1);
    }

    public function test_in_store_transaction_type(): void
    {
        $customerId = $this->createLegacyCustomer();

        // Create legacy in-house transaction
        DB::connection('legacy')->table('transactions')->insert([
            'store_id' => $this->store->id,
            'customer_id' => $customerId,
            'status_id' => 60,
            'is_in_house' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('migrate:legacy-transactions', ['store_id' => $this->store->id])
            ->assertSuccessful();

        $this->assertDatabaseHas('transactions', [
            'store_id' => $this->store->id,
            'type' => Transaction::TYPE_IN_STORE,
        ]);
    }

    public function test_mail_in_transaction_type(): void
    {
        $customerId = $this->createLegacyCustomer();

        // Create legacy mail-in transaction
        DB::connection('legacy')->table('transactions')->insert([
            'store_id' => $this->store->id,
            'customer_id' => $customerId,
            'status_id' => 60,
            'is_in_house' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('migrate:legacy-transactions', ['store_id' => $this->store->id])
            ->assertSuccessful();

        $this->assertDatabaseHas('transactions', [
            'store_id' => $this->store->id,
            'type' => Transaction::TYPE_MAIL_IN,
        ]);
    }

    public function test_status_mapping(): void
    {
        $customerId = $this->createLegacyCustomer();

        $testCases = [
            ['legacy_status' => 60, 'expected' => Transaction::STATUS_PENDING_KIT_REQUEST],
            ['legacy_status' => 2, 'expected' => Transaction::STATUS_ITEMS_RECEIVED],
            ['legacy_status' => 4, 'expected' => Transaction::STATUS_OFFER_GIVEN],
            ['legacy_status' => 5, 'expected' => Transaction::STATUS_OFFER_ACCEPTED],
            ['legacy_status' => 8, 'expected' => Transaction::STATUS_PAYMENT_PROCESSED],
        ];

        foreach ($testCases as $index => $testCase) {
            DB::connection('legacy')->table('transactions')->insert([
                'store_id' => $this->store->id,
                'customer_id' => $customerId,
                'status_id' => $testCase['legacy_status'],
                'is_in_house' => true,
                'bin_location' => "TEST-{$index}",
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->artisan('migrate:legacy-transactions', ['store_id' => $this->store->id])
            ->assertSuccessful();

        foreach ($testCases as $index => $testCase) {
            $transaction = Transaction::where('store_id', $this->store->id)
                ->where('bin_location', "TEST-{$index}")
                ->first();

            $this->assertNotNull($transaction, "Transaction not found for TEST-{$index}");
            $this->assertEquals(
                $testCase['expected'],
                $transaction->status,
                "Status mismatch for legacy status_id {$testCase['legacy_status']}"
            );
        }
    }

    public function test_skip_customers_option(): void
    {
        // Create legacy customer
        $legacyCustomerId = DB::connection('legacy')->table('customers')->insertGetId([
            'store_id' => $this->store->id,
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john@example.com',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create legacy transaction
        DB::connection('legacy')->table('transactions')->insert([
            'store_id' => $this->store->id,
            'customer_id' => $legacyCustomerId,
            'status_id' => 60,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('migrate:legacy-transactions', [
            'store_id' => $this->store->id,
            '--skip-customers' => true,
        ])->assertSuccessful();

        // Verify customer was not created
        $this->assertDatabaseCount('customers', 0);

        // Verify transaction was created without customer
        $this->assertDatabaseHas('transactions', [
            'store_id' => $this->store->id,
            'customer_id' => null,
        ]);
    }

    public function test_skip_activities_option(): void
    {
        $customerId = $this->createLegacyCustomer();

        // Create legacy transaction
        $legacyTransactionId = DB::connection('legacy')->table('transactions')->insertGetId([
            'store_id' => $this->store->id,
            'customer_id' => $customerId,
            'status_id' => 60,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create legacy activity
        DB::connection('legacy')->table('store_activities')->insert([
            'activityable_type' => 'App\\Models\\Transaction',
            'activityable_id' => $legacyTransactionId,
            'activity' => 'created_transaction',
            'description' => 'Transaction created',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('migrate:legacy-transactions', [
            'store_id' => $this->store->id,
            '--skip-activities' => true,
        ])->assertSuccessful();

        // Verify activity was not created
        $this->assertDatabaseCount('activity_logs', 0);
    }

    public function test_new_store_id_option(): void
    {
        $customerId = $this->createLegacyCustomer();
        $newStore = Store::factory()->create();

        // Create legacy transaction in original store
        DB::connection('legacy')->table('transactions')->insert([
            'store_id' => $this->store->id,
            'customer_id' => $customerId,
            'status_id' => 60,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('migrate:legacy-transactions', [
            'store_id' => $this->store->id,
            '--new-store-id' => $newStore->id,
        ])->assertSuccessful();

        // Verify transaction was created in new store
        $this->assertDatabaseHas('transactions', [
            'store_id' => $newStore->id,
        ]);

        // Verify transaction was not created in original store
        $this->assertDatabaseMissing('transactions', [
            'store_id' => $this->store->id,
        ]);
    }

    public function test_limit_option(): void
    {
        $customerId = $this->createLegacyCustomer();

        // Create multiple legacy transactions
        for ($i = 0; $i < 5; $i++) {
            DB::connection('legacy')->table('transactions')->insert([
                'store_id' => $this->store->id,
                'customer_id' => $customerId,
                'status_id' => 60,
                'bin_location' => "BIN-{$i}",
                'created_at' => now()->addMinutes($i),
                'updated_at' => now()->addMinutes($i),
            ]);
        }

        $this->artisan('migrate:legacy-transactions', [
            'store_id' => $this->store->id,
            '--limit' => 2,
        ])->assertSuccessful();

        // Verify only 2 transactions were created
        $this->assertDatabaseCount('transactions', 2);
    }

    public function test_customer_deduplication_by_email(): void
    {
        // Create existing customer
        $existingCustomer = Customer::factory()->create([
            'store_id' => $this->store->id,
            'email' => 'john@example.com',
        ]);

        // Create legacy customer with same email
        $legacyCustomerId = DB::connection('legacy')->table('customers')->insertGetId([
            'store_id' => $this->store->id,
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john@example.com',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create legacy transaction
        DB::connection('legacy')->table('transactions')->insert([
            'store_id' => $this->store->id,
            'customer_id' => $legacyCustomerId,
            'status_id' => 60,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('migrate:legacy-transactions', ['store_id' => $this->store->id])
            ->assertSuccessful();

        // Verify customer was not duplicated
        $this->assertDatabaseCount('customers', 1);

        // Verify transaction uses existing customer
        $this->assertDatabaseHas('transactions', [
            'store_id' => $this->store->id,
            'customer_id' => $existingCustomer->id,
        ]);
    }

    public function test_migrates_transaction_item_template_values(): void
    {
        // Create a template with fields in the new system
        $template = ProductTemplate::create([
            'store_id' => $this->store->id,
            'name' => 'Ring Template',
            'is_active' => true,
        ]);

        $metalField = ProductTemplateField::create([
            'product_template_id' => $template->id,
            'name' => 'metal_type',
            'canonical_name' => 'metal_type',
            'label' => 'Metal Type',
            'type' => 'select',
            'sort_order' => 1,
        ]);

        $caratField = ProductTemplateField::create([
            'product_template_id' => $template->id,
            'name' => 'carat_weight',
            'canonical_name' => 'carat_weight',
            'label' => 'Carat Weight',
            'type' => 'text',
            'sort_order' => 2,
        ]);

        // Create a category with the template in the new system
        $category = Category::create([
            'store_id' => $this->store->id,
            'name' => 'Rings',
            'slug' => 'rings',
            'template_id' => $template->id,
        ]);

        // Create legacy template (html_form)
        $legacyTemplateId = DB::connection('legacy')->table('html_forms')->insertGetId([
            'store_id' => $this->store->id,
            'title' => 'Ring Template',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create legacy template fields
        $legacyMetalFieldId = DB::connection('legacy')->table('html_form_fields')->insertGetId([
            'html_form_id' => $legacyTemplateId,
            'name' => 'metal_type',
            'label' => 'Metal Type',
            'type' => 'select',
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $legacyCaratFieldId = DB::connection('legacy')->table('html_form_fields')->insertGetId([
            'html_form_id' => $legacyTemplateId,
            'name' => 'carat_weight',
            'label' => 'Carat Weight',
            'type' => 'text',
            'sort_order' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create field options for the metal type select
        DB::connection('legacy')->table('html_form_field_options')->insert([
            ['html_form_field_id' => $legacyMetalFieldId, 'value' => '14k-gold', 'label' => '14K Gold', 'sort_order' => 1],
            ['html_form_field_id' => $legacyMetalFieldId, 'value' => '18k-gold', 'label' => '18K Gold', 'sort_order' => 2],
            ['html_form_field_id' => $legacyMetalFieldId, 'value' => 'platinum', 'label' => 'Platinum', 'sort_order' => 3],
        ]);

        // Create legacy category (store_categories) pointing to the template
        DB::connection('legacy')->table('store_categories')->insert([
            'id' => $category->id, // Use same ID as new category
            'store_id' => $this->store->id,
            'name' => 'Rings',
            'html_form_id' => $legacyTemplateId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create legacy customer and transaction
        $customerId = $this->createLegacyCustomer();

        $legacyTransactionId = DB::connection('legacy')->table('transactions')->insertGetId([
            'store_id' => $this->store->id,
            'customer_id' => $customerId,
            'status_id' => 60,
            'is_in_house' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create legacy transaction item with product_type_id pointing to the category
        $legacyItemId = DB::connection('legacy')->table('transaction_items')->insertGetId([
            'transaction_id' => $legacyTransactionId,
            'sku' => 'RING-001',
            'title' => 'Gold Ring',
            'price' => 500.00,
            'product_type_id' => $category->id,
            'html_form_id' => $legacyTemplateId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create legacy meta values for this transaction item
        DB::connection('legacy')->table('metas')->insert([
            [
                'metaable_type' => 'App\\Models\\TransactionItem',
                'metaable_id' => $legacyItemId,
                'field' => 'metal_type',
                'value' => '14K Gold', // This should be transformed to '14k-gold'
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'metaable_type' => 'App\\Models\\TransactionItem',
                'metaable_id' => $legacyItemId,
                'field' => 'carat_weight',
                'value' => '0.75',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        // Run the migration
        $this->artisan('migrate:legacy-transactions', ['store_id' => $this->store->id])
            ->assertSuccessful();

        // Verify transaction item was created
        $item = TransactionItem::where('sku', 'RING-001')->first();
        $this->assertNotNull($item);

        // Verify category is set
        $this->assertEquals($category->id, $item->category_id);

        // Verify template values are in the attributes JSON
        $attributes = $item->attributes;
        $this->assertNotNull($attributes);
        $this->assertArrayHasKey('template_id', $attributes);
        $this->assertEquals($template->id, $attributes['template_id']);
        $this->assertArrayHasKey('template_values', $attributes);

        // Verify template values were migrated correctly
        $templateValues = $attributes['template_values'];
        $this->assertArrayHasKey((string) $metalField->id, $templateValues);
        $this->assertArrayHasKey((string) $caratField->id, $templateValues);

        // Metal type should be transformed to match the select option value
        $this->assertEquals('14k-gold', $templateValues[(string) $metalField->id]);
        $this->assertEquals('0.75', $templateValues[(string) $caratField->id]);
    }

    public function test_transaction_item_without_template_still_migrates(): void
    {
        $customerId = $this->createLegacyCustomer();

        // Create legacy transaction without category/template
        $legacyTransactionId = DB::connection('legacy')->table('transactions')->insertGetId([
            'store_id' => $this->store->id,
            'customer_id' => $customerId,
            'status_id' => 60,
            'is_in_house' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create legacy transaction item without product_type_id
        DB::connection('legacy')->table('transaction_items')->insert([
            'transaction_id' => $legacyTransactionId,
            'sku' => 'ITEM-NO-CAT',
            'title' => 'Item Without Category',
            'price' => 100.00,
            'product_type_id' => null,
            'html_form_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Run the migration
        $this->artisan('migrate:legacy-transactions', ['store_id' => $this->store->id])
            ->assertSuccessful();

        // Verify transaction item was created
        $item = TransactionItem::where('sku', 'ITEM-NO-CAT')->first();
        $this->assertNotNull($item);

        // Verify attributes still has proper structure
        $attributes = $item->attributes;
        $this->assertArrayHasKey('template_id', $attributes);
        $this->assertNull($attributes['template_id']);
        $this->assertArrayHasKey('template_values', $attributes);
        $this->assertEmpty($attributes['template_values']);
    }

    public function test_migrates_payment_processed_at_from_activity_log(): void
    {
        $customerId = $this->createLegacyCustomer();
        $paymentDate = now()->subDays(5);

        // Create legacy transaction
        $legacyTransactionId = DB::connection('legacy')->table('transactions')->insertGetId([
            'store_id' => $this->store->id,
            'customer_id' => $customerId,
            'status_id' => 8, // payment_processed
            'is_in_house' => true,
            'final_offer' => 100.00,
            'created_at' => now()->subDays(10),
            'updated_at' => now()->subDays(3),
        ]);

        // Create legacy payment activity with specific timestamp
        DB::connection('legacy')->table('store_activities')->insert([
            'activityable_type' => 'App\\Models\\Transaction',
            'activityable_id' => $legacyTransactionId,
            'activity' => 'payment_added_to_transaction',
            'description' => 'Payment added',
            'created_at' => $paymentDate,
            'updated_at' => $paymentDate,
        ]);

        // Run the migration
        $this->artisan('migrate:legacy-transactions', ['store_id' => $this->store->id])
            ->assertSuccessful();

        // Verify payment_processed_at was migrated from the activity log
        $transaction = Transaction::where('store_id', $this->store->id)->first();
        $this->assertNotNull($transaction->payment_processed_at);
        $this->assertEquals(
            $paymentDate->format('Y-m-d H:i:s'),
            $transaction->payment_processed_at->format('Y-m-d H:i:s')
        );
    }

    public function test_migrates_item_prices_exactly_as_in_legacy(): void
    {
        $customerId = $this->createLegacyCustomer();

        // Create legacy transaction
        $legacyTransactionId = DB::connection('legacy')->table('transactions')->insertGetId([
            'store_id' => $this->store->id,
            'customer_id' => $customerId,
            'status_id' => 60,
            'is_in_house' => true,
            'final_offer' => 500.00,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create items with specific prices - these should be migrated exactly
        DB::connection('legacy')->table('transaction_items')->insert([
            [
                'transaction_id' => $legacyTransactionId,
                'sku' => 'EXACT-PRICE-1',
                'title' => 'Item with exact price',
                'price' => 123.45,
                'buy_price' => 100.00,
                'dwt' => 2.0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'transaction_id' => $legacyTransactionId,
                'sku' => 'ZERO-PRICE',
                'title' => 'Item with zero price',
                'price' => 0, // Zero price should stay zero - no calculation
                'buy_price' => 0,
                'dwt' => 1.0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        // Run the migration
        $this->artisan('migrate:legacy-transactions', ['store_id' => $this->store->id])
            ->assertSuccessful();

        // Verify prices are migrated exactly as they were in legacy
        $item1 = TransactionItem::where('sku', 'EXACT-PRICE-1')->first();
        $this->assertEquals(123.45, $item1->price);
        $this->assertEquals(100.00, $item1->buy_price);

        // Zero price should remain zero - no backfill calculation
        $item2 = TransactionItem::where('sku', 'ZERO-PRICE')->first();
        $this->assertEquals(0, $item2->price);
        $this->assertEquals(0, $item2->buy_price);
    }

    public function test_transaction_id_option_reimports_single_transaction(): void
    {
        $customerId = $this->createLegacyCustomer();

        // Create legacy transaction
        $legacyTransactionId = DB::connection('legacy')->table('transactions')->insertGetId([
            'store_id' => $this->store->id,
            'customer_id' => $customerId,
            'status_id' => 60,
            'preliminary_offer' => 100.00,
            'final_offer' => 150.00,
            'is_in_house' => false,
            'pub_note' => 'Original note',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // First import
        $this->artisan('migrate:legacy-transactions', ['store_id' => $this->store->id])
            ->assertSuccessful();

        $this->assertDatabaseCount('transactions', 1);
        $this->assertDatabaseHas('transactions', [
            'id' => $legacyTransactionId,
            'customer_notes' => 'Original note',
        ]);

        // Update legacy transaction
        DB::connection('legacy')->table('transactions')
            ->where('id', $legacyTransactionId)
            ->update(['pub_note' => 'Updated note', 'final_offer' => 200.00]);

        // Re-import single transaction
        $this->artisan('migrate:legacy-transactions', [
            'store_id' => $this->store->id,
            '--transaction-id' => $legacyTransactionId,
        ])->assertSuccessful();

        // Verify transaction was updated (not duplicated)
        $this->assertDatabaseCount('transactions', 1);
        $this->assertDatabaseHas('transactions', [
            'id' => $legacyTransactionId,
            'customer_notes' => 'Updated note',
            'final_offer' => 200.00,
        ]);
    }

    public function test_transaction_id_option_creates_new_if_not_exists(): void
    {
        $customerId = $this->createLegacyCustomer();

        // Create legacy transaction (never imported before)
        $legacyTransactionId = DB::connection('legacy')->table('transactions')->insertGetId([
            'store_id' => $this->store->id,
            'customer_id' => $customerId,
            'status_id' => 60,
            'is_in_house' => true,
            'pub_note' => 'Brand new',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Import just this one transaction
        $this->artisan('migrate:legacy-transactions', [
            'store_id' => $this->store->id,
            '--transaction-id' => $legacyTransactionId,
        ])->assertSuccessful();

        $this->assertDatabaseCount('transactions', 1);
        $this->assertDatabaseHas('transactions', [
            'id' => $legacyTransactionId,
            'customer_notes' => 'Brand new',
        ]);
    }

    public function test_transaction_id_option_fails_for_nonexistent_transaction(): void
    {
        $this->artisan('migrate:legacy-transactions', [
            'store_id' => $this->store->id,
            '--transaction-id' => 999999,
        ])->assertFailed();
    }

    public function test_transaction_id_option_updates_existing_items(): void
    {
        $customerId = $this->createLegacyCustomer();

        // Create legacy transaction with item
        $legacyTransactionId = DB::connection('legacy')->table('transactions')->insertGetId([
            'store_id' => $this->store->id,
            'customer_id' => $customerId,
            'status_id' => 60,
            'is_in_house' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $legacyItemId = DB::connection('legacy')->table('transaction_items')->insertGetId([
            'transaction_id' => $legacyTransactionId,
            'sku' => 'ITEM-001',
            'title' => 'Original Title',
            'price' => 100.00,
            'buy_price' => 80.00,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // First import
        $this->artisan('migrate:legacy-transactions', ['store_id' => $this->store->id])
            ->assertSuccessful();

        $this->assertDatabaseHas('transaction_items', [
            'id' => $legacyItemId,
            'title' => 'Original Title',
            'price' => 100.00,
        ]);

        // Update legacy item
        DB::connection('legacy')->table('transaction_items')
            ->where('id', $legacyItemId)
            ->update(['title' => 'Updated Title', 'price' => 200.00]);

        // Re-import
        $this->artisan('migrate:legacy-transactions', [
            'store_id' => $this->store->id,
            '--transaction-id' => $legacyTransactionId,
        ])->assertSuccessful();

        // Verify item was updated, not duplicated
        $this->assertDatabaseCount('transaction_items', 1);
        $this->assertDatabaseHas('transaction_items', [
            'id' => $legacyItemId,
            'title' => 'Updated Title',
            'price' => 200.00,
        ]);
    }

    public function test_transaction_id_option_updates_customer_on_reimport(): void
    {
        // Create legacy customer
        $legacyCustomerId = DB::connection('legacy')->table('customers')->insertGetId([
            'store_id' => $this->store->id,
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john.reimport@example.com',
            'phone_number' => '555-0001',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create legacy transaction
        $legacyTransactionId = DB::connection('legacy')->table('transactions')->insertGetId([
            'store_id' => $this->store->id,
            'customer_id' => $legacyCustomerId,
            'status_id' => 60,
            'is_in_house' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // First import
        $this->artisan('migrate:legacy-transactions', ['store_id' => $this->store->id])
            ->assertSuccessful();

        $this->assertDatabaseHas('customers', [
            'email' => 'john.reimport@example.com',
            'phone_number' => '555-0001',
        ]);

        // Update legacy customer
        DB::connection('legacy')->table('customers')
            ->where('id', $legacyCustomerId)
            ->update(['phone_number' => '555-9999', 'last_name' => 'Smith']);

        // Re-import
        $this->artisan('migrate:legacy-transactions', [
            'store_id' => $this->store->id,
            '--transaction-id' => $legacyTransactionId,
        ])->assertSuccessful();

        // Verify customer was updated
        $this->assertDatabaseCount('customers', 1);
        $this->assertDatabaseHas('customers', [
            'email' => 'john.reimport@example.com',
            'phone_number' => '555-9999',
            'last_name' => 'Smith',
        ]);
    }

    public function test_shipping_address_failure_does_not_crash_import(): void
    {
        $customerId = $this->createLegacyCustomer();

        // Create legacy transaction
        $legacyTransactionId = DB::connection('legacy')->table('transactions')->insertGetId([
            'store_id' => $this->store->id,
            'customer_id' => $customerId,
            'status_id' => 60,
            'is_in_house' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create a legacy address with an extremely long value that might fail validation
        // We'll use a mock approach: insert a valid address, then corrupt the addresses table
        DB::connection('legacy')->table('addresses')->insert([
            'addressable_type' => 'App\\Models\\Transaction',
            'addressable_id' => $legacyTransactionId,
            'first_name' => 'Test',
            'last_name' => 'User',
            'address' => str_repeat('A', 500),
            'city' => 'Test City',
            'state_id' => 1,
            'zip' => '12345',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // The migration should succeed even if the address part has issues
        $this->artisan('migrate:legacy-transactions', ['store_id' => $this->store->id])
            ->assertSuccessful();

        // Transaction should still be created
        $this->assertDatabaseHas('transactions', [
            'id' => $legacyTransactionId,
            'store_id' => $this->store->id,
        ]);
    }

    public function test_transaction_id_reimports_soft_deleted_transaction(): void
    {
        $customerId = $this->createLegacyCustomer();

        // Create legacy transaction
        $legacyTransactionId = DB::connection('legacy')->table('transactions')->insertGetId([
            'store_id' => $this->store->id,
            'customer_id' => $customerId,
            'status_id' => 60,
            'is_in_house' => true,
            'pub_note' => 'Will be deleted',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // First import
        $this->artisan('migrate:legacy-transactions', ['store_id' => $this->store->id])
            ->assertSuccessful();

        // Soft-delete the transaction
        Transaction::find($legacyTransactionId)->delete();
        $this->assertSoftDeleted('transactions', ['id' => $legacyTransactionId]);

        // Update legacy data
        DB::connection('legacy')->table('transactions')
            ->where('id', $legacyTransactionId)
            ->update(['pub_note' => 'Restored and updated']);

        // Re-import should restore and update
        $this->artisan('migrate:legacy-transactions', [
            'store_id' => $this->store->id,
            '--transaction-id' => $legacyTransactionId,
        ])->assertSuccessful();

        // Verify transaction is restored and updated
        $transaction = Transaction::find($legacyTransactionId);
        $this->assertNotNull($transaction);
        $this->assertNull($transaction->deleted_at);
        $this->assertEquals('Restored and updated', $transaction->customer_notes);
    }
}
