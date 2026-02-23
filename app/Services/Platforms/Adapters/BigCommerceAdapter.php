<?php

namespace App\Services\Platforms\Adapters;

use App\Contracts\Platforms\PlatformAdapterResult;
use App\Models\PlatformListing;

class BigCommerceAdapter extends BaseAdapter
{
    public function getPlatform(): string
    {
        return 'bigcommerce';
    }

    public function isConnected(): bool
    {
        return $this->marketplace && $this->marketplace->access_token;
    }

    public function publish(PlatformListing $listing): PlatformAdapterResult
    {
        if (! $this->isConnected()) {
            return PlatformAdapterResult::failure('BigCommerce is not connected');
        }

        // TODO: Implement BigCommerce API

        return PlatformAdapterResult::failure('BigCommerce publishing not yet implemented');
    }

    public function unpublish(PlatformListing $listing): PlatformAdapterResult
    {
        return PlatformAdapterResult::failure('BigCommerce unpublishing not yet implemented');
    }

    public function end(PlatformListing $listing): PlatformAdapterResult
    {
        return PlatformAdapterResult::failure('BigCommerce end listing not yet implemented');
    }

    public function updatePrice(PlatformListing $listing, float $price): PlatformAdapterResult
    {
        return PlatformAdapterResult::failure('BigCommerce price update not yet implemented');
    }

    public function updateInventory(PlatformListing $listing, int $quantity): PlatformAdapterResult
    {
        return PlatformAdapterResult::failure('BigCommerce inventory update not yet implemented');
    }

    public function sync(PlatformListing $listing): PlatformAdapterResult
    {
        return $this->publish($listing);
    }

    public function refresh(PlatformListing $listing): PlatformAdapterResult
    {
        return PlatformAdapterResult::failure('BigCommerce refresh not yet implemented');
    }
}
