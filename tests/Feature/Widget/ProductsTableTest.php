<?php

namespace Tests\Feature\Widget;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;
use App\Services\StoreContext;
use App\Widget\Products\ProductsTable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductsTableTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->store = Store::factory()->onboarded()->create(['user_id' => $this->user->id]);

        $role = Role::factory()->owner()->create(['store_id' => $this->store->id]);
        StoreUser::factory()->owner()->create([
            'user_id' => $this->user->id,
            'store_id' => $this->store->id,
            'role_id' => $role->id,
        ]);

        app(StoreContext::class)->setCurrentStore($this->store);
    }

    public function test_products_table_filters_uncategorized_products(): void
    {
        $category = Category::factory()->create(['store_id' => $this->store->id]);

        // Create products with a category
        $categorizedProducts = Product::factory()->count(3)->create([
            'store_id' => $this->store->id,
            'category_id' => $category->id,
        ]);
        foreach ($categorizedProducts as $product) {
            ProductVariant::factory()->create([
                'product_id' => $product->id,
                'quantity' => 1,
            ]);
        }

        // Create products without a category (uncategorized)
        $uncategorizedProducts = Product::factory()->count(2)->create([
            'store_id' => $this->store->id,
            'category_id' => null,
        ]);
        foreach ($uncategorizedProducts as $product) {
            ProductVariant::factory()->create([
                'product_id' => $product->id,
                'quantity' => 1,
            ]);
        }

        $widget = new ProductsTable;

        // Test with uncategorized filter
        $result = $widget->render(['store_id' => $this->store->id, 'uncategorized' => '1']);
        $this->assertEquals(2, $result['pagination']['total']);

        // Verify all returned products have no category
        foreach ($result['data']['items'] as $item) {
            $this->assertEquals('-', $item['product_type']['data']);
        }
    }

    public function test_products_table_filters_by_category_with_descendants(): void
    {
        // Create parent category
        $parentCategory = Category::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Parent',
        ]);

        // Create child category
        $childCategory = Category::factory()->create([
            'store_id' => $this->store->id,
            'parent_id' => $parentCategory->id,
            'name' => 'Child',
        ]);

        // Create product in parent category
        $parentProduct = Product::factory()->create([
            'store_id' => $this->store->id,
            'category_id' => $parentCategory->id,
        ]);
        ProductVariant::factory()->create([
            'product_id' => $parentProduct->id,
            'quantity' => 1,
        ]);

        // Create product in child category
        $childProduct = Product::factory()->create([
            'store_id' => $this->store->id,
            'category_id' => $childCategory->id,
        ]);
        ProductVariant::factory()->create([
            'product_id' => $childProduct->id,
            'quantity' => 1,
        ]);

        // Create product in unrelated category
        $otherCategory = Category::factory()->create(['store_id' => $this->store->id]);
        $otherProduct = Product::factory()->create([
            'store_id' => $this->store->id,
            'category_id' => $otherCategory->id,
        ]);
        ProductVariant::factory()->create([
            'product_id' => $otherProduct->id,
            'quantity' => 1,
        ]);

        $widget = new ProductsTable;

        // Filter by parent should include parent and child products
        $result = $widget->render(['store_id' => $this->store->id, 'category_id' => $parentCategory->id]);
        $this->assertEquals(2, $result['pagination']['total']);
    }

    public function test_products_table_returns_products_for_store(): void
    {
        $otherStore = Store::factory()->create();

        // Create products in current store
        Product::factory()->count(3)->create([
            'store_id' => $this->store->id,
        ])->each(function ($product) {
            ProductVariant::factory()->create([
                'product_id' => $product->id,
                'quantity' => 1,
            ]);
        });

        // Create products in other store (should not appear)
        Product::factory()->count(5)->create([
            'store_id' => $otherStore->id,
        ])->each(function ($product) {
            ProductVariant::factory()->create([
                'product_id' => $product->id,
                'quantity' => 1,
            ]);
        });

        $widget = new ProductsTable;
        $result = $widget->render(['store_id' => $this->store->id]);

        $this->assertEquals(3, $result['pagination']['total']);
    }
}
