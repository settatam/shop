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

        // TODO: Implement eBay Inventory API
        // https://developer.ebay.com/api-docs/sell/inventory/overview.html

        return PlatformAdapterResult::failure('eBay publishing not yet implemented');
    }

    public function unpublish(PlatformListing $listing): PlatformAdapterResult
    {
        if (! $this->isConnected()) {
            return PlatformAdapterResult::failure('eBay is not connected');
        }

        // TODO: Implement eBay offer withdrawal

        return PlatformAdapterResult::failure('eBay unpublishing not yet implemented');
    }

    public function end(PlatformListing $listing): PlatformAdapterResult
    {
        // TODO: Implement eBay listing end

        return PlatformAdapterResult::failure('eBay end listing not yet implemented');
    }

    public function updatePrice(PlatformListing $listing, float $price): PlatformAdapterResult
    {
        // TODO: Implement eBay price update

        return PlatformAdapterResult::failure('eBay price update not yet implemented');
    }

    public function updateInventory(PlatformListing $listing, int $quantity): PlatformAdapterResult
    {
        if (! $this->isConnected()) {
            return PlatformAdapterResult::failure('eBay is not connected');
        }

        $sku = $listing->platform_data['sku'] ?? null;
        if (! $sku) {
            return PlatformAdapterResult::failure('No SKU found for this listing');
        }

        try {
            $ebayService = app(EbayService::class);
            $ebayService->ensureValidToken($this->marketplace);
            $ebayService->ebayRequest($this->marketplace, 'PUT', "/sell/inventory/v1/inventory_item/{$sku}", [
                'availability' => [
                    'shipToLocationAvailability' => [
                        'quantity' => $quantity,
                    ],
                ],
            ]);

            $this->log('inventory_updated', [
                'listing_id' => $listing->id,
                'sku' => $sku,
                'quantity' => $quantity,
            ]);

            return PlatformAdapterResult::success('Inventory updated', ['quantity' => $quantity]);
        } catch (\Throwable $e) {
            $this->log('inventory_update_failed', [
                'listing_id' => $listing->id,
                'sku' => $sku,
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
        // TODO: Implement eBay listing refresh

        return PlatformAdapterResult::failure('eBay refresh not yet implemented');
    }
}
