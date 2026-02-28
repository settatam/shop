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
use App\Services\Platforms\Adapters\EtsyAdapter;
use App\Services\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EtsyAdapterTest extends TestCase
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

        $this->marketplace = StoreMarketplace::factory()->etsy()->create([
            'store_id' => $this->store->id,
            'external_store_id' => '12345678',
            'credentials' => [
                'shop_id' => 12345678,
                'user_id' => 87654321,
            ],
        ]);

        // Create product BEFORE channel, so channel's created observer auto-creates listing
        $this->product = Product::factory()->create([
            'store_id' => $this->store->id,
        ]);

        ProductVariant::factory()->create([
            'product_id' => $this->product->id,
            'sku' => 'ETSY-SKU-001',
            'price' => 24.99,
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
            'external_listing_id' => '9999999999',
            'status' => PlatformListing::STATUS_LISTED,
        ]);

        app(StoreContext::class)->setCurrentStore($this->store);
        $this->actingAs($this->user);
    }

    public function test_update_inventory_calls_etsy_api_with_correct_payload(): void
    {
        Http::fake([
            'openapi.etsy.com/v3/application/listings/9999999999/inventory' => Http::response(['listing_id' => 9999999999], 200),
        ]);

        $adapter = new EtsyAdapter($this->channel);
        $result = $adapter->updateInventory($this->listing, 15);

        $this->assertTrue($result->success);
        $this->assertEquals('Inventory updated', $result->message);
        $this->assertEquals(15, $result->data['quantity']);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/application/listings/9999999999/inventory')
                && $request->method() === 'PUT';
        });
    }

    public function test_update_inventory_fails_when_not_connected(): void
    {
        $disconnectedMarketplace = StoreMarketplace::factory()->etsy()->create([
            'store_id' => $this->store->id,
            'access_token' => null,
        ]);

        $channel = SalesChannel::factory()->create([
            'store_id' => $this->store->id,
            'store_marketplace_id' => $disconnectedMarketplace->id,
        ]);

        $adapter = new EtsyAdapter($channel);
        $result = $adapter->updateInventory($this->listing, 10);

        $this->assertFalse($result->success);
        $this->assertEquals('Etsy is not connected', $result->message);
    }

    public function test_update_inventory_fails_when_no_listing_id(): void
    {
        $this->listing->update(['external_listing_id' => null]);

        $adapter = new EtsyAdapter($this->channel);
        $result = $adapter->updateInventory($this->listing->fresh(), 10);

        $this->assertFalse($result->success);
        $this->assertEquals('No listing ID found for this listing', $result->message);
    }

    public function test_publish_creates_listing_on_etsy(): void
    {
        Http::fake([
            'openapi.etsy.com/v3/application/shops/12345678/listings' => Http::response([
                'listing_id' => 1111111111,
                'state' => 'active',
                'url' => 'https://www.etsy.com/listing/1111111111',
            ], 200),
        ]);

        $adapter = new EtsyAdapter($this->channel);
        $result = $adapter->publish($this->listing);

        $this->assertTrue($result->success);
        $this->assertNotNull($result->externalId);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/application/shops/12345678/listings')
                && $request->method() === 'POST';
        });
    }

    public function test_unpublish_deactivates_listing(): void
    {
        Http::fake([
            'openapi.etsy.com/v3/application/shops/12345678/listings/9999999999' => Http::response(['listing_id' => 9999999999, 'state' => 'inactive'], 200),
        ]);

        $adapter = new EtsyAdapter($this->channel);
        $result = $adapter->unpublish($this->listing);

        $this->assertTrue($result->success);
        $this->assertEquals('Listing deactivated on Etsy', $result->message);

        $this->listing->refresh();
        $this->assertEquals(PlatformListing::STATUS_ENDED, $this->listing->status);

        Http::assertSent(function ($request) {
            if ($request->method() !== 'PUT') {
                return false;
            }

            $body = json_decode($request->body(), true);

            return ($body['state'] ?? '') === 'inactive';
        });
    }

    public function test_update_price_calls_inventory_endpoint(): void
    {
        Http::fake([
            'openapi.etsy.com/v3/application/listings/9999999999/inventory' => Http::response(['listing_id' => 9999999999], 200),
        ]);

        $adapter = new EtsyAdapter($this->channel);
        $result = $adapter->updatePrice($this->listing, 39.99);

        $this->assertTrue($result->success);
        $this->assertEquals('Price updated', $result->message);
        $this->assertEquals(39.99, $result->data['price']);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/application/listings/9999999999/inventory')
                && $request->method() === 'PUT';
        });
    }

    public function test_refresh_returns_listing_status(): void
    {
        Http::fake([
            'openapi.etsy.com/v3/application/listings/9999999999' => Http::response([
                'listing_id' => 9999999999,
                'state' => 'active',
                'title' => 'Test Etsy Product',
                'views' => 42,
            ], 200),
        ]);

        $adapter = new EtsyAdapter($this->channel);
        $result = $adapter->refresh($this->listing);

        $this->assertTrue($result->success);
        $this->assertEquals('active', $result->data['etsy_state']);

        $this->listing->refresh();
        $this->assertEquals(PlatformListing::STATUS_LISTED, $this->listing->status);
    }
}
