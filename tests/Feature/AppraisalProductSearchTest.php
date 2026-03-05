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

class AppraisalProductSearchTest extends TestCase
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
            'title' => 'Diamond Necklace',
            'quantity' => 1,
            'status' => Product::STATUS_ACTIVE,
        ]);
        ProductVariant::factory()->create([
            'product_id' => $product->id,
            'sku' => 'DN-001',
        ]);

        $response = $this->getJson('/appraisals/search-products?query=Diamond');

        $response->assertOk()
            ->assertJsonCount(1, 'products')
            ->assertJsonPath('products.0.title', 'Diamond Necklace')
            ->assertJsonPath('products.0.sku', 'DN-001');
    }

    public function test_appraisal_creation_with_product_id_marks_product_in_repair(): void
    {
        $this->actingAs($this->user);

        $storeUser = StoreUser::where('store_id', $this->store->id)->first();
        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'title' => 'Emerald Ring',
            'quantity' => 1,
            'status' => Product::STATUS_ACTIVE,
        ]);
        ProductVariant::factory()->create([
            'product_id' => $product->id,
            'sku' => 'ER-001',
        ]);

        $response = $this->post('/appraisals', [
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

        $repairItem = RepairItem::where('product_id', $product->id)->first();
        $this->assertNotNull($repairItem);
        $this->assertEquals('ER-001', $repairItem->sku);
    }

    public function test_can_add_item_to_existing_appraisal(): void
    {
        $this->actingAs($this->user);

        $repair = Repair::factory()->pending()->create([
            'store_id' => $this->store->id,
            'is_appraisal' => true,
            'customer_id' => Customer::factory()->create(['store_id' => $this->store->id])->id,
        ]);

        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'title' => 'Ruby Bracelet',
            'quantity' => 1,
            'status' => Product::STATUS_ACTIVE,
        ]);
        ProductVariant::factory()->create([
            'product_id' => $product->id,
            'sku' => 'RB-001',
        ]);

        $response = $this->post("/appraisals/{$repair->id}/add-item", [
            'product_id' => $product->id,
            'title' => $product->title,
            'vendor_cost' => 25,
            'customer_cost' => 60,
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('repair_items', [
            'repair_id' => $repair->id,
            'product_id' => $product->id,
            'title' => 'Ruby Bracelet',
        ]);

        $product->refresh();
        $this->assertEquals(Product::STATUS_IN_REPAIR, $product->status);
    }

    public function test_can_add_custom_item_to_existing_appraisal(): void
    {
        $this->actingAs($this->user);

        $repair = Repair::factory()->pending()->create([
            'store_id' => $this->store->id,
            'is_appraisal' => true,
            'customer_id' => Customer::factory()->create(['store_id' => $this->store->id])->id,
        ]);

        $response = $this->post("/appraisals/{$repair->id}/add-item", [
            'title' => 'Antique Brooch Appraisal',
            'description' => 'Evaluate vintage piece',
            'vendor_cost' => 15,
            'customer_cost' => 40,
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('repair_items', [
            'repair_id' => $repair->id,
            'title' => 'Antique Brooch Appraisal',
            'description' => 'Evaluate vintage piece',
        ]);
    }
}
