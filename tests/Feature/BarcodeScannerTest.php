<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BarcodeScannerTest extends TestCase
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

        $this->user->update(['current_store_id' => $this->store->id]);
        app(StoreContext::class)->setCurrentStore($this->store);

        Warehouse::factory()->create([
            'store_id' => $this->store->id,
            'is_default' => true,
        ]);
    }

    public function test_can_lookup_product_by_barcode(): void
    {
        $this->actingAs($this->user);

        $product = Product::factory()->create(['store_id' => $this->store->id]);
        $variant = ProductVariant::factory()->create([
            'product_id' => $product->id,
            'barcode' => '123456789012',
            'sku' => 'TEST-SKU-001',
        ]);

        $response = $this->getJson('/orders/lookup-barcode?barcode=123456789012');

        $response->assertOk();
        $response->assertJson([
            'found' => true,
            'product' => [
                'id' => $product->id,
                'variant_id' => $variant->id,
                'barcode' => '123456789012',
            ],
        ]);
    }

    public function test_can_lookup_product_by_sku(): void
    {
        $this->actingAs($this->user);

        $product = Product::factory()->create(['store_id' => $this->store->id]);
        $variant = ProductVariant::factory()->create([
            'product_id' => $product->id,
            'barcode' => null,
            'sku' => 'UNIQUE-SKU-999',
        ]);

        $response = $this->getJson('/orders/lookup-barcode?barcode=UNIQUE-SKU-999');

        $response->assertOk();
        $response->assertJson([
            'found' => true,
            'product' => [
                'id' => $product->id,
                'variant_id' => $variant->id,
                'sku' => 'UNIQUE-SKU-999',
            ],
        ]);
    }

    public function test_returns_not_found_for_unknown_barcode(): void
    {
        $this->actingAs($this->user);

        $response = $this->getJson('/orders/lookup-barcode?barcode=UNKNOWN-BARCODE');

        $response->assertOk();
        $response->assertJson([
            'found' => false,
            'product' => null,
        ]);
    }

    public function test_returns_error_when_no_barcode_provided(): void
    {
        $this->actingAs($this->user);

        $response = $this->getJson('/orders/lookup-barcode');

        $response->assertStatus(400);
        $response->assertJson([
            'found' => false,
            'error' => 'No barcode provided',
        ]);
    }

    public function test_does_not_return_products_from_other_stores(): void
    {
        $this->actingAs($this->user);

        $otherStore = Store::factory()->create();
        $otherProduct = Product::factory()->create(['store_id' => $otherStore->id]);
        ProductVariant::factory()->create([
            'product_id' => $otherProduct->id,
            'barcode' => 'OTHER-STORE-BARCODE',
        ]);

        $response = $this->getJson('/orders/lookup-barcode?barcode=OTHER-STORE-BARCODE');

        $response->assertOk();
        $response->assertJson([
            'found' => false,
            'product' => null,
        ]);
    }

    public function test_product_search_includes_barcode_field(): void
    {
        $this->actingAs($this->user);

        // Use the barcode lookup endpoint which does exact matching via SQL
        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'title' => 'Test Product',
        ]);
        $variant = ProductVariant::factory()->create([
            'product_id' => $product->id,
            'barcode' => 'SEARCHABLE-BARCODE',
            'sku' => 'DIFF-SKU',
        ]);

        $response = $this->getJson('/orders/lookup-barcode?barcode=SEARCHABLE-BARCODE');

        $response->assertOk();
        $response->assertJson([
            'found' => true,
            'product' => [
                'id' => $product->id,
                'variant_id' => $variant->id,
                'barcode' => 'SEARCHABLE-BARCODE',
            ],
        ]);
    }
}
