<?php

namespace App\Services\Platforms\Adapters;

use App\Contracts\Platforms\PlatformAdapterResult;
use App\Models\PlatformListing;

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

        // TODO: Implement Etsy API

        return PlatformAdapterResult::failure('Etsy publishing not yet implemented');
    }

    public function unpublish(PlatformListing $listing): PlatformAdapterResult
    {
        return PlatformAdapterResult::failure('Etsy unpublishing not yet implemented');
    }

    public function end(PlatformListing $listing): PlatformAdapterResult
    {
        return PlatformAdapterResult::failure('Etsy end listing not yet implemented');
    }

    public function updatePrice(PlatformListing $listing, float $price): PlatformAdapterResult
    {
        return PlatformAdapterResult::failure('Etsy price update not yet implemented');
    }

    public function updateInventory(PlatformListing $listing, int $quantity): PlatformAdapterResult
    {
        return PlatformAdapterResult::failure('Etsy inventory update not yet implemented');
    }

    public function sync(PlatformListing $listing): PlatformAdapterResult
    {
        return $this->publish($listing);
    }

    public function refresh(PlatformListing $listing): PlatformAdapterResult
    {
        return PlatformAdapterResult::failure('Etsy refresh not yet implemented');
    }
}
