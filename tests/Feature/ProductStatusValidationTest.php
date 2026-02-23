<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductStatusValidationTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Store $store;

    protected Warehouse $warehouse;

    protected Category $category;

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

        $this->user->update(['current_store_id' => $this->store->id]);
        app(StoreContext::class)->setCurrentStore($this->store);

        $this->warehouse = Warehouse::factory()->create([
            'store_id' => $this->store->id,
            'is_default' => true,
        ]);
        $this->category = Category::factory()->create(['store_id' => $this->store->id]);
    }

    public function test_cannot_publish_product_with_zero_quantity_and_sell_out_of_stock_disabled(): void
    {
        $this->actingAs($this->user);

        $response = $this->withSession(['current_store_id' => $this->store->id])
            ->post('/products', [
                'title' => 'Test Product',
                'is_published' => true,
                'sell_out_of_stock' => false,
                'track_quantity' => true,
                'has_variants' => false,
                'charge_taxes' => true,
                'variants' => [
                    [
                        'sku' => 'TEST-001',
                        'price' => 99.99,
                        'quantity' => 0, // Zero quantity
                        'warehouse_id' => $this->warehouse->id,
                    ],
                ],
            ]);

        $response->assertSessionHasErrors('is_published');
    }

    public function test_can_publish_product_with_zero_quantity_when_sell_out_of_stock_enabled(): void
    {
        $this->actingAs($this->user);

        $response = $this->withSession(['current_store_id' => $this->store->id])
            ->post('/products', [
                'title' => 'Test Product',
                'is_published' => true,
                'sell_out_of_stock' => true, // Enabled
                'track_quantity' => true,
                'has_variants' => false,
                'charge_taxes' => true,
                'variants' => [
                    [
                        'sku' => 'TEST-002',
                        'price' => 99.99,
                        'quantity' => 0, // Zero quantity but sell_out_of_stock is true
                        'warehouse_id' => $this->warehouse->id,
                    ],
                ],
            ]);

        $response->assertSessionDoesntHaveErrors('is_published');
        $this->assertDatabaseHas('products', [
            'title' => 'Test Product',
            'is_published' => true,
        ]);
    }

    public function test_can_publish_product_with_positive_quantity(): void
    {
        $this->actingAs($this->user);

        $response = $this->withSession(['current_store_id' => $this->store->id])
            ->post('/products', [
                'title' => 'Test Product With Stock',
                'is_published' => true,
                'sell_out_of_stock' => false,
                'track_quantity' => true,
                'has_variants' => false,
                'charge_taxes' => true,
                'variants' => [
                    [
                        'sku' => 'TEST-003',
                        'price' => 99.99,
                        'quantity' => 5, // Has quantity
                        'warehouse_id' => $this->warehouse->id,
                    ],
                ],
            ]);

        $response->assertSessionDoesntHaveErrors('is_published');
        $this->assertDatabaseHas('products', [
            'title' => 'Test Product With Stock',
            'is_published' => true,
        ]);
    }

    public function test_cannot_change_product_to_active_with_zero_quantity_and_sell_out_of_stock_disabled(): void
    {
        $this->actingAs($this->user);

        // Create a draft product first
        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'status' => Product::STATUS_DRAFT,
            'is_published' => false,
            'is_draft' => true,
            'sell_out_of_stock' => false,
        ]);

        $variant = $product->variants()->create([
            'sku' => 'TEST-EXISTING-001',
            'price' => 50.00,
            'quantity' => 0,
        ]);

        $response = $this->withSession(['current_store_id' => $this->store->id])
            ->put("/products/{$product->id}", [
                'title' => $product->title,
                'status' => 'active', // Trying to make active
                'sell_out_of_stock' => false,
                'track_quantity' => true,
                'has_variants' => false,
                'charge_taxes' => true,
                'variants' => [
                    [
                        'id' => $variant->id,
                        'sku' => 'TEST-EXISTING-001',
                        'price' => 50.00,
                        'quantity' => 0, // Still zero
                        'warehouse_id' => $this->warehouse->id,
                    ],
                ],
            ]);

        $response->assertSessionHasErrors('status');
    }

    public function test_can_change_product_to_active_with_zero_quantity_when_sell_out_of_stock_enabled(): void
    {
        $this->actingAs($this->user);

        // Create a draft product first
        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'status' => Product::STATUS_DRAFT,
            'is_published' => false,
            'is_draft' => true,
            'sell_out_of_stock' => false,
        ]);

        $variant = $product->variants()->create([
            'sku' => 'TEST-EXISTING-002',
            'price' => 50.00,
            'quantity' => 0,
        ]);

        $response = $this->withSession(['current_store_id' => $this->store->id])
            ->put("/products/{$product->id}", [
                'title' => $product->title,
                'status' => 'active',
                'sell_out_of_stock' => true, // Enabling sell out of stock
                'track_quantity' => true,
                'has_variants' => false,
                'charge_taxes' => true,
                'variants' => [
                    [
                        'id' => $variant->id,
                        'sku' => 'TEST-EXISTING-002',
                        'price' => 50.00,
                        'quantity' => 0,
                        'warehouse_id' => $this->warehouse->id,
                    ],
                ],
            ]);

        $response->assertSessionDoesntHaveErrors('status');
        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'status' => Product::STATUS_ACTIVE,
        ]);
    }

    public function test_can_save_draft_product_with_zero_quantity(): void
    {
        $this->actingAs($this->user);

        $response = $this->withSession(['current_store_id' => $this->store->id])
            ->post('/products', [
                'title' => 'Draft Product',
                'is_published' => false, // Draft
                'sell_out_of_stock' => false,
                'track_quantity' => true,
                'has_variants' => false,
                'charge_taxes' => true,
                'variants' => [
                    [
                        'sku' => 'TEST-DRAFT-001',
                        'price' => 99.99,
                        'quantity' => 0,
                        'warehouse_id' => $this->warehouse->id,
                    ],
                ],
            ]);

        $response->assertSessionDoesntHaveErrors('is_published');
        $this->assertDatabaseHas('products', [
            'title' => 'Draft Product',
            'is_published' => false,
        ]);
    }
}
