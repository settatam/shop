<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\TransactionWarehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LegacySyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Enable legacy sync for tests
        config(['legacy-sync.enabled' => true]);
        config(['legacy-sync.store_mapping' => [43 => 43, 44 => 1, 63 => 3]]);
    }

    public function test_sync_command_fails_when_disabled(): void
    {
        config(['legacy-sync.enabled' => false]);

        $this->artisan('sync:legacy-warehouse')
            ->expectsOutput('Legacy sync is disabled. Set LEGACY_SYNC_ENABLED=true to enable.')
            ->assertFailed();
    }

    public function test_sync_command_fails_without_store_mapping(): void
    {
        config(['legacy-sync.store_mapping' => []]);

        $this->artisan('sync:legacy-warehouse')
            ->expectsOutput('No store mappings configured. Set LEGACY_STORE_MAPPING env variable.')
            ->assertFailed();
    }

    public function test_clear_command_fails_when_disabled(): void
    {
        config(['legacy-sync.enabled' => false]);

        $this->artisan('clear:legacy-warehouse')
            ->expectsOutput('Legacy sync is disabled. Set LEGACY_SYNC_ENABLED=true to enable.')
            ->assertFailed();
    }

    public function test_clear_command_deletes_warehouse_records(): void
    {
        $store = Store::factory()->create(['id' => 43]);

        // Create some warehouse records
        TransactionWarehouse::create([
            'legacy_id' => 1,
            'legacy_store_id' => 43,
            'store_id' => 43,
            'customer_name' => 'Test Customer',
        ]);

        TransactionWarehouse::create([
            'legacy_id' => 2,
            'legacy_store_id' => 43,
            'store_id' => 43,
            'customer_name' => 'Another Customer',
        ]);

        $this->assertEquals(2, TransactionWarehouse::count());

        $this->artisan('clear:legacy-warehouse', ['--force' => true])
            ->assertSuccessful();

        $this->assertEquals(0, TransactionWarehouse::count());
    }

    public function test_reports_command_fails_when_reports_disabled(): void
    {
        config(['legacy-sync.reports.enabled' => false]);

        $this->artisan('reports:send-legacy-daily')
            ->expectsOutput('Legacy reports are disabled. Set LEGACY_SYNC_ENABLED=true and LEGACY_REPORTS_ENABLED=true.')
            ->assertFailed();
    }

    public function test_transaction_warehouse_model_maps_store_ids(): void
    {
        config(['legacy-sync.store_mapping' => [43 => 43, 44 => 1, 63 => 3]]);

        $this->assertEquals(43, TransactionWarehouse::mapLegacyStoreId(43));
        $this->assertEquals(1, TransactionWarehouse::mapLegacyStoreId(44));
        $this->assertEquals(3, TransactionWarehouse::mapLegacyStoreId(63));
        $this->assertNull(TransactionWarehouse::mapLegacyStoreId(999));
    }

    public function test_transaction_warehouse_model_returns_legacy_store_ids(): void
    {
        config(['legacy-sync.store_mapping' => [43 => 43, 44 => 1, 63 => 3]]);

        $this->assertEquals([43], TransactionWarehouse::getLegacyStoreIds(43));
        $this->assertEquals([44], TransactionWarehouse::getLegacyStoreIds(1));
        $this->assertEquals([63], TransactionWarehouse::getLegacyStoreIds(3));
        $this->assertEquals([], TransactionWarehouse::getLegacyStoreIds(999));
    }

    public function test_transaction_warehouse_model_returns_all_configured_legacy_store_ids(): void
    {
        config(['legacy-sync.store_mapping' => [43 => 43, 44 => 1, 63 => 3]]);

        $legacyIds = TransactionWarehouse::getConfiguredLegacyStoreIds();

        $this->assertCount(3, $legacyIds);
        $this->assertContains(43, $legacyIds);
        $this->assertContains(44, $legacyIds);
        $this->assertContains(63, $legacyIds);
    }

    public function test_clear_command_only_deletes_specified_stores(): void
    {
        $store43 = Store::factory()->create(['id' => 43]);
        $store44 = Store::factory()->create(['id' => 1]);

        // Create records for store 43
        TransactionWarehouse::create([
            'legacy_id' => 1,
            'legacy_store_id' => 43,
            'store_id' => 43,
            'customer_name' => 'Store 43 Customer',
        ]);

        // Create records for store 44
        TransactionWarehouse::create([
            'legacy_id' => 2,
            'legacy_store_id' => 44,
            'store_id' => 1,
            'customer_name' => 'Store 44 Customer',
        ]);

        $this->assertEquals(2, TransactionWarehouse::count());

        // Only clear store 43
        $this->artisan('clear:legacy-warehouse', ['--store' => '43', '--force' => true])
            ->assertSuccessful();

        $this->assertEquals(1, TransactionWarehouse::count());
        $this->assertEquals(44, TransactionWarehouse::first()->legacy_store_id);
    }
}
