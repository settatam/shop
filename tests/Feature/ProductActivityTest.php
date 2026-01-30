<?php

namespace Tests\Feature;

use App\Models\Memo;
use App\Models\MemoItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Repair;
use App\Models\RepairItem;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;
use App\Models\Vendor;
use App\Services\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductActivityTest extends TestCase
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

    public function test_product_has_order_items_relationship(): void
    {
        $vendor = Vendor::factory()->create(['store_id' => $this->store->id]);
        $product = Product::factory()->create(['store_id' => $this->store->id, 'vendor_id' => $vendor->id]);
        ProductVariant::factory()->create(['product_id' => $product->id]);

        $order = Order::factory()->create(['store_id' => $this->store->id]);
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
        ]);

        $this->assertCount(1, $product->orderItems);
        $this->assertEquals($order->id, $product->orderItems->first()->order_id);
    }

    public function test_product_has_memo_items_relationship(): void
    {
        $vendor = Vendor::factory()->create(['store_id' => $this->store->id]);
        $product = Product::factory()->create(['store_id' => $this->store->id, 'vendor_id' => $vendor->id]);
        ProductVariant::factory()->create(['product_id' => $product->id]);

        $memo = Memo::factory()->create(['store_id' => $this->store->id]);
        MemoItem::factory()->create([
            'memo_id' => $memo->id,
            'product_id' => $product->id,
        ]);

        $this->assertCount(1, $product->memoItems);
        $this->assertEquals($memo->id, $product->memoItems->first()->memo_id);
    }

    public function test_product_has_repair_items_relationship(): void
    {
        $vendor = Vendor::factory()->create(['store_id' => $this->store->id]);
        $product = Product::factory()->create(['store_id' => $this->store->id, 'vendor_id' => $vendor->id]);
        ProductVariant::factory()->create(['product_id' => $product->id]);

        $repair = Repair::factory()->create(['store_id' => $this->store->id]);
        RepairItem::factory()->create([
            'repair_id' => $repair->id,
            'product_id' => $product->id,
        ]);

        $this->assertCount(1, $product->repairItems);
        $this->assertEquals($repair->id, $product->repairItems->first()->repair_id);
    }

    public function test_product_edit_page_includes_activity_data(): void
    {
        $this->actingAs($this->user);

        $vendor = Vendor::factory()->create(['store_id' => $this->store->id]);
        $product = Product::factory()->create(['store_id' => $this->store->id, 'vendor_id' => $vendor->id]);
        ProductVariant::factory()->create(['product_id' => $product->id]);

        // Create an order with this product
        $order = Order::factory()->create(['store_id' => $this->store->id]);
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
        ]);

        // Create a memo with this product
        $memo = Memo::factory()->create(['store_id' => $this->store->id]);
        MemoItem::factory()->create([
            'memo_id' => $memo->id,
            'product_id' => $product->id,
        ]);

        $response = $this->withSession(['current_store_id' => $this->store->id])
            ->get("/products/{$product->id}/edit");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('products/Edit')
            ->has('activity')
            ->has('activity.orders')
            ->has('activity.memos')
            ->has('activity.repairs')
        );
    }

    public function test_product_activity_shows_correct_order_count(): void
    {
        $this->actingAs($this->user);

        $vendor = Vendor::factory()->create(['store_id' => $this->store->id]);
        $product = Product::factory()->create(['store_id' => $this->store->id, 'vendor_id' => $vendor->id]);
        ProductVariant::factory()->create(['product_id' => $product->id]);

        // Create two orders with this product
        $order1 = Order::factory()->create(['store_id' => $this->store->id]);
        $order2 = Order::factory()->create(['store_id' => $this->store->id]);

        OrderItem::factory()->create([
            'order_id' => $order1->id,
            'product_id' => $product->id,
        ]);
        OrderItem::factory()->create([
            'order_id' => $order2->id,
            'product_id' => $product->id,
        ]);

        $response = $this->withSession(['current_store_id' => $this->store->id])
            ->get("/products/{$product->id}/edit");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('products/Edit')
            ->where('activity.orders', fn ($orders) => count($orders) === 2)
        );
    }
}
