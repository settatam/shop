<?php

namespace App\Jobs;

use App\Facades\Channel;
use App\Models\Activity;
use App\Models\ActivityLog;
use App\Models\PlatformListing;
use App\Models\SalesChannel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class DeactivateSalesChannelJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 600; // 10 minutes

    /**
     * Create a new job instance.
     */
    public function __construct(
        public SalesChannel $channel,
        public ?int $userId = null,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $listings = PlatformListing::where('sales_channel_id', $this->channel->id)
            ->where('status', PlatformListing::STATUS_LISTED)
            ->get();

        $total = $listings->count();
        $success = 0;
        $failed = 0;

        Log::info("Deactivating sales channel {$this->channel->id}: ending {$total} listings");

        foreach ($listings as $listing) {
            try {
                // Call platform API to end the listing
                $result = Channel::listing($listing)->end();

                if ($result->success) {
                    $success++;

                    ActivityLog::log(
                        Activity::LISTINGS_STATUS_CHANGE,
                        $listing,
                        $this->userId,
                        [
                            'old_status' => PlatformListing::STATUS_LISTED,
                            'new_status' => PlatformListing::STATUS_ENDED,
                            'reason' => 'channel_deactivated',
                        ],
                        'Listing ended due to channel deactivation'
                    );
                } else {
                    $failed++;
                    Log::warning("Failed to end listing {$listing->id}: {$result->message}");

                    // Still update status locally even if platform API fails
                    $listing->update([
                        'status' => PlatformListing::STATUS_ENDED,
                        'last_error' => "Channel deactivated but platform delist failed: {$result->message}",
                    ]);
                }
            } catch (\Exception $e) {
                $failed++;
                Log::error("Exception ending listing {$listing->id}: {$e->getMessage()}");

                // Update status locally
                $listing->update([
                    'status' => PlatformListing::STATUS_ENDED,
                    'last_error' => "Channel deactivated but platform delist failed: {$e->getMessage()}",
                ]);
            }
        }

        Log::info("Channel {$this->channel->id} deactivation complete: {$success} succeeded, {$failed} failed out of {$total}");
    }
}
