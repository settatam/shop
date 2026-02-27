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
     * Uses listing overrides with product fallbacks, and includes variant data.
     */
    protected function buildProductData(PlatformListing $listing): array
    {
        $product = $listing->product;
        $listing->loadMissing('listingVariants.productVariant');

        return [
            'title' => $listing->getEffectiveTitle(),
            'description' => $listing->getEffectiveDescription(),
            'price' => $listing->getEffectivePrice(),
            'quantity' => $listing->getEffectiveQuantity(),
            'images' => $listing->getEffectiveImages(),
            'category' => $listing->platform_category_id ?? $product?->category?->name,
            'attributes' => $listing->attributes ?? [],
            'platform_settings' => $listing->platform_settings ?? [],
            'weight' => $product?->weight,
            'variants' => $listing->listingVariants->map(fn ($lv) => [
                'sku' => $lv->getEffectiveSku(),
                'barcode' => $lv->getEffectiveBarcode(),
                'price' => $lv->getEffectivePrice(),
                'compare_at_price' => (float) ($lv->compare_at_price ?? $lv->productVariant?->product?->compare_at_price ?? 0),
                'quantity' => $lv->getEffectiveQuantity(),
                'option1' => $lv->productVariant?->option1_value,
                'option2' => $lv->productVariant?->option2_value,
                'option3' => $lv->productVariant?->option3_value,
                'external_variant_id' => $lv->external_variant_id,
                'external_inventory_item_id' => $lv->external_inventory_item_id,
            ])->all(),
        ];
    }
}
