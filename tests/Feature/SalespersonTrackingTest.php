<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Inventory;
use App\Models\Memo;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Repair;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Orders\OrderCreationService;
use App\Services\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalespersonTrackingTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected User $salesperson;

    protected Store $store;

    protected StoreUser $ownerStoreUser;

    protected StoreUser $salespersonStoreUser;

    protected function setUp(): void
    {
        parent::setUp();

        // Create owner user and store
        $this->owner = User::factory()->create(['name' => 'Store Owner']);
        $this->store = Store::factory()->create(['user_id' => $this->owner->id, 'step' => 2]);

        Role::createDefaultRoles($this->store->id);
        $ownerRole = Role::where('store_id', $this->store->id)->where('slug', 'owner')->first();

        $this->ownerStoreUser = StoreUser::create([
            'user_id' => $this->owner->id,
            'store_id' => $this->store->id,
            'role_id' => $ownerRole->id,
            'is_owner' => true,
            'status' => 'active',
            'first_name' => 'Store',
            'last_name' => 'Owner',
            'email' => $this->owner->email,
        ]);

        // Create a separate salesperson user
        $this->salesperson = User::factory()->create(['name' => 'Sales Person']);
        $salesRole = Role::where('store_id', $this->store->id)->where('slug', 'staff')->first()
            ?? Role::factory()->create(['store_id' => $this->store->id, 'name' => 'Staff', 'slug' => 'staff']);

        $this->salespersonStoreUser = StoreUser::create([
            'user_id' => $this->salesperson->id,
            'store_id' => $this->store->id,
            'role_id' => $salesRole->id,
            'status' => 'active',
            'first_name' => 'Sales',
            'last_name' => 'Person',
            'email' => $this->salesperson->email,
        ]);

        $this->owner->update(['current_store_id' => $this->store->id]);
        app(StoreContext::class)->setCurrentStore($this->store);
    }

    protected function withStore()
    {
        return $this->withSession(['current_store_id' => $this->store->id]);
    }

    public function test_order_stores_salesperson_and_created_by(): void
    {
        $this->actingAs($this->owner);

        $warehouse = Warehouse::factory()->create(['store_id' => $this->store->id]);
        $product = Product::factory()->create(['store_id' => $this->store->id]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);

        Inventory::factory()->create([
            'store_id' => $this->store->id,
            'warehouse_id' => $warehouse->id,
            'product_variant_id' => $variant->id,
            'quantity' => 10,
        ]);

        $service = app(OrderCreationService::class);

        $order = $service->createFromWizard([
            'store_user_id' => $this->salespersonStoreUser->id,
            'warehouse_id' => $warehouse->id,
            'items' => [
                [
                    'product_id' => $product->id,
                    'variant_id' => $variant->id,
                    'quantity' => 1,
                    'price' => 100.00,
                ],
            ],
        ], $this->store);

        $this->assertEquals($this->salespersonStoreUser->id, $order->store_user_id);
        $this->assertEquals($this->salesperson->id, $order->user_id);
        $this->assertEquals($this->owner->id, $order->created_by);
    }

    public function test_transaction_stores_salesperson_and_created_by(): void
    {
        $this->actingAs($this->owner);

        $customer = Customer::factory()->create(['store_id' => $this->store->id]);
        $category = Category::factory()->create(['store_id' => $this->store->id]);

        $response = $this->withStore()->post('/transactions/buy', [
            'store_user_id' => $this->salespersonStoreUser->id,
            'customer_id' => $customer->id,
            'items' => [
                [
                    'title' => 'Gold Ring',
                    'category_id' => $category->id,
                    'precious_metal' => TransactionItem::METAL_GOLD_14K,
                    'dwt' => 2.5,
                    'condition' => TransactionItem::CONDITION_USED,
                    'price' => 500,
                    'buy_price' => 350,
                ],
            ],
            'payments' => [
                [
                    'method' => Transaction::PAYMENT_CASH,
                    'amount' => 350,
                ],
            ],
        ]);

        $response->assertRedirect();

        $transaction = Transaction::where('store_id', $this->store->id)->latest()->first();
        $this->assertNotNull($transaction);
        $this->assertEquals($this->salespersonStoreUser->id, $transaction->store_user_id);
        $this->assertEquals($this->salesperson->id, $transaction->user_id);
        $this->assertEquals($this->owner->id, $transaction->created_by);
    }

    public function test_memo_stores_salesperson_and_created_by(): void
    {
        $this->actingAs($this->owner);

        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'quantity' => 1,
        ]);

        $response = $this->withStore()->post('/memos', [
            'store_user_id' => $this->salespersonStoreUser->id,
            'vendor' => [
                'name' => 'Test Vendor',
                'email' => 'vendor@example.com',
            ],
            'items' => [
                [
                    'product_id' => $product->id,
                    'price' => 500.00,
                    'tenor' => 30,
                ],
            ],
            'tenure' => 30,
        ]);

        $response->assertRedirect();

        $memo = Memo::where('store_id', $this->store->id)->latest()->first();
        $this->assertNotNull($memo);
        $this->assertEquals($this->salespersonStoreUser->id, $memo->store_user_id);
        $this->assertEquals($this->salesperson->id, $memo->user_id);
        $this->assertEquals($this->owner->id, $memo->created_by);
    }

    public function test_repair_stores_salesperson_and_created_by(): void
    {
        $this->actingAs($this->owner);

        $customer = Customer::factory()->create(['store_id' => $this->store->id]);
        $category = Category::factory()->create(['store_id' => $this->store->id]);

        $response = $this->withStore()->post('/repairs', [
            'store_user_id' => $this->salespersonStoreUser->id,
            'customer_id' => $customer->id,
            'items' => [
                [
                    'title' => 'Watch Repair',
                    'category_id' => $category->id,
                    'vendor_cost' => 10.00,
                    'customer_cost' => 35.00,
                ],
            ],
            'service_fee' => 15.00,
            'tax_rate' => 0.08,
            'shipping_cost' => 0,
            'discount' => 0,
        ]);

        $response->assertRedirect();

        $repair = Repair::where('store_id', $this->store->id)->latest()->first();
        $this->assertNotNull($repair);
        $this->assertEquals($this->salespersonStoreUser->id, $repair->store_user_id);
        $this->assertEquals($this->salesperson->id, $repair->user_id);
        $this->assertEquals($this->owner->id, $repair->created_by);
    }

    public function test_order_show_page_includes_salesperson_data(): void
    {
        $this->actingAs($this->owner);

        $order = Order::factory()->create([
            'store_id' => $this->store->id,
            'user_id' => $this->salesperson->id,
            'store_user_id' => $this->salespersonStoreUser->id,
            'created_by' => $this->owner->id,
        ]);

        $response = $this->withStore()->get("/orders/{$order->id}");

        $response->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('orders/Show')
                ->where('order.store_user.id', $this->salespersonStoreUser->id)
                ->where('order.store_user.name', 'Sales Person')
                ->where('order.created_by_user.id', $this->owner->id)
                ->where('order.created_by_user.name', 'Store Owner')
            );
    }

    public function test_store_user_relationship_on_models(): void
    {
        $order = Order::factory()->create([
            'store_id' => $this->store->id,
            'store_user_id' => $this->salespersonStoreUser->id,
            'created_by' => $this->owner->id,
        ]);

        $this->assertNotNull($order->storeUser);
        $this->assertEquals($this->salespersonStoreUser->id, $order->storeUser->id);
        $this->assertEquals('Sales Person', $order->storeUser->full_name);

        $this->assertNotNull($order->createdByUser);
        $this->assertEquals($this->owner->id, $order->createdByUser->id);
    }
}
