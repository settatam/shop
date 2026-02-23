<?php

namespace App\Services\Platforms;

use App\Contracts\Platforms\PlatformAdapterContract;
use App\Contracts\Platforms\PlatformAdapterResult;
use App\Models\PlatformListing;
use App\Services\Platforms\Adapters\AdapterFactory;
use Illuminate\Support\Facades\DB;

class ListingManager
{
    protected PlatformListing $listing;

    protected ?PlatformAdapterContract $adapter = null;

    public function __construct(PlatformListing $listing)
    {
        $this->listing = $listing;
    }

    /**
     * Get the underlying listing model.
     */
    public function getListing(): PlatformListing
    {
        return $this->listing;
    }

    /**
     * Get the platform adapter for this listing.
     */
    public function getAdapter(): PlatformAdapterContract
    {
        if ($this->adapter === null) {
            $this->adapter = AdapterFactory::make($this->listing->salesChannel);
        }

        return $this->adapter;
    }

    /**
     * Publish the listing to the platform.
     */
    public function publish(): PlatformAdapterResult
    {
        return DB::transaction(function () {
            $this->listing->update(['status' => PlatformListing::STATUS_PENDING]);

            $result = $this->getAdapter()->publish($this->listing);

            if ($result->success) {
                $this->listing->update([
                    'status' => PlatformListing::STATUS_LISTED,
                    'external_listing_id' => $result->externalId ?? $this->listing->external_listing_id,
                    'listing_url' => $result->externalUrl ?? $this->listing->listing_url,
                    'published_at' => now(),
                    'last_synced_at' => now(),
                    'last_error' => null,
                ]);
            } else {
                $this->listing->update([
                    'status' => PlatformListing::STATUS_ERROR,
                    'last_error' => $result->message,
                ]);
            }

            return $result;
        });
    }

    /**
     * Unpublish/deactivate the listing on the platform.
     */
    public function unpublish(): PlatformAdapterResult
    {
        return DB::transaction(function () {
            $result = $this->getAdapter()->unpublish($this->listing);

            if ($result->success) {
                $this->listing->update([
                    'status' => PlatformListing::STATUS_ENDED,
                    'last_synced_at' => now(),
                    'last_error' => null,
                ]);
            } else {
                $this->listing->update([
                    'last_error' => $result->message,
                ]);
            }

            return $result;
        });
    }

    /**
     * End/remove the listing from the platform.
     */
    public function end(): PlatformAdapterResult
    {
        return DB::transaction(function () {
            $result = $this->getAdapter()->end($this->listing);

            if ($result->success) {
                $this->listing->update([
                    'status' => PlatformListing::STATUS_ENDED,
                    'last_synced_at' => now(),
                    'last_error' => null,
                ]);
            } else {
                $this->listing->update([
                    'last_error' => $result->message,
                ]);
            }

            return $result;
        });
    }

    /**
     * Update the price on the platform.
     */
    public function updatePrice(float $price): PlatformAdapterResult
    {
        return DB::transaction(function () use ($price) {
            $result = $this->getAdapter()->updatePrice($this->listing, $price);

            if ($result->success) {
                $this->listing->update([
                    'platform_price' => $price,
                    'last_synced_at' => now(),
                    'last_error' => null,
                ]);
            } else {
                $this->listing->update([
                    'last_error' => $result->message,
                ]);
            }

            return $result;
        });
    }

    /**
     * Update the inventory on the platform.
     */
    public function updateInventory(?int $quantity = null): PlatformAdapterResult
    {
        $quantity = $quantity ?? $this->listing->product?->total_quantity ?? 0;

        return DB::transaction(function () use ($quantity) {
            $result = $this->getAdapter()->updateInventory($this->listing, $quantity);

            if ($result->success) {
                $this->listing->update([
                    'platform_quantity' => $quantity,
                    'last_synced_at' => now(),
                    'last_error' => null,
                ]);
            } else {
                $this->listing->update([
                    'last_error' => $result->message,
                ]);
            }

            return $result;
        });
    }

    /**
     * Sync all listing data to the platform.
     */
    public function sync(): PlatformAdapterResult
    {
        return DB::transaction(function () {
            $result = $this->getAdapter()->sync($this->listing);

            if ($result->success) {
                $this->listing->update([
                    'last_synced_at' => now(),
                    'last_error' => null,
                ]);

                if (isset($result->data['price'])) {
                    $this->listing->update(['platform_price' => $result->data['price']]);
                }
                if (isset($result->data['quantity'])) {
                    $this->listing->update(['platform_quantity' => $result->data['quantity']]);
                }
            } else {
                $this->listing->update([
                    'last_error' => $result->message,
                ]);
            }

            return $result;
        });
    }

    /**
     * Refresh listing data from the platform.
     */
    public function refresh(): PlatformAdapterResult
    {
        return DB::transaction(function () {
            $result = $this->getAdapter()->refresh($this->listing);

            if ($result->success) {
                $updates = [
                    'last_synced_at' => now(),
                    'last_error' => null,
                ];

                if (isset($result->data['status'])) {
                    $updates['status'] = $result->data['status'];
                }
                if (isset($result->data['price'])) {
                    $updates['platform_price'] = $result->data['price'];
                }
                if (isset($result->data['quantity'])) {
                    $updates['platform_quantity'] = $result->data['quantity'];
                }
                if (isset($result->data['url'])) {
                    $updates['listing_url'] = $result->data['url'];
                }

                $this->listing->update($updates);
            } else {
                $this->listing->update([
                    'last_error' => $result->message,
                ]);
            }

            return $result;
        });
    }

    /**
     * Check if the listing is published.
     */
    public function isPublished(): bool
    {
        return $this->listing->isListed();
    }

    /**
     * Check if the listing is a draft.
     */
    public function isDraft(): bool
    {
        return $this->listing->isNotListed();
    }

    /**
     * Check if the listing is ended.
     */
    public function isEnded(): bool
    {
        return $this->listing->isEnded();
    }

    /**
     * Get the platform name.
     */
    public function getPlatformName(): string
    {
        return $this->listing->salesChannel?->name ?? 'Unknown';
    }
}
