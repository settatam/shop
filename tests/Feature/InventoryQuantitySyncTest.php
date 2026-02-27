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
use App\Services\Platforms\ListingBuilderService;
use App\Services\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryQuantitySyncTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Store $store;

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

        app(StoreContext::class)->setCurrentStore($this->store);
        $this->actingAs($this->user);
    }

    /**
     * Create a product with a variant, and return both.
     * Creates channel AFTER the product so auto-listing doesn't collide.
     *
     * @return array{product: Product, channel: SalesChannel, listing: PlatformListing}
     */
    protected function createProductWithListing(int $inventoryQuantity = 46, ?int $quantityOverride = null): array
    {
        // Create the product first (this triggers auto-listing for existing channels)
        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'status' => 'active',
        ]);

        ProductVariant::factory()->create([
            'product_id' => $product->id,
            'quantity' => $inventoryQuantity,
            'price' => 99.99,
        ]);

        // Create the channel AFTER the product to avoid auto-listing collision
        $channel = SalesChannel::factory()->create([
            'store_id' => $this->store->id,
            'is_active' => true,
        ]);

        // The product auto-creates a listing for the channel via ensureListingExists
        $listing = $product->ensureListingExists($channel);
        $listing->update(['quantity_override' => $quantityOverride]);
        $listing->refresh();

        $product->load('variants');

        return compact('product', 'channel', 'listing');
    }

    public function test_new_listing_has_null_platform_quantity_and_null_quantity_override(): void
    {
        $data = $this->createProductWithListing();

        $this->assertNull($data['listing']->platform_quantity);
        $this->assertNull($data['listing']->quantity_override);
    }

    public function test_effective_quantity_returns_inventory_when_no_override(): void
    {
        $data = $this->createProductWithListing(inventoryQuantity: 46, quantityOverride: null);

        $this->assertEquals(46, $data['listing']->getEffectiveQuantity());
    }

    public function test_effective_quantity_returns_override_when_less_than_inventory(): void
    {
        $data = $this->createProductWithListing(inventoryQuantity: 46, quantityOverride: 10);

        $this->assertEquals(10, $data['listing']->getEffectiveQuantity());
    }

    public function test_effective_quantity_caps_at_inventory_when_override_exceeds_it(): void
    {
        $data = $this->createProductWithListing(inventoryQuantity: 46, quantityOverride: 100);

        // Inventory is 46, override is 100, should return 46
        $this->assertEquals(46, $data['listing']->getEffectiveQuantity());
    }

    public function test_has_quantity_override_returns_true_when_set(): void
    {
        $data = $this->createProductWithListing(quantityOverride: 10);

        $this->assertTrue($data['listing']->hasQuantityOverride());
    }

    public function test_has_quantity_override_returns_false_when_null(): void
    {
        $data = $this->createProductWithListing(quantityOverride: null);

        $this->assertFalse($data['listing']->hasQuantityOverride());
    }

    public function test_clear_quantity_override_reverts_to_inventory(): void
    {
        $data = $this->createProductWithListing(inventoryQuantity: 46, quantityOverride: 10);

        $this->assertEquals(10, $data['listing']->getEffectiveQuantity());

        $data['listing']->clearQuantityOverride();
        $data['listing']->refresh();

        $this->assertNull($data['listing']->quantity_override);
        $this->assertEquals(46, $data['listing']->getEffectiveQuantity());
    }

    public function test_channel_update_writes_quantity_to_override(): void
    {
        $data = $this->createProductWithListing(quantityOverride: null);

        $response = $this->putJson(
            "/products/{$data['product']->id}/channels/{$data['channel']->id}",
            ['quantity' => 15]
        );

        $response->assertOk();

        $data['listing']->refresh();
        $this->assertEquals(15, $data['listing']->quantity_override);
    }

    public function test_listing_builder_uses_effective_quantity_with_override(): void
    {
        $marketplace = StoreMarketplace::factory()->create([
            'store_id' => $this->store->id,
        ]);

        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'status' => 'active',
        ]);

        ProductVariant::factory()->create([
            'product_id' => $product->id,
            'quantity' => 46,
            'price' => 99.99,
        ]);

        $channel = SalesChannel::factory()->create([
            'store_id' => $this->store->id,
            'store_marketplace_id' => $marketplace->id,
            'is_active' => true,
        ]);

        $listing = $product->ensureListingExists($channel);
        $listing->update([
            'store_marketplace_id' => $marketplace->id,
            'quantity_override' => 5,
        ]);

        $product->load(['images', 'legacyImages', 'variants', 'category', 'brand']);

        $service = app(ListingBuilderService::class);
        $preview = $service->previewListing($product, $marketplace);

        $this->assertEquals(5, $preview['listing']['quantity']);
    }

    public function test_listing_builder_uses_inventory_when_no_override(): void
    {
        $marketplace = StoreMarketplace::factory()->create([
            'store_id' => $this->store->id,
        ]);

        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'status' => 'active',
        ]);

        ProductVariant::factory()->create([
            'product_id' => $product->id,
            'quantity' => 46,
            'price' => 99.99,
        ]);

        $channel = SalesChannel::factory()->create([
            'store_id' => $this->store->id,
            'store_marketplace_id' => $marketplace->id,
            'is_active' => true,
        ]);

        $listing = $product->ensureListingExists($channel);
        $listing->update([
            'store_marketplace_id' => $marketplace->id,
            'quantity_override' => null,
        ]);

        $product->load(['images', 'legacyImages', 'variants', 'category', 'brand']);

        $service = app(ListingBuilderService::class);
        $preview = $service->previewListing($product, $marketplace);

        $this->assertEquals(46, $preview['listing']['quantity']);
    }

    public function test_ensure_listing_exists_creates_with_null_platform_quantity(): void
    {
        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'status' => 'active',
        ]);

        $channel = SalesChannel::factory()->create([
            'store_id' => $this->store->id,
            'is_active' => true,
        ]);

        $listing = $product->ensureListingExists($channel);

        $this->assertNull($listing->platform_quantity);
        $this->assertNull($listing->quantity_override);
    }

    public function test_list_on_channel_creates_with_null_platform_quantity(): void
    {
        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'status' => 'active',
        ]);

        $channel = SalesChannel::factory()->create([
            'store_id' => $this->store->id,
            'is_active' => true,
        ]);

        $listing = $product->listOnChannel($channel);

        $this->assertNull($listing->platform_quantity);
        $this->assertNull($listing->quantity_override);
    }

    public function test_effective_quantity_with_zero_inventory_and_override(): void
    {
        $data = $this->createProductWithListing(inventoryQuantity: 0, quantityOverride: 10);

        // min(10, 0) = 0
        $this->assertEquals(0, $data['listing']->getEffectiveQuantity());
    }

    public function test_effective_quantity_with_zero_inventory_and_no_override(): void
    {
        $data = $this->createProductWithListing(inventoryQuantity: 0, quantityOverride: null);

        $this->assertEquals(0, $data['listing']->getEffectiveQuantity());
    }

    public function test_per_listing_effective_quantity_differs_across_listings(): void
    {
        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'status' => 'active',
        ]);

        ProductVariant::factory()->create([
            'product_id' => $product->id,
            'quantity' => 46,
            'price' => 99.99,
        ]);

        $channel1 = SalesChannel::factory()->create([
            'store_id' => $this->store->id,
            'is_active' => true,
        ]);
        $channel2 = SalesChannel::factory()->create([
            'store_id' => $this->store->id,
            'is_active' => true,
        ]);

        $listing1 = $product->ensureListingExists($channel1);
        $listing1->update(['quantity_override' => 10]);
        $listing1->refresh();

        $listing2 = $product->ensureListingExists($channel2);
        // listing2 has no override

        $product->load('variants');

        // listing1 effective = min(10, 46) = 10
        $this->assertEquals(10, $listing1->getEffectiveQuantity());
        // listing2 effective = 46 (no override)
        $this->assertEquals(46, $listing2->getEffectiveQuantity());
    }
}
