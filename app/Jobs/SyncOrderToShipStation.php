<?php

namespace App\Jobs;

use App\Models\Order;
use App\Services\ShipStation\ShipStationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncOrderToShipStation implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     */
    public int $backoff = 60;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Order $order
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Get ShipStation service for the order's store
        $service = ShipStationService::forStore($this->order->store_id);

        // Check if ShipStation is configured and auto-sync is enabled
        if (! $service->isConfigured()) {
            Log::debug('ShipStation not configured for store', [
                'store_id' => $this->order->store_id,
                'order_id' => $this->order->id,
            ]);

            return;
        }

        if (! $service->isAutoSyncEnabled()) {
            Log::debug('ShipStation auto-sync disabled', [
                'store_id' => $this->order->store_id,
                'order_id' => $this->order->id,
            ]);

            return;
        }

        // Skip if order already has a ShipStation reference
        if ($this->order->shipstation_store) {
            Log::debug('Order already synced to ShipStation', [
                'order_id' => $this->order->id,
                'shipstation_store' => $this->order->shipstation_store,
            ]);

            return;
        }

        // Create order in ShipStation
        $result = $service->createOrder($this->order);

        if ($result['success']) {
            // Store the ShipStation order ID reference
            $this->order->update([
                'shipstation_store' => $result['order_id'],
            ]);

            Log::info('Order synced to ShipStation', [
                'order_id' => $this->order->id,
                'shipstation_order_id' => $result['order_id'],
            ]);
        } else {
            Log::warning('Failed to sync order to ShipStation', [
                'order_id' => $this->order->id,
                'error' => $result['error'],
            ]);

            // If it's a retryable error, throw an exception to retry the job
            if ($this->attempts() < $this->tries) {
                throw new \RuntimeException("ShipStation sync failed: {$result['error']}");
            }
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('SyncOrderToShipStation job failed', [
            'order_id' => $this->order->id,
            'error' => $exception->getMessage(),
        ]);
    }
}
