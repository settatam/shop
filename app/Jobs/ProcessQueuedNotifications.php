<?php

namespace App\Jobs;

use App\Models\QueuedNotification;
use App\Models\Store;
use App\Services\Notifications\NotificationManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProcessQueuedNotifications implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Get all ready-to-send notifications grouped by store
        $ready = QueuedNotification::getReadyToSend();

        $byStore = $ready->groupBy('store_id');

        foreach ($byStore as $storeId => $notifications) {
            $store = Store::find($storeId);

            if (! $store) {
                continue;
            }

            $manager = new NotificationManager($store);

            foreach ($notifications as $queued) {
                try {
                    $subscription = $queued->subscription;

                    if (! $subscription || ! $subscription->is_enabled) {
                        $queued->markAsFailed('Subscription not found or disabled');

                        continue;
                    }

                    $data = $queued->data;
                    $data['store'] = $store;

                    $manager->sendSubscription($subscription, $data, $queued->notifiable);
                    $queued->markAsSent();
                } catch (\Exception $e) {
                    Log::error('Failed to process queued notification', [
                        'queued_id' => $queued->id,
                        'error' => $e->getMessage(),
                    ]);

                    $queued->markAsFailed($e->getMessage());
                }
            }
        }
    }
}
