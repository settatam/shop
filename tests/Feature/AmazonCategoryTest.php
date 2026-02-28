<?php

namespace Tests\Feature;

use App\Models\AmazonCategory;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreMarketplace;
use App\Models\StoreUser;
use App\Models\User;
use App\Services\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AmazonCategoryTest extends TestCase
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

        $this->marketplace = StoreMarketplace::factory()->amazon()->create([
            'store_id' => $this->store->id,
        ]);

        app(StoreContext::class)->setCurrentStore($this->store);
        $this->actingAs($this->user);
    }

    public function test_taxonomy_endpoint_returns_root_categories(): void
    {
        $parent = AmazonCategory::create([
            'name' => 'Electronics',
            'amazon_category_id' => 'ELECTRONICS',
            'level' => 0,
            'parent_id' => null,
            'path' => 'Electronics',
        ]);

        AmazonCategory::create([
            'name' => 'Computers',
            'amazon_category_id' => 'COMPUTERS',
            'level' => 1,
            'parent_id' => $parent->id,
            'amazon_parent_id' => 'ELECTRONICS',
            'path' => 'Electronics > Computers',
        ]);

        $response = $this->getJson('/api/taxonomy/amazon/categories');

        $response->assertOk();
        $response->assertJsonCount(1);
        $response->assertJsonFragment([
            'name' => 'Electronics',
            'amazon_category_id' => 'ELECTRONICS',
            'has_children' => true,
        ]);
    }

    public function test_search_by_name_returns_filtered_results(): void
    {
        $parent = AmazonCategory::create([
            'name' => 'Electronics',
            'amazon_category_id' => 'ELECTRONICS',
            'level' => 0,
            'parent_id' => null,
            'path' => 'Electronics',
        ]);

        AmazonCategory::create([
            'name' => 'Laptops',
            'amazon_category_id' => 'LAPTOPS',
            'level' => 1,
            'parent_id' => $parent->id,
            'amazon_parent_id' => 'ELECTRONICS',
            'path' => 'Electronics > Laptops',
        ]);

        AmazonCategory::create([
            'name' => 'Clothing',
            'amazon_category_id' => 'CLOTHING',
            'level' => 0,
            'parent_id' => null,
            'path' => 'Clothing',
        ]);

        $response = $this->getJson('/api/taxonomy/amazon/categories?query=Laptop');

        $response->assertOk();
        $response->assertJsonCount(1);
        $response->assertJsonFragment([
            'name' => 'Laptops',
            'amazon_category_id' => 'LAPTOPS',
        ]);
    }

    public function test_browse_by_parent_id_returns_children(): void
    {
        $parent = AmazonCategory::create([
            'name' => 'Electronics',
            'amazon_category_id' => 'ELECTRONICS',
            'level' => 0,
            'parent_id' => null,
            'path' => 'Electronics',
        ]);

        AmazonCategory::create([
            'name' => 'Laptops',
            'amazon_category_id' => 'LAPTOPS',
            'level' => 1,
            'parent_id' => $parent->id,
            'amazon_parent_id' => 'ELECTRONICS',
            'path' => 'Electronics > Laptops',
        ]);

        AmazonCategory::create([
            'name' => 'Tablets',
            'amazon_category_id' => 'TABLETS',
            'level' => 1,
            'parent_id' => $parent->id,
            'amazon_parent_id' => 'ELECTRONICS',
            'path' => 'Electronics > Tablets',
        ]);

        $response = $this->getJson("/api/taxonomy/amazon/categories?parent_id={$parent->id}");

        $response->assertOk();
        $response->assertJsonCount(2);
        $response->assertJsonFragment(['name' => 'Laptops']);
        $response->assertJsonFragment(['name' => 'Tablets']);
    }

    public function test_category_mapping_saves_amazon_category(): void
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
                'primary_category_id' => 'LAPTOP',
                'primary_category_name' => 'Electronics > Laptops',
                'secondary_category_id' => null,
                'secondary_category_name' => null,
            ]
        );

        $response->assertSuccessful();

        $this->assertDatabaseHas('category_platform_mappings', [
            'category_id' => $category->id,
            'store_marketplace_id' => $this->marketplace->id,
            'primary_category_id' => 'LAPTOP',
            'primary_category_name' => 'Electronics > Laptops',
        ]);
    }
}
