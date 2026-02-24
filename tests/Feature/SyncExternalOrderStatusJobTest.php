<?php

namespace Tests\Feature;

use App\Enums\Platform;
use App\Jobs\SyncExternalOrderStatusJob;
use App\Models\Order;
use App\Models\PlatformOrder;
use App\Models\StoreMarketplace;
use App\Services\Marketplace\Connectors\ShopifyConnector;
use App\Services\Marketplace\Contracts\PlatformConnectorInterface;
use App\Services\Marketplace\DTOs\PlatformOrder as PlatformOrderDto;
use App\Services\Marketplace\PlatformConnectorManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class SyncExternalOrderStatusJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_updates_platform_order_from_connector_dto(): void
    {
        $marketplace = StoreMarketplace::factory()->shopify()->create();

        $order = Order::factory()->create([
            'store_id' => $marketplace->store_id,
            'status' => Order::STATUS_PENDING,
            'source_platform' => 'shopify',
        ]);

        $platformOrder = PlatformOrder::factory()->create([
            'store_marketplace_id' => $marketplace->id,
            'order_id' => $order->id,
            'external_order_id' => 'ext-123',
            'status' => 'pending',
            'fulfillment_status' => 'unfulfilled',
            'payment_status' => 'pending',
        ]);

        $dto = new PlatformOrderDto(
            externalId: 'ext-123',
            status: 'pending',
            fulfillmentStatus: 'unfulfilled',
            paymentStatus: 'paid',
            metadata: ['source' => 'api'],
        );

        $connector = Mockery::mock(PlatformConnectorInterface::class);
        $connector->shouldReceive('getOrder')
            ->with('ext-123')
            ->once()
            ->andReturn($dto);

        $connectorManager = Mockery::mock(PlatformConnectorManager::class);
        $connectorManager->shouldReceive('hasConnector')
            ->with(Platform::Shopify)
            ->once()
            ->andReturn(true);
        $connectorManager->shouldReceive('getConnectorForMarketplace')
            ->with(Mockery::on(fn ($m) => $m->id === $marketplace->id))
            ->once()
            ->andReturn($connector);

        $this->app->instance(PlatformConnectorManager::class, $connectorManager);

        $job = new SyncExternalOrderStatusJob($platformOrder);
        $job->handle($connectorManager, app(\App\Services\Webhooks\OrderImportService::class));

        $platformOrder->refresh();
        $this->assertEquals('paid', $platformOrder->payment_status);
        $this->assertNotNull($platformOrder->last_synced_at);

        $order->refresh();
        $this->assertEquals(Order::STATUS_CONFIRMED, $order->status);
    }

    public function test_job_skips_when_no_connector_available(): void
    {
        $marketplace = StoreMarketplace::factory()->ebay()->create();

        $platformOrder = PlatformOrder::factory()->create([
            'store_marketplace_id' => $marketplace->id,
            'external_order_id' => 'ext-456',
            'status' => 'pending',
        ]);

        $connectorManager = Mockery::mock(PlatformConnectorManager::class);
        $connectorManager->shouldReceive('hasConnector')
            ->with(Platform::Ebay)
            ->once()
            ->andReturn(false);
        $connectorManager->shouldNotReceive('getConnectorForMarketplace');

        Log::shouldReceive('debug')
            ->once()
            ->withArgs(fn ($msg) => str_contains($msg, 'No connector available'));

        $job = new SyncExternalOrderStatusJob($platformOrder);
        $job->handle($connectorManager, app(\App\Services\Webhooks\OrderImportService::class));
    }

    public function test_job_skips_when_dto_is_null(): void
    {
        $marketplace = StoreMarketplace::factory()->shopify()->create();

        $platformOrder = PlatformOrder::factory()->create([
            'store_marketplace_id' => $marketplace->id,
            'external_order_id' => 'ext-789',
            'status' => 'pending',
        ]);

        $connector = Mockery::mock(PlatformConnectorInterface::class);
        $connector->shouldReceive('getOrder')
            ->with('ext-789')
            ->once()
            ->andReturn(null);

        $connectorManager = Mockery::mock(PlatformConnectorManager::class);
        $connectorManager->shouldReceive('hasConnector')
            ->with(Platform::Shopify)
            ->andReturn(true);
        $connectorManager->shouldReceive('getConnectorForMarketplace')
            ->andReturn($connector);

        Log::shouldReceive('debug')
            ->once()
            ->withArgs(fn ($msg) => str_contains($msg, 'Could not fetch order'));

        $job = new SyncExternalOrderStatusJob($platformOrder);
        $job->handle($connectorManager, app(\App\Services\Webhooks\OrderImportService::class));
    }

    public function test_status_does_not_regress(): void
    {
        $marketplace = StoreMarketplace::factory()->shopify()->create();

        $order = Order::factory()->create([
            'store_id' => $marketplace->store_id,
            'status' => Order::STATUS_SHIPPED,
            'source_platform' => 'shopify',
        ]);

        $platformOrder = PlatformOrder::factory()->create([
            'store_marketplace_id' => $marketplace->id,
            'order_id' => $order->id,
            'external_order_id' => 'ext-regress',
            'status' => 'pending',
            'fulfillment_status' => 'unfulfilled',
            'payment_status' => 'paid',
        ]);

        // DTO with confirmed status which is lower than shipped
        $dto = new PlatformOrderDto(
            externalId: 'ext-regress',
            status: 'pending',
            fulfillmentStatus: 'unfulfilled',
            paymentStatus: 'paid',
        );

        $connector = Mockery::mock(PlatformConnectorInterface::class);
        $connector->shouldReceive('getOrder')->andReturn($dto);

        $connectorManager = Mockery::mock(PlatformConnectorManager::class);
        $connectorManager->shouldReceive('hasConnector')->andReturn(true);
        $connectorManager->shouldReceive('getConnectorForMarketplace')->andReturn($connector);

        $job = new SyncExternalOrderStatusJob($platformOrder);
        $job->handle($connectorManager, app(\App\Services\Webhooks\OrderImportService::class));

        $order->refresh();
        // Status should remain shipped since confirmed is a regression
        $this->assertEquals(Order::STATUS_SHIPPED, $order->status);
    }

    public function test_cancelled_status_can_always_be_set(): void
    {
        $marketplace = StoreMarketplace::factory()->shopify()->create();

        $order = Order::factory()->create([
            'store_id' => $marketplace->store_id,
            'status' => Order::STATUS_SHIPPED,
            'source_platform' => 'shopify',
        ]);

        $platformOrder = PlatformOrder::factory()->create([
            'store_marketplace_id' => $marketplace->id,
            'order_id' => $order->id,
            'external_order_id' => 'ext-cancel',
            'status' => 'pending',
            'fulfillment_status' => 'unfulfilled',
            'payment_status' => 'paid',
        ]);

        $dto = new PlatformOrderDto(
            externalId: 'ext-cancel',
            status: 'cancelled',
            fulfillmentStatus: 'unfulfilled',
            paymentStatus: 'pending',
        );

        $connector = Mockery::mock(PlatformConnectorInterface::class);
        $connector->shouldReceive('getOrder')->andReturn($dto);

        $connectorManager = Mockery::mock(PlatformConnectorManager::class);
        $connectorManager->shouldReceive('hasConnector')->andReturn(true);
        $connectorManager->shouldReceive('getConnectorForMarketplace')->andReturn($connector);

        $job = new SyncExternalOrderStatusJob($platformOrder);
        $job->handle($connectorManager, app(\App\Services\Webhooks\OrderImportService::class));

        $order->refresh();
        $this->assertEquals(Order::STATUS_CANCELLED, $order->status);
    }

    public function test_job_does_not_update_order_when_not_imported(): void
    {
        $marketplace = StoreMarketplace::factory()->shopify()->create();

        $platformOrder = PlatformOrder::factory()->create([
            'store_marketplace_id' => $marketplace->id,
            'order_id' => null,
            'external_order_id' => 'ext-no-order',
            'status' => 'pending',
            'fulfillment_status' => 'unfulfilled',
            'payment_status' => 'pending',
        ]);

        $dto = new PlatformOrderDto(
            externalId: 'ext-no-order',
            status: 'pending',
            fulfillmentStatus: 'fulfilled',
            paymentStatus: 'paid',
        );

        $connector = Mockery::mock(PlatformConnectorInterface::class);
        $connector->shouldReceive('getOrder')->andReturn($dto);

        $connectorManager = Mockery::mock(PlatformConnectorManager::class);
        $connectorManager->shouldReceive('hasConnector')->andReturn(true);
        $connectorManager->shouldReceive('getConnectorForMarketplace')->andReturn($connector);

        $job = new SyncExternalOrderStatusJob($platformOrder);
        $job->handle($connectorManager, app(\App\Services\Webhooks\OrderImportService::class));

        $platformOrder->refresh();
        $this->assertEquals('fulfilled', $platformOrder->fulfillment_status);
        $this->assertEquals('paid', $platformOrder->payment_status);
        $this->assertNotNull($platformOrder->last_synced_at);
    }

    public function test_completed_status_progresses_from_shipped(): void
    {
        $marketplace = StoreMarketplace::factory()->shopify()->create();

        $order = Order::factory()->create([
            'store_id' => $marketplace->store_id,
            'status' => Order::STATUS_SHIPPED,
            'source_platform' => 'shopify',
        ]);

        $platformOrder = PlatformOrder::factory()->create([
            'store_marketplace_id' => $marketplace->id,
            'order_id' => $order->id,
            'external_order_id' => 'ext-complete',
            'status' => 'pending',
        ]);

        $dto = new PlatformOrderDto(
            externalId: 'ext-complete',
            status: 'completed',
            fulfillmentStatus: 'fulfilled',
            paymentStatus: 'paid',
        );

        $connector = Mockery::mock(PlatformConnectorInterface::class);
        $connector->shouldReceive('getOrder')->andReturn($dto);

        $connectorManager = Mockery::mock(PlatformConnectorManager::class);
        $connectorManager->shouldReceive('hasConnector')->andReturn(true);
        $connectorManager->shouldReceive('getConnectorForMarketplace')->andReturn($connector);

        $job = new SyncExternalOrderStatusJob($platformOrder);
        $job->handle($connectorManager, app(\App\Services\Webhooks\OrderImportService::class));

        $order->refresh();
        $this->assertEquals(Order::STATUS_COMPLETED, $order->status);
    }

    public function test_job_is_dispatched_with_delay_from_webhook_import(): void
    {
        Queue::fake();

        $marketplace = StoreMarketplace::factory()->shopify()->create();

        $payload = [
            'id' => 12345,
            'order_number' => 1001,
            'financial_status' => 'paid',
            'fulfillment_status' => null,
            'total_price' => '100.00',
            'subtotal_price' => '80.00',
            'total_tax' => '8.00',
            'total_discounts' => '0',
            'currency' => 'USD',
            'created_at' => now()->toIso8601String(),
            'customer' => [
                'id' => 1,
                'email' => 'test@example.com',
                'first_name' => 'Test',
                'last_name' => 'User',
            ],
            'line_items' => [],
            'shipping_address' => null,
            'billing_address' => null,
            'total_shipping_price_set' => ['shop_money' => ['amount' => '12.00']],
        ];

        $importService = app(\App\Services\Webhooks\OrderImportService::class);
        $importService->importFromWebhookPayload($payload, $marketplace, Platform::Shopify);

        Queue::assertPushed(SyncExternalOrderStatusJob::class, function ($job) {
            return $job->delay !== null;
        });
    }

    public function test_sync_orders_propagates_status_to_linked_order(): void
    {
        $marketplace = StoreMarketplace::factory()->shopify()->create();

        $order = Order::factory()->create([
            'store_id' => $marketplace->store_id,
            'status' => Order::STATUS_PENDING,
            'source_platform' => 'shopify',
        ]);

        PlatformOrder::factory()->create([
            'store_marketplace_id' => $marketplace->id,
            'order_id' => $order->id,
            'external_order_id' => 'ext-sync-123',
            'status' => 'pending',
            'fulfillment_status' => 'unfulfilled',
            'payment_status' => 'pending',
        ]);

        $dto = new PlatformOrderDto(
            externalId: 'ext-sync-123',
            orderNumber: '1001',
            status: 'pending',
            fulfillmentStatus: 'unfulfilled',
            paymentStatus: 'paid',
            total: 100.00,
            subtotal: 80.00,
            orderedAt: now(),
        );

        $connector = Mockery::mock(PlatformConnectorInterface::class);
        $connector->shouldReceive('initialize')->andReturnSelf();
        $connector->shouldReceive('getOrders')
            ->once()
            ->andReturn([$dto]);

        $this->app->bind(ShopifyConnector::class, fn () => $connector);

        $connectorManager = app(PlatformConnectorManager::class);
        $result = $connectorManager->syncOrders($marketplace);

        $this->assertEquals(0, $result['errors']);
        $this->assertEquals(1, $result['synced']);

        $order->refresh();
        $this->assertEquals(Order::STATUS_CONFIRMED, $order->status);
    }

    public function test_refunded_status_can_always_be_set(): void
    {
        $marketplace = StoreMarketplace::factory()->shopify()->create();

        $order = Order::factory()->create([
            'store_id' => $marketplace->store_id,
            'status' => Order::STATUS_COMPLETED,
            'source_platform' => 'shopify',
        ]);

        $platformOrder = PlatformOrder::factory()->create([
            'store_marketplace_id' => $marketplace->id,
            'order_id' => $order->id,
            'external_order_id' => 'ext-refund',
            'status' => 'completed',
        ]);

        $dto = new PlatformOrderDto(
            externalId: 'ext-refund',
            status: 'pending',
            fulfillmentStatus: 'unfulfilled',
            paymentStatus: 'refunded',
        );

        $connector = Mockery::mock(PlatformConnectorInterface::class);
        $connector->shouldReceive('getOrder')->andReturn($dto);

        $connectorManager = Mockery::mock(PlatformConnectorManager::class);
        $connectorManager->shouldReceive('hasConnector')->andReturn(true);
        $connectorManager->shouldReceive('getConnectorForMarketplace')->andReturn($connector);

        $job = new SyncExternalOrderStatusJob($platformOrder);
        $job->handle($connectorManager, app(\App\Services\Webhooks\OrderImportService::class));

        $order->refresh();
        $this->assertEquals(Order::STATUS_REFUNDED, $order->status);
    }
}
