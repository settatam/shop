<?php

namespace App\Services\Platforms\Adapters;

use App\Contracts\Platforms\PlatformAdapterResult;
use App\Models\PlatformListing;
use App\Services\Platforms\Walmart\WalmartService;

class WalmartAdapter extends BaseAdapter
{
    public function getPlatform(): string
    {
        return 'walmart';
    }

    public function isConnected(): bool
    {
        return $this->marketplace && $this->marketplace->access_token;
    }

    public function publish(PlatformListing $listing): PlatformAdapterResult
    {
        if (! $this->isConnected()) {
            return PlatformAdapterResult::failure('Walmart is not connected');
        }

        $product = $listing->product;
        if (! $product) {
            return PlatformAdapterResult::failure('No product found for this listing');
        }

        try {
            $service = app(WalmartService::class);
            $service->ensureValidToken($this->marketplace);

            $itemData = $service->mapToWalmartItem($product, $this->marketplace);
            $sku = $itemData['sku'] ?? $listing->external_listing_id;

            $service->walmartRequest(
                $this->marketplace,
                'POST',
                '/v3/feeds?feedType=item',
                ['ItemFeed' => ['item' => [$itemData]]],
                ['Content-Type' => 'application/json']
            );

            $listing->update([
                'external_listing_id' => $sku,
                'status' => PlatformListing::STATUS_PENDING,
                'last_synced_at' => now(),
            ]);

            $this->log('published', [
                'listing_id' => $listing->id,
                'sku' => $sku,
            ]);

            return PlatformAdapterResult::created($sku, null, ['sku' => $sku]);
        } catch (\Throwable $e) {
            $this->log('publish_failed', [
                'listing_id' => $listing->id,
                'error' => $e->getMessage(),
            ]);

            return PlatformAdapterResult::failure("Failed to publish to Walmart: {$e->getMessage()}", $e);
        }
    }

    public function unpublish(PlatformListing $listing): PlatformAdapterResult
    {
        if (! $this->isConnected()) {
            return PlatformAdapterResult::failure('Walmart is not connected');
        }

        $sku = $listing->external_listing_id;
        if (! $sku) {
            return PlatformAdapterResult::failure('No SKU found for this listing');
        }

        try {
            $service = app(WalmartService::class);
            $service->ensureValidToken($this->marketplace);
            $service->walmartRequest($this->marketplace, 'DELETE', "/v3/items/{$sku}");

            $listing->update([
                'status' => PlatformListing::STATUS_ENDED,
                'last_synced_at' => now(),
            ]);

            $this->log('unpublished', [
                'listing_id' => $listing->id,
                'sku' => $sku,
            ]);

            return PlatformAdapterResult::success('Item retired from Walmart');
        } catch (\Throwable $e) {
            $this->log('unpublish_failed', [
                'listing_id' => $listing->id,
                'error' => $e->getMessage(),
            ]);

            return PlatformAdapterResult::failure("Failed to unpublish from Walmart: {$e->getMessage()}", $e);
        }
    }

    public function end(PlatformListing $listing): PlatformAdapterResult
    {
        return $this->unpublish($listing);
    }

    public function updatePrice(PlatformListing $listing, float $price): PlatformAdapterResult
    {
        if (! $this->isConnected()) {
            return PlatformAdapterResult::failure('Walmart is not connected');
        }

        $sku = $listing->external_listing_id;
        if (! $sku) {
            return PlatformAdapterResult::failure('No SKU found for this listing');
        }

        try {
            $service = app(WalmartService::class);
            $service->ensureValidToken($this->marketplace);

            $service->walmartRequest(
                $this->marketplace,
                'POST',
                '/v3/feeds?feedType=price',
                [
                    'PriceFeed' => [
                        'PriceHeader' => ['version' => '1.5.1'],
                        'Price' => [[
                            'itemIdentifier' => ['sku' => $sku],
                            'pricingList' => [
                                'pricing' => [[
                                    'currentPrice' => [
                                        'currency' => 'USD',
                                        'amount' => $price,
                                    ],
                                ]],
                            ],
                        ]],
                    ],
                ],
                ['Content-Type' => 'application/json']
            );

            $this->log('price_updated', [
                'listing_id' => $listing->id,
                'sku' => $sku,
                'price' => $price,
            ]);

            return PlatformAdapterResult::success('Price updated', ['price' => $price]);
        } catch (\Throwable $e) {
            $this->log('price_update_failed', [
                'listing_id' => $listing->id,
                'sku' => $sku,
                'error' => $e->getMessage(),
            ]);

            return PlatformAdapterResult::failure("Failed to update Walmart price: {$e->getMessage()}", $e);
        }
    }

    public function updateInventory(PlatformListing $listing, int $quantity): PlatformAdapterResult
    {
        if (! $this->isConnected()) {
            return PlatformAdapterResult::failure('Walmart is not connected');
        }

        $sku = $listing->external_listing_id;
        if (! $sku) {
            return PlatformAdapterResult::failure('No SKU found for this listing');
        }

        try {
            $service = app(WalmartService::class);
            $service->ensureValidToken($this->marketplace);

            $service->walmartRequest(
                $this->marketplace,
                'PUT',
                "/v3/inventory?sku={$sku}",
                [
                    'sku' => $sku,
                    'quantity' => [
                        'unit' => 'EACH',
                        'amount' => $quantity,
                    ],
                ]
            );

            $this->log('inventory_updated', [
                'listing_id' => $listing->id,
                'sku' => $sku,
                'quantity' => $quantity,
            ]);

            return PlatformAdapterResult::success('Inventory updated', ['quantity' => $quantity]);
        } catch (\Throwable $e) {
            $this->log('inventory_update_failed', [
                'listing_id' => $listing->id,
                'sku' => $sku,
                'error' => $e->getMessage(),
            ]);

            return PlatformAdapterResult::failure("Failed to update Walmart inventory: {$e->getMessage()}", $e);
        }
    }

    public function sync(PlatformListing $listing): PlatformAdapterResult
    {
        return $this->publish($listing);
    }

    public function refresh(PlatformListing $listing): PlatformAdapterResult
    {
        if (! $this->isConnected()) {
            return PlatformAdapterResult::failure('Walmart is not connected');
        }

        $sku = $listing->external_listing_id;
        if (! $sku) {
            return PlatformAdapterResult::failure('No SKU found for this listing');
        }

        try {
            $service = app(WalmartService::class);
            $service->ensureValidToken($this->marketplace);

            $response = $service->walmartRequest(
                $this->marketplace,
                'GET',
                "/v3/items/{$sku}"
            );

            $publishedStatus = $response['publishedStatus'] ?? null;
            $newStatus = match ($publishedStatus) {
                'PUBLISHED' => PlatformListing::STATUS_LISTED,
                'UNPUBLISHED', 'RETIRED' => PlatformListing::STATUS_ENDED,
                'SYSTEM_PROBLEM' => PlatformListing::STATUS_ERROR,
                default => $listing->status,
            };

            $listing->update([
                'status' => $newStatus,
                'platform_data' => array_merge($listing->platform_data ?? [], [
                    'walmart_status' => $publishedStatus,
                    'product_name' => $response['productName'] ?? null,
                ]),
                'last_synced_at' => now(),
            ]);

            $this->log('refreshed', [
                'listing_id' => $listing->id,
                'sku' => $sku,
                'walmart_status' => $publishedStatus,
                'mapped_status' => $newStatus,
            ]);

            return PlatformAdapterResult::success('Listing refreshed', [
                'walmart_status' => $publishedStatus,
                'status' => $newStatus,
            ]);
        } catch (\Throwable $e) {
            $this->log('refresh_failed', [
                'listing_id' => $listing->id,
                'sku' => $sku,
                'error' => $e->getMessage(),
            ]);

            return PlatformAdapterResult::failure("Failed to refresh Walmart listing: {$e->getMessage()}", $e);
        }
    }
}
