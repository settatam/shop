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
            // Get variant ID first
            $product = $this->getShopifyProduct($listing->external_listing_id);
            $variantId = $product['variants'][0]['id'] ?? null;

            if (! $variantId) {
                return PlatformAdapterResult::failure('No variant found');
            }

            $response = $this->apiRequest('PUT', "/variants/{$variantId}.json", [
                'variant' => [
                    'id' => $variantId,
                    'price' => number_format($price, 2, '.', ''),
                ],
            ]);

            if (! $response->successful()) {
                return PlatformAdapterResult::failure('Failed to update price: '.$response->body());
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
            $product = $this->getShopifyProduct($listing->external_listing_id);
            $inventoryItemId = $product['variants'][0]['inventory_item_id'] ?? null;

            if (! $inventoryItemId) {
                return PlatformAdapterResult::failure('No inventory item found');
            }

            // Get location ID
            $locationsResponse = $this->apiRequest('GET', '/locations.json');
            $locationId = $locationsResponse->json('locations.0.id');

            if (! $locationId) {
                return PlatformAdapterResult::failure('No location found');
            }

            $response = $this->apiRequest('POST', '/inventory_levels/set.json', [
                'location_id' => $locationId,
                'inventory_item_id' => $inventoryItemId,
                'available' => $quantity,
            ]);

            if (! $response->successful()) {
                return PlatformAdapterResult::failure('Failed to update inventory: '.$response->body());
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
                'active' => PlatformListing::STATUS_ACTIVE,
                'draft' => PlatformListing::STATUS_DRAFT,
                'archived' => PlatformListing::STATUS_ENDED,
                default => PlatformListing::STATUS_DRAFT,
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
            'variants' => [
                [
                    'price' => number_format($data['price'], 2, '.', ''),
                    'sku' => $data['sku'],
                    'barcode' => $data['barcode'],
                    'inventory_management' => 'shopify',
                    'inventory_quantity' => $data['quantity'],
                ],
            ],
        ];

        // Add images if available
        if (! empty($data['images'])) {
            $shopifyData['images'] = array_map(fn ($url) => ['src' => $url], $data['images']);
        }

        return $shopifyData;
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
