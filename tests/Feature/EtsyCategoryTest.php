<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\EtsyCategory;
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

class EtsyCategoryTest extends TestCase
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

        $this->marketplace = StoreMarketplace::factory()->etsy()->create([
            'store_id' => $this->store->id,
        ]);

        app(StoreContext::class)->setCurrentStore($this->store);
        $this->actingAs($this->user);
    }

    public function test_taxonomy_endpoint_returns_root_categories(): void
    {
        $parent = EtsyCategory::create([
            'name' => 'Jewelry',
            'etsy_id' => 1000,
            'level' => 0,
            'parent_id' => null,
        ]);

        EtsyCategory::create([
            'name' => 'Necklaces',
            'etsy_id' => 1001,
            'level' => 1,
            'parent_id' => $parent->id,
            'etsy_parent_id' => 1000,
        ]);

        $response = $this->getJson('/api/taxonomy/etsy/categories');

        $response->assertOk();
        $response->assertJsonCount(1);
        $response->assertJsonFragment([
            'name' => 'Jewelry',
            'etsy_id' => 1000,
            'has_children' => true,
        ]);
    }

    public function test_search_by_name_returns_filtered_results(): void
    {
        EtsyCategory::create([
            'name' => 'Jewelry',
            'etsy_id' => 1000,
            'level' => 0,
            'parent_id' => null,
        ]);

        EtsyCategory::create([
            'name' => 'Clothing',
            'etsy_id' => 2000,
            'level' => 0,
            'parent_id' => null,
        ]);

        $response = $this->getJson('/api/taxonomy/etsy/categories?query=Jewelry');

        $response->assertOk();
        $response->assertJsonCount(1);
        $response->assertJsonFragment([
            'name' => 'Jewelry',
            'etsy_id' => 1000,
        ]);
    }

    public function test_browse_by_parent_id_returns_children(): void
    {
        $parent = EtsyCategory::create([
            'name' => 'Jewelry',
            'etsy_id' => 1000,
            'level' => 0,
            'parent_id' => null,
        ]);

        EtsyCategory::create([
            'name' => 'Necklaces',
            'etsy_id' => 1001,
            'level' => 1,
            'parent_id' => $parent->id,
            'etsy_parent_id' => 1000,
        ]);

        EtsyCategory::create([
            'name' => 'Rings',
            'etsy_id' => 1002,
            'level' => 1,
            'parent_id' => $parent->id,
            'etsy_parent_id' => 1000,
        ]);

        $response = $this->getJson("/api/taxonomy/etsy/categories?parent_id={$parent->id}");

        $response->assertOk();
        $response->assertJsonCount(2);
        $response->assertJsonFragment(['name' => 'Necklaces']);
        $response->assertJsonFragment(['name' => 'Rings']);
    }

    public function test_category_mapping_saves_etsy_category(): void
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
                'primary_category_id' => '1001',
                'primary_category_name' => 'Jewelry > Necklaces',
                'secondary_category_id' => null,
                'secondary_category_name' => null,
            ]
        );

        $response->assertSuccessful();

        $this->assertDatabaseHas('category_platform_mappings', [
            'category_id' => $category->id,
            'store_marketplace_id' => $this->marketplace->id,
            'primary_category_id' => '1001',
            'primary_category_name' => 'Jewelry > Necklaces',
        ]);
    }
}
