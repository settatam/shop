<?php

namespace Tests\Feature;

use App\Enums\Platform;
use App\Jobs\ProcessWebhookJob;
use App\Models\Order;
use App\Models\PlatformOrder;
use App\Models\Store;
use App\Models\StoreMarketplace;
use App\Models\WebhookLog;
use App\Services\Webhooks\OrderImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WebhookTest extends TestCase
{
    use RefreshDatabase;

    protected Store $store;

    protected StoreMarketplace $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = Store::factory()->create();
        $this->connection = StoreMarketplace::factory()->shopify()->create([
            'store_id' => $this->store->id,
        ]);
    }

    public function test_shopify_webhook_is_logged_and_queued(): void
    {
        Queue::fake();

        $payload = $this->getShopifyOrderPayload();

        $response = $this->postJson(
            "/api/webhooks/shopify/{$this->connection->id}",
            $payload,
            [
                'X-Shopify-Topic' => 'orders/create',
                'X-Shopify-Hmac-Sha256' => $this->calculateShopifyHmac($payload),
            ]
        );

        $response->assertStatus(200)
            ->assertJson(['status' => 'queued']);

        $this->assertDatabaseHas('webhook_logs', [
            'store_marketplace_id' => $this->connection->id,
            'store_id' => $this->store->id,
            'event_type' => 'orders/create',
            'status' => WebhookLog::STATUS_PENDING,
        ]);

        Queue::assertPushed(ProcessWebhookJob::class);
    }

    public function test_webhook_returns_404_for_invalid_connection(): void
    {
        $response = $this->postJson(
            '/api/webhooks/shopify/99999',
            $this->getShopifyOrderPayload()
        );

        $response->assertStatus(404);
    }

    public function test_webhook_returns_400_for_inactive_connection(): void
    {
        $inactiveConnection = StoreMarketplace::factory()->shopify()->inactive()->create([
            'store_id' => $this->store->id,
        ]);

        $response = $this->postJson(
            "/api/webhooks/shopify/{$inactiveConnection->id}",
            $this->getShopifyOrderPayload()
        );

        $response->assertStatus(400);
    }

    public function test_non_order_events_are_skipped(): void
    {
        Queue::fake();

        $payload = ['product' => ['id' => 123]];

        $response = $this->postJson(
            "/api/webhooks/shopify/{$this->connection->id}",
            $payload,
            [
                'X-Shopify-Topic' => 'products/create',
                'X-Shopify-Hmac-Sha256' => $this->calculateShopifyHmac($payload),
            ]
        );

        $response->assertStatus(200)
            ->assertJson(['status' => 'skipped']);

        $this->assertDatabaseHas('webhook_logs', [
            'store_marketplace_id' => $this->connection->id,
            'status' => WebhookLog::STATUS_SKIPPED,
        ]);

        Queue::assertNotPushed(ProcessWebhookJob::class);
    }

    public function test_ebay_webhook_is_processed(): void
    {
        Queue::fake();

        $ebayConnection = StoreMarketplace::factory()->ebay()->create([
            'store_id' => $this->store->id,
        ]);

        $payload = $this->getEbayOrderPayload();

        $response = $this->postJson(
            "/api/webhooks/ebay/{$ebayConnection->id}",
            $payload
        );

        $response->assertStatus(200);
        Queue::assertPushed(ProcessWebhookJob::class);
    }

    public function test_woocommerce_webhook_is_processed(): void
    {
        Queue::fake();

        $wooConnection = StoreMarketplace::factory()->woocommerce()->create([
            'store_id' => $this->store->id,
        ]);

        $payload = $this->getWooCommerceOrderPayload();

        $response = $this->postJson(
            "/api/webhooks/woocommerce/{$wooConnection->id}",
            $payload,
            ['X-WC-Webhook-Topic' => 'order.created']
        );

        $response->assertStatus(200);
        Queue::assertPushed(ProcessWebhookJob::class);
    }

    public function test_order_import_service_creates_order_from_shopify_payload(): void
    {
        $service = app(OrderImportService::class);

        $payload = $this->getShopifyOrderPayload();

        $order = $service->importFromWebhookPayload(
            $payload,
            $this->connection,
            Platform::Shopify
        );

        $this->assertInstanceOf(Order::class, $order);
        $this->assertEquals($this->store->id, $order->store_id);
        $this->assertEquals('shopify', $order->source_platform);
        $this->assertNotNull($order->external_marketplace_id);
        $this->assertCount(1, $order->items);

        $this->assertDatabaseHas('platform_orders', [
            'store_marketplace_id' => $this->connection->id,
            'external_order_id' => (string) $payload['id'],
            'order_id' => $order->id,
        ]);
    }

    public function test_order_import_service_creates_customer(): void
    {
        $service = app(OrderImportService::class);

        $payload = $this->getShopifyOrderPayload();

        $order = $service->importFromWebhookPayload(
            $payload,
            $this->connection,
            Platform::Shopify
        );

        $this->assertNotNull($order->customer_id);
        $this->assertDatabaseHas('customers', [
            'store_id' => $this->store->id,
            'email' => $payload['customer']['email'],
        ]);
    }

    public function test_order_import_service_handles_duplicate_orders(): void
    {
        $service = app(OrderImportService::class);

        $payload = $this->getShopifyOrderPayload();

        $order1 = $service->importFromWebhookPayload(
            $payload,
            $this->connection,
            Platform::Shopify
        );

        $order2 = $service->importFromWebhookPayload(
            $payload,
            $this->connection,
            Platform::Shopify
        );

        $this->assertEquals($order1->id, $order2->id);
        $this->assertDatabaseCount('platform_orders', 1);
    }

    public function test_platform_order_is_created_from_webhook(): void
    {
        $service = app(OrderImportService::class);

        $payload = $this->getShopifyOrderPayload();

        $service->importFromWebhookPayload(
            $payload,
            $this->connection,
            Platform::Shopify
        );

        $platformOrder = PlatformOrder::where('external_order_id', (string) $payload['id'])->first();

        $this->assertNotNull($platformOrder);
        $this->assertEquals($this->connection->id, $platformOrder->store_marketplace_id);
        $this->assertTrue($platformOrder->isImported());
        $this->assertNotNull($platformOrder->last_synced_at);
    }

    public function test_webhook_log_status_transitions(): void
    {
        $webhookLog = WebhookLog::factory()->pending()->create([
            'store_id' => $this->store->id,
            'store_marketplace_id' => $this->connection->id,
        ]);

        $this->assertTrue($webhookLog->isPending());

        $webhookLog->markAsProcessing();
        $this->assertTrue($webhookLog->fresh()->isProcessing());

        $webhookLog->markAsCompleted(['test' => true]);
        $webhookLog->refresh();
        $this->assertTrue($webhookLog->isCompleted());
        $this->assertNotNull($webhookLog->processed_at);
        $this->assertEquals(['test' => true], $webhookLog->response);
    }

    public function test_webhook_log_failure_tracking(): void
    {
        $webhookLog = WebhookLog::factory()->pending()->create([
            'store_id' => $this->store->id,
            'store_marketplace_id' => $this->connection->id,
        ]);

        $webhookLog->markAsFailed('Test error');
        $webhookLog->refresh();

        $this->assertTrue($webhookLog->isFailed());
        $this->assertEquals('Test error', $webhookLog->error_message);
        $this->assertEquals(1, $webhookLog->retry_count);
        $this->assertTrue($webhookLog->canRetry());

        $webhookLog->markAsFailed('Error 2');
        $webhookLog->markAsFailed('Error 3');
        $webhookLog->refresh();

        $this->assertEquals(3, $webhookLog->retry_count);
        $this->assertFalse($webhookLog->canRetry());
    }

    public function test_order_import_from_ebay_payload(): void
    {
        $service = app(OrderImportService::class);

        $ebayConnection = StoreMarketplace::factory()->ebay()->create([
            'store_id' => $this->store->id,
        ]);

        $payload = $this->getEbayOrderPayload();

        $order = $service->importFromWebhookPayload(
            $payload,
            $ebayConnection,
            Platform::Ebay
        );

        $this->assertInstanceOf(Order::class, $order);
        $this->assertEquals('ebay', $order->source_platform);
    }

    public function test_order_import_from_woocommerce_payload(): void
    {
        $service = app(OrderImportService::class);

        $wooConnection = StoreMarketplace::factory()->woocommerce()->create([
            'store_id' => $this->store->id,
        ]);

        $payload = $this->getWooCommerceOrderPayload();

        $order = $service->importFromWebhookPayload(
            $payload,
            $wooConnection,
            Platform::WooCommerce
        );

        $this->assertInstanceOf(Order::class, $order);
        $this->assertEquals('woocommerce', $order->source_platform);
        $this->assertEquals(Order::STATUS_CONFIRMED, $order->status);
    }

    protected function getShopifyOrderPayload(): array
    {
        return [
            'id' => 5678901234567,
            'order_number' => 1001,
            'name' => '#1001',
            'total_price' => '150.00',
            'subtotal_price' => '140.00',
            'total_tax' => '10.00',
            'total_discounts' => '0.00',
            'currency' => 'USD',
            'financial_status' => 'paid',
            'fulfillment_status' => null,
            'created_at' => now()->toIso8601String(),
            'customer' => [
                'id' => 123456789,
                'email' => 'test@example.com',
                'first_name' => 'John',
                'last_name' => 'Doe',
                'phone' => '+1234567890',
            ],
            'shipping_address' => [
                'first_name' => 'John',
                'last_name' => 'Doe',
                'address1' => '123 Main St',
                'address2' => 'Apt 4',
                'city' => 'New York',
                'province_code' => 'NY',
                'zip' => '10001',
                'country_code' => 'US',
                'phone' => '+1234567890',
            ],
            'billing_address' => [
                'first_name' => 'John',
                'last_name' => 'Doe',
                'address1' => '123 Main St',
                'city' => 'New York',
                'province_code' => 'NY',
                'zip' => '10001',
                'country_code' => 'US',
            ],
            'line_items' => [
                [
                    'id' => 987654321,
                    'title' => 'Test Product',
                    'quantity' => 2,
                    'price' => '70.00',
                    'sku' => 'TEST-SKU-001',
                    'variant_id' => 111222333,
                    'product_id' => 444555666,
                    'total_discount' => '0.00',
                    'tax_lines' => [],
                ],
            ],
            'total_shipping_price_set' => [
                'shop_money' => [
                    'amount' => '0.00',
                    'currency_code' => 'USD',
                ],
            ],
        ];
    }

    protected function getEbayOrderPayload(): array
    {
        return [
            'orderId' => 'eBay-Order-12345',
            'orderFulfillmentStatus' => 'NOT_STARTED',
            'orderPaymentStatus' => 'PAID',
            'creationDate' => now()->toIso8601String(),
            'pricingSummary' => [
                'total' => ['value' => '100.00', 'currency' => 'USD'],
                'priceSubtotal' => ['value' => '90.00', 'currency' => 'USD'],
                'deliveryCost' => ['value' => '10.00', 'currency' => 'USD'],
                'tax' => ['value' => '0.00', 'currency' => 'USD'],
            ],
            'buyer' => [
                'username' => 'testbuyer123',
                'email' => 'ebaybuyer@example.com',
            ],
            'fulfillmentStartInstructions' => [
                [
                    'shippingStep' => [
                        'shipTo' => [
                            'fullName' => 'Jane Smith',
                            'addressLine1' => '456 Oak Ave',
                            'city' => 'Los Angeles',
                            'stateOrProvince' => 'CA',
                            'postalCode' => '90001',
                            'countryCode' => 'US',
                        ],
                    ],
                ],
            ],
            'lineItems' => [
                [
                    'lineItemId' => 'item-123',
                    'title' => 'eBay Product',
                    'quantity' => 1,
                    'lineItemCost' => ['value' => '90.00'],
                    'sku' => 'EBAY-SKU-001',
                ],
            ],
        ];
    }

    protected function getWooCommerceOrderPayload(): array
    {
        return [
            'id' => 12345,
            'number' => '12345',
            'status' => 'processing',
            'currency' => 'USD',
            'total' => '125.00',
            'subtotal' => '100.00',
            'shipping_total' => '15.00',
            'total_tax' => '10.00',
            'discount_total' => '0.00',
            'date_created' => now()->toIso8601String(),
            'date_paid' => now()->toIso8601String(),
            'customer_id' => 5,
            'billing' => [
                'first_name' => 'Mike',
                'last_name' => 'Johnson',
                'email' => 'mike@example.com',
                'phone' => '+1987654321',
                'address_1' => '789 Pine St',
                'city' => 'Chicago',
                'state' => 'IL',
                'postcode' => '60601',
                'country' => 'US',
            ],
            'shipping' => [
                'first_name' => 'Mike',
                'last_name' => 'Johnson',
                'address_1' => '789 Pine St',
                'city' => 'Chicago',
                'state' => 'IL',
                'postcode' => '60601',
                'country' => 'US',
            ],
            'line_items' => [
                [
                    'id' => 1,
                    'name' => 'WooCommerce Product',
                    'product_id' => 100,
                    'variation_id' => 0,
                    'quantity' => 2,
                    'price' => 50.00,
                    'subtotal' => '100.00',
                    'total' => '100.00',
                    'total_tax' => '10.00',
                    'sku' => 'WOO-SKU-001',
                ],
            ],
        ];
    }

    protected function calculateShopifyHmac(array $payload): string
    {
        $secret = $this->connection->credentials['webhook_secret'] ?? 'test-secret';

        return base64_encode(
            hash_hmac('sha256', json_encode($payload), $secret, true)
        );
    }
}
