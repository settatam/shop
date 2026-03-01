<?php

namespace App\Services\Platforms\Adapters;

use App\Contracts\Platforms\PlatformAdapterResult;
use App\Models\PlatformListing;
use Illuminate\Support\Facades\Http;

class WooCommerceAdapter extends BaseAdapter
{
    protected string $apiVersion = 'wc/v3';

    public function getPlatform(): string
    {
        return 'woocommerce';
    }

    public function isConnected(): bool
    {
        return $this->marketplace
            && $this->marketplace->access_token
            && $this->getCredential('site_url');
    }

    public function publish(PlatformListing $listing): PlatformAdapterResult
    {
        if (! $this->isConnected()) {
            return PlatformAdapterResult::failure('WooCommerce is not connected');
        }

        try {
            $productData = $this->buildWooProductData($listing);

            if ($listing->external_listing_id) {
                return $this->updateWooProduct($listing, $productData);
            }

            $response = $this->wooRequest('POST', 'products', $productData);

            if (! $response->successful()) {
                return PlatformAdapterResult::failure(
                    'Failed to create WooCommerce product: '.$response->body()
                );
            }

            $wooProduct = $response->json();
            $productId = $wooProduct['id'] ?? null;
            $permalink = $wooProduct['permalink'] ?? null;

            $this->syncVariantExternalIds($listing, $wooProduct['variations'] ?? []);

            $this->log('Product published', ['woo_id' => $productId]);

            return PlatformAdapterResult::created(
                externalId: (string) $productId,
                externalUrl: $permalink,
                data: ['woo_product' => $wooProduct]
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
            $response = $this->wooRequest('PUT', "products/{$listing->external_listing_id}", [
                'status' => 'draft',
            ]);

            if (! $response->successful()) {
                return PlatformAdapterResult::failure('Failed to unpublish: '.$response->body());
            }

            $this->log('Product unpublished', ['woo_id' => $listing->external_listing_id]);

            return PlatformAdapterResult::success('Product unpublished from WooCommerce');
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
            $response = $this->wooRequest('DELETE', "products/{$listing->external_listing_id}", [
                'force' => false,
            ]);

            if (! $response->successful() && $response->status() !== 404) {
                return PlatformAdapterResult::failure('Failed to delete: '.$response->body());
            }

            $this->log('Product trashed', ['woo_id' => $listing->external_listing_id]);

            return PlatformAdapterResult::success('Product trashed on WooCommerce');
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
                $response = $this->wooRequest('PUT', "products/{$listing->external_listing_id}", [
                    'regular_price' => number_format($price, 2, '.', ''),
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

                    $response = $this->wooRequest(
                        'PUT',
                        "products/{$listing->external_listing_id}/variations/{$variantId}",
                        [
                            'regular_price' => number_format($listingVariant->getEffectivePrice(), 2, '.', ''),
                        ]
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
                $response = $this->wooRequest('PUT', "products/{$listing->external_listing_id}", [
                    'manage_stock' => true,
                    'stock_quantity' => $quantity,
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

                    $response = $this->wooRequest(
                        'PUT',
                        "products/{$listing->external_listing_id}/variations/{$variantId}",
                        [
                            'manage_stock' => true,
                            'stock_quantity' => $listingVariant->getEffectiveQuantity(),
                        ]
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

        return $this->updateWooProduct($listing, $this->buildWooProductData($listing));
    }

    public function refresh(PlatformListing $listing): PlatformAdapterResult
    {
        if (! $this->isConnected() || ! $listing->external_listing_id) {
            return PlatformAdapterResult::failure('Cannot refresh: not connected');
        }

        try {
            $response = $this->wooRequest('GET', "products/{$listing->external_listing_id}");

            if (! $response->successful()) {
                return PlatformAdapterResult::failure('Product not found on WooCommerce');
            }

            $product = $response->json();

            $status = match ($product['status'] ?? 'draft') {
                'publish' => PlatformListing::STATUS_LISTED,
                'draft' => PlatformListing::STATUS_NOT_LISTED,
                'trash' => PlatformListing::STATUS_ENDED,
                default => PlatformListing::STATUS_NOT_LISTED,
            };

            return PlatformAdapterResult::success('Refreshed from WooCommerce', [
                'status' => $status,
                'price' => (float) ($product['price'] ?? 0),
                'quantity' => (int) ($product['stock_quantity'] ?? 0),
                'url' => $product['permalink'] ?? null,
            ]);
        } catch (\Throwable $e) {
            return PlatformAdapterResult::failure($e->getMessage(), $e);
        }
    }

    protected function updateWooProduct(PlatformListing $listing, array $productData): PlatformAdapterResult
    {
        $response = $this->wooRequest(
            'PUT',
            "products/{$listing->external_listing_id}",
            $productData
        );

        if (! $response->successful()) {
            return PlatformAdapterResult::failure('Failed to update: '.$response->body());
        }

        $wooProduct = $response->json();
        if ($wooProduct) {
            $this->syncVariantExternalIds($listing, $wooProduct['variations'] ?? []);
        }

        $this->log('Product updated', ['woo_id' => $listing->external_listing_id]);

        return PlatformAdapterResult::success('Product updated on WooCommerce');
    }

    protected function buildWooProductData(PlatformListing $listing): array
    {
        $data = $this->buildProductData($listing);

        $wooData = [
            'name' => $data['title'],
            'description' => $data['description'] ?? '',
            'status' => 'publish',
        ];

        $variants = $data['variants'] ?? [];
        if (count($variants) > 1) {
            $wooData['type'] = 'variable';

            $options = [[], [], []];
            foreach ($variants as $variant) {
                if ($variant['option1'] ?? null) {
                    $options[0][] = $variant['option1'];
                }
                if ($variant['option2'] ?? null) {
                    $options[1][] = $variant['option2'];
                }
                if ($variant['option3'] ?? null) {
                    $options[2][] = $variant['option3'];
                }
            }

            $optionNames = ['Option 1', 'Option 2', 'Option 3'];
            $attributes = [];
            foreach ($options as $index => $values) {
                $uniqueValues = array_unique(array_filter($values));
                if (! empty($uniqueValues)) {
                    $attributes[] = [
                        'name' => $optionNames[$index],
                        'options' => array_values($uniqueValues),
                        'visible' => true,
                        'variation' => true,
                    ];
                }
            }

            $wooData['attributes'] = $attributes;
        } elseif (! empty($variants)) {
            $variant = $variants[0];
            $wooData['type'] = 'simple';
            $wooData['regular_price'] = number_format($variant['price'], 2, '.', '');
            $wooData['sku'] = $variant['sku'] ?? '';
            $wooData['manage_stock'] = true;
            $wooData['stock_quantity'] = $variant['quantity'] ?? 0;
        } else {
            $wooData['type'] = 'simple';
            $wooData['regular_price'] = number_format($data['price'], 2, '.', '');
            $wooData['manage_stock'] = true;
            $wooData['stock_quantity'] = $data['quantity'] ?? 0;
        }

        if (! empty($data['images'])) {
            $wooData['images'] = array_map(fn ($url) => ['src' => $url], $data['images']);
        }

        if (! empty($data['category'])) {
            $wooData['categories'] = [['name' => $data['category']]];
        }

        if (! empty($data['weight'])) {
            $wooData['weight'] = (string) $data['weight'];
        }

        return $wooData;
    }

    /**
     * Match WooCommerce response variant IDs to listing variants by SKU.
     *
     * @param  array<int>  $variationIds
     */
    protected function syncVariantExternalIds(PlatformListing $listing, array $variationIds): void
    {
        if (empty($variationIds)) {
            return;
        }

        $listing->loadMissing('listingVariants');

        // WooCommerce returns variation IDs as integers in the product response.
        // We need to fetch each variation to get its SKU for matching.
        foreach ($variationIds as $variationId) {
            $response = $this->wooRequest('GET', "products/{$listing->external_listing_id}/variations/{$variationId}");

            if (! $response->successful()) {
                continue;
            }

            $wooVariant = $response->json();
            $sku = $wooVariant['sku'] ?? null;

            $listingVariant = $listing->listingVariants
                ->first(fn ($lv) => $lv->getEffectiveSku() === $sku);

            if (! $listingVariant && $listing->listingVariants->count() === 1 && count($variationIds) === 1) {
                $listingVariant = $listing->listingVariants->first();
            }

            if ($listingVariant) {
                $listingVariant->update([
                    'external_variant_id' => (string) $wooVariant['id'],
                    'platform_data' => $wooVariant,
                ]);
            }
        }
    }

    protected function wooRequest(string $method, string $endpoint, array $data = []): \Illuminate\Http\Client\Response
    {
        $siteUrl = $this->getCredential('site_url');
        $consumerKey = $this->getCredential('consumer_key');
        $consumerSecret = decrypt($this->getCredential('consumer_secret'));

        $url = "{$siteUrl}/wp-json/{$this->apiVersion}/{$endpoint}";

        $request = Http::withBasicAuth($consumerKey, $consumerSecret)
            ->withHeaders(['Content-Type' => 'application/json']);

        return match (strtoupper($method)) {
            'GET' => $request->get($url, $data),
            'POST' => $request->post($url, $data),
            'PUT' => $request->put($url, $data),
            'DELETE' => $request->delete($url, $data),
            default => throw new \InvalidArgumentException("Unsupported HTTP method: {$method}"),
        };
    }
}
