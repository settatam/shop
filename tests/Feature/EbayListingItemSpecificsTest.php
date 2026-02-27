<?php

namespace Tests\Feature;

use App\Enums\Platform;
use App\Models\Category;
use App\Models\CategoryPlatformMapping;
use App\Models\EbayItemSpecific;
use App\Models\EbayItemSpecificValue;
use App\Models\PlatformListing;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreMarketplace;
use App\Models\StoreUser;
use App\Models\User;
use App\Services\Platforms\Ebay\EbayItemSpecificsService;
use App\Services\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use Tests\TestCase;

class EbayListingItemSpecificsTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Store $store;

    protected StoreMarketplace $marketplace;

    protected Product $product;

    protected Category $category;

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

        $this->marketplace = StoreMarketplace::factory()->ebay()->create([
            'store_id' => $this->store->id,
        ]);

        $this->category = Category::factory()->create(['store_id' => $this->store->id]);

        $this->product = Product::factory()->create([
            'store_id' => $this->store->id,
            'category_id' => $this->category->id,
        ]);

        ProductVariant::factory()->create([
            'product_id' => $this->product->id,
            'price' => 99.99,
        ]);

        app(StoreContext::class)->setCurrentStore($this->store);
        $this->actingAs($this->user);
    }

    public function test_listing_with_platform_settings_category_override_loads_item_specifics(): void
    {
        $ebayCategoryId = '12345';

        EbayItemSpecific::create([
            'ebay_category_id' => $ebayCategoryId,
            'name' => 'Brand',
            'type' => 'string',
            'is_required' => true,
            'is_recommended' => false,
            'aspect_mode' => 'FREE_TEXT',
        ]);

        EbayItemSpecific::create([
            'ebay_category_id' => $ebayCategoryId,
            'name' => 'Color',
            'type' => 'string',
            'is_required' => false,
            'is_recommended' => true,
            'aspect_mode' => 'FREE_TEXT',
        ]);

        // Create listing with listing-level category override
        $listing = PlatformListing::where('product_id', $this->product->id)
            ->where('store_marketplace_id', $this->marketplace->id)
            ->first();

        if ($listing) {
            $listing->update([
                'platform_settings' => ['primary_category_id' => $ebayCategoryId],
            ]);
        } else {
            PlatformListing::create([
                'product_id' => $this->product->id,
                'store_marketplace_id' => $this->marketplace->id,
                'platform_settings' => ['primary_category_id' => $ebayCategoryId],
                'status' => PlatformListing::STATUS_NOT_LISTED,
            ]);
        }

        $response = $this->get("/products/{$this->product->id}/platforms/{$this->marketplace->id}");

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('products/platforms/Show')
            ->has('ebayItemSpecifics')
            ->has('ebayItemSpecifics.specifics', 2)
            ->where('ebayItemSpecifics.category_mapping_id', null)
            ->where('ebayItemSpecifics.needs_sync', false)
        );
    }

    public function test_listing_without_category_mapping_but_with_listing_override_shows_specifics(): void
    {
        $ebayCategoryId = '99999';

        // No category mapping exists for this product's category
        $this->assertNull(
            CategoryPlatformMapping::where('category_id', $this->category->id)
                ->where('store_marketplace_id', $this->marketplace->id)
                ->first()
        );

        EbayItemSpecific::create([
            'ebay_category_id' => $ebayCategoryId,
            'name' => 'Metal',
            'type' => 'string',
            'is_required' => true,
            'is_recommended' => false,
            'aspect_mode' => 'FREE_TEXT',
        ]);

        $listing = PlatformListing::where('product_id', $this->product->id)
            ->where('store_marketplace_id', $this->marketplace->id)
            ->first();

        if ($listing) {
            $listing->update([
                'platform_settings' => ['primary_category_id' => $ebayCategoryId],
                'attributes' => ['Metal' => '14k Gold'],
            ]);
        } else {
            PlatformListing::create([
                'product_id' => $this->product->id,
                'store_marketplace_id' => $this->marketplace->id,
                'platform_settings' => ['primary_category_id' => $ebayCategoryId],
                'attributes' => ['Metal' => '14k Gold'],
                'status' => PlatformListing::STATUS_NOT_LISTED,
            ]);
        }

        $response = $this->get("/products/{$this->product->id}/platforms/{$this->marketplace->id}");

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('products/platforms/Show')
            ->has('ebayItemSpecifics.specifics', 1)
            ->where('ebayItemSpecifics.specifics.0.name', 'Metal')
            ->where('ebayItemSpecifics.specifics.0.resolved_value', '14k Gold')
            ->where('ebayItemSpecifics.specifics.0.is_listing_override', true)
        );
    }

    public function test_item_specifics_endpoint_returns_specifics_for_category(): void
    {
        $ebayCategoryId = '55555';

        EbayItemSpecific::create([
            'ebay_category_id' => $ebayCategoryId,
            'name' => 'Size',
            'type' => 'string',
            'is_required' => true,
            'is_recommended' => false,
            'aspect_mode' => 'FREE_TEXT',
        ]);

        $specific = EbayItemSpecific::create([
            'ebay_category_id' => $ebayCategoryId,
            'name' => 'Material',
            'type' => 'string',
            'is_required' => false,
            'is_recommended' => true,
            'aspect_mode' => 'SELECTION_ONLY',
        ]);

        EbayItemSpecificValue::create([
            'ebay_category_id' => $ebayCategoryId,
            'ebay_item_specific_id' => $specific->id,
            'value' => 'Gold',
        ]);

        $response = $this->postJson(
            "/products/{$this->product->id}/platforms/{$this->marketplace->id}/item-specifics",
            ['category_id' => $ebayCategoryId],
        );

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonCount(2, 'specifics');

        // Required specifics come first (sorted by is_required desc)
        $response->assertJsonPath('specifics.0.name', 'Size');
        $response->assertJsonPath('specifics.0.is_required', true);
        $response->assertJsonPath('specifics.1.name', 'Material');
        $response->assertJsonPath('specifics.1.allowed_values.0', 'Gold');
        $response->assertJsonPath('category_mapping_id', null);
        $response->assertJsonPath('needs_sync', false);
    }

    public function test_item_specifics_endpoint_triggers_fetch_when_not_locally_available(): void
    {
        $ebayCategoryId = '77777';

        // No specifics exist locally
        $this->assertEquals(0, EbayItemSpecific::where('ebay_category_id', $ebayCategoryId)->count());

        // Mock the service to simulate ensureItemSpecificsExist creating records,
        // while getItemSpecifics delegates to real DB query
        $mockService = Mockery::mock(EbayItemSpecificsService::class);
        $mockService->shouldReceive('ensureItemSpecificsExist')
            ->once()
            ->with($ebayCategoryId, Mockery::type(StoreMarketplace::class))
            ->andReturnUsing(function (string $categoryId) {
                EbayItemSpecific::create([
                    'ebay_category_id' => $categoryId,
                    'name' => 'Brand',
                    'type' => 'string',
                    'is_required' => true,
                    'is_recommended' => false,
                    'aspect_mode' => 'FREE_TEXT',
                ]);
            });
        $mockService->shouldReceive('getItemSpecifics')
            ->once()
            ->with($ebayCategoryId)
            ->andReturnUsing(function (string $categoryId) {
                return EbayItemSpecific::where('ebay_category_id', $categoryId)
                    ->with('values')
                    ->orderByDesc('is_required')
                    ->orderByDesc('is_recommended')
                    ->orderBy('name')
                    ->get();
            });
        $this->app->instance(EbayItemSpecificsService::class, $mockService);

        $response = $this->postJson(
            "/products/{$this->product->id}/platforms/{$this->marketplace->id}/item-specifics",
            ['category_id' => $ebayCategoryId],
        );

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonCount(1, 'specifics');
        $response->assertJsonPath('specifics.0.name', 'Brand');
    }

    public function test_category_mapping_resolution_is_used_as_fallback(): void
    {
        $ebayCategoryId = '67890';

        CategoryPlatformMapping::create([
            'store_id' => $this->store->id,
            'category_id' => $this->category->id,
            'store_marketplace_id' => $this->marketplace->id,
            'platform' => Platform::Ebay,
            'primary_category_id' => $ebayCategoryId,
            'primary_category_name' => 'Rings',
            'item_specifics_synced_at' => now(),
        ]);

        EbayItemSpecific::create([
            'ebay_category_id' => $ebayCategoryId,
            'name' => 'Ring Size',
            'type' => 'string',
            'is_required' => true,
            'is_recommended' => false,
            'aspect_mode' => 'FREE_TEXT',
        ]);

        // No listing override — should fall back to category mapping
        $response = $this->get("/products/{$this->product->id}/platforms/{$this->marketplace->id}");

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('products/platforms/Show')
            ->has('ebayItemSpecifics.specifics', 1)
            ->where('ebayItemSpecifics.specifics.0.name', 'Ring Size')
            ->whereNot('ebayItemSpecifics.category_mapping_id', null)
        );
    }

    public function test_listing_override_category_takes_precedence_over_mapping(): void
    {
        $mappingCategoryId = '67890';
        $overrideCategoryId = '11111';

        // Create mapping with one category
        CategoryPlatformMapping::create([
            'store_id' => $this->store->id,
            'category_id' => $this->category->id,
            'store_marketplace_id' => $this->marketplace->id,
            'platform' => Platform::Ebay,
            'primary_category_id' => $mappingCategoryId,
            'primary_category_name' => 'Rings',
            'item_specifics_synced_at' => now(),
        ]);

        // Specifics for the mapping category
        EbayItemSpecific::create([
            'ebay_category_id' => $mappingCategoryId,
            'name' => 'Ring Size',
            'type' => 'string',
            'is_required' => true,
            'is_recommended' => false,
            'aspect_mode' => 'FREE_TEXT',
        ]);

        // Specifics for the override category
        EbayItemSpecific::create([
            'ebay_category_id' => $overrideCategoryId,
            'name' => 'Necklace Length',
            'type' => 'string',
            'is_required' => true,
            'is_recommended' => false,
            'aspect_mode' => 'FREE_TEXT',
        ]);

        // Create listing with a different category override
        $listing = PlatformListing::where('product_id', $this->product->id)
            ->where('store_marketplace_id', $this->marketplace->id)
            ->first();

        if ($listing) {
            $listing->update([
                'platform_settings' => ['primary_category_id' => $overrideCategoryId],
            ]);
        } else {
            PlatformListing::create([
                'product_id' => $this->product->id,
                'store_marketplace_id' => $this->marketplace->id,
                'platform_settings' => ['primary_category_id' => $overrideCategoryId],
                'status' => PlatformListing::STATUS_NOT_LISTED,
            ]);
        }

        $response = $this->get("/products/{$this->product->id}/platforms/{$this->marketplace->id}");

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('products/platforms/Show')
            ->has('ebayItemSpecifics.specifics', 1)
            ->where('ebayItemSpecifics.specifics.0.name', 'Necklace Length')
            ->where('ebayItemSpecifics.category_mapping_id', null)
        );
    }

    public function test_item_specifics_endpoint_validates_category_id(): void
    {
        $response = $this->postJson(
            "/products/{$this->product->id}/platforms/{$this->marketplace->id}/item-specifics",
            [],
        );

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('category_id');
    }
}
