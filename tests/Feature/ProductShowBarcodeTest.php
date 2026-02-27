<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;
use App\Services\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductShowBarcodeTest extends TestCase
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

    public function test_show_returns_default_barcode_attributes_without_category(): void
    {
        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'price_code' => 'ABC123',
        ]);
        ProductVariant::factory()->create(['product_id' => $product->id]);

        $response = $this->actingAs($this->user)
            ->get("/products/{$product->id}");

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('products/Show')
            ->has('barcodeAttributes')
            ->where('barcodeAttributes', ['price_code', 'category', 'price'])
            ->has('templateFieldValues')
            ->where('product.price_code', 'ABC123')
        );
    }

    public function test_show_returns_category_barcode_attributes(): void
    {
        $category = Category::factory()->create([
            'store_id' => $this->store->id,
            'barcode_attributes' => ['sku', 'price', 'category'],
        ]);

        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'category_id' => $category->id,
        ]);
        ProductVariant::factory()->create(['product_id' => $product->id]);

        $response = $this->actingAs($this->user)
            ->get("/products/{$product->id}");

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('products/Show')
            ->where('barcodeAttributes', ['sku', 'price', 'category'])
        );
    }

    public function test_show_returns_template_field_values(): void
    {
        $product = Product::factory()->create([
            'store_id' => $this->store->id,
        ]);
        ProductVariant::factory()->create(['product_id' => $product->id]);

        $response = $this->actingAs($this->user)
            ->get("/products/{$product->id}");

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('products/Show')
            ->has('templateFieldValues')
        );
    }
}
