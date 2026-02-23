<?php

namespace App\Services\Platforms\Adapters;

use App\Contracts\Platforms\PlatformAdapterResult;
use App\Models\PlatformListing;

class WooCommerceAdapter extends BaseAdapter
{
    public function getPlatform(): string
    {
        return 'woocommerce';
    }

    public function isConnected(): bool
    {
        return $this->marketplace && $this->marketplace->access_token;
    }

    public function publish(PlatformListing $listing): PlatformAdapterResult
    {
        if (! $this->isConnected()) {
            return PlatformAdapterResult::failure('WooCommerce is not connected');
        }

        // TODO: Implement WooCommerce API

        return PlatformAdapterResult::failure('WooCommerce publishing not yet implemented');
    }

    public function unpublish(PlatformListing $listing): PlatformAdapterResult
    {
        return PlatformAdapterResult::failure('WooCommerce unpublishing not yet implemented');
    }

    public function end(PlatformListing $listing): PlatformAdapterResult
    {
        return PlatformAdapterResult::failure('WooCommerce end listing not yet implemented');
    }

    public function updatePrice(PlatformListing $listing, float $price): PlatformAdapterResult
    {
        return PlatformAdapterResult::failure('WooCommerce price update not yet implemented');
    }

    public function updateInventory(PlatformListing $listing, int $quantity): PlatformAdapterResult
    {
        return PlatformAdapterResult::failure('WooCommerce inventory update not yet implemented');
    }

    public function sync(PlatformListing $listing): PlatformAdapterResult
    {
        return $this->publish($listing);
    }

    public function refresh(PlatformListing $listing): PlatformAdapterResult
    {
        return PlatformAdapterResult::failure('WooCommerce refresh not yet implemented');
    }
}
