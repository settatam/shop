<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Customer;
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
            $table->decimal('est_value', 10, 2)->nullable();
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
            $table->boolean('is_added_to_inventory')->default(false);
            $table->timestamp('date_added_to_inventory')->nullable();
            $table->timestamp('reviewed_date_time')->nullable();
            $table->timestamps();
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

        Schema::connection('legacy')->create('shipping_labels', function ($table) {
            $table->id();
            $table->string('labelable_type');
            $table->unsignedBigInteger('labelable_id');
            $table->string('label_type');
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
        // Create legacy transaction
        $legacyTransactionId = DB::connection('legacy')->table('transactions')->insertGetId([
            'store_id' => $this->store->id,
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
        // Create legacy transaction
        $legacyTransactionId = DB::connection('legacy')->table('transactions')->insertGetId([
            'store_id' => $this->store->id,
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
        // Create legacy transaction
        $legacyTransactionId = DB::connection('legacy')->table('transactions')->insertGetId([
            'store_id' => $this->store->id,
            'status_id' => 60,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create legacy activities
        DB::connection('legacy')->table('store_activities')->insert([
            [
                'activityable_type' => 'App\\Models\\Transaction',
                'activityable_id' => $legacyTransactionId,
                'activity' => 'created_transaction',
                'description' => 'Transaction created',
                'user_id' => 1,
                'created_at' => now()->subHours(2),
                'updated_at' => now()->subHours(2),
            ],
            [
                'activityable_type' => 'App\\Models\\Transaction',
                'activityable_id' => $legacyTransactionId,
                'activity' => 'status_updated',
                'description' => 'Status changed to Kit Received',
                'user_id' => 1,
                'created_at' => now()->subHour(),
                'updated_at' => now()->subHour(),
            ],
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
        // Create legacy transaction
        DB::connection('legacy')->table('transactions')->insert([
            'store_id' => $this->store->id,
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
        // Create legacy transaction
        $legacyTransactionId = DB::connection('legacy')->table('transactions')->insertGetId([
            'store_id' => $this->store->id,
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
        // Create legacy in-house transaction
        DB::connection('legacy')->table('transactions')->insert([
            'store_id' => $this->store->id,
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
        // Create legacy mail-in transaction
        DB::connection('legacy')->table('transactions')->insert([
            'store_id' => $this->store->id,
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
        // Create legacy transaction
        $legacyTransactionId = DB::connection('legacy')->table('transactions')->insertGetId([
            'store_id' => $this->store->id,
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
        $newStore = Store::factory()->create();

        // Create legacy transaction in original store
        DB::connection('legacy')->table('transactions')->insert([
            'store_id' => $this->store->id,
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
        // Create multiple legacy transactions
        for ($i = 0; $i < 5; $i++) {
            DB::connection('legacy')->table('transactions')->insert([
                'store_id' => $this->store->id,
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
}
