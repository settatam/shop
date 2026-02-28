<?php

namespace Tests\Feature;

use App\Models\PlatformListing;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\SalesChannel;
use App\Models\Store;
use App\Models\StoreMarketplace;
use App\Models\StoreUser;
use App\Models\User;
use App\Services\Platforms\Adapters\WalmartAdapter;
use App\Services\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WalmartAdapterTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Store $store;

    protected StoreMarketplace $marketplace;

    protected SalesChannel $channel;

    protected Product $product;

    protected PlatformListing $listing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->store = Store::factory()->create([
            'user_id' => $this->user->id,
            'name' => 'Test Store',
            'step' => 2,
        ]);

        $this->user->update(['current_store_id' => $this->store->id]);

        $role = Role::factory()->owner()->create(['store_id' => $this->store->id]);
        StoreUser::factory()->owner()->create([
            'user_id' => $this->user->id,
            'store_id' => $this->store->id,
            'role_id' => $role->id,
        ]);

        $this->marketplace = StoreMarketplace::factory()->walmart()->create([
            'store_id' => $this->store->id,
            'credentials' => [
                'client_id' => 'test-client-id',
                'client_secret' => encrypt('test-client-secret'),
            ],
        ]);

        // Create product BEFORE channel, so channel's created observer auto-creates listing
        $this->product = Product::factory()->create([
            'store_id' => $this->store->id,
        ]);

        ProductVariant::factory()->create([
            'product_id' => $this->product->id,
            'sku' => 'TEST-SKU-001',
            'price' => 29.99,
        ]);

        // Channel creation will auto-create a PlatformListing for the product
        $this->channel = SalesChannel::factory()->create([
            'store_id' => $this->store->id,
            'store_marketplace_id' => $this->marketplace->id,
        ]);

        // Retrieve the auto-created listing and update it with our test data
        $this->listing = PlatformListing::where('product_id', $this->product->id)
            ->where('sales_channel_id', $this->channel->id)
            ->first();

        $this->listing->update([
            'external_listing_id' => 'TEST-SKU-001',
            'status' => PlatformListing::STATUS_LISTED,
        ]);

        app(StoreContext::class)->setCurrentStore($this->store);
        $this->actingAs($this->user);
    }

    public function test_update_inventory_calls_walmart_api_with_correct_payload(): void
    {
        Http::fake([
            'marketplace.walmartapis.com/v3/inventory*' => Http::response(['sku' => 'TEST-SKU-001', 'quantity' => ['unit' => 'EACH', 'amount' => 15]], 200),
        ]);

        $adapter = new WalmartAdapter($this->channel);
        $result = $adapter->updateInventory($this->listing, 15);

        $this->assertTrue($result->success);
        $this->assertEquals('Inventory updated', $result->message);
        $this->assertEquals(15, $result->data['quantity']);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/v3/inventory')
                && str_contains($request->url(), 'sku=TEST-SKU-001')
                && $request->method() === 'PUT';
        });
    }

    public function test_update_inventory_fails_when_not_connected(): void
    {
        $disconnectedMarketplace = StoreMarketplace::factory()->walmart()->create([
            'store_id' => $this->store->id,
            'access_token' => null,
        ]);

        $channel = SalesChannel::factory()->create([
            'store_id' => $this->store->id,
            'store_marketplace_id' => $disconnectedMarketplace->id,
        ]);

        $adapter = new WalmartAdapter($channel);
        $result = $adapter->updateInventory($this->listing, 10);

        $this->assertFalse($result->success);
        $this->assertEquals('Walmart is not connected', $result->message);
    }

    public function test_update_inventory_fails_when_no_sku(): void
    {
        $this->listing->update(['external_listing_id' => null]);

        $adapter = new WalmartAdapter($this->channel);
        $result = $adapter->updateInventory($this->listing->fresh(), 10);

        $this->assertFalse($result->success);
        $this->assertEquals('No SKU found for this listing', $result->message);
    }

    public function test_publish_submits_item_feed(): void
    {
        Http::fake([
            'marketplace.walmartapis.com/v3/feeds*' => Http::response(['feedId' => 'FEED-001'], 200),
        ]);

        $adapter = new WalmartAdapter($this->channel);
        $result = $adapter->publish($this->listing);

        $this->assertTrue($result->success);
        $this->assertNotNull($result->externalId);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/v3/feeds')
                && str_contains($request->url(), 'feedType=item')
                && $request->method() === 'POST';
        });
    }

    public function test_unpublish_retires_item(): void
    {
        Http::fake([
            'marketplace.walmartapis.com/v3/items/TEST-SKU-001' => Http::response([], 200),
        ]);

        $adapter = new WalmartAdapter($this->channel);
        $result = $adapter->unpublish($this->listing);

        $this->assertTrue($result->success);
        $this->assertEquals('Item retired from Walmart', $result->message);

        $this->listing->refresh();
        $this->assertEquals(PlatformListing::STATUS_ENDED, $this->listing->status);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/v3/items/TEST-SKU-001')
                && $request->method() === 'DELETE';
        });
    }

    public function test_update_price_submits_price_feed(): void
    {
        Http::fake([
            'marketplace.walmartapis.com/v3/feeds*' => Http::response(['feedId' => 'FEED-002'], 200),
        ]);

        $adapter = new WalmartAdapter($this->channel);
        $result = $adapter->updatePrice($this->listing, 39.99);

        $this->assertTrue($result->success);
        $this->assertEquals('Price updated', $result->message);
        $this->assertEquals(39.99, $result->data['price']);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/v3/feeds')
                && str_contains($request->url(), 'feedType=price')
                && $request->method() === 'POST';
        });
    }

    public function test_refresh_returns_item_status_from_walmart(): void
    {
        Http::fake([
            'marketplace.walmartapis.com/v3/items/TEST-SKU-001' => Http::response([
                'sku' => 'TEST-SKU-001',
                'publishedStatus' => 'PUBLISHED',
                'productName' => 'Test Product',
            ], 200),
        ]);

        $adapter = new WalmartAdapter($this->channel);
        $result = $adapter->refresh($this->listing);

        $this->assertTrue($result->success);
        $this->assertEquals('PUBLISHED', $result->data['walmart_status']);

        $this->listing->refresh();
        $this->assertEquals(PlatformListing::STATUS_LISTED, $this->listing->status);
    }
}
