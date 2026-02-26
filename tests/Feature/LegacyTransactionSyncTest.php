<?php

namespace Tests\Feature;

use App\Jobs\SyncCustomerToLegacyJob;
use App\Jobs\SyncTransactionToLegacyJob;
use App\Models\Address;
use App\Models\Customer;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Services\Legacy\LegacyTransactionSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LegacyTransactionSyncTest extends TestCase
{
    use RefreshDatabase;

    protected Store $store;

    protected int $legacyStoreId = 43;

    protected int $systemUserId = 1;

    protected bool $legacyConnectionConfigured = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = Store::factory()->create();

        $this->setupLegacyConnection();
        $this->createLegacyTables();

        config([
            'legacy-sync.enabled' => true,
        ]);
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

        Schema::connection('legacy')->create('transactions', function ($table) {
            $table->id();
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('status_id')->default(60);
            $table->decimal('preliminary_offer', 10, 2)->nullable();
            $table->decimal('final_offer', 10, 2)->nullable();
            $table->decimal('estimated_value', 10, 2)->nullable();
            $table->boolean('is_accepted')->default(false);
            $table->boolean('is_declined')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::connection('legacy')->create('transaction_items', function ($table) {
            $table->id();
            $table->unsignedBigInteger('transaction_id');
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->decimal('price', 10, 2)->nullable();
            $table->decimal('buy_price', 10, 2)->nullable();
            $table->decimal('dwt', 8, 4)->nullable();
            $table->string('precious_metal')->nullable();
            $table->integer('quantity')->default(1);
            $table->dateTime('reviewed_date_time')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamps();
        });

        Schema::connection('legacy')->create('statuses', function ($table) {
            $table->id();
            $table->unsignedBigInteger('status_id');
            $table->string('name');
            $table->unsignedBigInteger('store_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::connection('legacy')->create('store_activities', function ($table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('activity');
            $table->unsignedBigInteger('activityable_id');
            $table->string('activityable_type');
            $table->unsignedBigInteger('creatable_id')->nullable();
            $table->string('creatable_type')->nullable();
            $table->text('description')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::connection('legacy')->create('status_updates', function ($table) {
            $table->id();
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('updateable_id');
            $table->string('updateable_type');
            $table->string('previous_status');
            $table->string('current_status');
            $table->timestamps();
        });

        Schema::connection('legacy')->create('users', function ($table) {
            $table->id();
            $table->string('username');
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->timestamps();
        });

        Schema::connection('legacy')->create('customers', function ($table) {
            $table->id();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone_number')->nullable();
            $table->string('company_name')->nullable();
            $table->string('street_address')->nullable();
            $table->string('street_address2')->nullable();
            $table->string('city')->nullable();
            $table->integer('state_id')->nullable();
            $table->string('zip')->nullable();
            $table->integer('store_id');
            $table->timestamps();
            $table->softDeletes();
        });

        $this->seedLegacyData();
    }

    protected function seedLegacyData(): void
    {
        // Seed legacy store with matching name
        DB::connection('legacy')->table('stores')->insert([
            'id' => $this->legacyStoreId,
            'name' => $this->store->name,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Seed system user
        DB::connection('legacy')->table('users')->insert([
            'id' => $this->systemUserId,
            'username' => 'system',
            'first_name' => 'System',
            'last_name' => 'User',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->seedLegacyStatuses();
    }

    protected function seedLegacyStatuses(): void
    {
        $statuses = [
            ['status_id' => 1, 'name' => 'Pending Offer'],
            ['status_id' => 2, 'name' => 'Pending Kit Request - Confirmed'],
            ['status_id' => 3, 'name' => 'Pending Kit Request - On Hold'],
            ['status_id' => 4, 'name' => 'Kit Received - Rejected By Admin'],
            ['status_id' => 5, 'name' => 'Kit Sent'],
            ['status_id' => 6, 'name' => 'Kits Received'],
            ['status_id' => 7, 'name' => 'Kit Received - Ready to buy'],
            ['status_id' => 8, 'name' => 'Offer Given'],
            ['status_id' => 9, 'name' => 'Offer Accepted'],
            ['status_id' => 10, 'name' => 'Offer Declined'],
            ['status_id' => 11, 'name' => 'Payment Processed'],
            ['status_id' => 12, 'name' => 'Returned kit'],
            ['status_id' => 13, 'name' => 'Returned By Admin'],
            ['status_id' => 14, 'name' => 'Cancelled'],
        ];

        foreach ($statuses as $status) {
            DB::connection('legacy')->table('statuses')->insert(array_merge($status, [
                'store_id' => $this->legacyStoreId,
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        }
    }

    /**
     * Insert a transaction directly in the legacy database.
     */
    protected function createLegacyTransaction(int $id, int $statusId = 60): void
    {
        DB::connection('legacy')->table('transactions')->insert([
            'id' => $id,
            'store_id' => $this->legacyStoreId,
            'status_id' => $statusId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Insert an item directly in the legacy database.
     */
    protected function createLegacyItem(int $id, int $transactionId, array $overrides = []): void
    {
        DB::connection('legacy')->table('transaction_items')->insert(array_merge([
            'id' => $id,
            'transaction_id' => $transactionId,
            'title' => 'Legacy Item',
            'price' => 100.00,
            'buy_price' => 50.00,
            'quantity' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    // ---------------------------------------------------------------
    // Transaction status sync tests
    // ---------------------------------------------------------------

    public function test_syncs_transaction_status_to_legacy(): void
    {
        $transaction = Transaction::factory()->create([
            'store_id' => $this->store->id,
            'status' => Transaction::STATUS_PENDING,
            'final_offer' => 200.00,
            'preliminary_offer' => 180.00,
            'estimated_value' => 250.00,
        ]);

        $this->createLegacyTransaction($transaction->id);

        $service = app(LegacyTransactionSyncService::class);
        $service->sync($transaction);

        $legacyTxn = DB::connection('legacy')
            ->table('transactions')
            ->where('id', $transaction->id)
            ->first();

        $this->assertEquals(1, $legacyTxn->status_id); // 'Pending Offer' status_id
        $this->assertEquals(180.00, (float) $legacyTxn->preliminary_offer);
        $this->assertEquals(250.00, (float) $legacyTxn->estimated_value);
        $this->assertFalse((bool) $legacyTxn->is_accepted);
        $this->assertFalse((bool) $legacyTxn->is_declined);
    }

    public function test_syncs_offer_accepted_status_sets_is_accepted(): void
    {
        $transaction = Transaction::factory()->offerAccepted()->create([
            'store_id' => $this->store->id,
        ]);

        $this->createLegacyTransaction($transaction->id);

        $service = app(LegacyTransactionSyncService::class);
        $service->sync($transaction);

        $legacyTxn = DB::connection('legacy')
            ->table('transactions')
            ->where('id', $transaction->id)
            ->first();

        $this->assertEquals(9, $legacyTxn->status_id); // 'Offer Accepted' status_id
        $this->assertTrue((bool) $legacyTxn->is_accepted);
        $this->assertFalse((bool) $legacyTxn->is_declined);
    }

    public function test_syncs_payment_processed_status_sets_is_accepted(): void
    {
        $transaction = Transaction::factory()->paymentProcessed()->create([
            'store_id' => $this->store->id,
        ]);

        $this->createLegacyTransaction($transaction->id);

        $service = app(LegacyTransactionSyncService::class);
        $service->sync($transaction);

        $legacyTxn = DB::connection('legacy')
            ->table('transactions')
            ->where('id', $transaction->id)
            ->first();

        $this->assertEquals(11, $legacyTxn->status_id); // 'Payment Processed' status_id
        $this->assertTrue((bool) $legacyTxn->is_accepted);
        $this->assertFalse((bool) $legacyTxn->is_declined);
    }

    public function test_syncs_offer_declined_status_sets_is_declined(): void
    {
        $transaction = Transaction::factory()->offerDeclined()->create([
            'store_id' => $this->store->id,
        ]);

        $this->createLegacyTransaction($transaction->id);

        $service = app(LegacyTransactionSyncService::class);
        $service->sync($transaction);

        $legacyTxn = DB::connection('legacy')
            ->table('transactions')
            ->where('id', $transaction->id)
            ->first();

        $this->assertEquals(10, $legacyTxn->status_id); // 'Offer Declined' status_id
        $this->assertFalse((bool) $legacyTxn->is_accepted);
        $this->assertTrue((bool) $legacyTxn->is_declined);
    }

    // ---------------------------------------------------------------
    // Transaction item sync tests
    // ---------------------------------------------------------------

    public function test_syncs_transaction_items_to_legacy(): void
    {
        $transaction = Transaction::factory()->create([
            'store_id' => $this->store->id,
            'status' => Transaction::STATUS_PENDING,
        ]);

        $item = TransactionItem::factory()->create([
            'transaction_id' => $transaction->id,
            'title' => 'Updated Gold Ring',
            'price' => 300.00,
            'buy_price' => 200.00,
            'dwt' => 5.5000,
            'description' => 'A beautiful ring',
            'precious_metal' => 'gold_14k',
            'quantity' => 2,
        ]);

        $this->createLegacyTransaction($transaction->id);
        $this->createLegacyItem($item->id, $transaction->id);

        $service = app(LegacyTransactionSyncService::class);
        $service->sync($transaction);

        $legacyItem = DB::connection('legacy')
            ->table('transaction_items')
            ->where('id', $item->id)
            ->first();

        $this->assertEquals('Updated Gold Ring', $legacyItem->title);
        $this->assertEquals(300.00, (float) $legacyItem->price);
        $this->assertEquals(200.00, (float) $legacyItem->buy_price);
        $this->assertEquals(5.5, (float) $legacyItem->dwt);
        $this->assertEquals('A beautiful ring', $legacyItem->description);
        $this->assertEquals('gold_14k', $legacyItem->precious_metal);
        $this->assertEquals(2, $legacyItem->quantity);
    }

    public function test_skips_items_that_dont_exist_in_legacy(): void
    {
        $transaction = Transaction::factory()->create([
            'store_id' => $this->store->id,
            'status' => Transaction::STATUS_PENDING,
        ]);

        // Create item in new system only (no corresponding legacy item)
        TransactionItem::factory()->create([
            'transaction_id' => $transaction->id,
            'title' => 'New Only Item',
        ]);

        $this->createLegacyTransaction($transaction->id);

        $service = app(LegacyTransactionSyncService::class);

        // Should not throw
        $service->sync($transaction);

        // Legacy should have no items
        $this->assertEquals(0, DB::connection('legacy')
            ->table('transaction_items')
            ->where('transaction_id', $transaction->id)
            ->count());
    }

    // ---------------------------------------------------------------
    // Guard clause tests
    // ---------------------------------------------------------------

    public function test_does_not_sync_when_disabled(): void
    {
        config(['legacy-sync.enabled' => false]);

        $transaction = Transaction::factory()->create([
            'store_id' => $this->store->id,
            'status' => Transaction::STATUS_OFFER_ACCEPTED,
        ]);

        $this->createLegacyTransaction($transaction->id);

        $service = app(LegacyTransactionSyncService::class);
        $service->sync($transaction);

        // Status should remain unchanged (default 60)
        $legacyTxn = DB::connection('legacy')
            ->table('transactions')
            ->where('id', $transaction->id)
            ->first();

        $this->assertEquals(60, $legacyTxn->status_id);
    }

    public function test_does_not_sync_when_store_not_in_legacy(): void
    {
        $unmappedStore = Store::factory()->create();
        $transaction = Transaction::factory()->create([
            'store_id' => $unmappedStore->id,
            'status' => Transaction::STATUS_OFFER_ACCEPTED,
        ]);

        $this->createLegacyTransaction($transaction->id);

        $service = app(LegacyTransactionSyncService::class);
        $service->sync($transaction);

        $legacyTxn = DB::connection('legacy')
            ->table('transactions')
            ->where('id', $transaction->id)
            ->first();

        $this->assertEquals(60, $legacyTxn->status_id);
    }

    public function test_does_not_sync_when_transaction_not_in_legacy(): void
    {
        $transaction = Transaction::factory()->create([
            'store_id' => $this->store->id,
            'status' => Transaction::STATUS_OFFER_ACCEPTED,
        ]);

        // Do NOT create a legacy transaction

        $service = app(LegacyTransactionSyncService::class);
        $service->sync($transaction);

        // Verify no legacy record was created (only updates, never inserts)
        $this->assertFalse(
            $service->transactionExistsInLegacy($transaction->id)
        );
    }

    // ---------------------------------------------------------------
    // Job dispatch tests
    // ---------------------------------------------------------------

    public function test_dispatches_job_on_transaction_status_change(): void
    {
        Bus::fake([SyncTransactionToLegacyJob::class]);

        $transaction = Transaction::factory()->pending()->create([
            'store_id' => $this->store->id,
        ]);

        Bus::assertNotDispatched(SyncTransactionToLegacyJob::class);

        $transaction->update(['status' => Transaction::STATUS_OFFER_GIVEN]);

        Bus::assertDispatched(SyncTransactionToLegacyJob::class, function ($job) use ($transaction) {
            return $job->transaction->id === $transaction->id;
        });
    }

    public function test_does_not_dispatch_job_when_non_status_field_changes(): void
    {
        Bus::fake([SyncTransactionToLegacyJob::class]);

        $transaction = Transaction::factory()->pending()->create([
            'store_id' => $this->store->id,
        ]);

        $transaction->update(['bin_location' => 'BIN-99']);

        Bus::assertNotDispatched(SyncTransactionToLegacyJob::class);
    }

    public function test_dispatches_job_on_transaction_item_saved(): void
    {
        Bus::fake([SyncTransactionToLegacyJob::class]);

        $transaction = Transaction::factory()->create([
            'store_id' => $this->store->id,
        ]);

        TransactionItem::factory()->create([
            'transaction_id' => $transaction->id,
        ]);

        Bus::assertDispatched(SyncTransactionToLegacyJob::class, function ($job) use ($transaction) {
            return $job->transaction->id === $transaction->id;
        });
    }

    public function test_dispatches_job_on_transaction_item_updated(): void
    {
        Bus::fake([SyncTransactionToLegacyJob::class]);

        $transaction = Transaction::factory()->create([
            'store_id' => $this->store->id,
        ]);

        $item = TransactionItem::factory()->create([
            'transaction_id' => $transaction->id,
        ]);

        Bus::fake([SyncTransactionToLegacyJob::class]);

        $item->update(['buy_price' => 999.99]);

        Bus::assertDispatched(SyncTransactionToLegacyJob::class);
    }

    // ---------------------------------------------------------------
    // Job execution test
    // ---------------------------------------------------------------

    public function test_job_calls_sync_service(): void
    {
        $transaction = Transaction::factory()->offerAccepted()->create([
            'store_id' => $this->store->id,
        ]);

        $this->createLegacyTransaction($transaction->id);

        $job = new SyncTransactionToLegacyJob($transaction);
        $job->handle(app(LegacyTransactionSyncService::class));

        $legacyTxn = DB::connection('legacy')
            ->table('transactions')
            ->where('id', $transaction->id)
            ->first();

        $this->assertEquals(9, $legacyTxn->status_id);
        $this->assertTrue((bool) $legacyTxn->is_accepted);
    }

    public function test_job_does_not_throw_on_failure(): void
    {
        config(['database.connections.legacy.host' => '255.255.255.255']);
        DB::purge('legacy');

        $transaction = Transaction::factory()->create([
            'store_id' => $this->store->id,
        ]);

        $job = new SyncTransactionToLegacyJob($transaction);

        // Should not throw - errors are caught and logged
        $job->handle(app(LegacyTransactionSyncService::class));

        $this->assertTrue(true); // Confirms no exception was thrown

        // Re-setup for tearDown
        $this->setupLegacyConnection();
    }

    // ---------------------------------------------------------------
    // Status mapping tests
    // ---------------------------------------------------------------

    public function test_maps_all_statuses_correctly(): void
    {
        $expectedMappings = [
            Transaction::STATUS_PENDING => 1,
            Transaction::STATUS_KIT_REQUEST_CONFIRMED => 2,
            Transaction::STATUS_KIT_REQUEST_ON_HOLD => 3,
            Transaction::STATUS_KIT_REQUEST_REJECTED => 4,
            Transaction::STATUS_KIT_SENT => 5,
            Transaction::STATUS_KIT_DELIVERED => 5, // Both map to 'Kit Sent'
            Transaction::STATUS_ITEMS_RECEIVED => 6,
            Transaction::STATUS_ITEMS_REVIEWED => 7,
            Transaction::STATUS_OFFER_GIVEN => 8,
            Transaction::STATUS_OFFER_ACCEPTED => 9,
            Transaction::STATUS_OFFER_DECLINED => 10,
            Transaction::STATUS_PAYMENT_PROCESSED => 11,
            Transaction::STATUS_RETURN_REQUESTED => 12,
            Transaction::STATUS_ITEMS_RETURNED => 13,
            Transaction::STATUS_CANCELLED => 14,
        ];

        $service = app(LegacyTransactionSyncService::class);

        foreach ($expectedMappings as $newStatus => $expectedLegacyStatusId) {
            $transaction = Transaction::factory()->create([
                'store_id' => $this->store->id,
                'status' => $newStatus,
            ]);

            $this->createLegacyTransaction($transaction->id);

            $service->sync($transaction);

            $legacyTxn = DB::connection('legacy')
                ->table('transactions')
                ->where('id', $transaction->id)
                ->first();

            $this->assertEquals(
                $expectedLegacyStatusId,
                $legacyTxn->status_id,
                "Status '{$newStatus}' should map to legacy status_id {$expectedLegacyStatusId}, got {$legacyTxn->status_id}"
            );
        }
    }

    // ---------------------------------------------------------------
    // Activity logging tests
    // ---------------------------------------------------------------

    public function test_creates_activity_when_item_price_changes(): void
    {
        $transaction = Transaction::factory()->create([
            'store_id' => $this->store->id,
            'status' => Transaction::STATUS_PENDING,
        ]);

        $item = TransactionItem::factory()->create([
            'transaction_id' => $transaction->id,
            'buy_price' => 150.00,
            'price' => 300.00,
        ]);

        $this->createLegacyTransaction($transaction->id);
        $this->createLegacyItem($item->id, $transaction->id, [
            'buy_price' => 50.00,
            'price' => 100.00,
        ]);

        $service = app(LegacyTransactionSyncService::class);
        $service->sync($transaction);

        // Should have activity on the transaction
        $transactionActivities = DB::connection('legacy')
            ->table('store_activities')
            ->where('activityable_type', 'App\Models\Transaction')
            ->where('activityable_id', $transaction->id)
            ->where('activity', 'updated_transaction_item_amount')
            ->get();

        $this->assertCount(1, $transactionActivities);

        // Should have activity on the item
        $itemActivities = DB::connection('legacy')
            ->table('store_activities')
            ->where('activityable_type', 'App\Models\TransactionItem')
            ->where('activityable_id', $item->id)
            ->where('activity', 'updated_transaction_item_amount')
            ->get();

        $this->assertCount(1, $itemActivities);
    }

    public function test_creates_activity_when_item_reviewed(): void
    {
        $transaction = Transaction::factory()->create([
            'store_id' => $this->store->id,
            'status' => Transaction::STATUS_PENDING,
        ]);

        $item = TransactionItem::factory()->create([
            'transaction_id' => $transaction->id,
            'reviewed_at' => now(),
            'reviewed_by' => 1,
        ]);

        $this->createLegacyTransaction($transaction->id);
        $this->createLegacyItem($item->id, $transaction->id);

        $service = app(LegacyTransactionSyncService::class);
        $service->sync($transaction);

        $activities = DB::connection('legacy')
            ->table('store_activities')
            ->where('activityable_type', 'App\Models\TransactionItem')
            ->where('activityable_id', $item->id)
            ->where('activity', 'transaction_item_reviewed')
            ->get();

        $this->assertCount(1, $activities);
        $this->assertStringContainsString("Item {$item->id} Reviewed by System", $activities->first()->description);

        // Verify reviewed_date_time was set on legacy item
        $legacyItem = DB::connection('legacy')
            ->table('transaction_items')
            ->where('id', $item->id)
            ->first();

        $this->assertNotNull($legacyItem->reviewed_date_time);
    }

    public function test_creates_status_update_on_status_change(): void
    {
        $transaction = Transaction::factory()->create([
            'store_id' => $this->store->id,
            'status' => Transaction::STATUS_OFFER_ACCEPTED,
        ]);

        $this->createLegacyTransaction($transaction->id, 60);

        $service = app(LegacyTransactionSyncService::class);
        $service->sync($transaction);

        // Should have a status_updated activity
        $activities = DB::connection('legacy')
            ->table('store_activities')
            ->where('activityable_type', 'App\Models\Transaction')
            ->where('activityable_id', $transaction->id)
            ->where('activity', 'status_updated')
            ->get();

        $this->assertCount(1, $activities);

        // Should have a status_updates record
        $statusUpdates = DB::connection('legacy')
            ->table('status_updates')
            ->where('updateable_id', $transaction->id)
            ->where('updateable_type', 'App\Models\Transaction')
            ->get();

        $this->assertCount(1, $statusUpdates);
        $this->assertEquals('60', $statusUpdates->first()->previous_status);
        $this->assertEquals('9', $statusUpdates->first()->current_status); // Offer Accepted = 9
        $this->assertEquals($this->legacyStoreId, $statusUpdates->first()->store_id);
    }

    public function test_recalculates_final_offer_after_item_sync(): void
    {
        $transaction = Transaction::factory()->create([
            'store_id' => $this->store->id,
            'status' => Transaction::STATUS_PENDING,
        ]);

        $item1 = TransactionItem::factory()->create([
            'transaction_id' => $transaction->id,
            'buy_price' => 100.50,
        ]);

        $item2 = TransactionItem::factory()->create([
            'transaction_id' => $transaction->id,
            'buy_price' => 200.75,
        ]);

        $this->createLegacyTransaction($transaction->id);
        $this->createLegacyItem($item1->id, $transaction->id);
        $this->createLegacyItem($item2->id, $transaction->id);

        $service = app(LegacyTransactionSyncService::class);
        $service->sync($transaction);

        $legacyTxn = DB::connection('legacy')
            ->table('transactions')
            ->where('id', $transaction->id)
            ->first();

        // final_offer should be recalculated from items: 100.50 + 200.75 = 301.25
        $this->assertEquals(301.25, (float) $legacyTxn->final_offer);
    }

    public function test_does_not_create_activity_when_no_price_change(): void
    {
        $transaction = Transaction::factory()->create([
            'store_id' => $this->store->id,
            'status' => Transaction::STATUS_PENDING,
        ]);

        $item = TransactionItem::factory()->create([
            'transaction_id' => $transaction->id,
            'title' => 'Updated Title',
            'buy_price' => 50.00,
            'price' => 100.00,
        ]);

        $this->createLegacyTransaction($transaction->id);
        // Legacy item already has the same buy_price and price
        $this->createLegacyItem($item->id, $transaction->id, [
            'buy_price' => 50.00,
            'price' => 100.00,
        ]);

        $service = app(LegacyTransactionSyncService::class);
        $service->sync($transaction);

        // Should NOT have price change activity
        $priceActivities = DB::connection('legacy')
            ->table('store_activities')
            ->where('activity', 'updated_transaction_item_amount')
            ->count();

        $this->assertEquals(0, $priceActivities);
    }

    public function test_does_not_create_status_activity_when_status_unchanged(): void
    {
        $transaction = Transaction::factory()->create([
            'store_id' => $this->store->id,
            'status' => Transaction::STATUS_PENDING,
        ]);

        // Legacy transaction already at status_id 1 (Pending Offer)
        $this->createLegacyTransaction($transaction->id, 1);

        $service = app(LegacyTransactionSyncService::class);
        $service->sync($transaction);

        $statusActivities = DB::connection('legacy')
            ->table('store_activities')
            ->where('activity', 'status_updated')
            ->count();

        $this->assertEquals(0, $statusActivities);

        $statusUpdates = DB::connection('legacy')
            ->table('status_updates')
            ->count();

        $this->assertEquals(0, $statusUpdates);
    }

    // --- Customer Sync Tests ---

    protected function createLegacyCustomer(int $id, array $overrides = []): void
    {
        DB::connection('legacy')->table('customers')->insert(array_merge([
            'id' => $id,
            'first_name' => 'Legacy',
            'last_name' => 'Customer',
            'store_id' => $this->legacyStoreId,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    public function test_syncs_customer_details_to_legacy(): void
    {
        $customer = Customer::factory()->create([
            'store_id' => $this->store->id,
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john@example.com',
            'phone_number' => '555-1234',
            'company_name' => 'Acme Inc',
        ]);

        $this->createLegacyCustomer($customer->id);

        $service = app(LegacyTransactionSyncService::class);
        $service->syncCustomer($customer);

        $legacyCustomer = DB::connection('legacy')
            ->table('customers')
            ->where('id', $customer->id)
            ->first();

        $this->assertEquals('John', $legacyCustomer->first_name);
        $this->assertEquals('Doe', $legacyCustomer->last_name);
        $this->assertEquals('john@example.com', $legacyCustomer->email);
        $this->assertEquals('555-1234', $legacyCustomer->phone_number);
        $this->assertEquals('Acme Inc', $legacyCustomer->company_name);
    }

    public function test_syncs_customer_primary_address_to_legacy(): void
    {
        $customer = Customer::factory()->create([
            'store_id' => $this->store->id,
        ]);

        Address::factory()->default()->forCustomer($customer)->create([
            'address' => '123 Main St',
            'address2' => 'Suite 100',
            'city' => 'Springfield',
            'state_id' => 14,
            'zip' => '62704',
        ]);

        $this->createLegacyCustomer($customer->id);

        $service = app(LegacyTransactionSyncService::class);
        $service->syncCustomer($customer);

        $legacyCustomer = DB::connection('legacy')
            ->table('customers')
            ->where('id', $customer->id)
            ->first();

        $this->assertEquals('123 Main St', $legacyCustomer->street_address);
        $this->assertEquals('Suite 100', $legacyCustomer->street_address2);
        $this->assertEquals('Springfield', $legacyCustomer->city);
        $this->assertEquals(14, $legacyCustomer->state_id);
        $this->assertEquals('62704', $legacyCustomer->zip);
    }

    public function test_syncs_first_address_when_no_default(): void
    {
        $customer = Customer::factory()->create([
            'store_id' => $this->store->id,
        ]);

        Address::factory()->forCustomer($customer)->create([
            'is_default' => false,
            'address' => '456 Oak Ave',
            'city' => 'Portland',
            'zip' => '97201',
        ]);

        $this->createLegacyCustomer($customer->id);

        $service = app(LegacyTransactionSyncService::class);
        $service->syncCustomer($customer);

        $legacyCustomer = DB::connection('legacy')
            ->table('customers')
            ->where('id', $customer->id)
            ->first();

        $this->assertEquals('456 Oak Ave', $legacyCustomer->street_address);
        $this->assertEquals('Portland', $legacyCustomer->city);
        $this->assertEquals('97201', $legacyCustomer->zip);
    }

    public function test_skips_customer_sync_when_not_in_legacy(): void
    {
        $customer = Customer::factory()->create([
            'store_id' => $this->store->id,
            'first_name' => 'Ghost',
        ]);

        $service = app(LegacyTransactionSyncService::class);
        $service->syncCustomer($customer);

        $count = DB::connection('legacy')
            ->table('customers')
            ->where('first_name', 'Ghost')
            ->count();

        $this->assertEquals(0, $count);
    }

    public function test_dispatches_customer_sync_job_on_customer_saved(): void
    {
        Bus::fake([SyncCustomerToLegacyJob::class]);

        $customer = Customer::factory()->create([
            'store_id' => $this->store->id,
        ]);

        Bus::assertDispatched(SyncCustomerToLegacyJob::class, function ($job) use ($customer) {
            return $job->customer->id === $customer->id;
        });
    }

    public function test_dispatches_customer_sync_job_on_address_saved(): void
    {
        $customer = Customer::factory()->create([
            'store_id' => $this->store->id,
        ]);

        Bus::fake([SyncCustomerToLegacyJob::class]);

        Address::factory()->forCustomer($customer)->create();

        Bus::assertDispatched(SyncCustomerToLegacyJob::class, function ($job) use ($customer) {
            return $job->customer->id === $customer->id;
        });
    }

    public function test_does_not_sync_customer_when_disabled(): void
    {
        config(['legacy-sync.enabled' => false]);

        $customer = Customer::factory()->create([
            'store_id' => $this->store->id,
            'first_name' => 'Ignored',
        ]);

        $this->createLegacyCustomer($customer->id, ['first_name' => 'Original']);

        $service = app(LegacyTransactionSyncService::class);
        $service->syncCustomer($customer);

        $legacyCustomer = DB::connection('legacy')
            ->table('customers')
            ->where('id', $customer->id)
            ->first();

        $this->assertEquals('Original', $legacyCustomer->first_name);
    }
}
