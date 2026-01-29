<?php

namespace Tests\Feature\Api;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Image;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

class ProductPreviewTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->store = Store::factory()->create(['user_id' => $this->user->id]);

        $role = Role::factory()->owner()->create(['store_id' => $this->store->id]);
        StoreUser::factory()->owner()->create([
            'user_id' => $this->user->id,
            'store_id' => $this->store->id,
            'role_id' => $role->id,
        ]);

        app(StoreContext::class)->setCurrentStore($this->store);
    }

    public function test_can_get_product_preview(): void
    {
        Passport::actingAs($this->user);

        $product = Product::factory()->published()->create([
            'store_id' => $this->store->id,
            'title' => 'Test Product',
        ]);

        ProductVariant::factory()->create([
            'product_id' => $product->id,
            'price' => 29.99,
        ]);

        $response = $this->getJson("/api/v1/products/{$product->id}/preview");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'id',
                'title',
                'image_url',
                'price',
                'status',
                'category_name',
                'brand_name',
                'total_quantity',
                'inventory_levels',
            ])
            ->assertJsonFragment([
                'id' => $product->id,
                'title' => 'Test Product',
                'status' => 'Published',
            ]);
    }

    public function test_preview_returns_correct_status_for_draft_product(): void
    {
        Passport::actingAs($this->user);

        $product = Product::factory()->draft()->create([
            'store_id' => $this->store->id,
        ]);

        ProductVariant::factory()->create(['product_id' => $product->id]);

        $response = $this->getJson("/api/v1/products/{$product->id}/preview");

        $response->assertStatus(200)
            ->assertJsonFragment([
                'status' => 'Draft',
            ]);
    }

    public function test_preview_includes_category_and_brand_names(): void
    {
        Passport::actingAs($this->user);

        $category = Category::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Electronics',
        ]);
        $brand = Brand::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Acme Corp',
        ]);

        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'category_id' => $category->id,
            'brand_id' => $brand->id,
        ]);

        ProductVariant::factory()->create(['product_id' => $product->id]);

        $response = $this->getJson("/api/v1/products/{$product->id}/preview");

        $response->assertStatus(200)
            ->assertJsonFragment([
                'category_name' => 'Electronics',
                'brand_name' => 'Acme Corp',
            ]);
    }

    public function test_preview_returns_null_for_missing_category_and_brand(): void
    {
        Passport::actingAs($this->user);

        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'category_id' => null,
            'brand_id' => null,
        ]);

        ProductVariant::factory()->create(['product_id' => $product->id]);

        $response = $this->getJson("/api/v1/products/{$product->id}/preview");

        $response->assertStatus(200)
            ->assertJson([
                'category_name' => null,
                'brand_name' => null,
            ]);
    }

    public function test_preview_returns_minimum_price_across_variants(): void
    {
        Passport::actingAs($this->user);

        $product = Product::factory()->withVariants()->create([
            'store_id' => $this->store->id,
        ]);

        ProductVariant::factory()->create(['product_id' => $product->id, 'price' => 50.00]);
        ProductVariant::factory()->create(['product_id' => $product->id, 'price' => 25.00]);
        ProductVariant::factory()->create(['product_id' => $product->id, 'price' => 75.00]);

        $response = $this->getJson("/api/v1/products/{$product->id}/preview");

        $response->assertStatus(200);

        // Price is returned as a string due to decimal casting
        $this->assertEquals('25.00', $response->json('price'));
    }

    public function test_preview_includes_primary_image_url(): void
    {
        Passport::actingAs($this->user);

        $product = Product::factory()->create(['store_id' => $this->store->id]);
        ProductVariant::factory()->create(['product_id' => $product->id]);

        // Create image using the new polymorphic Image model
        Image::create([
            'store_id' => $this->store->id,
            'imageable_type' => Product::class,
            'imageable_id' => $product->id,
            'path' => 'test-store/products/image.jpg',
            'url' => 'https://example.com/image.jpg',
            'alt_text' => 'Test image',
            'is_primary' => true,
            'sort_order' => 0,
        ]);

        $response = $this->getJson("/api/v1/products/{$product->id}/preview");

        $response->assertStatus(200)
            ->assertJsonFragment([
                'image_url' => 'https://example.com/image.jpg',
            ]);
    }

    public function test_preview_returns_null_image_when_no_images(): void
    {
        Passport::actingAs($this->user);

        $product = Product::factory()->create(['store_id' => $this->store->id]);
        ProductVariant::factory()->create(['product_id' => $product->id]);

        $response = $this->getJson("/api/v1/products/{$product->id}/preview");

        $response->assertStatus(200)
            ->assertJson([
                'image_url' => null,
            ]);
    }

    public function test_preview_includes_inventory_levels_by_warehouse(): void
    {
        Passport::actingAs($this->user);

        $warehouse1 = Warehouse::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Main Warehouse',
            'code' => 'MW-001',
        ]);
        $warehouse2 = Warehouse::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Secondary Warehouse',
            'code' => 'SW-001',
        ]);

        $product = Product::factory()->create(['store_id' => $this->store->id]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);

        Inventory::factory()->create([
            'store_id' => $this->store->id,
            'warehouse_id' => $warehouse1->id,
            'product_variant_id' => $variant->id,
            'quantity' => 50,
            'reserved_quantity' => 5,
        ]);

        Inventory::factory()->create([
            'store_id' => $this->store->id,
            'warehouse_id' => $warehouse2->id,
            'product_variant_id' => $variant->id,
            'quantity' => 30,
            'reserved_quantity' => 0,
        ]);

        $response = $this->getJson("/api/v1/products/{$product->id}/preview");

        $response->assertStatus(200)
            ->assertJsonCount(2, 'inventory_levels');

        $inventoryLevels = $response->json('inventory_levels');

        $mainWarehouse = collect($inventoryLevels)->firstWhere('warehouse_name', 'Main Warehouse');
        $this->assertNotNull($mainWarehouse);
        $this->assertEquals(50, $mainWarehouse['quantity']);
        $this->assertEquals(45, $mainWarehouse['available_quantity']);

        $secondaryWarehouse = collect($inventoryLevels)->firstWhere('warehouse_name', 'Secondary Warehouse');
        $this->assertNotNull($secondaryWarehouse);
        $this->assertEquals(30, $secondaryWarehouse['quantity']);
        $this->assertEquals(30, $secondaryWarehouse['available_quantity']);
    }

    public function test_preview_aggregates_inventory_across_variants(): void
    {
        Passport::actingAs($this->user);

        $warehouse = Warehouse::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Main Warehouse',
        ]);

        $product = Product::factory()->withVariants()->create(['store_id' => $this->store->id]);
        $variant1 = ProductVariant::factory()->create(['product_id' => $product->id]);
        $variant2 = ProductVariant::factory()->create(['product_id' => $product->id]);

        Inventory::factory()->create([
            'store_id' => $this->store->id,
            'warehouse_id' => $warehouse->id,
            'product_variant_id' => $variant1->id,
            'quantity' => 20,
            'reserved_quantity' => 5,
        ]);

        Inventory::factory()->create([
            'store_id' => $this->store->id,
            'warehouse_id' => $warehouse->id,
            'product_variant_id' => $variant2->id,
            'quantity' => 30,
            'reserved_quantity' => 10,
        ]);

        $response = $this->getJson("/api/v1/products/{$product->id}/preview");

        $response->assertStatus(200)
            ->assertJsonCount(1, 'inventory_levels');

        $inventoryLevel = $response->json('inventory_levels.0');
        $this->assertEquals('Main Warehouse', $inventoryLevel['warehouse_name']);
        $this->assertEquals(50, $inventoryLevel['quantity']);
        $this->assertEquals(35, $inventoryLevel['available_quantity']);
    }

    public function test_preview_returns_empty_inventory_levels_when_no_inventory(): void
    {
        Passport::actingAs($this->user);

        $product = Product::factory()->create(['store_id' => $this->store->id]);
        ProductVariant::factory()->create(['product_id' => $product->id]);

        $response = $this->getJson("/api/v1/products/{$product->id}/preview");

        $response->assertStatus(200)
            ->assertJson([
                'inventory_levels' => [],
            ]);
    }

    public function test_preview_returns_total_quantity(): void
    {
        Passport::actingAs($this->user);

        // Create product with has_variants=false so total_quantity comes from product.quantity
        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'has_variants' => false,
            'quantity' => 100,
        ]);

        ProductVariant::factory()->create(['product_id' => $product->id]);

        $response = $this->getJson("/api/v1/products/{$product->id}/preview");

        $response->assertStatus(200)
            ->assertJsonFragment([
                'total_quantity' => 100,
            ]);
    }

    public function test_cannot_preview_product_from_different_store(): void
    {
        Passport::actingAs($this->user);

        $otherStore = Store::factory()->create();
        $otherProduct = Product::factory()->create(['store_id' => $otherStore->id]);
        ProductVariant::factory()->create(['product_id' => $otherProduct->id]);

        $response = $this->getJson("/api/v1/products/{$otherProduct->id}/preview");

        $response->assertStatus(404);
    }

    public function test_preview_returns_404_for_nonexistent_product(): void
    {
        Passport::actingAs($this->user);

        $response = $this->getJson('/api/v1/products/99999/preview');

        $response->assertStatus(404);
    }

    public function test_unauthenticated_user_cannot_access_preview(): void
    {
        $product = Product::factory()->create(['store_id' => $this->store->id]);
        ProductVariant::factory()->create(['product_id' => $product->id]);

        $response = $this->getJson("/api/v1/products/{$product->id}/preview");

        $response->assertStatus(401);
    }
}
