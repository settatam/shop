<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreMarketplace;
use App\Models\StoreUser;
use App\Models\User;
use App\Models\WalmartCategory;
use App\Services\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WalmartCategoryTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Store $store;

    protected StoreMarketplace $marketplace;

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
        ]);

        app(StoreContext::class)->setCurrentStore($this->store);
        $this->actingAs($this->user);
    }

    public function test_taxonomy_endpoint_returns_root_categories(): void
    {
        $parent = WalmartCategory::create([
            'name' => 'Electronics',
            'walmart_category_id' => '1000',
            'level' => 0,
            'parent_id' => null,
            'path' => 'Electronics',
        ]);

        WalmartCategory::create([
            'name' => 'Computers',
            'walmart_category_id' => '1001',
            'level' => 1,
            'parent_id' => $parent->id,
            'walmart_parent_id' => '1000',
            'path' => 'Electronics > Computers',
        ]);

        $response = $this->getJson('/api/taxonomy/walmart/categories');

        $response->assertOk();
        $response->assertJsonCount(1);
        $response->assertJsonFragment([
            'name' => 'Electronics',
            'walmart_category_id' => '1000',
            'has_children' => true,
        ]);
    }

    public function test_search_by_name_returns_filtered_results(): void
    {
        $parent = WalmartCategory::create([
            'name' => 'Electronics',
            'walmart_category_id' => '1000',
            'level' => 0,
            'parent_id' => null,
            'path' => 'Electronics',
        ]);

        WalmartCategory::create([
            'name' => 'Laptops',
            'walmart_category_id' => '1002',
            'level' => 1,
            'parent_id' => $parent->id,
            'walmart_parent_id' => '1000',
            'path' => 'Electronics > Laptops',
        ]);

        WalmartCategory::create([
            'name' => 'Clothing',
            'walmart_category_id' => '2000',
            'level' => 0,
            'parent_id' => null,
            'path' => 'Clothing',
        ]);

        $response = $this->getJson('/api/taxonomy/walmart/categories?query=Laptop');

        $response->assertOk();
        $response->assertJsonCount(1);
        $response->assertJsonFragment([
            'name' => 'Laptops',
            'walmart_category_id' => '1002',
        ]);
    }

    public function test_browse_by_parent_id_returns_children(): void
    {
        $parent = WalmartCategory::create([
            'name' => 'Electronics',
            'walmart_category_id' => '1000',
            'level' => 0,
            'parent_id' => null,
            'path' => 'Electronics',
        ]);

        WalmartCategory::create([
            'name' => 'Laptops',
            'walmart_category_id' => '1002',
            'level' => 1,
            'parent_id' => $parent->id,
            'walmart_parent_id' => '1000',
            'path' => 'Electronics > Laptops',
        ]);

        WalmartCategory::create([
            'name' => 'Tablets',
            'walmart_category_id' => '1003',
            'level' => 1,
            'parent_id' => $parent->id,
            'walmart_parent_id' => '1000',
            'path' => 'Electronics > Tablets',
        ]);

        $response = $this->getJson("/api/taxonomy/walmart/categories?parent_id={$parent->id}");

        $response->assertOk();
        $response->assertJsonCount(2);
        $response->assertJsonFragment(['name' => 'Laptops']);
        $response->assertJsonFragment(['name' => 'Tablets']);
    }

    public function test_category_mapping_saves_walmart_category(): void
    {
        $category = Category::factory()->create(['store_id' => $this->store->id]);
        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'category_id' => $category->id,
        ]);

        ProductVariant::factory()->create(['product_id' => $product->id]);

        $response = $this->postJson(
            "/categories/{$category->id}/platform-mappings/{$this->marketplace->id}",
            [
                'primary_category_id' => '5000',
                'primary_category_name' => 'Electronics > TV & Video',
                'secondary_category_id' => null,
                'secondary_category_name' => null,
            ]
        );

        $response->assertSuccessful();

        $this->assertDatabaseHas('category_platform_mappings', [
            'category_id' => $category->id,
            'store_marketplace_id' => $this->marketplace->id,
            'primary_category_id' => '5000',
            'primary_category_name' => 'Electronics > TV & Video',
        ]);
    }
}
