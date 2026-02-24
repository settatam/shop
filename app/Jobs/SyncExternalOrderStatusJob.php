<?php

namespace App\Jobs;

use App\Models\PlatformOrder;
use App\Services\Marketplace\PlatformConnectorManager;
use App\Services\Webhooks\OrderImportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncExternalOrderStatusJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     */
    public int $backoff = 30;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public PlatformOrder $platformOrder
    ) {}

    /**
     * Execute the job.
     */
    public function handle(PlatformConnectorManager $connectorManager, OrderImportService $importService): void
    {
        $marketplace = $this->platformOrder->marketplace;

        if (! $marketplace) {
            Log::debug('SyncExternalOrderStatus: No marketplace found for platform order', [
                'platform_order_id' => $this->platformOrder->id,
            ]);

            return;
        }

        if (! $connectorManager->hasConnector($marketplace->platform)) {
            Log::debug('SyncExternalOrderStatus: No connector available for platform', [
                'platform' => $marketplace->platform->value,
                'platform_order_id' => $this->platformOrder->id,
            ]);

            return;
        }

        try {
            $connector = $connectorManager->getConnectorForMarketplace($marketplace);
        } catch (\Throwable $e) {
            Log::warning('SyncExternalOrderStatus: Failed to initialize connector', [
                'platform' => $marketplace->platform->value,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $dto = $connector->getOrder($this->platformOrder->external_order_id);

        if (! $dto) {
            Log::debug('SyncExternalOrderStatus: Could not fetch order from platform', [
                'platform' => $marketplace->platform->value,
                'external_order_id' => $this->platformOrder->external_order_id,
            ]);

            return;
        }

        $importService->syncOrderFromDto($this->platformOrder, $dto, $marketplace->platform);

        // If the order has refund-related payment status, sync returns
        if (in_array($dto->paymentStatus, ['refunded', 'partially_refunded'])) {
            SyncOrderReturnsJob::dispatch($this->platformOrder)->delay(now()->addSeconds(10));
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('SyncExternalOrderStatusJob failed', [
            'platform_order_id' => $this->platformOrder->id,
            'error' => $exception->getMessage(),
        ]);
    }
}
