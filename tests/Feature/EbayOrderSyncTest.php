<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\StoreMarketplace;
use App\Services\Platforms\Ebay\EbayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class EbayOrderSyncTest extends TestCase
{
    use RefreshDatabase;

    protected Store $store;

    protected StoreMarketplace $marketplace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = Store::factory()->create();

        $this->marketplace = StoreMarketplace::factory()->ebay()->create([
            'store_id' => $this->store->id,
            'connected_successfully' => true,
            'status' => 'active',
            'settings' => ['marketplace_id' => 'EBAY_US'],
        ]);
    }

    public function test_command_syncs_orders_for_active_connections(): void
    {
        $mockService = Mockery::mock(EbayService::class);
        $mockService->shouldReceive('pullOrders')
            ->once()
            ->withArgs(function ($connection, $since) {
                return $connection->id === $this->marketplace->id && is_string($since);
            })
            ->andReturn(collect());

        $this->app->instance(EbayService::class, $mockService);

        $this->artisan('ebay:sync-orders')
            ->assertSuccessful()
            ->expectsOutputToContain('imported 0 order(s)');
    }

    public function test_command_respects_store_filter(): void
    {
        $otherStore = Store::factory()->create();
        $otherMarketplace = StoreMarketplace::factory()->ebay()->create([
            'store_id' => $otherStore->id,
            'connected_successfully' => true,
            'status' => 'active',
        ]);

        $mockService = Mockery::mock(EbayService::class);
        $mockService->shouldReceive('pullOrders')
            ->once()
            ->withArgs(function ($connection) {
                return $connection->id === $this->marketplace->id;
            })
            ->andReturn(collect());

        $this->app->instance(EbayService::class, $mockService);

        $this->artisan("ebay:sync-orders --store={$this->store->id}")
            ->assertSuccessful();
    }

    public function test_command_skips_inactive_connections(): void
    {
        $this->marketplace->update(['status' => 'inactive']);

        $mockService = Mockery::mock(EbayService::class);
        $mockService->shouldNotReceive('pullOrders');

        $this->app->instance(EbayService::class, $mockService);

        $this->artisan('ebay:sync-orders')
            ->assertSuccessful()
            ->expectsOutputToContain('No active eBay connections found');
    }

    public function test_command_skips_disconnected_connections(): void
    {
        $this->marketplace->update(['connected_successfully' => false]);

        $mockService = Mockery::mock(EbayService::class);
        $mockService->shouldNotReceive('pullOrders');

        $this->app->instance(EbayService::class, $mockService);

        $this->artisan('ebay:sync-orders')
            ->assertSuccessful()
            ->expectsOutputToContain('No active eBay connections found');
    }

    public function test_command_uses_last_sync_at_as_since_parameter(): void
    {
        $syncTime = now()->subHours(2);
        $this->marketplace->update(['last_sync_at' => $syncTime]);

        $mockService = Mockery::mock(EbayService::class);
        $mockService->shouldReceive('pullOrders')
            ->once()
            ->withArgs(function ($connection, $since) use ($syncTime) {
                return $since === $syncTime->toIso8601String();
            })
            ->andReturn(collect());

        $this->app->instance(EbayService::class, $mockService);

        $this->artisan('ebay:sync-orders')->assertSuccessful();
    }

    public function test_command_handles_api_errors_gracefully(): void
    {
        $mockService = Mockery::mock(EbayService::class);
        $mockService->shouldReceive('pullOrders')
            ->once()
            ->andThrow(new \Exception('eBay API error: 500'));

        $this->app->instance(EbayService::class, $mockService);

        $this->artisan('ebay:sync-orders')
            ->assertSuccessful()
            ->expectsOutputToContain('eBay API error: 500');
    }

    public function test_command_syncs_multiple_connections(): void
    {
        $store2 = Store::factory()->create();
        $marketplace2 = StoreMarketplace::factory()->ebay()->create([
            'store_id' => $store2->id,
            'connected_successfully' => true,
            'status' => 'active',
        ]);

        $mockService = Mockery::mock(EbayService::class);
        $mockService->shouldReceive('pullOrders')
            ->twice()
            ->andReturn(collect());

        $this->app->instance(EbayService::class, $mockService);

        $this->artisan('ebay:sync-orders')
            ->assertSuccessful()
            ->expectsOutputToContain('2 eBay connection(s)');
    }
}
