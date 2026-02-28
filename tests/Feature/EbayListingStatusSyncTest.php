<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\PlatformListing;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SalesChannel;
use App\Models\Store;
use App\Models\StoreMarketplace;
use App\Services\Platforms\Ebay\EbayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class EbayListingStatusSyncTest extends TestCase
{
    use RefreshDatabase;

    protected Store $store;

    protected StoreMarketplace $marketplace;

    protected SalesChannel $channel;

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

        $this->channel = SalesChannel::factory()->create([
            'store_id' => $this->store->id,
            'store_marketplace_id' => $this->marketplace->id,
            'type' => 'ebay',
        ]);
    }

    protected function createListedProduct(array $platformData = []): PlatformListing
    {
        $product = Product::factory()->create(['store_id' => $this->store->id]);
        ProductVariant::factory()->create(['product_id' => $product->id, 'sku' => 'SKU-'.uniqid()]);

        $listing = PlatformListing::where('product_id', $product->id)
            ->where('store_marketplace_id', $this->marketplace->id)
            ->first();

        if (! $listing) {
            $listing = PlatformListing::create([
                'product_id' => $product->id,
                'store_marketplace_id' => $this->marketplace->id,
                'sales_channel_id' => $this->channel->id,
                'status' => PlatformListing::STATUS_LISTED,
                'platform_data' => $platformData,
                'external_listing_id' => 'ebay-'.uniqid(),
            ]);
        } else {
            $listing->update([
                'status' => PlatformListing::STATUS_LISTED,
                'platform_data' => $platformData,
                'external_listing_id' => 'ebay-'.uniqid(),
            ]);
        }

        return $listing->fresh();
    }

    protected function mockEbayService(): EbayService
    {
        $mock = Mockery::mock(EbayService::class);
        $mock->shouldReceive('ensureValidToken')->byDefault();
        $mock->shouldReceive('updateListingStatusFromEbay')
            ->byDefault()
            ->andReturnUsing(function (PlatformListing $listing, string $newStatus) {
                $listing->update(['status' => $newStatus]);
            });

        $this->app->instance(EbayService::class, $mock);

        return $mock;
    }

    public function test_detects_ended_listing_and_updates_status(): void
    {
        $listing = $this->createListedProduct(['offer_id' => 'offer-123']);

        $mock = $this->mockEbayService();
        $mock->shouldReceive('getOfferStatus')
            ->once()
            ->with(Mockery::type(StoreMarketplace::class), 'offer-123')
            ->andReturn(['status' => 'ENDED', 'listingId' => null, 'availableQuantity' => 0]);

        $this->artisan('ebay:sync-listing-status')->assertSuccessful();

        $this->assertEquals(PlatformListing::STATUS_ENDED, $listing->fresh()->status);
    }

    public function test_leaves_published_listing_unchanged(): void
    {
        $listing = $this->createListedProduct(['offer_id' => 'offer-456']);

        $mock = $this->mockEbayService();
        $mock->shouldReceive('getOfferStatus')
            ->once()
            ->with(Mockery::type(StoreMarketplace::class), 'offer-456')
            ->andReturn(['status' => 'PUBLISHED', 'listingId' => '12345', 'availableQuantity' => 5]);
        $mock->shouldNotReceive('updateListingStatusFromEbay');

        $this->artisan('ebay:sync-listing-status')->assertSuccessful();

        $this->assertEquals(PlatformListing::STATUS_LISTED, $listing->fresh()->status);
    }

    public function test_handles_missing_offer_as_ended(): void
    {
        $listing = $this->createListedProduct(['offer_id' => 'offer-gone']);

        $mock = $this->mockEbayService();
        $mock->shouldReceive('getOfferStatus')
            ->once()
            ->with(Mockery::type(StoreMarketplace::class), 'offer-gone')
            ->andReturn(null);

        $this->artisan('ebay:sync-listing-status')->assertSuccessful();

        $this->assertEquals(PlatformListing::STATUS_ENDED, $listing->fresh()->status);
    }

    public function test_multi_variant_all_ended_updates_status(): void
    {
        $listing = $this->createListedProduct([
            'multi_variant' => true,
            'offer_ids' => ['SKU-A' => 'offer-a', 'SKU-B' => 'offer-b'],
        ]);

        $mock = $this->mockEbayService();
        $mock->shouldReceive('getOfferStatus')
            ->with(Mockery::type(StoreMarketplace::class), 'offer-a')
            ->andReturn(['status' => 'ENDED', 'listingId' => null, 'availableQuantity' => 0]);
        $mock->shouldReceive('getOfferStatus')
            ->with(Mockery::type(StoreMarketplace::class), 'offer-b')
            ->andReturn(['status' => 'ENDED', 'listingId' => null, 'availableQuantity' => 0]);

        $this->artisan('ebay:sync-listing-status')->assertSuccessful();

        $this->assertEquals(PlatformListing::STATUS_ENDED, $listing->fresh()->status);
    }

    public function test_multi_variant_some_published_stays_listed(): void
    {
        $listing = $this->createListedProduct([
            'multi_variant' => true,
            'offer_ids' => ['SKU-A' => 'offer-a', 'SKU-B' => 'offer-b'],
        ]);

        $mock = $this->mockEbayService();
        $mock->shouldReceive('getOfferStatus')
            ->with(Mockery::type(StoreMarketplace::class), 'offer-a')
            ->andReturn(['status' => 'PUBLISHED', 'listingId' => '111', 'availableQuantity' => 5]);
        // offer-b should not be checked because we break early on finding a PUBLISHED offer
        $mock->shouldReceive('getOfferStatus')
            ->with(Mockery::type(StoreMarketplace::class), 'offer-b')
            ->never();
        $mock->shouldNotReceive('updateListingStatusFromEbay');

        $this->artisan('ebay:sync-listing-status')->assertSuccessful();

        $this->assertEquals(PlatformListing::STATUS_LISTED, $listing->fresh()->status);
    }

    public function test_creates_sync_log_entry(): void
    {
        $listing = $this->createListedProduct(['offer_id' => 'offer-log']);

        $mock = $this->mockEbayService();
        $mock->shouldReceive('getOfferStatus')
            ->once()
            ->andReturn(['status' => 'PUBLISHED', 'listingId' => '999', 'availableQuantity' => 3]);

        $this->artisan('ebay:sync-listing-status')->assertSuccessful();

        $this->assertDatabaseHas('sync_logs', [
            'store_marketplace_id' => $this->marketplace->id,
            'sync_type' => 'listing_status',
            'direction' => 'pull',
            'status' => 'completed',
        ]);
    }

    public function test_update_listing_status_from_ebay_logs_activity(): void
    {
        $listing = $this->createListedProduct(['offer_id' => 'offer-activity']);

        // Use the real service for this test to verify ActivityLog::log works
        $realService = app(EbayService::class);
        $realService->updateListingStatusFromEbay($listing, PlatformListing::STATUS_ENDED);

        $this->assertEquals(PlatformListing::STATUS_ENDED, $listing->fresh()->status);

        $this->assertDatabaseHas('activity_logs', [
            'activity_slug' => 'listings.status_change',
            'subject_type' => PlatformListing::class,
            'subject_id' => $listing->id,
        ]);
    }

    public function test_respects_store_filter(): void
    {
        $listing = $this->createListedProduct(['offer_id' => 'offer-filter']);

        $otherStore = Store::factory()->create();
        StoreMarketplace::factory()->ebay()->create([
            'store_id' => $otherStore->id,
            'connected_successfully' => true,
            'status' => 'active',
        ]);

        $mock = $this->mockEbayService();
        // Should only be called once — for our store, not the other store
        $mock->shouldReceive('ensureValidToken')->once();
        $mock->shouldReceive('getOfferStatus')
            ->once()
            ->with(Mockery::type(StoreMarketplace::class), 'offer-filter')
            ->andReturn(['status' => 'PUBLISHED', 'listingId' => '999', 'availableQuantity' => 1]);

        $this->artisan("ebay:sync-listing-status --store={$this->store->id}")
            ->assertSuccessful();
    }

    public function test_skips_not_listed_listings(): void
    {
        $product = Product::factory()->create(['store_id' => $this->store->id]);
        ProductVariant::factory()->create(['product_id' => $product->id]);

        // The auto-created listing defaults to NOT_LISTED, so it should be skipped
        $listing = PlatformListing::where('product_id', $product->id)
            ->where('store_marketplace_id', $this->marketplace->id)
            ->first();

        if ($listing) {
            $listing->update([
                'status' => PlatformListing::STATUS_NOT_LISTED,
                'platform_data' => ['offer_id' => 'should-not-check'],
            ]);
        }

        $mock = $this->mockEbayService();
        $mock->shouldNotReceive('getOfferStatus');

        $this->artisan('ebay:sync-listing-status')
            ->assertSuccessful()
            ->expectsOutputToContain('no listed listings');
    }

    public function test_handles_api_errors_gracefully(): void
    {
        $listing = $this->createListedProduct(['offer_id' => 'offer-err']);

        $mock = $this->mockEbayService();
        $mock->shouldReceive('ensureValidToken')
            ->once()
            ->andThrow(new \Exception('Token refresh failed'));

        $this->artisan('ebay:sync-listing-status')
            ->assertSuccessful()
            ->expectsOutputToContain('Token refresh failed');

        $this->assertDatabaseHas('sync_logs', [
            'store_marketplace_id' => $this->marketplace->id,
            'status' => 'failed',
        ]);
    }

    public function test_listing_without_offer_id_is_skipped(): void
    {
        $listing = $this->createListedProduct([]);

        $mock = $this->mockEbayService();
        $mock->shouldNotReceive('getOfferStatus');

        $this->artisan('ebay:sync-listing-status')->assertSuccessful();

        // Should remain listed — no offer_id means nothing to check
        $this->assertEquals(PlatformListing::STATUS_LISTED, $listing->fresh()->status);
    }

    public function test_update_listing_status_does_nothing_for_same_status(): void
    {
        $listing = $this->createListedProduct(['offer_id' => 'offer-same']);

        $realService = app(EbayService::class);
        $realService->updateListingStatusFromEbay($listing, PlatformListing::STATUS_LISTED);

        // No activity log should be created for same status
        $this->assertDatabaseMissing('activity_logs', [
            'subject_type' => PlatformListing::class,
            'subject_id' => $listing->id,
        ]);
    }
}
