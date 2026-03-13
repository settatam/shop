<?php

namespace Tests\Feature;

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
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PosControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Store $store;

    protected StoreUser $storeUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->store = Store::factory()->create([
            'user_id' => $this->user->id,
            'step' => 2,
        ]);

        $role = Role::factory()->owner()->create(['store_id' => $this->store->id]);
        $this->storeUser = StoreUser::factory()->owner()->create([
            'user_id' => $this->user->id,
            'store_id' => $this->store->id,
            'role_id' => $role->id,
        ]);

        $this->user->update(['current_store_id' => $this->store->id]);
        app(StoreContext::class)->setCurrentStore($this->store);
    }

    public function test_pos_page_loads_with_pos_mode(): void
    {
        $response = $this->actingAs($this->user)->get('/pos');

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('orders/CreateWizard')
            ->where('posMode', true)
            ->has('storeUsers')
            ->has('categories')
            ->has('warehouses')
            ->has('defaultTaxRate')
            ->has('preciousMetals')
            ->has('itemConditions')
        );
    }

    public function test_pos_requires_authentication(): void
    {
        $response = $this->get('/pos');

        $response->assertRedirect('/login');
    }

    public function test_pos_store_creates_order_and_redirects(): void
    {
        $warehouse = Warehouse::factory()->create(['store_id' => $this->store->id]);
        $product = Product::factory()->create(['store_id' => $this->store->id]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);
        Inventory::factory()->create([
            'store_id' => $this->store->id,
            'warehouse_id' => $warehouse->id,
            'product_variant_id' => $variant->id,
            'quantity' => 10,
        ]);

        $response = $this->actingAs($this->user)->post('/pos', [
            'store_user_id' => $this->storeUser->id,
            'warehouse_id' => $warehouse->id,
            'tax_rate' => 0,
            'items' => [
                [
                    'product_id' => $product->id,
                    'variant_id' => $variant->id,
                    'quantity' => 1,
                    'price' => 99.99,
                ],
            ],
        ]);

        $response->assertRedirect(route('web.pos.show'));
        $response->assertSessionHas('pos_completed_order');

        $completedOrder = session('pos_completed_order');
        $this->assertEquals(99.99, $completedOrder['total']);
        $this->assertArrayHasKey('id', $completedOrder);
        $this->assertEquals('Walk-in Customer', $completedOrder['customer_name']);
    }

    public function test_pos_store_validates_input(): void
    {
        $response = $this->actingAs($this->user)->post('/pos', [
            'store_user_id' => null,
            'items' => [],
        ]);

        $response->assertSessionHasErrors(['store_user_id']);
    }
}
