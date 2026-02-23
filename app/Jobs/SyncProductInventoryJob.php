<?php

namespace App\Jobs;

use App\Facades\Channel;
use App\Models\Activity;
use App\Models\ActivityLog;
use App\Models\PlatformListing;
use App\Models\Product;
use App\Traits\LogsJobExecution;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SyncProductInventoryJob implements ShouldQueue
{
    use LogsJobExecution, Queueable;

    public int $tries = 3;

    public int $timeout = 300; // 5 minutes

    public int $backoff = 30; // 30 seconds between retries

    protected int $storeId;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Product $product,
        public ?string $triggerReason = null,
    ) {
        $this->storeId = $product->store_id;
        $this->onQueue('inventory-sync');
    }

    /**
     * Get the store ID for logging.
     */
    protected function getStoreIdForLogging(): ?int
    {
        return $this->storeId;
    }

    /**
     * Get the payload for logging.
     */
    protected function getPayloadForLogging(): array
    {
        // Ensure variants are loaded for accurate quantity
        if (! $this->product->relationLoaded('variants')) {
            $this->product->load('variants');
        }

        // Get quantity from variants if available, otherwise use product quantity
        $quantity = $this->product->variants->isNotEmpty()
            ? $this->product->variants->sum('quantity')
            : ($this->product->quantity ?? 0);

        return [
            'queue' => 'inventory-sync',
            'product_id' => $this->product->id,
            'product_sku' => $this->product->variants->first()?->sku,
            'trigger_reason' => $this->triggerReason,
            'quantity' => $quantity,
        ];
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $this->startJobLog();

        try {
            // Refresh product with variants to get accurate total_quantity
            $this->product->load('variants');

            // Get quantity from variants if available, otherwise use product quantity
            $quantity = $this->product->variants->isNotEmpty()
                ? $this->product->variants->sum('quantity')
                : ($this->product->quantity ?? 0);
            $results = [
                'product_id' => $this->product->id,
                'quantity' => $quantity,
                'trigger' => $this->triggerReason,
                'listings_processed' => 0,
                'ended' => 0,
                'updated' => 0,
                'skipped' => 0,
                'failed' => 0,
                'errors' => [],
            ];

            Log::info("SyncProductInventoryJob: Starting sync for product {$this->product->id}, quantity: {$quantity}");

            // Get all listed listings for this product
            $listings = PlatformListing::where('product_id', $this->product->id)
                ->where('status', PlatformListing::STATUS_LISTED)
                ->with('salesChannel')
                ->get();

            $results['listings_processed'] = $listings->count();

            if ($listings->isEmpty()) {
                Log::info("SyncProductInventoryJob: No listed listings to sync for product {$this->product->id}");
                $this->completeJobLog($results);

                return;
            }

            foreach ($listings as $listing) {
                try {
                    $channelName = $listing->salesChannel?->name ?? 'Unknown';

                    if ($quantity <= 0) {
                        // End the listing on this platform
                        Log::info("SyncProductInventoryJob: Ending listing {$listing->id} on {$channelName} (quantity: 0)");

                        $result = Channel::listing($listing)->end();

                        if ($result->success) {
                            $results['ended']++;

                            ActivityLog::log(
                                Activity::LISTINGS_STATUS_CHANGE,
                                $listing,
                                null,
                                [
                                    'old_status' => PlatformListing::STATUS_LISTED,
                                    'new_status' => PlatformListing::STATUS_ENDED,
                                    'reason' => 'out_of_stock',
                                    'trigger' => $this->triggerReason,
                                ],
                                'Listing ended automatically due to zero inventory'
                            );
                        } else {
                            $results['failed']++;
                            $results['errors'][] = [
                                'listing_id' => $listing->id,
                                'channel' => $channelName,
                                'action' => 'end',
                                'error' => $result->message,
                            ];
                            Log::warning("SyncProductInventoryJob: Failed to end listing {$listing->id}: {$result->message}");
                        }
                    } else {
                        // Update inventory on this platform
                        Log::info("SyncProductInventoryJob: Updating inventory for listing {$listing->id} on {$channelName} to {$quantity}");

                        $result = Channel::listing($listing)->updateInventory($quantity);

                        if ($result->success) {
                            $results['updated']++;
                        } else {
                            $results['failed']++;
                            $results['errors'][] = [
                                'listing_id' => $listing->id,
                                'channel' => $channelName,
                                'action' => 'update_inventory',
                                'error' => $result->message,
                            ];
                            Log::warning("SyncProductInventoryJob: Failed to update inventory for listing {$listing->id}: {$result->message}");
                        }
                    }
                } catch (\Exception $e) {
                    $results['failed']++;
                    $results['errors'][] = [
                        'listing_id' => $listing->id,
                        'channel' => $listing->salesChannel?->name ?? 'Unknown',
                        'action' => $quantity <= 0 ? 'end' : 'update_inventory',
                        'error' => $e->getMessage(),
                    ];
                    Log::error("SyncProductInventoryJob: Exception processing listing {$listing->id}: {$e->getMessage()}");
                }
            }

            Log::info("SyncProductInventoryJob: Completed sync for product {$this->product->id}", $results);

            $this->completeJobLog($results);
        } catch (\Throwable $e) {
            Log::error("SyncProductInventoryJob: Fatal error for product {$this->product->id}: {$e->getMessage()}");
            $this->failJobLog($e);
            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(?\Throwable $exception): void
    {
        Log::error("SyncProductInventoryJob: Job failed for product {$this->product->id}", [
            'error' => $exception?->getMessage(),
            'trace' => $exception?->getTraceAsString(),
        ]);

        if ($exception) {
            $this->failJobLog($exception);
        }
    }
}
