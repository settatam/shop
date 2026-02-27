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

/**
 * Push the current inventory quantity for a product to all listed marketplace platforms.
 *
 * This job is the single point of outbound inventory synchronization. It is dispatched
 * automatically by Inventory::booted() whenever an inventory record is saved or deleted,
 * ensuring that every stock change — sales, manual adjustments, transfers, corrections —
 * is propagated to all external platforms.
 *
 * Behavior:
 * - Loads all PlatformListing records with status "listed" for the product.
 * - If the product's total quantity is zero → ends each listing via the platform adapter.
 * - If the product has stock → calls updateInventory() on each platform adapter.
 * - Results (updated/ended/failed counts) are logged to the job execution log.
 *
 * Dispatched from:
 * - Inventory::booted() saved/deleted hooks (automatic, covers all inventory changes)
 * - Product::syncInventoryToAllPlatforms() (explicit call from services)
 * - OrderCreationService::reduceStock() / restoreStock()
 * - OrderImportService::createOrderItems()
 *
 * Queue: inventory-sync | Retries: 3 | Backoff: 30s | Timeout: 5min
 */
class SyncProductInventoryJob implements ShouldQueue
{
    use LogsJobExecution, Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    public int $backoff = 30;

    protected int $storeId;

    public function __construct(
        public Product $product,
        public ?string $triggerReason = null,
    ) {
        $this->storeId = $product->store_id;
        $this->onQueue('inventory-sync');
    }

    protected function getStoreIdForLogging(): ?int
    {
        return $this->storeId;
    }

    /**
     * Build the payload written to the job execution log at start.
     *
     * @return array{queue: string, product_id: int, product_sku: string|null, trigger_reason: string|null, quantity: int}
     */
    protected function getPayloadForLogging(): array
    {
        if (! $this->product->relationLoaded('variants')) {
            $this->product->load('variants');
        }

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
     * Sync inventory to all listed platforms for this product.
     *
     * For each listing:
     * - quantity > 0 → Channel::listing($listing)->updateInventory($quantity)
     * - quantity = 0 → Channel::listing($listing)->end() and log status change
     */
    public function handle(): void
    {
        $this->startJobLog();

        try {
            $this->product->load('variants');

            $results = [
                'product_id' => $this->product->id,
                'trigger' => $this->triggerReason,
                'listings_processed' => 0,
                'ended' => 0,
                'updated' => 0,
                'skipped' => 0,
                'failed' => 0,
                'errors' => [],
            ];

            Log::info("SyncProductInventoryJob: Starting sync for product {$this->product->id}");

            $listings = PlatformListing::where('product_id', $this->product->id)
                ->whereIn('status', [PlatformListing::STATUS_LISTED, PlatformListing::STATUS_ACTIVE])
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
                    $effectiveQuantity = $listing->getEffectiveQuantity();

                    if ($effectiveQuantity <= 0) {
                        Log::info("SyncProductInventoryJob: Ending listing {$listing->id} on {$channelName} (effective quantity: 0)");

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
                        Log::info("SyncProductInventoryJob: Updating inventory for listing {$listing->id} on {$channelName} to {$effectiveQuantity}");

                        $result = Channel::listing($listing)->updateInventory($effectiveQuantity);

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
     * Handle permanent failure after all retries exhausted.
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
