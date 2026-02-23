<?php

namespace App\Services\Platforms\Adapters;

use App\Contracts\Platforms\PlatformAdapterContract;
use App\Models\PlatformListing;
use App\Models\SalesChannel;
use App\Models\StoreMarketplace;

abstract class BaseAdapter implements PlatformAdapterContract
{
    protected SalesChannel $channel;

    protected ?StoreMarketplace $marketplace;

    public function __construct(SalesChannel $channel)
    {
        $this->channel = $channel;
        $this->marketplace = $channel->storeMarketplace;
    }

    /**
     * Get the sales channel.
     */
    public function getChannel(): SalesChannel
    {
        return $this->channel;
    }

    /**
     * Get the marketplace connection.
     */
    public function getMarketplace(): ?StoreMarketplace
    {
        return $this->marketplace;
    }

    /**
     * Get credentials from the marketplace.
     */
    protected function getCredential(string $key, mixed $default = null): mixed
    {
        return $this->marketplace?->credentials[$key] ?? $default;
    }

    /**
     * Get a setting from the channel.
     */
    protected function getSetting(string $key, mixed $default = null): mixed
    {
        return $this->channel->settings[$key] ?? $default;
    }

    /**
     * Log an action for debugging.
     */
    protected function log(string $action, array $context = []): void
    {
        logger()->channel('platforms')->info("[{$this->getPlatform()}] {$action}", array_merge([
            'channel_id' => $this->channel->id,
            'marketplace_id' => $this->marketplace?->id,
        ], $context));
    }

    /**
     * Build product data for the platform from a listing.
     */
    protected function buildProductData(PlatformListing $listing): array
    {
        $product = $listing->product;
        $variant = $product?->variants?->first();

        return [
            'title' => $product?->title,
            'description' => $product?->description,
            'price' => $listing->platform_price ?? $variant?->price ?? 0,
            'quantity' => $listing->platform_quantity ?? $product?->total_quantity ?? 0,
            'sku' => $variant?->sku,
            'barcode' => $variant?->barcode,
            'weight' => $product?->weight,
            'images' => $product?->images?->pluck('url')->toArray() ?? [],
            'category' => $product?->category?->name,
        ];
    }
}
