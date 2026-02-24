<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PlatformOrder;
use App\Models\Product;
use App\Models\ProductReturn;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreMarketplace;
use App\Models\StoreUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ReturnProcessingTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->store = Store::factory()->create(['user_id' => $this->user->id, 'step' => 2]);

        Role::createDefaultRoles($this->store->id);

        $ownerRole = Role::where('store_id', $this->store->id)
            ->where('slug', 'owner')
            ->first();

        StoreUser::create([
            'user_id' => $this->user->id,
            'store_id' => $this->store->id,
            'role_id' => $ownerRole->id,
            'is_owner' => true,
            'status' => 'active',
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => $this->user->email,
        ]);

        $this->user->update(['current_store_id' => $this->store->id]);
    }

    protected function withStore()
    {
        return $this->withSession(['current_store_id' => $this->store->id]);
    }

    public function test_process_item_return_creates_return_record(): void
    {
        $this->actingAs($this->user);

        $customer = Customer::factory()->create(['store_id' => $this->store->id]);
        $product = Product::factory()->create(['store_id' => $this->store->id]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);

        $order = Order::factory()->create([
            'store_id' => $this->store->id,
            'customer_id' => $customer->id,
            'status' => Order::STATUS_COMPLETED,
        ]);

        $orderItem = OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'quantity' => 2,
            'price' => 100.00,
        ]);

        $response = $this->withStore()
            ->post("/orders/{$order->id}/process-item-return", [
                'items' => [
                    [
                        'order_item_id' => $orderItem->id,
                        'quantity' => 1,
                        'reason' => 'Customer changed mind',
                        'restock' => true,
                    ],
                ],
                'return_method' => 'in_store',
                'reason' => 'General return',
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('returns', [
            'store_id' => $this->store->id,
            'order_id' => $order->id,
            'customer_id' => $customer->id,
            'status' => ProductReturn::STATUS_PROCESSING,
            'return_method' => ProductReturn::METHOD_IN_STORE,
        ]);

        $this->assertDatabaseHas('return_items', [
            'order_item_id' => $orderItem->id,
            'quantity' => 1,
            'restock' => true,
        ]);
    }

    public function test_process_item_return_fails_for_cancelled_orders(): void
    {
        $this->actingAs($this->user);

        $order = Order::factory()->create([
            'store_id' => $this->store->id,
            'status' => Order::STATUS_CANCELLED,
        ]);

        $orderItem = OrderItem::factory()->create([
            'order_id' => $order->id,
            'quantity' => 1,
            'price' => 50.00,
        ]);

        $response = $this->withStore()
            ->post("/orders/{$order->id}/process-item-return", [
                'items' => [
                    [
                        'order_item_id' => $orderItem->id,
                        'quantity' => 1,
                        'restock' => true,
                    ],
                ],
                'return_method' => 'in_store',
            ]);

        // Should fail because cancelled orders cannot have returns processed
        $response->assertRedirect();
        $response->assertSessionHas('error', 'Returns can only be processed for confirmed, shipped, delivered, or completed orders.');

        $this->assertDatabaseMissing('returns', [
            'order_id' => $order->id,
        ]);
    }

    public function test_process_item_return_validates_item_belongs_to_order(): void
    {
        $this->actingAs($this->user);

        $order1 = Order::factory()->create([
            'store_id' => $this->store->id,
            'status' => Order::STATUS_COMPLETED,
        ]);

        $order2 = Order::factory()->create([
            'store_id' => $this->store->id,
            'status' => Order::STATUS_COMPLETED,
        ]);

        $orderItemFromOrder2 = OrderItem::factory()->create([
            'order_id' => $order2->id,
            'quantity' => 1,
            'price' => 50.00,
        ]);

        $response = $this->withStore()
            ->post("/orders/{$order1->id}/process-item-return", [
                'items' => [
                    [
                        'order_item_id' => $orderItemFromOrder2->id,
                        'quantity' => 1,
                        'restock' => true,
                    ],
                ],
                'return_method' => 'in_store',
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('error', 'Invalid order item specified.');
    }

    public function test_full_return_marks_order_as_refunded(): void
    {
        $this->actingAs($this->user);

        $customer = Customer::factory()->create(['store_id' => $this->store->id]);
        $product = Product::factory()->create(['store_id' => $this->store->id]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);

        $order = Order::factory()->create([
            'store_id' => $this->store->id,
            'customer_id' => $customer->id,
            'status' => Order::STATUS_COMPLETED,
        ]);

        $orderItem = OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'quantity' => 1,
            'price' => 100.00,
        ]);

        $response = $this->withStore()
            ->post("/orders/{$order->id}/process-item-return", [
                'items' => [
                    [
                        'order_item_id' => $orderItem->id,
                        'quantity' => 1,
                        'reason' => 'Full return',
                        'restock' => true,
                    ],
                ],
                'return_method' => 'in_store',
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $order->refresh();
        $this->assertEquals(Order::STATUS_REFUNDED, $order->status);
    }

    public function test_process_return_syncs_to_shopify(): void
    {
        $this->actingAs($this->user);

        $marketplace = StoreMarketplace::factory()->shopify()->create([
            'store_id' => $this->store->id,
            'shop_domain' => 'test-store.myshopify.com',
            'access_token' => 'test-token',
            'status' => 'active',
        ]);

        $customer = Customer::factory()->create(['store_id' => $this->store->id]);
        $product = Product::factory()->create(['store_id' => $this->store->id]);
        $variant = ProductVariant::factory()->create([
            'product_id' => $product->id,
            'sku' => 'TEST-SKU-123',
        ]);

        $order = Order::factory()->create([
            'store_id' => $this->store->id,
            'customer_id' => $customer->id,
            'status' => Order::STATUS_COMPLETED,
            'source_platform' => 'shopify',
        ]);

        $platformOrder = PlatformOrder::create([
            'store_marketplace_id' => $marketplace->id,
            'order_id' => $order->id,
            'external_order_id' => '5001',
            'external_order_number' => '1001',
            'status' => 'paid',
            'total' => 100.00,
            'subtotal' => 100.00,
            'ordered_at' => now(),
            'line_items' => [
                [
                    'id' => '12345',
                    'sku' => 'TEST-SKU-123',
                    'title' => 'Test Product',
                    'quantity' => 1,
                    'price' => '100.00',
                ],
            ],
        ]);

        $orderItem = OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'sku' => 'TEST-SKU-123',
            'quantity' => 1,
            'price' => 100.00,
        ]);

        // Mock the Shopify API calls
        Http::fake([
            '*refunds/calculate.json' => Http::response([
                'refund' => [
                    'transactions' => [
                        [
                            'parent_id' => '9999',
                            'amount' => '100.00',
                            'gateway' => 'shopify_payments',
                        ],
                    ],
                ],
            ], 200),
            '*refunds.json' => Http::response([
                'refund' => [
                    'id' => '77777',
                    'order_id' => '5001',
                    'created_at' => now()->toIso8601String(),
                ],
            ], 200),
        ]);

        $response = $this->withStore()
            ->post("/orders/{$order->id}/process-item-return", [
                'items' => [
                    [
                        'order_item_id' => $orderItem->id,
                        'quantity' => 1,
                        'reason' => 'Return with platform sync',
                        'restock' => true,
                    ],
                ],
                'return_method' => 'in_store',
            ]);

        $response->assertRedirect();

        // Verify the return was created and synced
        $return = ProductReturn::where('order_id', $order->id)->first();
        $this->assertNotNull($return);
        $this->assertEquals('77777', $return->external_return_id);
        $this->assertEquals(ProductReturn::SYNC_STATUS_SYNCED, $return->sync_status);
    }

    public function test_return_with_shipped_method_sets_correct_type(): void
    {
        $this->actingAs($this->user);

        $customer = Customer::factory()->create(['store_id' => $this->store->id]);
        $product = Product::factory()->create(['store_id' => $this->store->id]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);

        $order = Order::factory()->create([
            'store_id' => $this->store->id,
            'customer_id' => $customer->id,
            'status' => Order::STATUS_COMPLETED,
        ]);

        $orderItem = OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'quantity' => 1,
            'price' => 100.00,
        ]);

        $response = $this->withStore()
            ->post("/orders/{$order->id}/process-item-return", [
                'items' => [
                    [
                        'order_item_id' => $orderItem->id,
                        'quantity' => 1,
                        'restock' => true,
                    ],
                ],
                'return_method' => 'shipped',
            ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('returns', [
            'order_id' => $order->id,
            'return_method' => ProductReturn::METHOD_SHIPPED,
        ]);
    }

    public function test_webhook_refund_import_matches_order_items_via_platform_order(): void
    {
        $marketplace = StoreMarketplace::factory()->shopify()->create([
            'store_id' => $this->store->id,
            'shop_domain' => 'test-store.myshopify.com',
            'access_token' => 'test-token',
            'status' => 'active',
        ]);

        $customer = Customer::factory()->create(['store_id' => $this->store->id]);
        $product = Product::factory()->create(['store_id' => $this->store->id]);
        $variant = ProductVariant::factory()->create([
            'product_id' => $product->id,
            'sku' => 'WEBHOOK-SKU-001',
        ]);

        $order = Order::factory()->create([
            'store_id' => $this->store->id,
            'customer_id' => $customer->id,
            'status' => Order::STATUS_COMPLETED,
            'source_platform' => 'shopify',
        ]);

        // Create platform order with line_items that include external_id
        $platformOrder = PlatformOrder::create([
            'store_marketplace_id' => $marketplace->id,
            'order_id' => $order->id,
            'external_order_id' => '9001',
            'external_order_number' => '2001',
            'status' => 'paid',
            'total' => 100.00,
            'subtotal' => 100.00,
            'ordered_at' => now(),
            'line_items' => [
                [
                    'external_id' => '987654321',
                    'sku' => 'WEBHOOK-SKU-001',
                    'title' => 'Test Product from Webhook',
                    'quantity' => 1,
                    'price' => '100.00',
                ],
            ],
        ]);

        $orderItem = OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'sku' => 'WEBHOOK-SKU-001',
            'title' => 'Test Product from Webhook',
            'quantity' => 1,
            'price' => 100.00,
        ]);

        // Simulate importing a refund via webhook using ReturnSyncService
        $returnSyncService = app(\App\Services\Returns\ReturnSyncService::class);

        // Mock the ShopifyReturnSyncer to return normalized data
        $normalizedData = [
            'external_return_id' => 'refund_12345',
            'external_order_id' => '9001',
            'status' => 'completed',
            'refund_amount' => 100.00,
            'items' => [
                [
                    'external_line_item_id' => '987654321',
                    'sku' => 'WEBHOOK-SKU-001',
                    'quantity' => 1,
                    'unit_price' => 100.00,
                    'restock' => true,
                ],
            ],
        ];

        // Use reflection to call the protected createReturnFromPayload method
        $reflection = new \ReflectionClass($returnSyncService);
        $method = $reflection->getMethod('createReturnFromPayload');
        $method->setAccessible(true);

        $return = $method->invoke(
            $returnSyncService,
            $normalizedData,
            $marketplace,
            \App\Enums\Platform::Shopify
        );

        // Verify the return was created and linked correctly
        $this->assertNotNull($return);
        $this->assertEquals('refund_12345', $return->external_return_id);
        $this->assertEquals($order->id, $return->order_id);

        // Verify the return item was linked to the correct order item
        $returnItem = $return->items()->first();
        $this->assertNotNull($returnItem);
        $this->assertEquals($orderItem->id, $returnItem->order_item_id);
        $this->assertEquals($variant->id, $returnItem->product_variant_id);
    }

    public function test_webhook_refund_import_creates_refund_payment_record(): void
    {
        $marketplace = StoreMarketplace::factory()->shopify()->create([
            'store_id' => $this->store->id,
            'shop_domain' => 'test-store.myshopify.com',
            'access_token' => 'test-token',
            'status' => 'active',
        ]);

        $customer = Customer::factory()->create(['store_id' => $this->store->id]);
        $product = Product::factory()->create(['store_id' => $this->store->id]);
        $variant = ProductVariant::factory()->create([
            'product_id' => $product->id,
            'sku' => 'REFUND-SKU-001',
        ]);

        $order = Order::factory()->create([
            'store_id' => $this->store->id,
            'customer_id' => $customer->id,
            'status' => Order::STATUS_COMPLETED,
            'source_platform' => 'shopify',
        ]);

        // Create platform order
        PlatformOrder::create([
            'store_marketplace_id' => $marketplace->id,
            'order_id' => $order->id,
            'external_order_id' => '9002',
            'external_order_number' => '2002',
            'status' => 'paid',
            'total' => 75.00,
            'subtotal' => 75.00,
            'ordered_at' => now(),
            'line_items' => [
                [
                    'external_id' => '111222333',
                    'sku' => 'REFUND-SKU-001',
                    'title' => 'Test Refund Product',
                    'quantity' => 1,
                    'price' => '75.00',
                ],
            ],
        ]);

        OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'sku' => 'REFUND-SKU-001',
            'quantity' => 1,
            'price' => 75.00,
        ]);

        $returnSyncService = app(\App\Services\Returns\ReturnSyncService::class);

        $normalizedData = [
            'external_return_id' => 'refund_99999',
            'external_order_id' => '9002',
            'status' => 'completed',
            'refund_amount' => 75.00,
            'items' => [
                [
                    'external_line_item_id' => '111222333',
                    'sku' => 'REFUND-SKU-001',
                    'quantity' => 1,
                    'unit_price' => 75.00,
                    'restock' => true,
                ],
            ],
        ];

        $reflection = new \ReflectionClass($returnSyncService);
        $method = $reflection->getMethod('createReturnFromPayload');
        $method->setAccessible(true);

        $return = $method->invoke(
            $returnSyncService,
            $normalizedData,
            $marketplace,
            \App\Enums\Platform::Shopify
        );

        // Verify the return was created
        $this->assertNotNull($return);
        $this->assertEquals(75.00, $return->refund_amount);

        // Verify a refund payment was created with negative amount
        $this->assertDatabaseHas('payments', [
            'order_id' => $order->id,
            'amount' => -75.00,
            'reference' => 'refund_refund_99999',
            'status' => 'completed',
        ]);

        // Verify the payment note mentions the return
        $payment = \App\Models\Payment::where('order_id', $order->id)
            ->where('amount', -75.00)
            ->first();

        $this->assertNotNull($payment);
        $this->assertStringContainsString('Refund from shopify', $payment->notes);
    }
}
