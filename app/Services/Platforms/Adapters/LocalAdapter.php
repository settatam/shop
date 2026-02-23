<?php

namespace App\Services\Platforms\Adapters;

use App\Contracts\Platforms\PlatformAdapterResult;
use App\Models\PlatformListing;

/**
 * Adapter for local/in-store sales channels.
 * These don't require external API calls - products are always "listed" locally.
 */
class LocalAdapter extends BaseAdapter
{
    public function getPlatform(): string
    {
        return 'local';
    }

    public function isConnected(): bool
    {
        return true; // Local is always connected
    }

    public function publish(PlatformListing $listing): PlatformAdapterResult
    {
        // Local listings are immediately active
        return PlatformAdapterResult::success('Product available for in-store sales');
    }

    public function unpublish(PlatformListing $listing): PlatformAdapterResult
    {
        return PlatformAdapterResult::success('Product removed from in-store availability');
    }

    public function end(PlatformListing $listing): PlatformAdapterResult
    {
        return PlatformAdapterResult::success('Listing ended');
    }

    public function updatePrice(PlatformListing $listing, float $price): PlatformAdapterResult
    {
        // Price is managed locally, no external sync needed
        return PlatformAdapterResult::success('Price updated', ['price' => $price]);
    }

    public function updateInventory(PlatformListing $listing, int $quantity): PlatformAdapterResult
    {
        // Inventory is managed locally
        return PlatformAdapterResult::success('Inventory updated', ['quantity' => $quantity]);
    }

    public function sync(PlatformListing $listing): PlatformAdapterResult
    {
        $product = $listing->product;
        $variant = $product?->variants?->first();

        return PlatformAdapterResult::success('Synced', [
            'price' => $variant?->price ?? 0,
            'quantity' => $product?->total_quantity ?? 0,
        ]);
    }

    public function refresh(PlatformListing $listing): PlatformAdapterResult
    {
        $product = $listing->product;
        $variant = $product?->variants?->first();

        return PlatformAdapterResult::success('Refreshed', [
            'status' => PlatformListing::STATUS_ACTIVE,
            'price' => $variant?->price ?? 0,
            'quantity' => $product?->total_quantity ?? 0,
        ]);
    }
}
