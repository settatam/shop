<?php

namespace App\Services\Platforms\Adapters;

use App\Contracts\Platforms\PlatformAdapterResult;
use App\Models\PlatformListing;
use Illuminate\Support\Facades\Http;

class ShopifyAdapter extends BaseAdapter
{
    protected string $apiVersion = '2024-01';

    public function getPlatform(): string
    {
        return 'shopify';
    }

    public function isConnected(): bool
    {
        return $this->marketplace
            && $this->marketplace->access_token
            && $this->marketplace->shop_domain;
    }

    public function publish(PlatformListing $listing): PlatformAdapterResult
    {
        if (! $this->isConnected()) {
            return PlatformAdapterResult::failure('Shopify is not connected');
        }

        try {
            $productData = $this->buildShopifyProductData($listing);

            // Check if product already exists on Shopify
            if ($listing->external_listing_id) {
                return $this->updateShopifyProduct($listing, $productData);
            }

            // Create new product
            $response = $this->apiRequest('POST', '/products.json', [
                'product' => $productData,
            ]);

            if (! $response->successful()) {
                return PlatformAdapterResult::failure(
                    'Failed to create Shopify product: '.$response->body()
                );
            }

            $shopifyProduct = $response->json('product');
            $productId = $shopifyProduct['id'] ?? null;
            $handle = $shopifyProduct['handle'] ?? null;

            // Update listing variants with external IDs from Shopify response
            $this->syncVariantExternalIds($listing, $shopifyProduct['variants'] ?? []);

            $listingUrl = $handle
                ? "https://{$this->marketplace->shop_domain}/products/{$handle}"
                : null;

            $this->log('Product published', ['shopify_id' => $productId]);

            return PlatformAdapterResult::created(
                externalId: (string) $productId,
                externalUrl: $listingUrl,
                data: ['shopify_product' => $shopifyProduct]
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
            // Set product to draft status in Shopify
            $response = $this->apiRequest('PUT', "/products/{$listing->external_listing_id}.json", [
                'product' => [
                    'id' => $listing->external_listing_id,
                    'status' => 'draft',
                ],
            ]);

            if (! $response->successful()) {
                return PlatformAdapterResult::failure('Failed to unpublish: '.$response->body());
            }

            $this->log('Product unpublished', ['shopify_id' => $listing->external_listing_id]);

            return PlatformAdapterResult::success('Product unpublished from Shopify');
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
            $response = $this->apiRequest('DELETE', "/products/{$listing->external_listing_id}.json");

            if (! $response->successful() && $response->status() !== 404) {
                return PlatformAdapterResult::failure('Failed to delete: '.$response->body());
            }

            $this->log('Product deleted', ['shopify_id' => $listing->external_listing_id]);

            return PlatformAdapterResult::success('Product deleted from Shopify');
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

            // Update all listing variants that have external IDs
            foreach ($listing->listingVariants as $listingVariant) {
                $variantId = $listingVariant->external_variant_id;
                if (! $variantId) {
                    continue;
                }

                $response = $this->apiRequest('PUT', "/variants/{$variantId}.json", [
                    'variant' => [
                        'id' => $variantId,
                        'price' => number_format($listingVariant->getEffectivePrice(), 2, '.', ''),
                    ],
                ]);

                if (! $response->successful()) {
                    return PlatformAdapterResult::failure('Failed to update price: '.$response->body());
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

            // Get location ID once
            $locationsResponse = $this->apiRequest('GET', '/locations.json');
            $locationId = $locationsResponse->json('locations.0.id');

            if (! $locationId) {
                return PlatformAdapterResult::failure('No location found');
            }

            // Update inventory for each listing variant that has an inventory item ID
            foreach ($listing->listingVariants as $listingVariant) {
                $inventoryItemId = $listingVariant->external_inventory_item_id;
                if (! $inventoryItemId) {
                    continue;
                }

                $response = $this->apiRequest('POST', '/inventory_levels/set.json', [
                    'location_id' => $locationId,
                    'inventory_item_id' => $inventoryItemId,
                    'available' => $listingVariant->getEffectiveQuantity(),
                ]);

                if (! $response->successful()) {
                    $this->log('Failed to update inventory for variant', [
                        'variant_id' => $listingVariant->id,
                        'error' => $response->body(),
                    ]);
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

        return $this->updateShopifyProduct($listing, $this->buildShopifyProductData($listing));
    }

    public function refresh(PlatformListing $listing): PlatformAdapterResult
    {
        if (! $this->isConnected() || ! $listing->external_listing_id) {
            return PlatformAdapterResult::failure('Cannot refresh: not connected');
        }

        try {
            $product = $this->getShopifyProduct($listing->external_listing_id);

            if (! $product) {
                return PlatformAdapterResult::failure('Product not found on Shopify');
            }

            $variant = $product['variants'][0] ?? [];
            $status = match ($product['status'] ?? 'draft') {
                'active' => PlatformListing::STATUS_LISTED,
                'draft' => PlatformListing::STATUS_NOT_LISTED,
                'archived' => PlatformListing::STATUS_ENDED,
                default => PlatformListing::STATUS_NOT_LISTED,
            };

            return PlatformAdapterResult::success('Refreshed from Shopify', [
                'status' => $status,
                'price' => (float) ($variant['price'] ?? 0),
                'quantity' => (int) ($variant['inventory_quantity'] ?? 0),
                'url' => $product['handle']
                    ? "https://{$this->marketplace->shop_domain}/products/{$product['handle']}"
                    : null,
            ]);
        } catch (\Throwable $e) {
            return PlatformAdapterResult::failure($e->getMessage(), $e);
        }
    }

    protected function updateShopifyProduct(PlatformListing $listing, array $productData): PlatformAdapterResult
    {
        $response = $this->apiRequest('PUT', "/products/{$listing->external_listing_id}.json", [
            'product' => array_merge(['id' => $listing->external_listing_id], $productData),
        ]);

        if (! $response->successful()) {
            return PlatformAdapterResult::failure('Failed to update: '.$response->body());
        }

        // Sync variant external IDs from response
        $shopifyProduct = $response->json('product');
        if ($shopifyProduct) {
            $this->syncVariantExternalIds($listing, $shopifyProduct['variants'] ?? []);
        }

        $this->log('Product updated', ['shopify_id' => $listing->external_listing_id]);

        return PlatformAdapterResult::success('Product updated on Shopify');
    }

    protected function getShopifyProduct(string $productId): ?array
    {
        $response = $this->apiRequest('GET', "/products/{$productId}.json");

        return $response->successful() ? $response->json('product') : null;
    }

    protected function buildShopifyProductData(PlatformListing $listing): array
    {
        $data = $this->buildProductData($listing);

        $shopifyData = [
            'title' => $data['title'],
            'body_html' => $data['description'],
            'status' => 'active',
        ];

        // Build multi-variant payload
        $variants = $data['variants'] ?? [];
        if (! empty($variants)) {
            $shopifyData['variants'] = array_map(function ($variant) {
                $shopifyVariant = [
                    'price' => number_format($variant['price'], 2, '.', ''),
                    'sku' => $variant['sku'],
                    'barcode' => $variant['barcode'],
                    'inventory_management' => 'shopify',
                    'inventory_quantity' => $variant['quantity'],
                ];

                if ($variant['option1'] ?? null) {
                    $shopifyVariant['option1'] = $variant['option1'];
                }
                if ($variant['option2'] ?? null) {
                    $shopifyVariant['option2'] = $variant['option2'];
                }
                if ($variant['option3'] ?? null) {
                    $shopifyVariant['option3'] = $variant['option3'];
                }

                // Include external variant ID for updates
                if ($variant['external_variant_id'] ?? null) {
                    $shopifyVariant['id'] = $variant['external_variant_id'];
                }

                return $shopifyVariant;
            }, $variants);
        } else {
            // Fallback: single variant from listing-level data
            $shopifyData['variants'] = [
                [
                    'price' => number_format($data['price'], 2, '.', ''),
                    'inventory_management' => 'shopify',
                    'inventory_quantity' => $data['quantity'],
                ],
            ];
        }

        // Add images if available
        if (! empty($data['images'])) {
            $shopifyData['images'] = array_map(fn ($url) => ['src' => $url], $data['images']);
        }

        return $shopifyData;
    }

    /**
     * Match Shopify response variants to listing variants by SKU and update external IDs.
     *
     * @param  array<array<string, mixed>>  $shopifyVariants
     */
    protected function syncVariantExternalIds(PlatformListing $listing, array $shopifyVariants): void
    {
        $listing->loadMissing('listingVariants');

        foreach ($shopifyVariants as $shopifyVariant) {
            $sku = $shopifyVariant['sku'] ?? null;
            $externalVariantId = (string) ($shopifyVariant['id'] ?? '');
            $inventoryItemId = (string) ($shopifyVariant['inventory_item_id'] ?? '');

            // Match by SKU
            $listingVariant = $listing->listingVariants
                ->first(fn ($lv) => $lv->getEffectiveSku() === $sku);

            // If no SKU match and only one variant, match by position
            if (! $listingVariant && $listing->listingVariants->count() === 1 && count($shopifyVariants) === 1) {
                $listingVariant = $listing->listingVariants->first();
            }

            if ($listingVariant) {
                $listingVariant->update([
                    'external_variant_id' => $externalVariantId,
                    'external_inventory_item_id' => $inventoryItemId,
                    'platform_data' => $shopifyVariant,
                ]);
            }
        }
    }

    protected function apiRequest(string $method, string $endpoint, array $data = []): \Illuminate\Http\Client\Response
    {
        $url = "https://{$this->marketplace->shop_domain}/admin/api/{$this->apiVersion}{$endpoint}";

        $request = Http::withHeaders([
            'X-Shopify-Access-Token' => $this->marketplace->access_token,
            'Content-Type' => 'application/json',
        ]);

        return match (strtoupper($method)) {
            'GET' => $request->get($url, $data),
            'POST' => $request->post($url, $data),
            'PUT' => $request->put($url, $data),
            'DELETE' => $request->delete($url),
            default => throw new \InvalidArgumentException("Unsupported HTTP method: {$method}"),
        };
    }
}
