<?php

namespace App\Services\Platforms\Adapters;

use App\Contracts\Platforms\PlatformAdapterResult;
use App\Models\PlatformListing;
use App\Services\Platforms\Amazon\AmazonService;

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

        $product = $listing->product;
        if (! $product) {
            return PlatformAdapterResult::failure('No product found for this listing');
        }

        try {
            $service = app(AmazonService::class);
            $service->ensureValidToken($this->marketplace);

            $listingData = $service->mapToAmazonListing($product, $this->marketplace);
            $sku = $product->variants->first()?->sku ?? $listing->external_listing_id ?? $product->handle;
            $marketplaceId = $this->marketplace->credentials['marketplace_ids'][0] ?? 'ATVPDKIKX0DER';

            $response = $service->amazonRequest(
                $this->marketplace,
                'PUT',
                "/listings/2021-08-01/items/{$this->marketplace->external_store_id}/{$sku}",
                [
                    'productType' => $listingData['productType'],
                    'requirements' => 'LISTING',
                    'attributes' => $listingData['attributes'],
                ],
                ['marketplaceIds' => $marketplaceId]
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

            return PlatformAdapterResult::failure("Failed to publish to Amazon: {$e->getMessage()}", $e);
        }
    }

    public function unpublish(PlatformListing $listing): PlatformAdapterResult
    {
        if (! $this->isConnected()) {
            return PlatformAdapterResult::failure('Amazon is not connected');
        }

        $sku = $listing->external_listing_id;
        if (! $sku) {
            return PlatformAdapterResult::failure('No SKU found for this listing');
        }

        try {
            $service = app(AmazonService::class);
            $service->ensureValidToken($this->marketplace);

            $marketplaceId = $this->marketplace->credentials['marketplace_ids'][0] ?? 'ATVPDKIKX0DER';

            $service->amazonRequest(
                $this->marketplace,
                'PATCH',
                "/listings/2021-08-01/items/{$this->marketplace->external_store_id}/{$sku}",
                [
                    'productType' => $listing->platform_data['productType'] ?? 'PRODUCT',
                    'patches' => [
                        [
                            'op' => 'replace',
                            'path' => '/attributes/fulfillment_availability',
                            'value' => [
                                [
                                    'fulfillment_channel_code' => 'DEFAULT',
                                    'quantity' => 0,
                                ],
                            ],
                        ],
                    ],
                ],
                ['marketplaceIds' => $marketplaceId]
            );

            $listing->update([
                'status' => PlatformListing::STATUS_ENDED,
                'last_synced_at' => now(),
            ]);

            $this->log('unpublished', [
                'listing_id' => $listing->id,
                'sku' => $sku,
            ]);

            return PlatformAdapterResult::success('Item unpublished from Amazon');
        } catch (\Throwable $e) {
            $this->log('unpublish_failed', [
                'listing_id' => $listing->id,
                'error' => $e->getMessage(),
            ]);

            return PlatformAdapterResult::failure("Failed to unpublish from Amazon: {$e->getMessage()}", $e);
        }
    }

    public function end(PlatformListing $listing): PlatformAdapterResult
    {
        if (! $this->isConnected()) {
            return PlatformAdapterResult::failure('Amazon is not connected');
        }

        $sku = $listing->external_listing_id;
        if (! $sku) {
            return PlatformAdapterResult::failure('No SKU found for this listing');
        }

        try {
            $service = app(AmazonService::class);
            $service->ensureValidToken($this->marketplace);

            $marketplaceId = $this->marketplace->credentials['marketplace_ids'][0] ?? 'ATVPDKIKX0DER';

            $service->amazonRequest(
                $this->marketplace,
                'DELETE',
                "/listings/2021-08-01/items/{$this->marketplace->external_store_id}/{$sku}",
                [],
                ['marketplaceIds' => $marketplaceId]
            );

            $listing->update([
                'status' => PlatformListing::STATUS_ENDED,
                'last_synced_at' => now(),
            ]);

            $this->log('ended', [
                'listing_id' => $listing->id,
                'sku' => $sku,
            ]);

            return PlatformAdapterResult::success('Listing ended on Amazon');
        } catch (\Throwable $e) {
            $this->log('end_failed', [
                'listing_id' => $listing->id,
                'error' => $e->getMessage(),
            ]);

            return PlatformAdapterResult::failure("Failed to end Amazon listing: {$e->getMessage()}", $e);
        }
    }

    public function updatePrice(PlatformListing $listing, float $price): PlatformAdapterResult
    {
        if (! $this->isConnected()) {
            return PlatformAdapterResult::failure('Amazon is not connected');
        }

        $sku = $listing->external_listing_id;
        if (! $sku) {
            return PlatformAdapterResult::failure('No SKU found for this listing');
        }

        try {
            $service = app(AmazonService::class);
            $service->ensureValidToken($this->marketplace);

            $marketplaceId = $this->marketplace->credentials['marketplace_ids'][0] ?? 'ATVPDKIKX0DER';

            $service->amazonRequest(
                $this->marketplace,
                'PATCH',
                "/listings/2021-08-01/items/{$this->marketplace->external_store_id}/{$sku}",
                [
                    'productType' => $listing->platform_data['productType'] ?? 'PRODUCT',
                    'patches' => [
                        [
                            'op' => 'replace',
                            'path' => '/attributes/purchasable_offer',
                            'value' => [
                                [
                                    'currency' => 'USD',
                                    'our_price' => [['schedule' => [['value_with_tax' => $price]]]],
                                ],
                            ],
                        ],
                    ],
                ],
                ['marketplaceIds' => $marketplaceId]
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

            return PlatformAdapterResult::failure("Failed to update Amazon price: {$e->getMessage()}", $e);
        }
    }

    public function updateInventory(PlatformListing $listing, int $quantity): PlatformAdapterResult
    {
        if (! $this->isConnected()) {
            return PlatformAdapterResult::failure('Amazon is not connected');
        }

        $sku = $listing->external_listing_id;
        if (! $sku) {
            return PlatformAdapterResult::failure('No SKU found for this listing');
        }

        try {
            $service = app(AmazonService::class);
            $service->ensureValidToken($this->marketplace);

            $marketplaceId = $this->marketplace->credentials['marketplace_ids'][0] ?? 'ATVPDKIKX0DER';

            $service->amazonRequest(
                $this->marketplace,
                'PATCH',
                "/listings/2021-08-01/items/{$this->marketplace->external_store_id}/{$sku}",
                [
                    'productType' => $listing->platform_data['productType'] ?? 'PRODUCT',
                    'patches' => [
                        [
                            'op' => 'replace',
                            'path' => '/attributes/fulfillment_availability',
                            'value' => [
                                [
                                    'fulfillment_channel_code' => 'DEFAULT',
                                    'quantity' => $quantity,
                                ],
                            ],
                        ],
                    ],
                ],
                ['marketplaceIds' => $marketplaceId]
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

            return PlatformAdapterResult::failure("Failed to update Amazon inventory: {$e->getMessage()}", $e);
        }
    }

    public function sync(PlatformListing $listing): PlatformAdapterResult
    {
        return $this->publish($listing);
    }

    public function refresh(PlatformListing $listing): PlatformAdapterResult
    {
        if (! $this->isConnected()) {
            return PlatformAdapterResult::failure('Amazon is not connected');
        }

        $sku = $listing->external_listing_id;
        if (! $sku) {
            return PlatformAdapterResult::failure('No SKU found for this listing');
        }

        try {
            $service = app(AmazonService::class);
            $service->ensureValidToken($this->marketplace);

            $marketplaceId = $this->marketplace->credentials['marketplace_ids'][0] ?? 'ATVPDKIKX0DER';

            $response = $service->amazonRequest(
                $this->marketplace,
                'GET',
                "/listings/2021-08-01/items/{$this->marketplace->external_store_id}/{$sku}",
                ['marketplaceIds' => $marketplaceId]
            );

            $amazonStatus = $response['status'] ?? null;
            $newStatus = match ($amazonStatus) {
                'BUYABLE' => PlatformListing::STATUS_LISTED,
                'DISCOVERABLE' => PlatformListing::STATUS_LISTED,
                'DELETED' => PlatformListing::STATUS_ENDED,
                default => $listing->status,
            };

            $listing->update([
                'status' => $newStatus,
                'platform_data' => array_merge($listing->platform_data ?? [], [
                    'amazon_status' => $amazonStatus,
                    'asin' => $response['asin'] ?? null,
                ]),
                'last_synced_at' => now(),
            ]);

            $this->log('refreshed', [
                'listing_id' => $listing->id,
                'sku' => $sku,
                'amazon_status' => $amazonStatus,
                'mapped_status' => $newStatus,
            ]);

            return PlatformAdapterResult::success('Listing refreshed', [
                'amazon_status' => $amazonStatus,
                'status' => $newStatus,
            ]);
        } catch (\Throwable $e) {
            $this->log('refresh_failed', [
                'listing_id' => $listing->id,
                'sku' => $sku,
                'error' => $e->getMessage(),
            ]);

            return PlatformAdapterResult::failure("Failed to refresh Amazon listing: {$e->getMessage()}", $e);
        }
    }
}
