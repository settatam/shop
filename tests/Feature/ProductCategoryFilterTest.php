<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;
use App\Services\StoreContext;
use App\Widget\Products\ProductsTable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductCategoryFilterTest extends TestCase
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
            'step' => 2,
        ]);

        $role = Role::factory()->owner()->create(['store_id' => $this->store->id]);
        StoreUser::factory()->owner()->create([
            'user_id' => $this->user->id,
            'store_id' => $this->store->id,
            'role_id' => $role->id,
        ]);

        app(StoreContext::class)->setCurrentStore($this->store);
    }

    public function test_products_page_includes_level2_categories(): void
    {
        $this->actingAs($this->user);

        // Create 3-level category hierarchy
        $level1 = Category::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Jewelry',
            'parent_id' => null,
        ]);

        $level2 = Category::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Rings',
            'parent_id' => $level1->id,
        ]);

        $level3 = Category::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Engagement Rings',
            'parent_id' => $level2->id,
        ]);

        $response = $this->get('/products');

        $response->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('products/Index')
                ->has('level2Categories')
                ->has('level3ByParent')
            );
    }

    public function test_category_filter_includes_child_categories(): void
    {
        // Create 3-level category hierarchy
        $level1 = Category::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Jewelry',
            'parent_id' => null,
        ]);

        $level2 = Category::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Rings',
            'parent_id' => $level1->id,
        ]);

        $level3a = Category::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Engagement Rings',
            'parent_id' => $level2->id,
        ]);

        $level3b = Category::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Wedding Bands',
            'parent_id' => $level2->id,
        ]);

        // Create products in each category
        $productInLevel2 = Product::factory()->create([
            'store_id' => $this->store->id,
            'category_id' => $level2->id,
            'title' => 'Ring Level 2',
        ]);

        $productInLevel3a = Product::factory()->create([
            'store_id' => $this->store->id,
            'category_id' => $level3a->id,
            'title' => 'Engagement Ring',
        ]);

        $productInLevel3b = Product::factory()->create([
            'store_id' => $this->store->id,
            'category_id' => $level3b->id,
            'title' => 'Wedding Band',
        ]);

        // Create a product in a different category
        $otherCategory = Category::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Other',
            'parent_id' => null,
        ]);

        $productInOther = Product::factory()->create([
            'store_id' => $this->store->id,
            'category_id' => $otherCategory->id,
            'title' => 'Other Product',
        ]);

        // Test filtering by Level 2 category (should include all children)
        $widget = new ProductsTable;
        $data = $widget->data([
            'store_id' => $this->store->id,
            'category_id' => $level2->id,
        ]);

        // Should find 3 products (1 in level2 + 2 in level3)
        $this->assertEquals(3, $data['total']);
    }

    public function test_category_filter_by_level3_only_returns_that_category(): void
    {
        // Create 3-level category hierarchy
        $level1 = Category::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Jewelry',
            'parent_id' => null,
        ]);

        $level2 = Category::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Rings',
            'parent_id' => $level1->id,
        ]);

        $level3a = Category::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Engagement Rings',
            'parent_id' => $level2->id,
        ]);

        $level3b = Category::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Wedding Bands',
            'parent_id' => $level2->id,
        ]);

        // Create products in each level3 category
        Product::factory()->create([
            'store_id' => $this->store->id,
            'category_id' => $level3a->id,
            'title' => 'Engagement Ring',
        ]);

        Product::factory()->create([
            'store_id' => $this->store->id,
            'category_id' => $level3b->id,
            'title' => 'Wedding Band',
        ]);

        // Test filtering by Level 3 category (should only include that category)
        $widget = new ProductsTable;
        $data = $widget->data([
            'store_id' => $this->store->id,
            'category_id' => $level3a->id,
        ]);

        // Should find only 1 product (the one in level3a)
        $this->assertEquals(1, $data['total']);
    }

    public function test_level3_categories_are_grouped_by_parent(): void
    {
        $this->actingAs($this->user);

        // Create 3-level category hierarchy
        $level1 = Category::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Jewelry',
            'parent_id' => null,
        ]);

        $level2a = Category::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Rings',
            'parent_id' => $level1->id,
        ]);

        $level2b = Category::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Necklaces',
            'parent_id' => $level1->id,
        ]);

        $level3a1 = Category::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Engagement Rings',
            'parent_id' => $level2a->id,
        ]);

        $level3a2 = Category::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Wedding Bands',
            'parent_id' => $level2a->id,
        ]);

        $level3b1 = Category::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Pendants',
            'parent_id' => $level2b->id,
        ]);

        $response = $this->get('/products');

        $response->assertStatus(200)
            ->assertInertia(function ($page) use ($level2a, $level2b) {
                $page->component('products/Index');

                // Check level2Categories contains both level 2 categories
                $level2Cats = $page->toArray()['props']['level2Categories'];
                $level2Ids = collect($level2Cats)->pluck('id')->toArray();
                $this->assertContains($level2a->id, $level2Ids);
                $this->assertContains($level2b->id, $level2Ids);

                // Check level3ByParent has correct grouping
                $level3ByParent = $page->toArray()['props']['level3ByParent'];
                $this->assertArrayHasKey($level2a->id, $level3ByParent);
                $this->assertArrayHasKey($level2b->id, $level3ByParent);
                $this->assertCount(2, $level3ByParent[$level2a->id]); // Rings has 2 children
                $this->assertCount(1, $level3ByParent[$level2b->id]); // Necklaces has 1 child
            });
    }
}
