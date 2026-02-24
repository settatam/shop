<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Inventory;
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
use App\Models\Warehouse;
use App\Services\Returns\ReturnService;
use App\Services\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

class ReturnTest extends TestCase
{
    use RefreshDatabase;

    protected Store $store;

    protected User $user;

    protected Customer $customer;

    protected Order $order;

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

    public function test_can_list_returns(): void
    {
        Passport::actingAs($this->user);

        ProductReturn::factory()->count(3)->create(['store_id' => $this->store->id]);
        ProductReturn::factory()->create(); // Different store

        $response = $this->getJson('/api/v1/returns');

        $response->assertStatus(200);
        $this->assertCount(3, $response->json('data'));
    }

    public function test_can_create_return_from_order(): void
    {
        Passport::actingAs($this->user);

        $orderItem = $this->order->items->first();

        $response = $this->postJson('/api/v1/returns', [
            'order_id' => $this->order->id,
            'reason' => 'defective',
            'items' => [
                [
                    'order_item_id' => $orderItem->id,
                    'quantity' => 1,
                    'unit_price' => $orderItem->price,
                    'condition' => 'used',
                    'reason' => 'Product was defective',
                ],
            ],
        ]);

        $response->assertStatus(201);
        $response->assertJsonFragment(['status' => 'pending']);

        $this->assertDatabaseHas('returns', [
            'order_id' => $this->order->id,
            'customer_id' => $this->customer->id,
            'status' => 'pending',
        ]);

        $this->assertDatabaseHas('return_items', [
            'order_item_id' => $orderItem->id,
            'quantity' => 1,
        ]);
    }

    public function test_can_view_return_details(): void
    {
        Passport::actingAs($this->user);

        $return = ProductReturn::factory()->create(['store_id' => $this->store->id]);
        ReturnItem::factory()->create(['return_id' => $return->id]);

        $response = $this->getJson("/api/v1/returns/{$return->id}");

        $response->assertStatus(200);
        $response->assertJsonFragment(['id' => $return->id]);
    }

    public function test_can_approve_pending_return(): void
    {
        Passport::actingAs($this->user);

        $return = ProductReturn::factory()->pending()->create(['store_id' => $this->store->id]);

        $response = $this->postJson("/api/v1/returns/{$return->id}/approve");

        $response->assertStatus(200);
        $response->assertJsonFragment(['status' => 'approved']);

        $return->refresh();
        $this->assertTrue($return->isApproved());
        $this->assertNotNull($return->approved_at);
    }

    public function test_can_reject_return(): void
    {
        Passport::actingAs($this->user);

        $return = ProductReturn::factory()->pending()->create(['store_id' => $this->store->id]);

        $response = $this->postJson("/api/v1/returns/{$return->id}/reject", [
            'reason' => 'Item shows signs of use beyond normal wear',
        ]);

        $response->assertStatus(200);
        $response->assertJsonFragment(['status' => 'rejected']);
    }

    public function test_can_process_approved_return(): void
    {
        Passport::actingAs($this->user);

        $return = ProductReturn::factory()->approved()->create(['store_id' => $this->store->id]);
        ReturnItem::factory()->create([
            'return_id' => $return->id,
            'quantity' => 1,
            'unit_price' => 50.00,
            'line_total' => 50.00,
        ]);

        $response = $this->postJson("/api/v1/returns/{$return->id}/process", [
            'refund_method' => 'store_credit',
        ]);

        $response->assertStatus(200);
        $response->assertJsonFragment(['status' => 'completed']);

        $return->refresh();
        $this->assertTrue($return->isCompleted());
        $this->assertEquals('store_credit', $return->refund_method);
    }

    public function test_can_cancel_pending_return(): void
    {
        Passport::actingAs($this->user);

        $return = ProductReturn::factory()->pending()->create(['store_id' => $this->store->id]);

        $response = $this->postJson("/api/v1/returns/{$return->id}/cancel");

        $response->assertStatus(200);
        $response->assertJsonFragment(['status' => 'cancelled']);
    }

    public function test_cannot_process_pending_return(): void
    {
        Passport::actingAs($this->user);

        $return = ProductReturn::factory()->pending()->create(['store_id' => $this->store->id]);

        $response = $this->postJson("/api/v1/returns/{$return->id}/process", [
            'refund_method' => 'cash',
        ]);

        $response->assertStatus(500);
    }

    public function test_return_service_creates_return_correctly(): void
    {
        $service = app(ReturnService::class);
        $orderItem = $this->order->items->first();

        $return = $service->createReturn($this->order, [
            [
                'order_item_id' => $orderItem->id,
                'quantity' => 1,
                'unit_price' => $orderItem->price,
            ],
        ], [
            'reason' => 'defective',
        ]);

        $this->assertInstanceOf(ProductReturn::class, $return);
        $this->assertEquals($this->order->id, $return->order_id);
        $this->assertEquals($this->customer->id, $return->customer_id);
        $this->assertTrue($return->isPending());
        $this->assertCount(1, $return->items);
    }

    public function test_return_service_applies_restocking_fee(): void
    {
        $policy = ReturnPolicy::factory()->withRestockingFee(10)->create([
            'store_id' => $this->store->id,
        ]);

        $service = app(ReturnService::class);
        $orderItem = $this->order->items->first();

        $return = $service->createReturn($this->order, [
            [
                'order_item_id' => $orderItem->id,
                'quantity' => 1,
                'unit_price' => 100.00,
            ],
        ], [
            'return_policy_id' => $policy->id,
        ]);

        $this->assertEquals(100.00, (float) $return->subtotal);
        $this->assertEquals(10.00, (float) $return->restocking_fee);
        $this->assertEquals(90.00, (float) $return->refund_amount);
    }

    public function test_return_service_restocks_items(): void
    {
        $variant = ProductVariant::factory()->create();
        $warehouse = Warehouse::factory()->create(['store_id' => $this->store->id]);
        $inventory = Inventory::factory()->create([
            'store_id' => $this->store->id,
            'product_variant_id' => $variant->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 5,
        ]);

        $return = ProductReturn::factory()->approved()->create(['store_id' => $this->store->id]);
        $returnItem = ReturnItem::factory()->create([
            'return_id' => $return->id,
            'product_variant_id' => $variant->id,
            'quantity' => 2,
            'unit_price' => 50.00,
            'restock' => true,
            'restocked' => false,
        ]);

        $service = app(ReturnService::class);
        $service->restockItems($return->fresh(['items']));

        $inventory->refresh();
        $this->assertEquals(7, $inventory->quantity);
        $this->assertTrue($returnItem->fresh()->wasRestocked());
    }

    public function test_return_status_transitions(): void
    {
        $return = ProductReturn::factory()->pending()->create(['store_id' => $this->store->id]);

        $this->assertTrue($return->isPending());
        $this->assertTrue($return->canBeApproved());
        $this->assertFalse($return->canBeProcessed());

        $return->approve($this->user->id);
        $return->refresh();

        $this->assertTrue($return->isApproved());
        $this->assertFalse($return->canBeApproved());
        $this->assertTrue($return->canBeProcessed());
    }

    public function test_filter_returns_by_status(): void
    {
        Passport::actingAs($this->user);

        ProductReturn::factory()->pending()->count(2)->create(['store_id' => $this->store->id]);
        ProductReturn::factory()->completed()->create(['store_id' => $this->store->id]);

        $response = $this->getJson('/api/v1/returns?status=pending');

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data'));
    }

    public function test_filter_returns_by_type(): void
    {
        Passport::actingAs($this->user);

        ProductReturn::factory()->count(2)->create(['store_id' => $this->store->id, 'type' => 'return']);
        ProductReturn::factory()->exchange()->create(['store_id' => $this->store->id]);

        $response = $this->getJson('/api/v1/returns?type=exchange');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
    }

    public function test_return_generates_unique_number(): void
    {
        $return1 = ProductReturn::factory()->create(['store_id' => $this->store->id]);
        $return2 = ProductReturn::factory()->create(['store_id' => $this->store->id]);

        $this->assertNotEquals($return1->return_number, $return2->return_number);
        $this->assertStringStartsWith('RET-', $return1->return_number);
    }

    public function test_can_mark_return_as_received(): void
    {
        Passport::actingAs($this->user);

        $return = ProductReturn::factory()->approved()->create(['store_id' => $this->store->id]);

        $response = $this->postJson("/api/v1/returns/{$return->id}/receive");

        $response->assertStatus(200);
        $return->refresh();
        $this->assertNotNull($return->received_at);
    }

    public function test_can_create_exchange(): void
    {
        Passport::actingAs($this->user);

        $return = ProductReturn::factory()->approved()->create([
            'store_id' => $this->store->id,
            'customer_id' => $this->customer->id,
        ]);
        ReturnItem::factory()->create(['return_id' => $return->id]);

        $newVariant = ProductVariant::factory()->create();

        $warehouse = Warehouse::factory()->create([
            'store_id' => $this->store->id,
            'is_default' => true,
        ]);

        Inventory::factory()->create([
            'store_id' => $this->store->id,
            'product_variant_id' => $newVariant->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 10,
            'reserved_quantity' => 0,
        ]);

        $response = $this->postJson("/api/v1/returns/{$return->id}/exchange", [
            'items' => [
                [
                    'product_variant_id' => $newVariant->id,
                    'quantity' => 1,
                    'price' => 75.00,
                ],
            ],
        ]);

        $response->assertStatus(200);
        $this->assertArrayHasKey('exchange_order_id', $response->json());

        $return->refresh();
        $this->assertTrue($return->isExchange());
    }

    public function test_cannot_approve_already_approved_return(): void
    {
        Passport::actingAs($this->user);

        $return = ProductReturn::factory()->approved()->create(['store_id' => $this->store->id]);

        $response = $this->postJson("/api/v1/returns/{$return->id}/approve");

        $response->assertStatus(500);
    }

    public function test_search_returns_by_number(): void
    {
        Passport::actingAs($this->user);

        $return = ProductReturn::factory()->create([
            'store_id' => $this->store->id,
            'return_number' => 'RET-20260114-ABC123',
        ]);
        ProductReturn::factory()->create(['store_id' => $this->store->id]);

        $response = $this->getJson('/api/v1/returns?search=ABC123');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
    }
}
