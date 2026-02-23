<?php

namespace App\Contracts\Platforms;

use App\Models\PlatformListing;

interface PlatformAdapterContract
{
    /**
     * Publish a listing to the platform.
     */
    public function publish(PlatformListing $listing): PlatformAdapterResult;

    /**
     * Unpublish/deactivate a listing on the platform.
     */
    public function unpublish(PlatformListing $listing): PlatformAdapterResult;

    /**
     * End/delete a listing from the platform.
     */
    public function end(PlatformListing $listing): PlatformAdapterResult;

    /**
     * Update the price on the platform.
     */
    public function updatePrice(PlatformListing $listing, float $price): PlatformAdapterResult;

    /**
     * Update the inventory/quantity on the platform.
     */
    public function updateInventory(PlatformListing $listing, int $quantity): PlatformAdapterResult;

    /**
     * Sync all listing data to the platform.
     */
    public function sync(PlatformListing $listing): PlatformAdapterResult;

    /**
     * Refresh listing data from the platform.
     */
    public function refresh(PlatformListing $listing): PlatformAdapterResult;

    /**
     * Get the platform identifier.
     */
    public function getPlatform(): string;

    /**
     * Check if the platform connection is valid.
     */
    public function isConnected(): bool;
}
