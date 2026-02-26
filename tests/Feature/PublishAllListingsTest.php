<?php

namespace Tests\Feature;

use App\Enums\Platform;
use App\Models\PlatformListing;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreMarketplace;
use App\Models\StoreUser;
use App\Models\User;
use App\Services\Platforms\Contracts\PlatformInterface;
use App\Services\Platforms\PlatformManager;
use App\Services\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class PublishAllListingsTest extends TestCase
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

    public function test_publish_all_publishes_to_available_marketplaces(): void
    {
        $product = $this->createProductWithVariant();

        $marketplace1 = $this->createConnectedMarketplace(Platform::Shopify, 'Shopify Store');
        $marketplace2 = $this->createConnectedMarketplace(Platform::Ebay, 'eBay Store');

        $this->mockPlatformManagerForSuccess($product, [$marketplace1, $marketplace2]);

        $response = $this->postJson("/products/{$product->id}/listings/publish-all");

        $response->assertOk();
        $response->assertJsonStructure([
            'success',
            'message',
            'published',
            'failed',
        ]);
        $response->assertJson([
            'success' => true,
        ]);
        $this->assertCount(2, $response->json('published'));
        $this->assertCount(0, $response->json('failed'));
    }

    public function test_publish_all_skips_already_listed_marketplaces(): void
    {
        $product = $this->createProductWithVariant();

        $marketplace1 = $this->createConnectedMarketplace(Platform::Shopify, 'Shopify Store');
        $marketplace2 = $this->createConnectedMarketplace(Platform::Ebay, 'eBay Store');

        // Already listed on Shopify
        PlatformListing::create([
            'store_marketplace_id' => $marketplace1->id,
            'product_id' => $product->id,
            'status' => PlatformListing::STATUS_LISTED,
            'published_at' => now(),
        ]);

        $this->mockPlatformManagerForSuccess($product, [$marketplace2]);

        $response = $this->postJson("/products/{$product->id}/listings/publish-all");

        $response->assertOk();
        $this->assertCount(1, $response->json('published'));
        $this->assertEquals('eBay Store', $response->json('published.0.marketplace_name'));
    }

    public function test_publish_all_returns_validation_errors_for_invalid_listings(): void
    {
        // Product without images or price — should fail validation
        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'status' => 'active',
            'title' => 'Test Product',
        ]);

        $this->createConnectedMarketplace(Platform::Shopify, 'Shopify Store');

        $response = $this->postJson("/products/{$product->id}/listings/publish-all");

        $response->assertOk();
        $response->assertJson(['success' => false]);
        $this->assertCount(0, $response->json('published'));
        $this->assertCount(1, $response->json('failed'));
        $this->assertNotEmpty($response->json('failed.0.errors'));
    }

    public function test_publish_all_returns_empty_when_no_marketplaces_available(): void
    {
        $product = $this->createProductWithVariant();

        // No marketplaces connected
        $response = $this->postJson("/products/{$product->id}/listings/publish-all");

        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'published' => [],
            'failed' => [],
        ]);
    }

    public function test_publish_all_returns_empty_when_all_already_listed(): void
    {
        $product = $this->createProductWithVariant();

        $marketplace = $this->createConnectedMarketplace(Platform::Shopify, 'Shopify Store');

        PlatformListing::create([
            'store_marketplace_id' => $marketplace->id,
            'product_id' => $product->id,
            'status' => PlatformListing::STATUS_LISTED,
            'published_at' => now(),
        ]);

        $response = $this->postJson("/products/{$product->id}/listings/publish-all");

        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'published' => [],
            'failed' => [],
        ]);
    }

    public function test_publish_all_handles_mixed_success_and_failure(): void
    {
        $product = $this->createProductWithVariant();

        $marketplace1 = $this->createConnectedMarketplace(Platform::Shopify, 'Shopify Store');
        $marketplace2 = $this->createConnectedMarketplace(Platform::Ebay, 'eBay Store');

        // Mock: Shopify succeeds, eBay throws an exception
        $mockManager = Mockery::mock(PlatformManager::class);
        $mockDriver = Mockery::mock(PlatformInterface::class);

        $successListing = new PlatformListing([
            'id' => 999,
            'status' => PlatformListing::STATUS_LISTED,
            'listing_url' => 'https://shopify.com/listing/123',
            'external_listing_id' => '123',
        ]);

        $mockDriver->shouldReceive('pushProduct')
            ->with(Mockery::on(fn ($p) => $p->id === $product->id), Mockery::on(fn ($m) => $m->id === $marketplace1->id))
            ->andReturn($successListing);

        $mockDriver->shouldReceive('pushProduct')
            ->with(Mockery::on(fn ($p) => $p->id === $product->id), Mockery::on(fn ($m) => $m->id === $marketplace2->id))
            ->andThrow(new \Exception('eBay API connection failed'));

        $mockManager->shouldReceive('driver')->andReturn($mockDriver);
        $this->app->instance(PlatformManager::class, $mockManager);

        $response = $this->postJson("/products/{$product->id}/listings/publish-all");

        $response->assertOk();
        $this->assertCount(1, $response->json('published'));
        $this->assertCount(1, $response->json('failed'));
        $this->assertStringContainsString('eBay API connection failed', $response->json('failed.0.errors.0'));
    }

    public function test_publish_all_requires_product_update_permission(): void
    {
        $product = $this->createProductWithVariant();

        // Create a user without update permission
        $limitedUser = User::factory()->create(['current_store_id' => $this->store->id]);
        $viewRole = Role::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Viewer',
        ]);
        StoreUser::factory()->create([
            'user_id' => $limitedUser->id,
            'store_id' => $this->store->id,
            'role_id' => $viewRole->id,
        ]);

        $this->actingAs($limitedUser);

        $response = $this->postJson("/products/{$product->id}/listings/publish-all");

        $response->assertForbidden();
    }

    /**
     * Create a product with a variant (price and images) that passes validation.
     */
    protected function createProductWithVariant(): Product
    {
        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'status' => 'active',
            'title' => 'Test Product',
        ]);

        ProductVariant::factory()->create([
            'product_id' => $product->id,
            'price' => 99.99,
            'sku' => 'TEST-SKU-'.uniqid(),
        ]);

        // Create a product image
        \App\Models\Image::create([
            'store_id' => $this->store->id,
            'imageable_type' => Product::class,
            'imageable_id' => $product->id,
            'path' => 'uploads/test-image.jpg',
            'url' => 'https://example.com/image.jpg',
            'disk' => 'do_spaces',
            'is_primary' => true,
        ]);

        return $product->fresh();
    }

    /**
     * Create a connected StoreMarketplace for the current store.
     */
    protected function createConnectedMarketplace(Platform $platform, string $name): StoreMarketplace
    {
        return StoreMarketplace::factory()->create([
            'store_id' => $this->store->id,
            'platform' => $platform,
            'name' => $name,
            'status' => 'active',
            'is_app' => false,
            'connected_successfully' => true,
        ]);
    }

    /**
     * Mock PlatformManager to return successful listings for the given marketplaces.
     */
    protected function mockPlatformManagerForSuccess(Product $product, array $marketplaces): void
    {
        $mockManager = Mockery::mock(PlatformManager::class);
        $mockDriver = Mockery::mock(PlatformInterface::class);

        foreach ($marketplaces as $marketplace) {
            $listing = new PlatformListing([
                'id' => rand(1000, 9999),
                'status' => PlatformListing::STATUS_LISTED,
                'listing_url' => "https://{$marketplace->platform->value}.com/listing/".rand(100, 999),
                'external_listing_id' => (string) rand(100000, 999999),
            ]);

            $mockDriver->shouldReceive('pushProduct')
                ->with(
                    Mockery::on(fn ($p) => $p->id === $product->id),
                    Mockery::on(fn ($m) => $m->id === $marketplace->id)
                )
                ->andReturn($listing);
        }

        $mockManager->shouldReceive('driver')->andReturn($mockDriver);
        $this->app->instance(PlatformManager::class, $mockManager);
    }
}
