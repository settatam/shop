<?php

namespace App\Console\Commands;

use App\Enums\Platform;
use App\Models\PlatformListing;
use App\Models\StoreMarketplace;
use App\Models\SyncLog;
use App\Services\Platforms\Ebay\EbayService;
use Illuminate\Console\Command;

class SyncEbayListingStatus extends Command
{
    protected $signature = 'ebay:sync-listing-status {--store= : Sync a specific store ID}';

    protected $description = 'Poll eBay offer statuses for all listed eBay listings';

    public function handle(EbayService $ebayService): int
    {
        $query = StoreMarketplace::query()
            ->where('platform', Platform::Ebay->value)
            ->where('status', 'active')
            ->where('connected_successfully', true);

        if ($storeId = $this->option('store')) {
            $query->where('store_id', $storeId);
        }

        $connections = $query->get();

        if ($connections->isEmpty()) {
            $this->info('No active eBay connections found.');

            return self::SUCCESS;
        }

        $this->info("Checking listing statuses for {$connections->count()} eBay connection(s)...");

        foreach ($connections as $connection) {
            $this->syncConnectionListings($ebayService, $connection);
        }

        $this->info('eBay listing status sync complete.');

        return self::SUCCESS;
    }

    protected function syncConnectionListings(EbayService $ebayService, StoreMarketplace $connection): void
    {
        $syncLog = SyncLog::start($connection->id, 'listing_status', 'pull');

        try {
            $ebayService->ensureValidToken($connection);

            $listings = PlatformListing::where('store_marketplace_id', $connection->id)
                ->where('status', PlatformListing::STATUS_LISTED)
                ->get();

            if ($listings->isEmpty()) {
                $syncLog->markCompleted(['message' => 'No listed listings to check']);
                $this->info("  Store #{$connection->store_id}: no listed listings.");

                return;
            }

            $changed = 0;

            foreach ($listings as $listing) {
                $syncLog->incrementProcessed();

                $statusChanged = $this->checkListingStatus($ebayService, $connection, $listing);

                if ($statusChanged) {
                    $changed++;
                }

                $syncLog->incrementSuccess();
            }

            $syncLog->markCompleted([
                'total' => $listings->count(),
                'changed' => $changed,
            ]);

            $this->info("  Store #{$connection->store_id}: checked {$listings->count()} listing(s), {$changed} status change(s).");
        } catch (\Throwable $e) {
            $syncLog->markFailed([$e->getMessage()]);
            $this->error("  Store #{$connection->store_id}: {$e->getMessage()}");
        }
    }

    protected function checkListingStatus(
        EbayService $ebayService,
        StoreMarketplace $connection,
        PlatformListing $listing
    ): bool {
        $isMultiVariant = $listing->platform_data['multi_variant'] ?? false;

        if ($isMultiVariant) {
            return $this->checkMultiVariantStatus($ebayService, $connection, $listing);
        }

        return $this->checkSingleVariantStatus($ebayService, $connection, $listing);
    }

    protected function checkSingleVariantStatus(
        EbayService $ebayService,
        StoreMarketplace $connection,
        PlatformListing $listing
    ): bool {
        $offerId = $listing->platform_data['offer_id'] ?? null;

        if (! $offerId) {
            return false;
        }

        $offerStatus = $ebayService->getOfferStatus($connection, $offerId);

        if (! $offerStatus) {
            $ebayService->updateListingStatusFromEbay($listing, PlatformListing::STATUS_ENDED);

            return true;
        }

        $newStatus = $this->mapEbayStatus($offerStatus['status']);

        if ($newStatus && $newStatus !== $listing->status) {
            $ebayService->updateListingStatusFromEbay($listing, $newStatus);

            return true;
        }

        return false;
    }

    protected function checkMultiVariantStatus(
        EbayService $ebayService,
        StoreMarketplace $connection,
        PlatformListing $listing
    ): bool {
        $offerIds = $listing->platform_data['offer_ids'] ?? [];
        $allEnded = true;

        foreach ($offerIds as $sku => $offerId) {
            $offerStatus = $ebayService->getOfferStatus($connection, $offerId);

            if ($offerStatus && $offerStatus['status'] === 'PUBLISHED') {
                $allEnded = false;

                break;
            }
        }

        if ($allEnded && ! empty($offerIds)) {
            $ebayService->updateListingStatusFromEbay($listing, PlatformListing::STATUS_ENDED);

            return true;
        }

        return false;
    }

    protected function mapEbayStatus(string $ebayStatus): ?string
    {
        return match ($ebayStatus) {
            'PUBLISHED' => PlatformListing::STATUS_LISTED,
            'ENDED', 'UNPUBLISHED' => PlatformListing::STATUS_ENDED,
            default => null,
        };
    }
}
