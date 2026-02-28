<?php

namespace App\Services\Platforms\Adapters;

use App\Contracts\Platforms\PlatformAdapterResult;
use App\Models\PlatformListing;
use App\Services\Platforms\Etsy\EtsyService;

class EtsyAdapter extends BaseAdapter
{
    public function getPlatform(): string
    {
        return 'etsy';
    }

    public function isConnected(): bool
    {
        return $this->marketplace && $this->marketplace->access_token;
    }

    public function publish(PlatformListing $listing): PlatformAdapterResult
    {
        if (! $this->isConnected()) {
            return PlatformAdapterResult::failure('Etsy is not connected');
        }

        $product = $listing->product;
        if (! $product) {
            return PlatformAdapterResult::failure('No product found for this listing');
        }

        try {
            $service = app(EtsyService::class);
            $service->ensureValidToken($this->marketplace);

            $shopId = $this->marketplace->credentials['shop_id'];
            $listingData = $service->mapToEtsyListing($product, $this->marketplace);

            $response = $service->etsyRequest(
                $this->marketplace,
                'POST',
                "/application/shops/{$shopId}/listings",
                $listingData
            );

            $listingId = (string) $response['listing_id'];

            $listing->update([
                'external_listing_id' => $listingId,
                'status' => ($response['state'] ?? '') === 'active'
                    ? PlatformListing::STATUS_LISTED
                    : PlatformListing::STATUS_PENDING,
                'listing_url' => $response['url'] ?? "https://www.etsy.com/listing/{$listingId}",
                'last_synced_at' => now(),
            ]);

            $this->log('published', [
                'listing_id' => $listing->id,
                'etsy_listing_id' => $listingId,
            ]);

            return PlatformAdapterResult::created($listingId, $listing->listing_url, ['listing_id' => $listingId]);
        } catch (\Throwable $e) {
            $this->log('publish_failed', [
                'listing_id' => $listing->id,
                'error' => $e->getMessage(),
            ]);

            return PlatformAdapterResult::failure("Failed to publish to Etsy: {$e->getMessage()}", $e);
        }
    }

    public function unpublish(PlatformListing $listing): PlatformAdapterResult
    {
        if (! $this->isConnected()) {
            return PlatformAdapterResult::failure('Etsy is not connected');
        }

        $listingId = $listing->external_listing_id;
        if (! $listingId) {
            return PlatformAdapterResult::failure('No listing ID found for this listing');
        }

        try {
            $service = app(EtsyService::class);
            $service->ensureValidToken($this->marketplace);

            $shopId = $this->marketplace->credentials['shop_id'];

            $service->etsyRequest(
                $this->marketplace,
                'PUT',
                "/application/shops/{$shopId}/listings/{$listingId}",
                ['state' => 'inactive']
            );

            $listing->update([
                'status' => PlatformListing::STATUS_ENDED,
                'last_synced_at' => now(),
            ]);

            $this->log('unpublished', [
                'listing_id' => $listing->id,
                'etsy_listing_id' => $listingId,
            ]);

            return PlatformAdapterResult::success('Listing deactivated on Etsy');
        } catch (\Throwable $e) {
            $this->log('unpublish_failed', [
                'listing_id' => $listing->id,
                'error' => $e->getMessage(),
            ]);

            return PlatformAdapterResult::failure("Failed to unpublish from Etsy: {$e->getMessage()}", $e);
        }
    }

    public function end(PlatformListing $listing): PlatformAdapterResult
    {
        if (! $this->isConnected()) {
            return PlatformAdapterResult::failure('Etsy is not connected');
        }

        $listingId = $listing->external_listing_id;
        if (! $listingId) {
            return PlatformAdapterResult::failure('No listing ID found for this listing');
        }

        try {
            $service = app(EtsyService::class);
            $service->ensureValidToken($this->marketplace);

            $service->etsyRequest(
                $this->marketplace,
                'DELETE',
                "/application/listings/{$listingId}"
            );

            $listing->update([
                'status' => PlatformListing::STATUS_ENDED,
                'last_synced_at' => now(),
            ]);

            $this->log('ended', [
                'listing_id' => $listing->id,
                'etsy_listing_id' => $listingId,
            ]);

            return PlatformAdapterResult::success('Listing deleted from Etsy');
        } catch (\Throwable $e) {
            $this->log('end_failed', [
                'listing_id' => $listing->id,
                'error' => $e->getMessage(),
            ]);

            return PlatformAdapterResult::failure("Failed to end Etsy listing: {$e->getMessage()}", $e);
        }
    }

    public function updatePrice(PlatformListing $listing, float $price): PlatformAdapterResult
    {
        if (! $this->isConnected()) {
            return PlatformAdapterResult::failure('Etsy is not connected');
        }

        $listingId = $listing->external_listing_id;
        if (! $listingId) {
            return PlatformAdapterResult::failure('No listing ID found for this listing');
        }

        try {
            $service = app(EtsyService::class);
            $service->ensureValidToken($this->marketplace);

            $service->etsyRequest(
                $this->marketplace,
                'PUT',
                "/application/listings/{$listingId}/inventory",
                [
                    'products' => [[
                        'offerings' => [[
                            'price' => [
                                'amount' => (int) ($price * 100),
                                'divisor' => 100,
                                'currency_code' => 'USD',
                            ],
                            'quantity' => $listing->platform_quantity ?? 1,
                            'is_enabled' => true,
                        ]],
                    ]],
                ]
            );

            $this->log('price_updated', [
                'listing_id' => $listing->id,
                'etsy_listing_id' => $listingId,
                'price' => $price,
            ]);

            return PlatformAdapterResult::success('Price updated', ['price' => $price]);
        } catch (\Throwable $e) {
            $this->log('price_update_failed', [
                'listing_id' => $listing->id,
                'error' => $e->getMessage(),
            ]);

            return PlatformAdapterResult::failure("Failed to update Etsy price: {$e->getMessage()}", $e);
        }
    }

    public function updateInventory(PlatformListing $listing, int $quantity): PlatformAdapterResult
    {
        if (! $this->isConnected()) {
            return PlatformAdapterResult::failure('Etsy is not connected');
        }

        $listingId = $listing->external_listing_id;
        if (! $listingId) {
            return PlatformAdapterResult::failure('No listing ID found for this listing');
        }

        try {
            $service = app(EtsyService::class);
            $service->ensureValidToken($this->marketplace);

            $service->etsyRequest(
                $this->marketplace,
                'PUT',
                "/application/listings/{$listingId}/inventory",
                [
                    'products' => [[
                        'offerings' => [[
                            'price' => [
                                'amount' => (int) (($listing->getEffectivePrice() ?? 0) * 100),
                                'divisor' => 100,
                                'currency_code' => 'USD',
                            ],
                            'quantity' => $quantity,
                            'is_enabled' => true,
                        ]],
                    ]],
                ]
            );

            $this->log('inventory_updated', [
                'listing_id' => $listing->id,
                'etsy_listing_id' => $listingId,
                'quantity' => $quantity,
            ]);

            return PlatformAdapterResult::success('Inventory updated', ['quantity' => $quantity]);
        } catch (\Throwable $e) {
            $this->log('inventory_update_failed', [
                'listing_id' => $listing->id,
                'error' => $e->getMessage(),
            ]);

            return PlatformAdapterResult::failure("Failed to update Etsy inventory: {$e->getMessage()}", $e);
        }
    }

    public function sync(PlatformListing $listing): PlatformAdapterResult
    {
        return $this->publish($listing);
    }

    public function refresh(PlatformListing $listing): PlatformAdapterResult
    {
        if (! $this->isConnected()) {
            return PlatformAdapterResult::failure('Etsy is not connected');
        }

        $listingId = $listing->external_listing_id;
        if (! $listingId) {
            return PlatformAdapterResult::failure('No listing ID found for this listing');
        }

        try {
            $service = app(EtsyService::class);
            $service->ensureValidToken($this->marketplace);

            $response = $service->etsyRequest(
                $this->marketplace,
                'GET',
                "/application/listings/{$listingId}"
            );

            $etsyState = $response['state'] ?? null;
            $newStatus = match ($etsyState) {
                'active' => PlatformListing::STATUS_LISTED,
                'inactive', 'expired', 'removed' => PlatformListing::STATUS_ENDED,
                'draft' => PlatformListing::STATUS_PENDING,
                default => $listing->status,
            };

            $listing->update([
                'status' => $newStatus,
                'platform_data' => array_merge($listing->platform_data ?? [], [
                    'etsy_state' => $etsyState,
                    'title' => $response['title'] ?? null,
                    'views' => $response['views'] ?? null,
                ]),
                'last_synced_at' => now(),
            ]);

            $this->log('refreshed', [
                'listing_id' => $listing->id,
                'etsy_listing_id' => $listingId,
                'etsy_state' => $etsyState,
                'mapped_status' => $newStatus,
            ]);

            return PlatformAdapterResult::success('Listing refreshed', [
                'etsy_state' => $etsyState,
                'status' => $newStatus,
            ]);
        } catch (\Throwable $e) {
            $this->log('refresh_failed', [
                'listing_id' => $listing->id,
                'error' => $e->getMessage(),
            ]);

            return PlatformAdapterResult::failure("Failed to refresh Etsy listing: {$e->getMessage()}", $e);
        }
    }
}
