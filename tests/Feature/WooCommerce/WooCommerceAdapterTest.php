<?php

namespace Tests\Feature\WooCommerce;

use App\Models\PlatformListing;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SalesChannel;
use App\Models\Store;
use App\Models\StoreMarketplace;
use App\Services\Platforms\Adapters\WooCommerceAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WooCommerceAdapterTest extends TestCase
{
    use RefreshDatabase;

    protected Store $store;

    protected StoreMarketplace $marketplace;

    protected SalesChannel $channel;

    protected WooCommerceAdapter $adapter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = Store::factory()->create();
        $this->marketplace = StoreMarketplace::factory()->woocommerce()->create([
            'store_id' => $this->store->id,
            'shop_domain' => 'https://test-store.com',
            'access_token' => 'ck_test_key',
            'status' => 'active',
            'credentials' => [
                'site_url' => 'https://test-store.com',
                'consumer_key' => 'ck_test_key',
                'consumer_secret' => encrypt('cs_test_secret'),
            ],
        ]);
        $this->channel = SalesChannel::factory()->create([
            'store_id' => $this->store->id,
            'store_marketplace_id' => $this->marketplace->id,
            'type' => 'woocommerce',
        ]);
        $this->adapter = new WooCommerceAdapter($this->channel);
    }

    /**
     * Create a product and return the auto-created listing for this channel.
     */
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

    public function test_is_connected_returns_false_without_site_url(): void
    {
        $this->marketplace->update(['credentials' => []]);
        $adapter = new WooCommerceAdapter($this->channel->fresh());

        $this->assertFalse($adapter->isConnected());
    }

    public function test_publish_creates_new_product(): void
    {
        Http::fake([
            'test-store.com/wp-json/wc/v3/products' => Http::response([
                'id' => 42,
                'permalink' => 'https://test-store.com/product/test-product',
                'variations' => [],
            ], 201),
        ]);

        $listing = $this->createProductWithListing(['external_listing_id' => null]);

        $result = $this->adapter->publish($listing);

        $this->assertTrue($result->success);
        $this->assertEquals('42', $result->externalId);
        $this->assertEquals('https://test-store.com/product/test-product', $result->externalUrl);
    }

    public function test_publish_updates_existing_product(): void
    {
        Http::fake([
            'test-store.com/wp-json/wc/v3/products/42' => Http::response([
                'id' => 42,
                'permalink' => 'https://test-store.com/product/test-product',
                'variations' => [],
            ], 200),
        ]);

        $listing = $this->createProductWithListing([
            'external_listing_id' => '42',
            'status' => PlatformListing::STATUS_LISTED,
        ]);

        $result = $this->adapter->publish($listing);

        $this->assertTrue($result->success);
    }

    public function test_unpublish_sets_draft_status(): void
    {
        Http::fake([
            'test-store.com/wp-json/wc/v3/products/42' => Http::response([
                'id' => 42,
                'status' => 'draft',
            ], 200),
        ]);

        $listing = $this->createProductWithListing([
            'external_listing_id' => '42',
            'status' => PlatformListing::STATUS_LISTED,
        ]);

        $result = $this->adapter->unpublish($listing);

        $this->assertTrue($result->success);
        $this->assertEquals('Product unpublished from WooCommerce', $result->message);
    }

    public function test_end_trashes_product(): void
    {
        Http::fake([
            'test-store.com/wp-json/wc/v3/products/42' => Http::response([
                'id' => 42,
                'status' => 'trash',
            ], 200),
        ]);

        $listing = $this->createProductWithListing([
            'external_listing_id' => '42',
            'status' => PlatformListing::STATUS_LISTED,
        ]);

        $result = $this->adapter->end($listing);

        $this->assertTrue($result->success);
    }

    public function test_update_price_updates_simple_product(): void
    {
        Http::fake([
            'test-store.com/wp-json/wc/v3/products/42' => Http::response([
                'id' => 42,
                'regular_price' => '29.99',
            ], 200),
        ]);

        $listing = $this->createProductWithListing([
            'external_listing_id' => '42',
            'status' => PlatformListing::STATUS_LISTED,
        ]);

        $result = $this->adapter->updatePrice($listing, 29.99);

        $this->assertTrue($result->success);
        $this->assertEquals(29.99, $result->data['price']);
    }

    public function test_update_inventory_updates_simple_product(): void
    {
        Http::fake([
            'test-store.com/wp-json/wc/v3/products/42' => Http::response([
                'id' => 42,
                'stock_quantity' => 15,
                'manage_stock' => true,
            ], 200),
        ]);

        $listing = $this->createProductWithListing([
            'external_listing_id' => '42',
            'status' => PlatformListing::STATUS_LISTED,
        ]);

        $result = $this->adapter->updateInventory($listing, 15);

        $this->assertTrue($result->success);
        $this->assertEquals(15, $result->data['quantity']);
    }

    public function test_refresh_fetches_product_from_woo_commerce(): void
    {
        Http::fake([
            'test-store.com/wp-json/wc/v3/products/42' => Http::response([
                'id' => 42,
                'status' => 'publish',
                'price' => '49.99',
                'stock_quantity' => 10,
                'permalink' => 'https://test-store.com/product/test-product',
            ], 200),
        ]);

        $listing = $this->createProductWithListing([
            'external_listing_id' => '42',
            'status' => PlatformListing::STATUS_LISTED,
        ]);

        $result = $this->adapter->refresh($listing);

        $this->assertTrue($result->success);
        $this->assertEquals(PlatformListing::STATUS_LISTED, $result->data['status']);
        $this->assertEquals(49.99, $result->data['price']);
        $this->assertEquals(10, $result->data['quantity']);
    }

    public function test_refresh_returns_not_listed_for_draft_product(): void
    {
        Http::fake([
            'test-store.com/wp-json/wc/v3/products/42' => Http::response([
                'id' => 42,
                'status' => 'draft',
                'price' => '49.99',
                'stock_quantity' => 0,
            ], 200),
        ]);

        $listing = $this->createProductWithListing([
            'external_listing_id' => '42',
            'status' => PlatformListing::STATUS_LISTED,
        ]);

        $result = $this->adapter->refresh($listing);

        $this->assertTrue($result->success);
        $this->assertEquals(PlatformListing::STATUS_NOT_LISTED, $result->data['status']);
    }

    public function test_publish_fails_when_not_connected(): void
    {
        $this->marketplace->update(['access_token' => null]);
        $adapter = new WooCommerceAdapter($this->channel->fresh());

        $listing = $this->createProductWithListing();

        $result = $adapter->publish($listing);

        $this->assertTrue($result->failed());
        $this->assertEquals('WooCommerce is not connected', $result->message);
    }

    public function test_publish_fails_on_api_error(): void
    {
        Http::fake([
            'test-store.com/wp-json/wc/v3/products' => Http::response([
                'code' => 'woocommerce_rest_cannot_create',
                'message' => 'Product title is required',
            ], 400),
        ]);

        $listing = $this->createProductWithListing(['external_listing_id' => null]);

        $result = $this->adapter->publish($listing);

        $this->assertTrue($result->failed());
    }
}
