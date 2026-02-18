<?php

namespace App\Console\Commands;

use App\Models\Store;
use App\Services\Shipping\TrackingService;
use Illuminate\Console\Command;

class UpdateShipmentTracking extends Command
{
    protected $signature = 'shipments:update-tracking
                            {--store= : Specific store ID to update}
                            {--type= : Type of shipments to update (outbound, return, all)}';

    protected $description = 'Poll carrier APIs to update tracking status for active shipments';

    public function handle(TrackingService $trackingService): int
    {
        $storeId = $this->option('store');
        $type = $this->option('type') ?? 'all';

        if ($storeId) {
            $store = Store::find($storeId);

            if (! $store) {
                $this->error("Store not found: {$storeId}");

                return self::FAILURE;
            }

            $this->info("Updating tracking for store: {$store->name}");
            $result = $trackingService->updateStoreShipments($store);
        } else {
            $this->info('Updating tracking for all stores...');
            $result = $trackingService->updateAllActiveShipments();
        }

        $this->newLine();
        $this->info('Tracking Update Complete');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Outbound kits updated', $result['outbound_updated']],
                ['Return shipments updated', $result['return_updated']],
                ['Errors', $result['errors']],
            ]
        );

        if ($result['errors'] > 0) {
            $this->warn('Some shipments failed to update. Check logs for details.');
        }

        return self::SUCCESS;
    }
}
