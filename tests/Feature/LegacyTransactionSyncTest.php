<?php

namespace Tests\Feature;

use App\Jobs\SyncTransactionToLegacyJob;
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

    protected bool $legacyConnectionConfigured = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = Store::factory()->create();

        $this->setupLegacyConnection();
        $this->createLegacyTables();

        config([
            'legacy-sync.enabled' => true,
            'legacy-sync.store_mapping' => [$this->legacyStoreId => $this->store->id],
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
        Schema::connection('legacy')->create('transactions', function ($table) {
            $table->id();
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('status_id')->default(60);
            $table->decimal('preliminary_offer', 10, 2)->nullable();
            $table->decimal('final_offer', 10, 2)->nullable();
            $table->decimal('est_value', 10, 2)->nullable();
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
    protected function createLegacyItem(int $id, int $transactionId): void
    {
        DB::connection('legacy')->table('transaction_items')->insert([
            'id' => $id,
            'transaction_id' => $transactionId,
            'title' => 'Legacy Item',
            'price' => 100.00,
            'buy_price' => 50.00,
            'quantity' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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
        $this->assertEquals(200.00, (float) $legacyTxn->final_offer);
        $this->assertEquals(180.00, (float) $legacyTxn->preliminary_offer);
        $this->assertEquals(250.00, (float) $legacyTxn->est_value);
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

    public function test_does_not_sync_when_store_not_mapped(): void
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
}
