<?php

namespace App\Services\Marketplace\Connectors;

use App\Enums\Platform;
use App\Services\Marketplace\DTOs\InventoryUpdate;
use App\Services\Marketplace\DTOs\PlatformOrder;
use App\Services\Marketplace\DTOs\PlatformProduct;
use Carbon\Carbon;

class BigCommerceConnector extends BasePlatformConnector
{
    protected const API_VERSION = 'v3';

    public function getPlatform(): Platform
    {
        return Platform::BigCommerce;
    }

    protected function getBaseUrl(): string
    {
        $this->ensureInitialized();

        $storeHash = $this->marketplace->external_store_id;

        return "https://api.bigcommerce.com/stores/{$storeHash}/".self::API_VERSION;
    }

    protected function getAuthHeaders(): array
    {
        $this->ensureInitialized();

        return [
            'X-Auth-Token' => $this->marketplace->access_token,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
    }

    // ========================================
    // Product Operations
    // ========================================

    public function getProducts(int $limit = 250, ?string $cursor = null): array
    {
        $params = [
            'limit' => min($limit, 250),
            'include' => 'images,variants',
        ];

        if ($cursor) {
            $params['page'] = (int) $cursor;
        }

        try {
            $response = $this->request('GET', '/catalog/products', $params);
            $data = $response->json('data', []);

            return array_map(fn ($product) => $this->transformProduct($product), $data);
        } catch (\Throwable) {
            return [];
        }
    }

    public function getProduct(string $externalId): ?PlatformProduct
    {
        try {
            $response = $this->request('GET', "/catalog/products/{$externalId}", [
                'include' => 'images,variants',
            ]);
            $data = $response->json('data');

            return $data ? $this->transformProduct($data) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function createProduct(PlatformProduct $product): ?string
    {
        try {
            $payload = $this->buildProductPayload($product);

            $response = $this->request('POST', '/catalog/products', $payload);

            return (string) $response->json('data.id');
        } catch (\Throwable) {
            return null;
        }
    }

    public function updateProduct(string $externalId, PlatformProduct $product): bool
    {
        try {
            $payload = $this->buildProductPayload($product);

            $this->request('PUT', "/catalog/products/{$externalId}", $payload);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function deleteProduct(string $externalId): bool
    {
        try {
            $this->request('DELETE', "/catalog/products/{$externalId}");

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    protected function transformProduct(array $data): PlatformProduct
    {
        $images = array_map(fn ($img) => $img['url_standard'] ?? $img['url_thumbnail'], $data['images'] ?? []);
        $variants = $data['variants'] ?? [];

        return new PlatformProduct(
            externalId: (string) ($data['id'] ?? ''),
            title: $data['name'] ?? '',
            description: $data['description'] ?? '',
            sku: $data['sku'] ?? null,
            barcode: $data['upc'] ?? $data['gtin'] ?? null,
            price: (float) ($data['price'] ?? 0),
            compareAtPrice: isset($data['retail_price']) ? (float) $data['retail_price'] : null,
            quantity: (int) ($data['inventory_level'] ?? 0),
            weight: isset($data['weight']) ? (float) $data['weight'] : null,
            weightUnit: 'lb',
            brand: $data['brand_id'] ? (string) $data['brand_id'] : null,
            category: null,
            categoryId: isset($data['categories'][0]) ? (string) $data['categories'][0] : null,
            images: $images,
            attributes: $data['custom_fields'] ?? [],
            variants: $this->transformVariants($variants),
            condition: $data['condition'] ?? 'new',
            status: $data['is_visible'] ? 'active' : 'draft',
            metadata: [
                'type' => $data['type'] ?? 'physical',
                'availability' => $data['availability'] ?? 'available',
                'inventory_tracking' => $data['inventory_tracking'] ?? 'none',
            ],
        );
    }

    protected function transformVariants(array $variants): array
    {
        return array_map(fn ($v) => [
            'external_id' => (string) $v['id'],
            'sku' => $v['sku'] ?? null,
            'barcode' => $v['upc'] ?? null,
            'price' => (float) ($v['price'] ?? 0),
            'quantity' => (int) ($v['inventory_level'] ?? 0),
            'weight' => isset($v['weight']) ? (float) $v['weight'] : null,
            'options' => $v['option_values'] ?? [],
        ], $variants);
    }

    protected function buildProductPayload(PlatformProduct $product): array
    {
        $payload = [
            'name' => $product->title,
            'type' => 'physical',
            'description' => $product->description,
            'price' => $product->price,
            'sku' => $product->sku,
            'weight' => $product->weight ?? 0,
            'is_visible' => $product->status === 'active',
        ];

        if ($product->barcode) {
            $payload['upc'] = $product->barcode;
        }

        if ($product->compareAtPrice) {
            $payload['retail_price'] = $product->compareAtPrice;
        }

        if ($product->categoryId) {
            $payload['categories'] = [(int) $product->categoryId];
        }

        return $payload;
    }

    // ========================================
    // Order Operations
    // ========================================

    public function getOrders(?\DateTimeInterface $since = null, int $limit = 250): array
    {
        $params = ['limit' => min($limit, 250)];

        if ($since) {
            $params['min_date_created'] = $since->format('c');
        }

        try {
            // BigCommerce v2 API for orders
            $baseUrl = str_replace('/v3', '/v2', $this->getBaseUrl());
            $response = $this->getHttpClient()
                ->withHeaders($this->getAuthHeaders())
                ->get("{$baseUrl}/orders", $params);

            $data = $response->json() ?? [];

            return array_map(fn ($order) => $this->transformOrder($order), $data);
        } catch (\Throwable) {
            return [];
        }
    }

    public function getOrder(string $externalId): ?PlatformOrder
    {
        try {
            $baseUrl = str_replace('/v3', '/v2', $this->getBaseUrl());
            $response = $this->getHttpClient()
                ->withHeaders($this->getAuthHeaders())
                ->get("{$baseUrl}/orders/{$externalId}");

            $data = $response->json();

            return $data ? $this->transformOrder($data) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function fulfillOrder(string $externalId, array $fulfillmentData): bool
    {
        try {
            $baseUrl = str_replace('/v3', '/v2', $this->getBaseUrl());

            // Create shipment
            $payload = [
                'tracking_number' => $fulfillmentData['tracking_number'] ?? '',
                'shipping_method' => $fulfillmentData['method'] ?? 'Standard',
                'shipping_provider' => $fulfillmentData['carrier'] ?? '',
                'order_address_id' => $fulfillmentData['address_id'] ?? 1,
                'items' => $fulfillmentData['items'] ?? [],
            ];

            $this->getHttpClient()
                ->withHeaders($this->getAuthHeaders())
                ->post("{$baseUrl}/orders/{$externalId}/shipments", $payload);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    protected function transformOrder(array $data): PlatformOrder
    {
        return new PlatformOrder(
            externalId: (string) ($data['id'] ?? ''),
            orderNumber: (string) ($data['id'] ?? ''),
            status: strtolower($data['status'] ?? 'pending'),
            fulfillmentStatus: $this->mapBigCommerceFulfillment($data['status_id'] ?? 0),
            paymentStatus: $data['payment_status'] ?? 'pending',
            total: (float) ($data['total_inc_tax'] ?? 0),
            subtotal: (float) ($data['subtotal_inc_tax'] ?? 0),
            shippingCost: (float) ($data['shipping_cost_inc_tax'] ?? 0),
            tax: (float) ($data['total_tax'] ?? 0),
            discount: (float) ($data['discount_amount'] ?? 0),
            currency: $data['currency_code'] ?? 'USD',
            customer: [
                'id' => (string) ($data['customer_id'] ?? ''),
                'email' => $data['billing_address']['email'] ?? null,
                'first_name' => $data['billing_address']['first_name'] ?? null,
                'last_name' => $data['billing_address']['last_name'] ?? null,
            ],
            shippingAddress: $this->transformBigCommerceAddress($data['shipping_addresses'][0] ?? $data['billing_address'] ?? []),
            billingAddress: $this->transformBigCommerceAddress($data['billing_address'] ?? []),
            lineItems: $this->transformBigCommerceLineItems($data['products'] ?? []),
            orderedAt: isset($data['date_created']) ? Carbon::parse($data['date_created']) : null,
            metadata: [
                'status_id' => $data['status_id'] ?? null,
                'payment_method' => $data['payment_method'] ?? null,
                'staff_notes' => $data['staff_notes'] ?? null,
            ],
        );
    }

    protected function mapBigCommerceFulfillment(int $statusId): string
    {
        // BigCommerce status IDs: 2=Shipped, 10=Completed
        return match ($statusId) {
            2, 10 => 'fulfilled',
            5 => 'cancelled',
            default => 'unfulfilled',
        };
    }

    protected function transformBigCommerceAddress(array $address): array
    {
        return [
            'first_name' => $address['first_name'] ?? null,
            'last_name' => $address['last_name'] ?? null,
            'address1' => $address['street_1'] ?? null,
            'address2' => $address['street_2'] ?? null,
            'city' => $address['city'] ?? null,
            'state' => $address['state'] ?? null,
            'postal_code' => $address['zip'] ?? null,
            'country' => $address['country'] ?? null,
            'phone' => $address['phone'] ?? null,
        ];
    }

    protected function transformBigCommerceLineItems(array $products): array
    {
        return array_map(fn ($item) => [
            'external_id' => (string) ($item['id'] ?? ''),
            'product_id' => (string) ($item['product_id'] ?? ''),
            'variant_id' => (string) ($item['variant_id'] ?? ''),
            'sku' => $item['sku'] ?? null,
            'title' => $item['name'] ?? '',
            'quantity' => (int) ($item['quantity'] ?? 1),
            'price' => (float) ($item['base_price'] ?? 0),
            'total' => (float) ($item['total_inc_tax'] ?? 0),
        ], $products);
    }

    // ========================================
    // Inventory Operations
    // ========================================

    public function updateInventory(InventoryUpdate $update): bool
    {
        if (! $update->externalId) {
            return false;
        }

        try {
            $this->request('PUT', "/catalog/products/{$update->externalId}", [
                'inventory_level' => $update->quantity,
            ]);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function bulkUpdateInventory(array $updates): array
    {
        $results = [];

        foreach ($updates as $update) {
            $results[$update->sku] = $this->updateInventory($update);
        }

        return $results;
    }

    // ========================================
    // Category Operations
    // ========================================

    public function getCategories(): array
    {
        try {
            $response = $this->request('GET', '/catalog/categories', ['limit' => 250]);
            $data = $response->json('data', []);

            return array_map(fn ($cat) => [
                'id' => (string) $cat['id'],
                'name' => $cat['name'],
                'parent_id' => $cat['parent_id'] ?? null,
                'path' => $cat['url']['path'] ?? null,
            ], $data);
        } catch (\Throwable) {
            return [];
        }
    }

    public function getCategoryAttributes(string $categoryId): array
    {
        // BigCommerce doesn't have category-specific attributes
        // Return custom fields schema
        return [
            'custom_fields' => [
                ['name' => 'Custom Field', 'type' => 'string'],
            ],
        ];
    }
}
