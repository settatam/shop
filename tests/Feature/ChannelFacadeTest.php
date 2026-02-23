<?php

namespace Tests\Feature;

use App\Contracts\Platforms\PlatformAdapterResult;
use App\Facades\Channel;
use App\Models\PlatformListing;
use App\Models\Product;
use App\Models\SalesChannel;
use App\Models\Store;
use App\Services\Platforms\Adapters\LocalAdapter;
use App\Services\Platforms\ListingManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChannelFacadeTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private SalesChannel $channel;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = Store::factory()->create();

        // Use the default in_store channel created by the Store observer
        $this->channel = SalesChannel::where('store_id', $this->store->id)
            ->where('is_local', true)
            ->first();

        // If no channel exists (test environment), create one with unique code
        if (! $this->channel) {
            $this->channel = SalesChannel::factory()->create([
                'store_id' => $this->store->id,
                'is_local' => true,
                'is_active' => true,
                'code' => 'in_store_test_'.$this->store->id,
            ]);
        }

        $this->product = Product::factory()->create([
            'store_id' => $this->store->id,
        ]);
    }

    public function test_channel_listing_returns_listing_manager(): void
    {
        $listing = PlatformListing::factory()->create([
            'product_id' => $this->product->id,
            'sales_channel_id' => $this->channel->id,
        ]);

        $manager = Channel::listing($listing);

        $this->assertInstanceOf(ListingManager::class, $manager);
        $this->assertEquals($listing->id, $manager->getListing()->id);
    }

    public function test_channel_listing_accepts_id(): void
    {
        $listing = PlatformListing::factory()->create([
            'product_id' => $this->product->id,
            'sales_channel_id' => $this->channel->id,
        ]);

        $manager = Channel::listing($listing->id);

        $this->assertInstanceOf(ListingManager::class, $manager);
        $this->assertEquals($listing->id, $manager->getListing()->id);
    }

    public function test_channel_platform_returns_adapter(): void
    {
        $adapter = Channel::platform($this->channel);

        $this->assertInstanceOf(LocalAdapter::class, $adapter);
        $this->assertEquals('local', $adapter->getPlatform());
    }

    public function test_channel_listings_for_product(): void
    {
        // Product auto-creates listings for all active channels
        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'status' => Product::STATUS_ACTIVE,
        ]);

        $listings = Channel::listingsFor($product);

        // Should have listings for all active channels
        $activeChannelCount = SalesChannel::where('store_id', $this->store->id)
            ->where('is_active', true)
            ->count();
        $this->assertCount($activeChannelCount, $listings);
    }

    public function test_channel_ensure_listings_creates_missing(): void
    {
        // Product auto-creates listings for all active channels on create
        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'status' => Product::STATUS_ACTIVE,
        ]);

        $activeChannelCount = SalesChannel::where('store_id', $this->store->id)
            ->where('is_active', true)
            ->count();

        // Product should already have listings for all active channels
        $this->assertCount($activeChannelCount, $product->platformListings);

        // ensureListings should be idempotent - calling it again returns same listings
        $listings = Channel::ensureListings($product);

        $this->assertCount($activeChannelCount, $listings);

        // Every active channel should have a listing
        $activeChannels = SalesChannel::where('store_id', $this->store->id)
            ->where('is_active', true)
            ->pluck('id');

        foreach ($activeChannels as $channelId) {
            $this->assertNotNull(
                $product->platformListings()->where('sales_channel_id', $channelId)->first()
            );
        }
    }

    public function test_listing_manager_publish_for_local_channel(): void
    {
        $listing = PlatformListing::factory()->notListed()->create([
            'product_id' => $this->product->id,
            'sales_channel_id' => $this->channel->id,
        ]);

        $result = Channel::listing($listing)->publish();

        $this->assertInstanceOf(PlatformAdapterResult::class, $result);
        $this->assertTrue($result->success);

        $listing->refresh();
        $this->assertEquals(PlatformListing::STATUS_LISTED, $listing->status);
        $this->assertNotNull($listing->published_at);
    }

    public function test_listing_manager_unpublish(): void
    {
        $listing = PlatformListing::factory()->listed()->create([
            'product_id' => $this->product->id,
            'sales_channel_id' => $this->channel->id,
        ]);

        $result = Channel::listing($listing)->unpublish();

        $this->assertTrue($result->success);

        $listing->refresh();
        $this->assertEquals(PlatformListing::STATUS_ENDED, $listing->status);
    }

    public function test_listing_manager_update_inventory(): void
    {
        $listing = PlatformListing::factory()->listed()->create([
            'product_id' => $this->product->id,
            'sales_channel_id' => $this->channel->id,
        ]);

        $result = Channel::listing($listing)->updateInventory(25);

        $this->assertTrue($result->success);

        $listing->refresh();
        $this->assertEquals(25, $listing->platform_quantity);
    }

    public function test_listing_manager_update_price(): void
    {
        $listing = PlatformListing::factory()->listed()->create([
            'product_id' => $this->product->id,
            'sales_channel_id' => $this->channel->id,
        ]);

        $result = Channel::listing($listing)->updatePrice(99.99);

        $this->assertTrue($result->success);

        $listing->refresh();
        $this->assertEquals(99.99, $listing->platform_price);
    }
}
