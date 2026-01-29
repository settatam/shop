<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Orders\OrderCreationService;
use App\Services\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

class OrderTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Store $store;

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

        app(StoreContext::class)->setCurrentStore($this->store);
    }

    public function test_can_list_orders(): void
    {
        Passport::actingAs($this->user);

        Order::factory()->count(3)->create(['store_id' => $this->store->id]);

        $response = $this->getJson('/api/v1/orders');

        $response->assertStatus(200)
            ->assertJsonCount(3, 'data');
    }

    public function test_can_filter_orders_by_status(): void
    {
        Passport::actingAs($this->user);

        Order::factory()->pending()->count(2)->create(['store_id' => $this->store->id]);
        Order::factory()->confirmed()->count(3)->create(['store_id' => $this->store->id]);

        $response = $this->getJson('/api/v1/orders?status=pending');

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data');
    }

    public function test_can_create_order_via_api(): void
    {
        Passport::actingAs($this->user);

        $response = $this->postJson('/api/v1/orders', [
            'items' => [
                [
                    'title' => 'Test Product',
                    'sku' => 'TEST-001',
                    'quantity' => 2,
                    'price' => 29.99,
                ],
            ],
            'shipping_cost' => 5.99,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', Order::STATUS_PENDING);

        $this->assertDatabaseHas('orders', [
            'store_id' => $this->store->id,
            'status' => Order::STATUS_PENDING,
        ]);

        $this->assertDatabaseHas('order_items', [
            'title' => 'Test Product',
            'sku' => 'TEST-001',
            'quantity' => 2,
        ]);
    }

    public function test_can_create_order_with_customer(): void
    {
        Passport::actingAs($this->user);

        $response = $this->postJson('/api/v1/orders', [
            'customer' => [
                'email' => 'test@example.com',
                'first_name' => 'John',
                'last_name' => 'Doe',
            ],
            'items' => [
                [
                    'title' => 'Test Product',
                    'quantity' => 1,
                    'price' => 50.00,
                ],
            ],
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('customers', [
            'store_id' => $this->store->id,
            'email' => 'test@example.com',
            'first_name' => 'John',
            'last_name' => 'Doe',
        ]);
    }

    public function test_can_create_order_with_existing_customer(): void
    {
        Passport::actingAs($this->user);

        $customer = Customer::factory()->create(['store_id' => $this->store->id]);

        $response = $this->postJson('/api/v1/orders', [
            'customer' => [
                'id' => $customer->id,
            ],
            'items' => [
                [
                    'title' => 'Test Product',
                    'quantity' => 1,
                    'price' => 50.00,
                ],
            ],
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.customer_id', $customer->id);
    }

    public function test_can_create_order_with_payment(): void
    {
        Passport::actingAs($this->user);

        $response = $this->postJson('/api/v1/orders', [
            'items' => [
                [
                    'title' => 'Test Product',
                    'quantity' => 1,
                    'price' => 100.00,
                ],
            ],
            'payments' => [
                [
                    'amount' => 100.00,
                    'payment_method' => Payment::METHOD_CASH,
                ],
            ],
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', Order::STATUS_CONFIRMED)
            ->assertJsonPath('data.is_fully_paid', true);

        $this->assertDatabaseHas('payments', [
            'store_id' => $this->store->id,
            'amount' => '100.00',
            'payment_method' => Payment::METHOD_CASH,
            'status' => Payment::STATUS_COMPLETED,
        ]);
    }

    public function test_partial_payment_sets_correct_status(): void
    {
        Passport::actingAs($this->user);

        $response = $this->postJson('/api/v1/orders', [
            'items' => [
                [
                    'title' => 'Test Product',
                    'quantity' => 1,
                    'price' => 100.00,
                ],
            ],
            'payments' => [
                [
                    'amount' => 50.00,
                    'payment_method' => Payment::METHOD_CASH,
                ],
            ],
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', Order::STATUS_PARTIAL_PAYMENT)
            ->assertJsonPath('data.is_fully_paid', false);
    }

    public function test_can_show_order_details(): void
    {
        Passport::actingAs($this->user);

        $order = Order::factory()->create(['store_id' => $this->store->id]);
        OrderItem::factory()->count(2)->create(['order_id' => $order->id]);

        $response = $this->getJson("/api/v1/orders/{$order->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $order->id)
            ->assertJsonCount(2, 'data.items');
    }

    public function test_can_update_order(): void
    {
        Passport::actingAs($this->user);

        $order = Order::factory()->pending()->create(['store_id' => $this->store->id]);

        $response = $this->putJson("/api/v1/orders/{$order->id}", [
            'notes' => 'Updated notes',
            'shipping_cost' => 15.99,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.notes', 'Updated notes');
    }

    public function test_can_cancel_order(): void
    {
        Passport::actingAs($this->user);

        $order = Order::factory()->pending()->create(['store_id' => $this->store->id]);

        $response = $this->postJson("/api/v1/orders/{$order->id}/cancel");

        $response->assertStatus(200)
            ->assertJsonPath('data.status', Order::STATUS_CANCELLED);
    }

    public function test_cancelling_order_restores_inventory(): void
    {
        Passport::actingAs($this->user);

        $warehouse = Warehouse::factory()->create(['store_id' => $this->store->id]);
        $product = Product::factory()->create(['store_id' => $this->store->id]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);

        $inventory = Inventory::factory()->create([
            'store_id' => $this->store->id,
            'warehouse_id' => $warehouse->id,
            'product_variant_id' => $variant->id,
            'quantity' => 100,
        ]);

        // Create order that reduces stock
        $response = $this->postJson('/api/v1/orders', [
            'items' => [
                [
                    'product_variant_id' => $variant->id,
                    'quantity' => 10,
                    'price' => 50.00,
                    'reduce_stock' => true,
                ],
            ],
        ]);

        $response->assertStatus(200);
        $this->assertEquals(90, $inventory->fresh()->quantity);

        $orderId = $response->json('data.id');

        // Cancel order
        $this->postJson("/api/v1/orders/{$orderId}/cancel")
            ->assertStatus(200);

        // Verify inventory restored
        $this->assertEquals(100, $inventory->fresh()->quantity);
    }

    public function test_can_add_payment_to_existing_order(): void
    {
        Passport::actingAs($this->user);

        $order = Order::factory()->pending()->create([
            'store_id' => $this->store->id,
            'total' => 100.00,
        ]);

        $response = $this->postJson("/api/v1/orders/{$order->id}/payment", [
            'amount' => 100.00,
            'payment_method' => Payment::METHOD_CARD,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', Order::STATUS_CONFIRMED);

        $this->assertDatabaseHas('payments', [
            'order_id' => $order->id,
            'amount' => '100.00',
        ]);
    }

    public function test_can_confirm_order(): void
    {
        Passport::actingAs($this->user);

        $order = Order::factory()->pending()->create(['store_id' => $this->store->id]);

        $response = $this->postJson("/api/v1/orders/{$order->id}/confirm");

        $response->assertStatus(200)
            ->assertJsonPath('data.status', Order::STATUS_CONFIRMED);
    }

    public function test_can_ship_order(): void
    {
        Passport::actingAs($this->user);

        $order = Order::factory()->confirmed()->create(['store_id' => $this->store->id]);

        $response = $this->postJson("/api/v1/orders/{$order->id}/ship");

        $response->assertStatus(200)
            ->assertJsonPath('data.status', Order::STATUS_SHIPPED);
    }

    public function test_can_deliver_order(): void
    {
        Passport::actingAs($this->user);

        $order = Order::factory()->shipped()->create(['store_id' => $this->store->id]);

        $response = $this->postJson("/api/v1/orders/{$order->id}/deliver");

        $response->assertStatus(200)
            ->assertJsonPath('data.status', Order::STATUS_DELIVERED);
    }

    public function test_can_complete_order(): void
    {
        Passport::actingAs($this->user);

        $order = Order::factory()->delivered()->create(['store_id' => $this->store->id]);

        $response = $this->postJson("/api/v1/orders/{$order->id}/complete");

        $response->assertStatus(200)
            ->assertJsonPath('data.status', Order::STATUS_COMPLETED);
    }

    public function test_cannot_delete_paid_order(): void
    {
        Passport::actingAs($this->user);

        $order = Order::factory()->confirmed()->create(['store_id' => $this->store->id]);

        $response = $this->deleteJson("/api/v1/orders/{$order->id}");

        $response->assertStatus(422)
            ->assertJsonFragment([
                'message' => 'Cannot delete a paid order. Cancel it first.',
            ]);
    }

    public function test_can_delete_pending_order(): void
    {
        Passport::actingAs($this->user);

        $order = Order::factory()->pending()->create(['store_id' => $this->store->id]);

        $response = $this->deleteJson("/api/v1/orders/{$order->id}");

        $response->assertStatus(200);
        $this->assertSoftDeleted('orders', ['id' => $order->id]);
    }

    public function test_order_totals_are_calculated_correctly(): void
    {
        $service = app(OrderCreationService::class);

        $order = $service->create([
            'items' => [
                ['title' => 'Item 1', 'quantity' => 2, 'price' => 50.00],
                ['title' => 'Item 2', 'quantity' => 1, 'price' => 30.00],
            ],
            'shipping_cost' => 10.00,
            'discount_cost' => 5.00,
        ], $this->store);

        // Sub-total: (2 * 50) + (1 * 30) = 130
        // Total: 130 + 10 - 5 = 135
        $this->assertEquals('130.00', $order->sub_total);
        $this->assertEquals('135.00', $order->total);
    }

    public function test_order_item_line_total_calculation(): void
    {
        $order = Order::factory()->create(['store_id' => $this->store->id]);

        $item = OrderItem::factory()->create([
            'order_id' => $order->id,
            'quantity' => 3,
            'price' => 100.00,
            'discount' => 10.00,
        ]);

        // Line total: (100 - 10) * 3 = 270
        $this->assertEquals(270, $item->line_total);
    }

    public function test_order_balance_due_calculation(): void
    {
        $order = Order::factory()->create([
            'store_id' => $this->store->id,
            'total' => 200.00,
        ]);

        Payment::factory()->completed()->create([
            'store_id' => $this->store->id,
            'payable_type' => Order::class,
            'payable_id' => $order->id,
            'order_id' => $order->id,
            'amount' => 75.00,
        ]);

        $order->refresh();
        $this->assertEquals(75.00, $order->total_paid);
        $this->assertEquals(125.00, $order->balance_due);
        $this->assertFalse($order->isFullyPaid());
    }

    public function test_order_requires_items(): void
    {
        Passport::actingAs($this->user);

        $response = $this->postJson('/api/v1/orders', [
            'shipping_cost' => 5.99,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['items']);
    }

    public function test_order_creation_validates_stock_when_requested(): void
    {
        $warehouse = Warehouse::factory()->create(['store_id' => $this->store->id]);
        $product = Product::factory()->create(['store_id' => $this->store->id]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);

        Inventory::factory()->create([
            'store_id' => $this->store->id,
            'warehouse_id' => $warehouse->id,
            'product_variant_id' => $variant->id,
            'quantity' => 5,
        ]);

        $service = app(OrderCreationService::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Insufficient stock');

        $service->create([
            'items' => [
                [
                    'product_variant_id' => $variant->id,
                    'quantity' => 10,
                    'price' => 50.00,
                    'validate_stock' => true,
                ],
            ],
        ], $this->store);
    }

    public function test_order_with_addresses(): void
    {
        Passport::actingAs($this->user);

        $response = $this->postJson('/api/v1/orders', [
            'items' => [
                ['title' => 'Test', 'quantity' => 1, 'price' => 50.00],
            ],
            'billing_address' => [
                'address_line1' => '123 Billing St',
                'city' => 'New York',
                'state' => 'NY',
                'postal_code' => '10001',
                'country' => 'US',
            ],
            'shipping_address' => [
                'address_line1' => '456 Shipping Ave',
                'city' => 'Los Angeles',
                'state' => 'CA',
                'postal_code' => '90001',
                'country' => 'US',
            ],
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.billing_address.city', 'New York')
            ->assertJsonPath('data.shipping_address.city', 'Los Angeles');
    }

    public function test_payment_adjustments_recognizes_tax_rate(): void
    {
        $order = Order::factory()->create([
            'store_id' => $this->store->id,
            'tax_rate' => 0.08, // 8% tax rate
            'sales_tax' => 0, // Not yet calculated
        ]);

        $adjustments = $order->getPaymentAdjustments();

        // charge_taxes should be true because tax_rate > 0
        $this->assertTrue($adjustments['charge_taxes']);
        $this->assertEquals(0.08, $adjustments['tax_rate']);
    }

    public function test_payment_adjustments_stores_service_fee(): void
    {
        $order = Order::factory()->create([
            'store_id' => $this->store->id,
            'service_fee_value' => 25.00,
            'service_fee_unit' => 'fixed',
            'service_fee_reason' => 'Processing fee',
        ]);

        $adjustments = $order->getPaymentAdjustments();

        $this->assertEquals(25.00, $adjustments['service_fee_value']);
        $this->assertEquals('fixed', $adjustments['service_fee_unit']);
        $this->assertEquals('Processing fee', $adjustments['service_fee_reason']);
    }

    public function test_update_payment_adjustments_saves_service_fee(): void
    {
        $order = Order::factory()->create([
            'store_id' => $this->store->id,
        ]);

        $order->updatePaymentAdjustments([
            'service_fee_value' => 15.00,
            'service_fee_unit' => 'percent',
            'service_fee_reason' => 'Card processing',
        ]);

        $order->refresh();

        $this->assertEquals('15.00', $order->service_fee_value);
        $this->assertEquals('percent', $order->service_fee_unit);
        $this->assertEquals('Card processing', $order->service_fee_reason);
    }
}
