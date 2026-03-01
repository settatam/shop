<?php

namespace Tests\Feature\BigCommerce;

use App\Models\PlatformListing;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SalesChannel;
use App\Models\Store;
use App\Models\StoreMarketplace;
use App\Services\Platforms\Adapters\BigCommerceAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BigCommerceAdapterTest extends TestCase
{
    use RefreshDatabase;

    protected Store $store;

    protected StoreMarketplace $marketplace;

    protected SalesChannel $channel;

    protected BigCommerceAdapter $adapter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = Store::factory()->create();
        $this->marketplace = StoreMarketplace::factory()->bigcommerce()->create([
            'store_id' => $this->store->id,
            'external_store_id' => 'abc123',
            'status' => 'active',
        ]);
        $this->channel = SalesChannel::factory()->create([
            'store_id' => $this->store->id,
            'store_marketplace_id' => $this->marketplace->id,
            'type' => 'bigcommerce',
        ]);
        $this->adapter = new BigCommerceAdapter($this->channel);
    }

    protected function createProductWithListing(array $listingOverrides = []): PlatformListing
    {
        $product = Product::factory()->create(['store_id' => $this->store->id]);
        ProductVariant::factory()->create(['product_id' => $product->id]);

        $listing = $product->platformListings()
            ->where('sales_channel_id', $this->channel->id)
            ->first();

        if ($listing && ! empty($listingOverrides)) {
            $listing->update($listingOverrides);
            $listing->refresh();
        }

        return $listing;
    }

    public function test_is_connected_returns_true_when_credentials_present(): void
    {
        $this->assertTrue($this->adapter->isConnected());
    }

    public function test_is_connected_returns_false_without_store_hash(): void
    {
        $this->marketplace->update([
            'external_store_id' => null,
            'credentials' => [],
        ]);
        $adapter = new BigCommerceAdapter($this->channel->fresh());

        $this->assertFalse($adapter->isConnected());
    }

    public function test_publish_creates_new_product(): void
    {
        Http::fake([
            'api.bigcommerce.com/stores/abc123/v3/catalog/products' => Http::response([
                'data' => [
                    'id' => 77,
                    'custom_url' => ['url' => '/test-product/'],
                    'variants' => [],
                ],
            ], 200),
        ]);

        $listing = $this->createProductWithListing(['external_listing_id' => null]);

        $result = $this->adapter->publish($listing);

        $this->assertTrue($result->success);
        $this->assertEquals('77', $result->externalId);
        $this->assertEquals('/test-product/', $result->externalUrl);
    }

    public function test_publish_updates_existing_product(): void
    {
        Http::fake([
            'api.bigcommerce.com/stores/abc123/v3/catalog/products/77' => Http::response([
                'data' => [
                    'id' => 77,
                    'custom_url' => ['url' => '/test-product/'],
                    'variants' => [],
                ],
            ], 200),
        ]);

        $listing = $this->createProductWithListing([
            'external_listing_id' => '77',
            'status' => PlatformListing::STATUS_LISTED,
        ]);

        $result = $this->adapter->publish($listing);

        $this->assertTrue($result->success);
    }

    public function test_unpublish_sets_invisible(): void
    {
        Http::fake([
            'api.bigcommerce.com/stores/abc123/v3/catalog/products/77' => Http::response([
                'data' => [
                    'id' => 77,
                    'is_visible' => false,
                ],
            ], 200),
        ]);

        $listing = $this->createProductWithListing([
            'external_listing_id' => '77',
            'status' => PlatformListing::STATUS_LISTED,
        ]);

        $result = $this->adapter->unpublish($listing);

        $this->assertTrue($result->success);
        $this->assertEquals('Product unpublished from BigCommerce', $result->message);
    }

    public function test_end_deletes_product(): void
    {
        Http::fake([
            'api.bigcommerce.com/stores/abc123/v3/catalog/products/77' => Http::response(null, 204),
        ]);

        $listing = $this->createProductWithListing([
            'external_listing_id' => '77',
            'status' => PlatformListing::STATUS_LISTED,
        ]);

        $result = $this->adapter->end($listing);

        $this->assertTrue($result->success);
    }

    public function test_update_price_updates_simple_product(): void
    {
        Http::fake([
            'api.bigcommerce.com/stores/abc123/v3/catalog/products/77' => Http::response([
                'data' => [
                    'id' => 77,
                    'price' => 29.99,
                ],
            ], 200),
        ]);

        $listing = $this->createProductWithListing([
            'external_listing_id' => '77',
            'status' => PlatformListing::STATUS_LISTED,
        ]);

        $result = $this->adapter->updatePrice($listing, 29.99);

        $this->assertTrue($result->success);
        $this->assertEquals(29.99, $result->data['price']);
    }

    public function test_update_inventory_updates_simple_product(): void
    {
        Http::fake([
            'api.bigcommerce.com/stores/abc123/v3/catalog/products/77' => Http::response([
                'data' => [
                    'id' => 77,
                    'inventory_level' => 15,
                ],
            ], 200),
        ]);

        $listing = $this->createProductWithListing([
            'external_listing_id' => '77',
            'status' => PlatformListing::STATUS_LISTED,
        ]);

        $result = $this->adapter->updateInventory($listing, 15);

        $this->assertTrue($result->success);
        $this->assertEquals(15, $result->data['quantity']);
    }

    public function test_refresh_fetches_product_from_big_commerce(): void
    {
        Http::fake([
            'api.bigcommerce.com/stores/abc123/v3/catalog/products/77' => Http::response([
                'data' => [
                    'id' => 77,
                    'is_visible' => true,
                    'price' => 49.99,
                    'inventory_level' => 10,
                    'custom_url' => ['url' => '/test-product/'],
                ],
            ], 200),
        ]);

        $listing = $this->createProductWithListing([
            'external_listing_id' => '77',
            'status' => PlatformListing::STATUS_LISTED,
        ]);

        $result = $this->adapter->refresh($listing);

        $this->assertTrue($result->success);
        $this->assertEquals(PlatformListing::STATUS_LISTED, $result->data['status']);
        $this->assertEquals(49.99, $result->data['price']);
        $this->assertEquals(10, $result->data['quantity']);
    }

    public function test_refresh_returns_not_listed_for_invisible_product(): void
    {
        Http::fake([
            'api.bigcommerce.com/stores/abc123/v3/catalog/products/77' => Http::response([
                'data' => [
                    'id' => 77,
                    'is_visible' => false,
                    'price' => 49.99,
                    'inventory_level' => 0,
                ],
            ], 200),
        ]);

        $listing = $this->createProductWithListing([
            'external_listing_id' => '77',
            'status' => PlatformListing::STATUS_LISTED,
        ]);

        $result = $this->adapter->refresh($listing);

        $this->assertTrue($result->success);
        $this->assertEquals(PlatformListing::STATUS_NOT_LISTED, $result->data['status']);
    }

    public function test_publish_fails_when_not_connected(): void
    {
        $this->marketplace->update(['access_token' => null]);
        $adapter = new BigCommerceAdapter($this->channel->fresh());

        $listing = $this->createProductWithListing();

        $result = $adapter->publish($listing);

        $this->assertTrue($result->failed());
        $this->assertEquals('BigCommerce is not connected', $result->message);
    }

    public function test_publish_fails_on_api_error(): void
    {
        Http::fake([
            'api.bigcommerce.com/stores/abc123/v3/catalog/products' => Http::response([
                'status' => 422,
                'title' => 'Missing required field',
            ], 422),
        ]);

        $listing = $this->createProductWithListing(['external_listing_id' => null]);

        $result = $this->adapter->publish($listing);

        $this->assertTrue($result->failed());
    }

    public function test_sync_publishes_when_no_external_id(): void
    {
        Http::fake([
            'api.bigcommerce.com/stores/abc123/v3/catalog/products' => Http::response([
                'data' => [
                    'id' => 88,
                    'custom_url' => ['url' => '/new-product/'],
                    'variants' => [],
                ],
            ], 200),
        ]);

        $listing = $this->createProductWithListing(['external_listing_id' => null]);

        $result = $this->adapter->sync($listing);

        $this->assertTrue($result->success);
        $this->assertEquals('88', $result->externalId);
    }

    public function test_sync_updates_when_external_id_exists(): void
    {
        Http::fake([
            'api.bigcommerce.com/stores/abc123/v3/catalog/products/77' => Http::response([
                'data' => [
                    'id' => 77,
                    'custom_url' => ['url' => '/test-product/'],
                    'variants' => [],
                ],
            ], 200),
        ]);

        $listing = $this->createProductWithListing([
            'external_listing_id' => '77',
            'status' => PlatformListing::STATUS_LISTED,
        ]);

        $result = $this->adapter->sync($listing);

        $this->assertTrue($result->success);
    }
}
