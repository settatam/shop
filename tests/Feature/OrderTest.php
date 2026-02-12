<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\SalesChannel;
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

        // Any payment confirms the order, even partial payments
        $response->assertStatus(200)
            ->assertJsonPath('data.status', Order::STATUS_CONFIRMED)
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

    public function test_can_ship_order_with_tracking(): void
    {
        $this->actingAs($this->user);

        $order = Order::factory()->confirmed()->create(['store_id' => $this->store->id]);

        $response = $this->post("/orders/{$order->id}/ship", [
            'tracking_number' => '1234567890',
            'carrier' => 'fedex',
        ]);

        $response->assertRedirect();

        $order->refresh();
        $this->assertEquals(Order::STATUS_SHIPPED, $order->status);
        $this->assertEquals('1234567890', $order->tracking_number);
        $this->assertEquals('fedex', $order->shipping_carrier);
        $this->assertNotNull($order->shipped_at);
    }

    public function test_order_tracking_url_is_generated(): void
    {
        $order = Order::factory()->shipped()->create([
            'store_id' => $this->store->id,
            'tracking_number' => 'TRACK123',
            'shipping_carrier' => 'fedex',
        ]);

        $this->assertEquals(
            'https://www.fedex.com/fedextrack/?trknbr=TRACK123',
            $order->getTrackingUrl()
        );

        $order->update(['shipping_carrier' => 'ups']);
        $this->assertEquals(
            'https://www.ups.com/track?tracknum=TRACK123',
            $order->getTrackingUrl()
        );

        $order->update(['tracking_number' => null]);
        $this->assertNull($order->getTrackingUrl());
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
            'tax_rate' => 0.08, // 8% tax rate stored as decimal
            'sales_tax' => 0, // Not yet calculated
        ]);

        $adjustments = $order->getPaymentAdjustments();

        // charge_taxes should be true because tax_rate > 0
        $this->assertTrue($adjustments['charge_taxes']);
        // tax_rate is returned as percentage (8) for the payment modal
        $this->assertEquals(8, $adjustments['tax_rate']);
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

    public function test_service_fee_calculated_only_on_subtotal(): void
    {
        $this->actingAs($this->user);

        $product = Product::factory()->create(['store_id' => $this->store->id]);
        $variant = ProductVariant::factory()->create([
            'product_id' => $product->id,
            'price' => 100.00,
        ]);

        // Create order with $100 subtotal
        $order = Order::factory()->pending()->create([
            'store_id' => $this->store->id,
            'sub_total' => 100.00,
            'shipping_cost' => 20.00,
            'sales_tax' => 10.00,
            'discount_cost' => 0,
            'total' => 130.00,
        ]);

        // 10% service fee should only apply to subtotal ($100), not shipping/tax
        $paymentService = app(\App\Services\PaymentService::class);
        $summary = $paymentService->calculateSummary($order, [
            'service_fee_value' => 10,
            'service_fee_unit' => 'percent',
        ]);

        // Service fee should be $10 (10% of $100 subtotal), not $13 (10% of $130 total)
        $this->assertEquals(10.00, $summary['service_fee_amount']);
    }

    public function test_partial_payment_service_fee_capped_at_subtotal(): void
    {
        $this->actingAs($this->user);

        $product = Product::factory()->create(['store_id' => $this->store->id]);
        $variant = ProductVariant::factory()->create([
            'product_id' => $product->id,
            'price' => 100.00,
        ]);

        // Create order with $100 subtotal + $50 shipping/tax = $150 total
        $order = Order::factory()->pending()->create([
            'store_id' => $this->store->id,
            'sub_total' => 100.00,
            'shipping_cost' => 30.00,
            'sales_tax' => 20.00,
            'discount_cost' => 0,
            'total' => 150.00,
        ]);

        $paymentService = app(\App\Services\PaymentService::class);

        // Full payment of $150 - service fee should be on subtotal only ($100)
        $result = $paymentService->processPayments($order, [
            'payment_method' => 'credit_card',
            'amount' => 150.00,
            'service_fee_value' => 3,
            'service_fee_unit' => 'percent',
        ], $this->user->id);

        // Service fee should be $3 (3% of $100 subtotal), not $4.50 (3% of $150)
        $this->assertEquals(3.00, $result['payment']->service_fee_amount);
    }

    public function test_partial_payment_under_subtotal_gets_proportional_service_fee(): void
    {
        $this->actingAs($this->user);

        $product = Product::factory()->create(['store_id' => $this->store->id]);
        $variant = ProductVariant::factory()->create([
            'product_id' => $product->id,
            'price' => 100.00,
        ]);

        // Create order with $100 subtotal
        $order = Order::factory()->pending()->create([
            'store_id' => $this->store->id,
            'sub_total' => 100.00,
            'shipping_cost' => 20.00,
            'discount_cost' => 0,
            'total' => 120.00,
        ]);

        $paymentService = app(\App\Services\PaymentService::class);

        // Partial payment of $50 (less than subtotal) - service fee on the $50
        $result = $paymentService->processPayments($order, [
            'payment_method' => 'credit_card',
            'amount' => 50.00,
            'service_fee_value' => 3,
            'service_fee_unit' => 'percent',
        ], $this->user->id);

        // Service fee should be $1.50 (3% of $50)
        $this->assertEquals(1.50, $result['payment']->service_fee_amount);
    }

    public function test_quick_product_is_created_with_quantity_one(): void
    {
        $this->user->update(['current_store_id' => $this->store->id]);
        $this->actingAs($this->user);

        $category = Category::factory()->create(['store_id' => $this->store->id]);

        $response = $this->postJson('/orders/create-product', [
            'title' => 'Quick Order Product',
            'sku' => 'QOP-001',
            'price' => 299.99,
            'cost' => 150.00,
            'category_id' => $category->id,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('product.title', 'Quick Order Product')
            ->assertJsonPath('product.quantity', 1);

        $this->assertDatabaseHas('products', [
            'store_id' => $this->store->id,
            'title' => 'Quick Order Product',
            'quantity' => 1,
            'is_published' => true,
            'is_draft' => false,
        ]);

        $this->assertDatabaseHas('product_variants', [
            'sku' => 'QOP-001',
            'quantity' => 1,
        ]);
    }

    public function test_quick_product_inherits_charge_taxes_from_category(): void
    {
        $this->user->update(['current_store_id' => $this->store->id]);
        $this->actingAs($this->user);

        // Create a category with charge_taxes = false
        $categoryNoTax = Category::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Non-Taxable',
            'charge_taxes' => false,
        ]);

        $response = $this->postJson('/orders/create-product', [
            'title' => 'Non-Taxable Product',
            'price' => 100.00,
            'category_id' => $categoryNoTax->id,
        ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('products', [
            'title' => 'Non-Taxable Product',
            'charge_taxes' => false,
        ]);

        // Create a category with charge_taxes = true
        $categoryWithTax = Category::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Taxable',
            'charge_taxes' => true,
        ]);

        $response = $this->postJson('/orders/create-product', [
            'title' => 'Taxable Product',
            'price' => 200.00,
            'category_id' => $categoryWithTax->id,
        ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('products', [
            'title' => 'Taxable Product',
            'charge_taxes' => true,
        ]);
    }

    public function test_quick_product_defaults_to_taxable_without_category(): void
    {
        $this->user->update(['current_store_id' => $this->store->id]);
        $this->actingAs($this->user);

        $response = $this->postJson('/orders/create-product', [
            'title' => 'No Category Product',
            'price' => 50.00,
        ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('products', [
            'title' => 'No Category Product',
            'charge_taxes' => true,
        ]);
    }

    public function test_quick_product_is_searchable_in_products(): void
    {
        $this->user->update(['current_store_id' => $this->store->id]);
        $this->actingAs($this->user);

        $category = Category::factory()->create(['store_id' => $this->store->id]);

        // Create the quick product
        $response = $this->postJson('/orders/create-product', [
            'title' => 'Searchable Quick Product',
            'sku' => 'SQP-999',
            'price' => 499.99,
            'category_id' => $category->id,
        ]);

        $response->assertStatus(201);

        // Verify it can be searched in the order product search
        $searchResponse = $this->getJson('/orders/search-products?query=Searchable');

        $searchResponse->assertStatus(200);
        $products = $searchResponse->json('products');

        $this->assertNotEmpty($products);
        $this->assertEquals('Searchable Quick Product', $products[0]['title']);
    }

    public function test_order_created_locally_gets_default_local_sales_channel(): void
    {
        $service = app(OrderCreationService::class);

        $order = $service->create([
            'items' => [
                ['title' => 'Test Item', 'quantity' => 1, 'price' => 100.00],
            ],
        ], $this->store);

        // Verify the order has a sales channel assigned
        $this->assertNotNull($order->sales_channel_id);

        // Verify the sales channel is local
        $salesChannel = SalesChannel::find($order->sales_channel_id);
        $this->assertNotNull($salesChannel);
        $this->assertTrue($salesChannel->is_local);
        $this->assertEquals($this->store->id, $salesChannel->store_id);
    }

    public function test_order_from_wizard_gets_default_local_sales_channel(): void
    {
        $storeUser = StoreUser::where('store_id', $this->store->id)
            ->where('user_id', $this->user->id)
            ->first();

        $product = Product::factory()->create(['store_id' => $this->store->id]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);

        $service = app(OrderCreationService::class);

        $order = $service->createFromWizard([
            'store_user_id' => $storeUser->id,
            'items' => [
                [
                    'product_id' => $product->id,
                    'variant_id' => $variant->id,
                    'quantity' => 1,
                    'price' => 50.00,
                ],
            ],
        ], $this->store);

        // Verify the order has a sales channel assigned
        $this->assertNotNull($order->sales_channel_id);

        // Verify the sales channel is local
        $salesChannel = SalesChannel::find($order->sales_channel_id);
        $this->assertNotNull($salesChannel);
        $this->assertTrue($salesChannel->is_local);
    }

    public function test_sales_channel_get_default_local_channel_creates_if_none_exists(): void
    {
        // Ensure no sales channels exist for this store
        SalesChannel::where('store_id', $this->store->id)->forceDelete();

        // Get default local channel - should create one
        $channel = SalesChannel::getDefaultLocalChannel($this->store->id);

        $this->assertNotNull($channel);
        $this->assertEquals($this->store->id, $channel->store_id);
        $this->assertTrue($channel->is_local);
        $this->assertTrue($channel->is_default);
        $this->assertEquals('in_store', $channel->code);
    }

    public function test_sales_channel_get_default_local_channel_returns_existing(): void
    {
        // Create a local sales channel
        $existingChannel = SalesChannel::create([
            'store_id' => $this->store->id,
            'name' => 'POS Terminal',
            'code' => 'pos_terminal',
            'type' => SalesChannel::TYPE_LOCAL,
            'is_local' => true,
            'is_active' => true,
            'is_default' => true,
        ]);

        // Get default local channel - should return the existing one
        $channel = SalesChannel::getDefaultLocalChannel($this->store->id);

        $this->assertEquals($existingChannel->id, $channel->id);
    }

    public function test_can_update_order_customer(): void
    {
        $this->user->update(['current_store_id' => $this->store->id]);
        $this->actingAs($this->user);

        // Create order without customer
        $order = Order::factory()->pending()->create([
            'store_id' => $this->store->id,
            'customer_id' => null,
        ]);

        // Create a customer
        $customer = Customer::factory()->create(['store_id' => $this->store->id]);

        $response = $this->patch("/orders/{$order->id}/customer", [
            'customer_id' => $customer->id,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $order->refresh();
        $this->assertEquals($customer->id, $order->customer_id);
    }

    public function test_cannot_update_order_with_customer_from_different_store(): void
    {
        $this->user->update(['current_store_id' => $this->store->id]);
        $this->actingAs($this->user);

        // Create order without customer
        $order = Order::factory()->pending()->create([
            'store_id' => $this->store->id,
            'customer_id' => null,
        ]);

        // Create a customer for a different store
        $otherStore = Store::factory()->create();
        $customer = Customer::factory()->create(['store_id' => $otherStore->id]);

        $response = $this->patch("/orders/{$order->id}/customer", [
            'customer_id' => $customer->id,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error');

        $order->refresh();
        $this->assertNull($order->customer_id);
    }
}
