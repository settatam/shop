<?php

namespace App\Jobs;

use App\Models\PlatformOrder;
use App\Models\ProductReturn;
use App\Services\Platforms\Shopify\ShopifyService;
use App\Services\Returns\ReturnSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncOrderReturnsJob implements ShouldQueue
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
    public function handle(ReturnSyncService $returnSyncService): void
    {
        if (! $this->platformOrder->isImported()) {
            return;
        }

        $marketplace = $this->platformOrder->marketplace;

        if (! $marketplace) {
            return;
        }

        $platformService = match ($marketplace->platform->value) {
            'shopify' => app(ShopifyService::class),
            default => null,
        };

        if (! $platformService) {
            Log::debug('SyncOrderReturns: No platform service available', [
                'platform' => $marketplace->platform->value,
                'platform_order_id' => $this->platformOrder->id,
            ]);

            return;
        }

        try {
            $refunds = $platformService->getOrderRefunds($this->platformOrder);
        } catch (\Throwable $e) {
            Log::warning('SyncOrderReturns: Failed to fetch refunds from platform', [
                'platform_order_id' => $this->platformOrder->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        if ($refunds->isEmpty()) {
            return;
        }

        foreach ($refunds as $refund) {
            $externalReturnId = (string) ($refund['id'] ?? '');

            $existingReturn = ProductReturn::where('external_return_id', $externalReturnId)
                ->where('store_marketplace_id', $marketplace->id)
                ->first();

            if ($existingReturn) {
                continue;
            }

            $returnSyncService->importFromWebhook($refund, $marketplace, $marketplace->platform);
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('SyncOrderReturnsJob failed', [
            'platform_order_id' => $this->platformOrder->id,
            'error' => $exception->getMessage(),
        ]);
    }
}
