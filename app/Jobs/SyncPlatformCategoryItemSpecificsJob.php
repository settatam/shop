<?php

namespace App\Jobs;

use App\Enums\Platform;
use App\Models\CategoryPlatformMapping;
use App\Services\Platforms\Ebay\EbayItemSpecificsService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Fetches item specifics from the platform's taxonomy API for the mapped category
 * and stores them locally. Currently supports eBay via the Taxonomy API.
 */
class SyncPlatformCategoryItemSpecificsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public int $backoff = 15;

    public function __construct(
        public CategoryPlatformMapping $mapping
    ) {
        $this->onQueue('default');
    }

    public function handle(EbayItemSpecificsService $itemSpecificsService): void
    {
        try {
            $platform = $this->mapping->platform;

            match ($platform) {
                Platform::Ebay => $this->syncEbayItemSpecifics($itemSpecificsService),
                default => Log::info("SyncPlatformCategoryItemSpecificsJob: No sync handler for platform {$platform->value}"),
            };

            $this->mapping->update(['item_specifics_synced_at' => now()]);

            Log::info("SyncPlatformCategoryItemSpecificsJob: Synced item specifics for mapping {$this->mapping->id}");
        } catch (\Throwable $e) {
            Log::error("SyncPlatformCategoryItemSpecificsJob: Failed for mapping {$this->mapping->id}: {$e->getMessage()}");
            throw $e;
        }
    }

    protected function syncEbayItemSpecifics(EbayItemSpecificsService $itemSpecificsService): void
    {
        $ebayCategoryId = $this->mapping->primary_category_id;
        $marketplace = $this->mapping->storeMarketplace;

        if (! $marketplace) {
            Log::warning("SyncPlatformCategoryItemSpecificsJob: No marketplace connection for mapping {$this->mapping->id}");

            return;
        }

        $itemSpecificsService->ensureItemSpecificsExist($ebayCategoryId, $marketplace);
    }
}
