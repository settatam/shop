<?php

namespace App\Services\Platforms\Adapters;

use App\Contracts\Platforms\PlatformAdapterResult;
use App\Models\PlatformListing;
use Illuminate\Support\Facades\Http;

class BigCommerceAdapter extends BaseAdapter
{
    public function getPlatform(): string
    {
        return 'bigcommerce';
    }

    public function isConnected(): bool
    {
        return $this->marketplace
            && $this->marketplace->access_token
            && $this->getStoreHash();
    }

    public function publish(PlatformListing $listing): PlatformAdapterResult
    {
        if (! $this->isConnected()) {
            return PlatformAdapterResult::failure('BigCommerce is not connected');
        }

        try {
            $productData = $this->buildBigCommerceProductData($listing);

            if ($listing->external_listing_id) {
                return $this->updateBigCommerceProduct($listing, $productData);
            }

            $response = $this->bigCommerceRequest('POST', '/v3/catalog/products', $productData);

            if (! $response->successful()) {
                return PlatformAdapterResult::failure(
                    'Failed to create BigCommerce product: '.$response->body()
                );
            }

            $bcProduct = $response->json('data');
            $productId = $bcProduct['id'] ?? null;
            $customUrl = $bcProduct['custom_url']['url'] ?? null;

            $this->syncVariantExternalIds($listing, $bcProduct['variants'] ?? []);

            $this->log('Product published', ['bc_id' => $productId]);

            return PlatformAdapterResult::created(
                externalId: (string) $productId,
                externalUrl: $customUrl,
                data: ['bc_product' => $bcProduct]
            );
        } catch (\Throwable $e) {
            $this->log('Publish failed', ['error' => $e->getMessage()]);

            return PlatformAdapterResult::failure($e->getMessage(), $e);
        }
    }

    public function unpublish(PlatformListing $listing): PlatformAdapterResult
    {
        if (! $this->isConnected() || ! $listing->external_listing_id) {
            return PlatformAdapterResult::failure('Cannot unpublish: not connected or no external ID');
        }

        try {
            $response = $this->bigCommerceRequest('PUT', "/v3/catalog/products/{$listing->external_listing_id}", [
                'is_visible' => false,
            ]);

            if (! $response->successful()) {
                return PlatformAdapterResult::failure('Failed to unpublish: '.$response->body());
            }

            $this->log('Product unpublished', ['bc_id' => $listing->external_listing_id]);

            return PlatformAdapterResult::success('Product unpublished from BigCommerce');
        } catch (\Throwable $e) {
            return PlatformAdapterResult::failure($e->getMessage(), $e);
        }
    }

    public function end(PlatformListing $listing): PlatformAdapterResult
    {
        if (! $this->isConnected() || ! $listing->external_listing_id) {
            return PlatformAdapterResult::success('Nothing to end');
        }

        try {
            $response = $this->bigCommerceRequest('DELETE', "/v3/catalog/products/{$listing->external_listing_id}");

            if (! $response->successful() && $response->status() !== 404) {
                return PlatformAdapterResult::failure('Failed to delete: '.$response->body());
            }

            $this->log('Product deleted', ['bc_id' => $listing->external_listing_id]);

            return PlatformAdapterResult::success('Product deleted on BigCommerce');
        } catch (\Throwable $e) {
            return PlatformAdapterResult::failure($e->getMessage(), $e);
        }
    }

    public function updatePrice(PlatformListing $listing, float $price): PlatformAdapterResult
    {
        if (! $this->isConnected() || ! $listing->external_listing_id) {
            return PlatformAdapterResult::failure('Cannot update price: not connected');
        }

        try {
            $listing->loadMissing('listingVariants');

            if ($listing->listingVariants->isEmpty()) {
                $response = $this->bigCommerceRequest('PUT', "/v3/catalog/products/{$listing->external_listing_id}", [
                    'price' => $price,
                ]);

                if (! $response->successful()) {
                    return PlatformAdapterResult::failure('Failed to update price: '.$response->body());
                }
            } else {
                foreach ($listing->listingVariants as $listingVariant) {
                    $variantId = $listingVariant->external_variant_id;
                    if (! $variantId) {
                        continue;
                    }

                    $response = $this->bigCommerceRequest(
                        'PUT',
                        "/v3/catalog/products/{$listing->external_listing_id}/variants/{$variantId}",
                        ['price' => $listingVariant->getEffectivePrice()]
                    );

                    if (! $response->successful()) {
                        return PlatformAdapterResult::failure('Failed to update variant price: '.$response->body());
                    }
                }
            }

            return PlatformAdapterResult::success('Price updated', ['price' => $price]);
        } catch (\Throwable $e) {
            return PlatformAdapterResult::failure($e->getMessage(), $e);
        }
    }

    public function updateInventory(PlatformListing $listing, int $quantity): PlatformAdapterResult
    {
        if (! $this->isConnected() || ! $listing->external_listing_id) {
            return PlatformAdapterResult::failure('Cannot update inventory: not connected');
        }

        try {
            $listing->loadMissing('listingVariants.productVariant');

            if ($listing->listingVariants->isEmpty()) {
                $response = $this->bigCommerceRequest('PUT', "/v3/catalog/products/{$listing->external_listing_id}", [
                    'inventory_level' => $quantity,
                    'inventory_tracking' => 'product',
                ]);

                if (! $response->successful()) {
                    return PlatformAdapterResult::failure('Failed to update inventory: '.$response->body());
                }
            } else {
                foreach ($listing->listingVariants as $listingVariant) {
                    $variantId = $listingVariant->external_variant_id;
                    if (! $variantId) {
                        continue;
                    }

                    $response = $this->bigCommerceRequest(
                        'PUT',
                        "/v3/catalog/products/{$listing->external_listing_id}/variants/{$variantId}",
                        ['inventory_level' => $listingVariant->getEffectiveQuantity()]
                    );

                    if (! $response->successful()) {
                        $this->log('Failed to update inventory for variant', [
                            'variant_id' => $listingVariant->id,
                            'error' => $response->body(),
                        ]);
                    }
                }
            }

            return PlatformAdapterResult::success('Inventory updated', ['quantity' => $quantity]);
        } catch (\Throwable $e) {
            return PlatformAdapterResult::failure($e->getMessage(), $e);
        }
    }

    public function sync(PlatformListing $listing): PlatformAdapterResult
    {
        if (! $listing->external_listing_id) {
            return $this->publish($listing);
        }

        return $this->updateBigCommerceProduct($listing, $this->buildBigCommerceProductData($listing));
    }

    public function refresh(PlatformListing $listing): PlatformAdapterResult
    {
        if (! $this->isConnected() || ! $listing->external_listing_id) {
            return PlatformAdapterResult::failure('Cannot refresh: not connected');
        }

        try {
            $response = $this->bigCommerceRequest('GET', "/v3/catalog/products/{$listing->external_listing_id}");

            if (! $response->successful()) {
                return PlatformAdapterResult::failure('Product not found on BigCommerce');
            }

            $product = $response->json('data');

            $status = ($product['is_visible'] ?? false)
                ? PlatformListing::STATUS_LISTED
                : PlatformListing::STATUS_NOT_LISTED;

            return PlatformAdapterResult::success('Refreshed from BigCommerce', [
                'status' => $status,
                'price' => (float) ($product['price'] ?? 0),
                'quantity' => (int) ($product['inventory_level'] ?? 0),
                'url' => $product['custom_url']['url'] ?? null,
            ]);
        } catch (\Throwable $e) {
            return PlatformAdapterResult::failure($e->getMessage(), $e);
        }
    }

    protected function updateBigCommerceProduct(PlatformListing $listing, array $productData): PlatformAdapterResult
    {
        $response = $this->bigCommerceRequest(
            'PUT',
            "/v3/catalog/products/{$listing->external_listing_id}",
            $productData
        );

        if (! $response->successful()) {
            return PlatformAdapterResult::failure('Failed to update: '.$response->body());
        }

        $bcProduct = $response->json('data');
        if ($bcProduct) {
            $this->syncVariantExternalIds($listing, $bcProduct['variants'] ?? []);
        }

        $this->log('Product updated', ['bc_id' => $listing->external_listing_id]);

        return PlatformAdapterResult::success('Product updated on BigCommerce');
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildBigCommerceProductData(PlatformListing $listing): array
    {
        $data = $this->buildProductData($listing);

        $bcData = [
            'name' => $data['title'],
            'description' => $data['description'] ?? '',
            'type' => 'physical',
            'is_visible' => true,
        ];

        $variants = $data['variants'] ?? [];
        if (count($variants) > 1) {
            // BigCommerce handles variants via the variants endpoint
            // For the product, set the base price from the first variant
            $bcData['price'] = (float) $variants[0]['price'];
            $bcData['sku'] = $variants[0]['sku'] ?? '';
        } elseif (! empty($variants)) {
            $variant = $variants[0];
            $bcData['price'] = (float) $variant['price'];
            $bcData['sku'] = $variant['sku'] ?? '';
            $bcData['inventory_level'] = $variant['quantity'] ?? 0;
            $bcData['inventory_tracking'] = 'product';
        } else {
            $bcData['price'] = (float) ($data['price'] ?? 0);
            $bcData['inventory_level'] = $data['quantity'] ?? 0;
            $bcData['inventory_tracking'] = 'product';
        }

        if (! empty($data['images'])) {
            $bcData['images'] = array_map(fn ($url, $index) => [
                'image_url' => $url,
                'is_thumbnail' => $index === 0,
            ], $data['images'], array_keys($data['images']));
        }

        if (! empty($data['category'])) {
            $bcData['categories'] = [$data['category']];
        }

        if (! empty($data['weight'])) {
            $bcData['weight'] = (float) $data['weight'];
        }

        return $bcData;
    }

    /**
     * Match BigCommerce response variant IDs to listing variants by SKU.
     *
     * @param  array<int, array<string, mixed>>  $bcVariants
     */
    protected function syncVariantExternalIds(PlatformListing $listing, array $bcVariants): void
    {
        if (empty($bcVariants)) {
            return;
        }

        $listing->loadMissing('listingVariants');

        foreach ($bcVariants as $bcVariant) {
            $sku = $bcVariant['sku'] ?? null;

            $listingVariant = $listing->listingVariants
                ->first(fn ($lv) => $lv->getEffectiveSku() === $sku);

            if (! $listingVariant && $listing->listingVariants->count() === 1 && count($bcVariants) === 1) {
                $listingVariant = $listing->listingVariants->first();
            }

            if ($listingVariant) {
                $listingVariant->update([
                    'external_variant_id' => (string) $bcVariant['id'],
                    'platform_data' => $bcVariant,
                ]);
            }
        }
    }

    protected function getStoreHash(): ?string
    {
        return $this->marketplace?->external_store_id
            ?? $this->getCredential('store_hash');
    }

    protected function bigCommerceRequest(string $method, string $endpoint, array $data = []): \Illuminate\Http\Client\Response
    {
        $storeHash = $this->getStoreHash();
        $url = "https://api.bigcommerce.com/stores/{$storeHash}{$endpoint}";

        $request = Http::withHeaders([
            'X-Auth-Token' => $this->marketplace->access_token,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ]);

        return match (strtoupper($method)) {
            'GET' => $request->get($url, $data),
            'POST' => $request->post($url, $data),
            'PUT' => $request->put($url, $data),
            'DELETE' => $request->delete($url, $data),
            default => throw new \InvalidArgumentException("Unsupported HTTP method: {$method}"),
        };
    }
}
