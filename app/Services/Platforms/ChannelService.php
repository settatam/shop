<?php

namespace App\Services\Platforms;

use App\Models\PlatformListing;
use App\Models\Product;
use App\Models\SalesChannel;
use App\Services\Platforms\Adapters\AdapterFactory;

class ChannelService
{
    /**
     * Get a listing manager for a specific listing.
     */
    public function listing(int|PlatformListing $listing): ListingManager
    {
        if (is_int($listing)) {
            $listing = PlatformListing::findOrFail($listing);
        }

        return new ListingManager($listing);
    }

    /**
     * Get a platform adapter for a specific sales channel.
     */
    public function platform(int|SalesChannel $channel): Adapters\BaseAdapter
    {
        if (is_int($channel)) {
            $channel = SalesChannel::findOrFail($channel);
        }

        return AdapterFactory::make($channel);
    }

    /**
     * Get all listings for a product.
     *
     * @return \Illuminate\Database\Eloquent\Collection<PlatformListing>
     */
    public function listingsFor(int|Product $product): \Illuminate\Database\Eloquent\Collection
    {
        if (is_int($product)) {
            $productId = $product;
        } else {
            $productId = $product->id;
        }

        return PlatformListing::where('product_id', $productId)->get();
    }

    /**
     * Publish a product to all connected channels.
     *
     * @return array<string, \App\Contracts\Platforms\PlatformAdapterResult>
     */
    public function publishToAll(int|Product $product): array
    {
        $listings = $this->listingsFor($product);
        $results = [];

        foreach ($listings as $listing) {
            $manager = $this->listing($listing);
            $results[$listing->salesChannel->name ?? $listing->sales_channel_id] = $manager->publish();
        }

        return $results;
    }

    /**
     * Sync inventory across all channels for a product.
     *
     * @return array<string, \App\Contracts\Platforms\PlatformAdapterResult>
     */
    public function syncInventoryForAll(int|Product $product): array
    {
        $listings = $this->listingsFor($product);
        $results = [];

        foreach ($listings as $listing) {
            $manager = $this->listing($listing);
            $results[$listing->salesChannel->name ?? $listing->sales_channel_id] = $manager->updateInventory();
        }

        return $results;
    }

    /**
     * Ensure a product has listings for all active channels.
     * Uses Product::ensureListingExists() which also creates variant rows.
     *
     * @return \Illuminate\Database\Eloquent\Collection<PlatformListing>
     */
    public function ensureListings(int|Product $product): \Illuminate\Database\Eloquent\Collection
    {
        if (is_int($product)) {
            $product = Product::findOrFail($product);
        }

        $activeChannels = SalesChannel::where('store_id', $product->store_id)
            ->where('is_active', true)
            ->get();

        foreach ($activeChannels as $channel) {
            $product->ensureListingExists($channel);
        }

        return $this->listingsFor($product);
    }
}
