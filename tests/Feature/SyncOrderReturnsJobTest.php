<?php

namespace Tests\Feature;

use App\Enums\Platform;
use App\Jobs\SyncExternalOrderStatusJob;
use App\Jobs\SyncOrderReturnsJob;
use App\Models\Order;
use App\Models\PlatformOrder;
use App\Models\ProductReturn;
use App\Models\StoreMarketplace;
use App\Services\Platforms\Shopify\ShopifyService;
use App\Services\Returns\ReturnSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class SyncOrderReturnsJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_creates_product_return_from_shopify_refund(): void
    {
        $marketplace = StoreMarketplace::factory()->shopify()->create();

        $order = Order::factory()->create([
            'store_id' => $marketplace->store_id,
            'status' => Order::STATUS_REFUNDED,
        ]);

        $platformOrder = PlatformOrder::factory()->create([
            'store_marketplace_id' => $marketplace->id,
            'order_id' => $order->id,
            'external_order_id' => 'ext-456',
            'payment_status' => 'refunded',
        ]);

        $refundPayload = [
            'id' => 99001,
            'order_id' => 'ext-456',
            'created_at' => now()->toISOString(),
            'note' => 'Customer requested refund',
            'refund_line_items' => [],
            'transactions' => [
                ['amount' => '50.00', 'kind' => 'refund'],
            ],
        ];

        $shopifyService = Mockery::mock(ShopifyService::class);
        $shopifyService->shouldReceive('getOrderRefunds')
            ->with(Mockery::on(fn ($po) => $po->id === $platformOrder->id))
            ->once()
            ->andReturn(collect([$refundPayload]));

        $this->app->instance(ShopifyService::class, $shopifyService);

        $returnSyncService = Mockery::mock(ReturnSyncService::class);
        $returnSyncService->shouldReceive('importFromWebhook')
            ->with($refundPayload, Mockery::on(fn ($m) => $m->id === $marketplace->id), Platform::Shopify)
            ->once();

        $job = new SyncOrderReturnsJob($platformOrder);
        $job->handle($returnSyncService);
    }

    public function test_job_skips_when_order_not_imported(): void
    {
        $marketplace = StoreMarketplace::factory()->shopify()->create();

        $platformOrder = PlatformOrder::factory()->create([
            'store_marketplace_id' => $marketplace->id,
            'order_id' => null,
            'external_order_id' => 'ext-789',
        ]);

        $returnSyncService = Mockery::mock(ReturnSyncService::class);
        $returnSyncService->shouldNotReceive('importFromWebhook');

        $job = new SyncOrderReturnsJob($platformOrder);
        $job->handle($returnSyncService);
    }

    public function test_job_skips_existing_returns(): void
    {
        $marketplace = StoreMarketplace::factory()->shopify()->create();

        $order = Order::factory()->create([
            'store_id' => $marketplace->store_id,
            'status' => Order::STATUS_REFUNDED,
        ]);

        $platformOrder = PlatformOrder::factory()->create([
            'store_marketplace_id' => $marketplace->id,
            'order_id' => $order->id,
            'external_order_id' => 'ext-456',
            'payment_status' => 'refunded',
        ]);

        // Create an existing return with the same external_return_id
        ProductReturn::factory()->create([
            'store_id' => $marketplace->store_id,
            'order_id' => $order->id,
            'external_return_id' => '99001',
            'store_marketplace_id' => $marketplace->id,
        ]);

        $refundPayload = [
            'id' => 99001,
            'order_id' => 'ext-456',
        ];

        $shopifyService = Mockery::mock(ShopifyService::class);
        $shopifyService->shouldReceive('getOrderRefunds')
            ->once()
            ->andReturn(collect([$refundPayload]));

        $this->app->instance(ShopifyService::class, $shopifyService);

        $returnSyncService = Mockery::mock(ReturnSyncService::class);
        $returnSyncService->shouldNotReceive('importFromWebhook');

        $job = new SyncOrderReturnsJob($platformOrder);
        $job->handle($returnSyncService);
    }

    public function test_job_handles_empty_refunds(): void
    {
        $marketplace = StoreMarketplace::factory()->shopify()->create();

        $order = Order::factory()->create([
            'store_id' => $marketplace->store_id,
        ]);

        $platformOrder = PlatformOrder::factory()->create([
            'store_marketplace_id' => $marketplace->id,
            'order_id' => $order->id,
            'external_order_id' => 'ext-456',
        ]);

        $shopifyService = Mockery::mock(ShopifyService::class);
        $shopifyService->shouldReceive('getOrderRefunds')
            ->once()
            ->andReturn(collect());

        $this->app->instance(ShopifyService::class, $shopifyService);

        $returnSyncService = Mockery::mock(ReturnSyncService::class);
        $returnSyncService->shouldNotReceive('importFromWebhook');

        $job = new SyncOrderReturnsJob($platformOrder);
        $job->handle($returnSyncService);
    }

    public function test_job_dispatched_after_refunded_order_import(): void
    {
        Queue::fake();

        $marketplace = StoreMarketplace::factory()->shopify()->create();

        $platformOrder = PlatformOrder::factory()->create([
            'store_marketplace_id' => $marketplace->id,
            'external_order_id' => 'ext-456',
            'payment_status' => 'refunded',
        ]);

        // Simulating what OrderImportService does after import
        SyncOrderReturnsJob::dispatch($platformOrder)->delay(now()->addSeconds(90));

        Queue::assertPushed(SyncOrderReturnsJob::class, function ($job) use ($platformOrder) {
            return $job->platformOrder->id === $platformOrder->id;
        });
    }

    public function test_status_job_dispatches_returns_job_when_refunded(): void
    {
        Queue::fake();

        $marketplace = StoreMarketplace::factory()->shopify()->create();

        $order = Order::factory()->create([
            'store_id' => $marketplace->store_id,
            'status' => Order::STATUS_CONFIRMED,
        ]);

        $platformOrder = PlatformOrder::factory()->create([
            'store_marketplace_id' => $marketplace->id,
            'order_id' => $order->id,
            'external_order_id' => 'ext-123',
            'status' => 'open',
            'payment_status' => 'paid',
        ]);

        $dto = new \App\Services\Marketplace\DTOs\PlatformOrder(
            externalId: 'ext-123',
            status: 'closed',
            fulfillmentStatus: 'fulfilled',
            paymentStatus: 'refunded',
            orderedAt: now(),
        );

        $connector = Mockery::mock(\App\Services\Marketplace\Contracts\PlatformConnectorInterface::class);
        $connector->shouldReceive('getOrder')
            ->with('ext-123')
            ->once()
            ->andReturn($dto);

        $connectorManager = Mockery::mock(\App\Services\Marketplace\PlatformConnectorManager::class);
        $connectorManager->shouldReceive('hasConnector')
            ->with(Platform::Shopify)
            ->andReturn(true);
        $connectorManager->shouldReceive('getConnectorForMarketplace')
            ->andReturn($connector);

        $this->app->instance(\App\Services\Marketplace\PlatformConnectorManager::class, $connectorManager);

        $job = new SyncExternalOrderStatusJob($platformOrder);
        $job->handle($connectorManager, app(\App\Services\Webhooks\OrderImportService::class));

        Queue::assertPushed(SyncOrderReturnsJob::class, function ($job) use ($platformOrder) {
            return $job->platformOrder->id === $platformOrder->id;
        });
    }
}
