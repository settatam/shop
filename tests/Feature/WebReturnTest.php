<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductReturn;
use App\Models\ProductVariant;
use App\Models\ReturnItem;
use App\Models\ReturnPolicy;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;
use App\Services\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WebReturnTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Store $store;

    protected Customer $customer;

    protected Order $order;

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

        $this->customer = Customer::factory()->create(['store_id' => $this->store->id]);
        $this->order = Order::factory()->confirmed()->create([
            'store_id' => $this->store->id,
            'customer_id' => $this->customer->id,
        ]);

        $variant = ProductVariant::factory()->create();
        OrderItem::factory()->create([
            'order_id' => $this->order->id,
            'product_variant_id' => $variant->id,
            'quantity' => 2,
            'price' => 50.00,
        ]);

        app(StoreContext::class)->setCurrentStore($this->store);
    }

    public function test_can_view_returns_index_page(): void
    {
        $this->actingAs($this->user);

        ProductReturn::factory()->count(3)->create(['store_id' => $this->store->id]);

        $response = $this->get('/returns');

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('returns/Index')
            ->has('statuses')
            ->has('types')
        );
    }

    public function test_can_view_return_create_page(): void
    {
        $this->actingAs($this->user);

        ReturnPolicy::factory()->create(['store_id' => $this->store->id]);

        $response = $this->get('/returns/create');

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('returns/Create')
            ->has('policies')
            ->has('types')
            ->has('conditions')
            ->has('reasons')
        );
    }

    public function test_can_view_return_show_page(): void
    {
        $this->actingAs($this->user);

        $return = ProductReturn::factory()->create([
            'store_id' => $this->store->id,
            'order_id' => $this->order->id,
        ]);
        ReturnItem::factory()->create(['return_id' => $return->id]);

        $response = $this->get("/returns/{$return->id}");

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('returns/Show')
            ->has('productReturn')
            ->has('statuses')
            ->has('refundMethods')
        );
    }

    public function test_can_search_orders_for_return(): void
    {
        $this->actingAs($this->user);

        $response = $this->getJson('/returns/search-orders?query='.$this->order->invoice_number);

        $response->assertStatus(200);
        $response->assertJsonStructure(['orders']);
    }

    public function test_can_create_return(): void
    {
        $this->actingAs($this->user);

        $orderItem = $this->order->items->first();

        $response = $this->post('/returns', [
            'order_id' => $this->order->id,
            'type' => 'return',
            'reason' => 'Defective product',
            'items' => [
                [
                    'order_item_id' => $orderItem->id,
                    'product_variant_id' => $orderItem->product_variant_id,
                    'quantity' => 1,
                    'unit_price' => $orderItem->price,
                    'condition' => 'used',
                    'reason' => 'Defective',
                    'restock' => true,
                ],
            ],
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('returns', [
            'order_id' => $this->order->id,
            'customer_id' => $this->customer->id,
            'status' => ProductReturn::STATUS_PENDING,
        ]);

        $this->assertDatabaseHas('return_items', [
            'order_item_id' => $orderItem->id,
            'quantity' => 1,
        ]);
    }

    public function test_can_approve_return(): void
    {
        $this->actingAs($this->user);

        $return = ProductReturn::factory()->pending()->create(['store_id' => $this->store->id]);

        $response = $this->post("/returns/{$return->id}/approve");

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $return->refresh();
        $this->assertTrue($return->isApproved());
        $this->assertNotNull($return->approved_at);
    }

    public function test_can_reject_return_with_reason(): void
    {
        $this->actingAs($this->user);

        $return = ProductReturn::factory()->pending()->create(['store_id' => $this->store->id]);

        $response = $this->post("/returns/{$return->id}/reject", [
            'reason' => 'Item not eligible for return',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $return->refresh();
        $this->assertTrue($return->isRejected());
    }

    public function test_cannot_reject_without_reason(): void
    {
        $this->actingAs($this->user);

        $return = ProductReturn::factory()->pending()->create(['store_id' => $this->store->id]);

        $response = $this->post("/returns/{$return->id}/reject", [
            'reason' => '',
        ]);

        $response->assertSessionHasErrors('reason');
    }

    public function test_can_process_approved_return(): void
    {
        $this->actingAs($this->user);

        $return = ProductReturn::factory()->approved()->create(['store_id' => $this->store->id]);

        $response = $this->post("/returns/{$return->id}/process");

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $return->refresh();
        $this->assertTrue($return->isProcessing());
    }

    public function test_can_receive_items(): void
    {
        $this->actingAs($this->user);

        $return = ProductReturn::factory()->create([
            'store_id' => $this->store->id,
            'status' => ProductReturn::STATUS_PROCESSING,
        ]);

        $response = $this->post("/returns/{$return->id}/receive");

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $return->refresh();
        $this->assertNotNull($return->received_at);
    }

    public function test_can_complete_return_with_refund(): void
    {
        $this->actingAs($this->user);

        $return = ProductReturn::factory()->create([
            'store_id' => $this->store->id,
            'status' => ProductReturn::STATUS_PROCESSING,
            'refund_amount' => 50.00,
        ]);

        $response = $this->post("/returns/{$return->id}/complete", [
            'refund_method' => ProductReturn::REFUND_ORIGINAL,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $return->refresh();
        $this->assertTrue($return->isCompleted());
        $this->assertEquals(ProductReturn::REFUND_ORIGINAL, $return->refund_method);
    }

    public function test_can_cancel_return(): void
    {
        $this->actingAs($this->user);

        $return = ProductReturn::factory()->pending()->create(['store_id' => $this->store->id]);

        $response = $this->post("/returns/{$return->id}/cancel");

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $return->refresh();
        $this->assertTrue($return->isCancelled());
    }

    public function test_cannot_cancel_completed_return(): void
    {
        $this->actingAs($this->user);

        $return = ProductReturn::factory()->completed()->create(['store_id' => $this->store->id]);

        $response = $this->post("/returns/{$return->id}/cancel");

        $response->assertRedirect();
        $response->assertSessionHas('error');

        $return->refresh();
        $this->assertTrue($return->isCompleted());
    }

    public function test_can_bulk_approve_returns(): void
    {
        $this->actingAs($this->user);

        $returns = ProductReturn::factory()->pending()->count(3)->create([
            'store_id' => $this->store->id,
        ]);

        $response = $this->post('/returns/bulk-action', [
            'action' => 'approve',
            'ids' => $returns->pluck('id')->toArray(),
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        foreach ($returns as $return) {
            $this->assertTrue($return->fresh()->isApproved());
        }
    }

    public function test_can_bulk_reject_returns(): void
    {
        $this->actingAs($this->user);

        $returns = ProductReturn::factory()->pending()->count(2)->create([
            'store_id' => $this->store->id,
        ]);

        $response = $this->post('/returns/bulk-action', [
            'action' => 'reject',
            'ids' => $returns->pluck('id')->toArray(),
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        foreach ($returns as $return) {
            $this->assertTrue($return->fresh()->isRejected());
        }
    }

    public function test_cannot_access_other_stores_returns(): void
    {
        $this->actingAs($this->user);

        $otherStore = Store::factory()->create();
        $return = ProductReturn::factory()->create(['store_id' => $otherStore->id]);

        $response = $this->get("/returns/{$return->id}");

        $response->assertStatus(404);
    }

    public function test_create_return_with_restocking_fee(): void
    {
        $this->actingAs($this->user);

        $policy = ReturnPolicy::factory()->create([
            'store_id' => $this->store->id,
            'restocking_fee_percent' => 10,
        ]);

        $orderItem = $this->order->items->first();

        $response = $this->post('/returns', [
            'order_id' => $this->order->id,
            'return_policy_id' => $policy->id,
            'type' => 'return',
            'items' => [
                [
                    'order_item_id' => $orderItem->id,
                    'quantity' => 1,
                    'unit_price' => 100.00,
                    'restock' => true,
                ],
            ],
        ]);

        $response->assertRedirect();

        $return = ProductReturn::where('order_id', $this->order->id)->first();
        $this->assertEquals(100.00, (float) $return->subtotal);
        $this->assertEquals(10.00, (float) $return->restocking_fee);
        $this->assertEquals(90.00, (float) $return->refund_amount);
    }

    public function test_can_create_return_preselected_order(): void
    {
        $this->actingAs($this->user);

        $response = $this->get('/returns/create?order_id='.$this->order->id);

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('returns/Create')
            ->has('selectedOrder')
        );
    }
}
