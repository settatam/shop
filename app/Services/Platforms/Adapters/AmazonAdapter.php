<?php

namespace App\Services\Platforms\Adapters;

use App\Contracts\Platforms\PlatformAdapterResult;
use App\Models\PlatformListing;

class AmazonAdapter extends BaseAdapter
{
    public function getPlatform(): string
    {
        return 'amazon';
    }

    public function isConnected(): bool
    {
        return $this->marketplace && $this->marketplace->access_token;
    }

    public function publish(PlatformListing $listing): PlatformAdapterResult
    {
        if (! $this->isConnected()) {
            return PlatformAdapterResult::failure('Amazon is not connected');
        }

        // TODO: Implement Amazon API

        return PlatformAdapterResult::failure('Amazon publishing not yet implemented');
    }

    public function unpublish(PlatformListing $listing): PlatformAdapterResult
    {
        return PlatformAdapterResult::failure('Amazon unpublishing not yet implemented');
    }

    public function end(PlatformListing $listing): PlatformAdapterResult
    {
        return PlatformAdapterResult::failure('Amazon end listing not yet implemented');
    }

    public function updatePrice(PlatformListing $listing, float $price): PlatformAdapterResult
    {
        return PlatformAdapterResult::failure('Amazon price update not yet implemented');
    }

    public function updateInventory(PlatformListing $listing, int $quantity): PlatformAdapterResult
    {
        return PlatformAdapterResult::failure('Amazon inventory update not yet implemented');
    }

    public function sync(PlatformListing $listing): PlatformAdapterResult
    {
        return $this->publish($listing);
    }

    public function refresh(PlatformListing $listing): PlatformAdapterResult
    {
        return PlatformAdapterResult::failure('Amazon refresh not yet implemented');
    }
}
