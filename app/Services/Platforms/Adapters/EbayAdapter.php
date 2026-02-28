<?php

namespace App\Services\Platforms\Adapters;

use App\Contracts\Platforms\PlatformAdapterResult;
use App\Models\PlatformListing;
use App\Services\Platforms\Ebay\EbayService;

class EbayAdapter extends BaseAdapter
{
    public function getPlatform(): string
    {
        return 'ebay';
    }

    public function isConnected(): bool
    {
        return $this->marketplace
            && $this->marketplace->access_token;
    }

    public function publish(PlatformListing $listing): PlatformAdapterResult
    {
        if (! $this->isConnected()) {
            return PlatformAdapterResult::failure('eBay is not connected');
        }

        try {
            $ebayService = app(EbayService::class);
            $product = $listing->product;

            $listing = $ebayService->pushProduct($product, $this->marketplace);

            $this->log('published', [
                'listing_id' => $listing->id,
                'external_listing_id' => $listing->external_listing_id,
            ]);

            return PlatformAdapterResult::created(
                $listing->external_listing_id,
                $listing->listing_url,
                ['platform_data' => $listing->platform_data]
            );
        } catch (\Throwable $e) {
            $this->log('publish_failed', [
                'listing_id' => $listing->id,
                'error' => $e->getMessage(),
            ]);

            return PlatformAdapterResult::failure("Failed to publish to eBay: {$e->getMessage()}", $e);
        }
    }

    public function unpublish(PlatformListing $listing): PlatformAdapterResult
    {
        if (! $this->isConnected()) {
            return PlatformAdapterResult::failure('eBay is not connected');
        }

        try {
            $ebayService = app(EbayService::class);
            $ebayService->unlistListing($listing);

            $this->log('unpublished', ['listing_id' => $listing->id]);

            return PlatformAdapterResult::success('Listing withdrawn from eBay');
        } catch (\Throwable $e) {
            $this->log('unpublish_failed', [
                'listing_id' => $listing->id,
                'error' => $e->getMessage(),
            ]);

            return PlatformAdapterResult::failure("Failed to unpublish from eBay: {$e->getMessage()}", $e);
        }
    }

    public function end(PlatformListing $listing): PlatformAdapterResult
    {
        if (! $this->isConnected()) {
            return PlatformAdapterResult::failure('eBay is not connected');
        }

        try {
            $ebayService = app(EbayService::class);
            $ebayService->deleteListing($listing);

            $this->log('ended', ['listing_id' => $listing->id]);

            return PlatformAdapterResult::success('Listing ended and removed from eBay');
        } catch (\Throwable $e) {
            $this->log('end_failed', [
                'listing_id' => $listing->id,
                'error' => $e->getMessage(),
            ]);

            return PlatformAdapterResult::failure("Failed to end eBay listing: {$e->getMessage()}", $e);
        }
    }

    public function updatePrice(PlatformListing $listing, float $price): PlatformAdapterResult
    {
        if (! $this->isConnected()) {
            return PlatformAdapterResult::failure('eBay is not connected');
        }

        try {
            $ebayService = app(EbayService::class);
            $ebayService->ensureValidToken($this->marketplace);

            $isMultiVariant = $listing->platform_data['multi_variant'] ?? false;

            if ($isMultiVariant) {
                $listing->loadMissing('listingVariants');
                foreach ($listing->platform_data['offer_ids'] ?? [] as $sku => $offerId) {
                    $this->updateOfferPrice($ebayService, $offerId, $price);
                }
            } else {
                $offerId = $listing->platform_data['offer_id'] ?? null;
                if (! $offerId) {
                    return PlatformAdapterResult::failure('No offer ID found for this listing');
                }
                $this->updateOfferPrice($ebayService, $offerId, $price);
            }

            $this->log('price_updated', [
                'listing_id' => $listing->id,
                'price' => $price,
            ]);

            return PlatformAdapterResult::success('Price updated', ['price' => $price]);
        } catch (\Throwable $e) {
            $this->log('price_update_failed', [
                'listing_id' => $listing->id,
                'error' => $e->getMessage(),
            ]);

            return PlatformAdapterResult::failure("Failed to update eBay price: {$e->getMessage()}", $e);
        }
    }

    public function updateInventory(PlatformListing $listing, int $quantity): PlatformAdapterResult
    {
        if (! $this->isConnected()) {
            return PlatformAdapterResult::failure('eBay is not connected');
        }

        try {
            $ebayService = app(EbayService::class);
            $ebayService->ensureValidToken($this->marketplace);

            $isMultiVariant = $listing->platform_data['multi_variant'] ?? false;

            if ($isMultiVariant) {
                foreach ($listing->platform_data['variant_skus'] ?? [] as $sku) {
                    $ebayService->ebayRequest($this->marketplace, 'PUT', "/sell/inventory/v1/inventory_item/{$sku}", [
                        'availability' => [
                            'shipToLocationAvailability' => [
                                'quantity' => $quantity,
                            ],
                        ],
                    ]);
                }
            } else {
                $sku = $listing->platform_data['sku'] ?? null;
                if (! $sku) {
                    return PlatformAdapterResult::failure('No SKU found for this listing');
                }

                $ebayService->ebayRequest($this->marketplace, 'PUT', "/sell/inventory/v1/inventory_item/{$sku}", [
                    'availability' => [
                        'shipToLocationAvailability' => [
                            'quantity' => $quantity,
                        ],
                    ],
                ]);
            }

            $this->log('inventory_updated', [
                'listing_id' => $listing->id,
                'quantity' => $quantity,
            ]);

            return PlatformAdapterResult::success('Inventory updated', ['quantity' => $quantity]);
        } catch (\Throwable $e) {
            $this->log('inventory_update_failed', [
                'listing_id' => $listing->id,
                'error' => $e->getMessage(),
            ]);

            return PlatformAdapterResult::failure("Failed to update eBay inventory: {$e->getMessage()}", $e);
        }
    }

    public function sync(PlatformListing $listing): PlatformAdapterResult
    {
        return $this->publish($listing);
    }

    public function refresh(PlatformListing $listing): PlatformAdapterResult
    {
        if (! $this->isConnected()) {
            return PlatformAdapterResult::failure('eBay is not connected');
        }

        try {
            $ebayService = app(EbayService::class);
            $ebayService->ensureValidToken($this->marketplace);

            $isMultiVariant = $listing->platform_data['multi_variant'] ?? false;

            if ($isMultiVariant) {
                $groupKey = $listing->platform_data['group_key'] ?? null;
                if (! $groupKey) {
                    return PlatformAdapterResult::failure('No inventory item group key found');
                }

                $groupData = $ebayService->ebayRequest(
                    $this->marketplace,
                    'GET',
                    "/sell/inventory/v1/inventory_item_group/{$groupKey}"
                );

                $this->log('refreshed', ['listing_id' => $listing->id, 'group_key' => $groupKey]);

                return PlatformAdapterResult::success('Listing refreshed', [
                    'group_data' => $groupData,
                    'variant_skus' => $groupData['variantSKUs'] ?? [],
                ]);
            }

            $offerId = $listing->platform_data['offer_id'] ?? null;
            if (! $offerId) {
                return PlatformAdapterResult::failure('No offer ID found for this listing');
            }

            $offerData = $ebayService->ebayRequest(
                $this->marketplace,
                'GET',
                "/sell/inventory/v1/offer/{$offerId}"
            );

            $this->log('refreshed', ['listing_id' => $listing->id, 'offer_id' => $offerId]);

            return PlatformAdapterResult::success('Listing refreshed', [
                'status' => $offerData['status'] ?? null,
                'price' => $offerData['pricingSummary']['price']['value'] ?? null,
                'quantity' => $offerData['availableQuantity'] ?? null,
                'listing_id' => $offerData['listing']['listingId'] ?? null,
            ]);
        } catch (\Throwable $e) {
            $this->log('refresh_failed', [
                'listing_id' => $listing->id,
                'error' => $e->getMessage(),
            ]);

            return PlatformAdapterResult::failure("Failed to refresh eBay listing: {$e->getMessage()}", $e);
        }
    }

    /**
     * Update the price on a single eBay offer.
     */
    protected function updateOfferPrice(EbayService $ebayService, string $offerId, float $price): void
    {
        $marketplaceId = $this->marketplace->settings['marketplace_id'] ?? 'EBAY_US';
        $currency = $ebayService->getCurrencyForMarketplace($marketplaceId);

        $ebayService->ebayRequest($this->marketplace, 'PUT', "/sell/inventory/v1/offer/{$offerId}", [
            'pricingSummary' => [
                'price' => [
                    'value' => (string) round($price, 2),
                    'currency' => $currency,
                ],
            ],
        ]);
    }
}
