<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Repair;
use App\Models\RepairItem;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;
use App\Services\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RepairProductSearchTest extends TestCase
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
    }

    public function test_search_products_returns_matching_products(): void
    {
        $this->actingAs($this->user);

        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'title' => 'Gold Ring 14k',
            'quantity' => 1,
            'status' => Product::STATUS_ACTIVE,
        ]);
        ProductVariant::factory()->create([
            'product_id' => $product->id,
            'sku' => 'GR-14K-001',
        ]);

        $response = $this->getJson('/repairs/search-products?query=Gold Ring');

        $response->assertOk()
            ->assertJsonCount(1, 'products')
            ->assertJsonPath('products.0.title', 'Gold Ring 14k')
            ->assertJsonPath('products.0.sku', 'GR-14K-001');
    }

    public function test_search_products_only_returns_in_stock_active_products(): void
    {
        $this->actingAs($this->user);

        // Active, in stock — should appear
        Product::factory()->create([
            'store_id' => $this->store->id,
            'title' => 'Available Ring',
            'quantity' => 5,
            'status' => Product::STATUS_ACTIVE,
        ]);

        // Active, out of stock — should NOT appear
        Product::factory()->create([
            'store_id' => $this->store->id,
            'title' => 'Out of Stock Ring',
            'quantity' => 0,
            'status' => Product::STATUS_ACTIVE,
        ]);

        // Draft, in stock — should NOT appear
        Product::factory()->create([
            'store_id' => $this->store->id,
            'title' => 'Draft Ring',
            'quantity' => 3,
            'status' => Product::STATUS_DRAFT,
        ]);

        // In repair, in stock — should NOT appear
        Product::factory()->create([
            'store_id' => $this->store->id,
            'title' => 'Repair Ring',
            'quantity' => 1,
            'status' => Product::STATUS_IN_REPAIR,
        ]);

        $response = $this->getJson('/repairs/search-products?query=Ring');

        $response->assertOk()
            ->assertJsonCount(1, 'products')
            ->assertJsonPath('products.0.title', 'Available Ring');
    }

    public function test_repair_creation_with_product_id_marks_product_in_repair(): void
    {
        $this->actingAs($this->user);

        $storeUser = StoreUser::where('store_id', $this->store->id)->first();
        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'title' => 'Test Bracelet',
            'quantity' => 1,
            'status' => Product::STATUS_ACTIVE,
        ]);
        ProductVariant::factory()->create([
            'product_id' => $product->id,
            'sku' => 'TB-001',
        ]);

        $response = $this->post('/repairs', [
            'store_user_id' => $storeUser->id,
            'customer' => [
                'first_name' => 'John',
                'last_name' => 'Doe',
            ],
            'items' => [
                [
                    'product_id' => $product->id,
                    'title' => $product->title,
                    'vendor_cost' => 50,
                    'customer_cost' => 100,
                ],
            ],
        ]);

        $response->assertRedirect();

        $product->refresh();
        $this->assertEquals(Product::STATUS_IN_REPAIR, $product->status);

        // Verify the repair item was created with product_id and sku
        $repairItem = RepairItem::where('product_id', $product->id)->first();
        $this->assertNotNull($repairItem);
        $this->assertEquals('TB-001', $repairItem->sku);
    }

    public function test_repair_creation_without_product_id_works(): void
    {
        $this->actingAs($this->user);

        $storeUser = StoreUser::where('store_id', $this->store->id)->first();

        $response = $this->post('/repairs', [
            'store_user_id' => $storeUser->id,
            'customer' => [
                'first_name' => 'Jane',
                'last_name' => 'Doe',
            ],
            'items' => [
                [
                    'title' => 'Custom Ring Resize',
                    'description' => 'Resize from 7 to 8',
                    'vendor_cost' => 30,
                    'customer_cost' => 75,
                ],
            ],
        ]);

        $response->assertRedirect();

        $repairItem = RepairItem::where('title', 'Custom Ring Resize')->first();
        $this->assertNotNull($repairItem);
        $this->assertNull($repairItem->product_id);
    }

    public function test_can_add_item_to_existing_repair(): void
    {
        $this->actingAs($this->user);

        $repair = Repair::factory()->pending()->create([
            'store_id' => $this->store->id,
            'customer_id' => Customer::factory()->create(['store_id' => $this->store->id])->id,
        ]);

        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'title' => 'Pendant for Repair',
            'quantity' => 1,
            'status' => Product::STATUS_ACTIVE,
        ]);
        ProductVariant::factory()->create([
            'product_id' => $product->id,
            'sku' => 'PND-001',
        ]);

        $response = $this->post("/repairs/{$repair->id}/add-item", [
            'product_id' => $product->id,
            'title' => $product->title,
            'vendor_cost' => 25,
            'customer_cost' => 60,
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('repair_items', [
            'repair_id' => $repair->id,
            'product_id' => $product->id,
            'title' => 'Pendant for Repair',
        ]);

        $product->refresh();
        $this->assertEquals(Product::STATUS_IN_REPAIR, $product->status);
    }

    public function test_can_add_custom_item_to_existing_repair(): void
    {
        $this->actingAs($this->user);

        $repair = Repair::factory()->pending()->create([
            'store_id' => $this->store->id,
            'customer_id' => Customer::factory()->create(['store_id' => $this->store->id])->id,
        ]);

        $response = $this->post("/repairs/{$repair->id}/add-item", [
            'title' => 'Chain Solder',
            'description' => 'Fix broken chain link',
            'vendor_cost' => 15,
            'customer_cost' => 40,
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('repair_items', [
            'repair_id' => $repair->id,
            'title' => 'Chain Solder',
            'description' => 'Fix broken chain link',
        ]);
    }

    public function test_can_inline_update_item_costs(): void
    {
        $this->actingAs($this->user);

        $repair = Repair::factory()->pending()->create([
            'store_id' => $this->store->id,
            'customer_id' => Customer::factory()->create(['store_id' => $this->store->id])->id,
        ]);

        $item = RepairItem::factory()->create([
            'repair_id' => $repair->id,
            'vendor_cost' => 50,
            'customer_cost' => 100,
        ]);

        $response = $this->patch("/repairs/{$repair->id}/items/{$item->id}", [
            'vendor_cost' => 75,
            'customer_cost' => 150,
        ]);

        $response->assertRedirect();

        $item->refresh();
        $this->assertEquals(75, $item->vendor_cost);
        $this->assertEquals(150, $item->customer_cost);
    }
}
