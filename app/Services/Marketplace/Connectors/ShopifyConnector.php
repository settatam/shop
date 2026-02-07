<?php

namespace App\Services\Marketplace\Connectors;

use App\Enums\Platform;
use App\Services\Marketplace\DTOs\InventoryUpdate;
use App\Services\Marketplace\DTOs\PlatformOrder;
use App\Services\Marketplace\DTOs\PlatformProduct;
use Carbon\Carbon;
use Illuminate\Http\Client\Response;

class ShopifyConnector extends BasePlatformConnector
{
    protected const API_VERSION = '2024-01';

    public function getPlatform(): Platform
    {
        return Platform::Shopify;
    }

    protected function getBaseUrl(): string
    {
        $this->ensureInitialized();

        $domain = $this->marketplace->shop_domain;

        return "https://{$domain}/admin/api/".self::API_VERSION;
    }

    protected function getAuthHeaders(): array
    {
        $this->ensureInitialized();

        return [
            'X-Shopify-Access-Token' => $this->marketplace->access_token,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
    }

    protected function parseRateLimitHeaders(Response $response): void
    {
        // Shopify uses X-Shopify-Shop-Api-Call-Limit format: "32/40"
        $limit = $response->header('X-Shopify-Shop-Api-Call-Limit');
        if ($limit && str_contains($limit, '/')) {
            [$used, $total] = explode('/', $limit);
            $this->rateLimitTotal = (int) $total;
            $this->rateLimitRemaining = $this->rateLimitTotal - (int) $used;
        }
    }

    // ========================================
    // Product Operations
    // ========================================

    public function getProducts(int $limit = 250, ?string $cursor = null): array
    {
        $params = ['limit' => min($limit, 250)];

        if ($cursor) {
            $params['page_info'] = $cursor;
        }

        $response = $this->request('GET', '/products.json', $params);
        $data = $response->json('products', []);

        return array_map(fn ($product) => $this->transformProduct($product), $data);
    }

    public function getProduct(string $externalId): ?PlatformProduct
    {
        try {
            $response = $this->request('GET', "/products/{$externalId}.json");
            $data = $response->json('product');

            return $data ? $this->transformProduct($data) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function createProduct(PlatformProduct $product): ?string
    {
        $payload = $this->buildProductPayload($product);

        $response = $this->request('POST', '/products.json', ['product' => $payload]);

        return $response->json('product.id');
    }

    public function updateProduct(string $externalId, PlatformProduct $product): bool
    {
        $payload = $this->buildProductPayload($product);

        try {
            $this->request('PUT', "/products/{$externalId}.json", ['product' => $payload]);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function deleteProduct(string $externalId): bool
    {
        try {
            $this->request('DELETE', "/products/{$externalId}.json");

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    protected function transformProduct(array $data): PlatformProduct
    {
        $variant = $data['variants'][0] ?? [];

        return new PlatformProduct(
            externalId: (string) $data['id'],
            title: $data['title'] ?? '',
            description: $data['body_html'] ?? '',
            sku: $variant['sku'] ?? null,
            barcode: $variant['barcode'] ?? null,
            price: (float) ($variant['price'] ?? 0),
            compareAtPrice: isset($variant['compare_at_price']) ? (float) $variant['compare_at_price'] : null,
            quantity: (int) ($variant['inventory_quantity'] ?? 0),
            weight: isset($variant['weight']) ? (float) $variant['weight'] : null,
            weightUnit: $variant['weight_unit'] ?? 'lb',
            brand: $data['vendor'] ?? null,
            category: $data['product_type'] ?? null,
            images: array_map(fn ($img) => $img['src'], $data['images'] ?? []),
            attributes: $data['options'] ?? [],
            variants: $this->transformVariants($data['variants'] ?? []),
            status: $data['status'] ?? 'active',
            metadata: [
                'handle' => $data['handle'] ?? null,
                'tags' => $data['tags'] ?? '',
                'published_at' => $data['published_at'] ?? null,
            ],
        );
    }

    protected function transformVariants(array $variants): array
    {
        return array_map(fn ($v) => [
            'external_id' => (string) $v['id'],
            'sku' => $v['sku'] ?? null,
            'barcode' => $v['barcode'] ?? null,
            'price' => (float) ($v['price'] ?? 0),
            'compare_at_price' => isset($v['compare_at_price']) ? (float) $v['compare_at_price'] : null,
            'quantity' => (int) ($v['inventory_quantity'] ?? 0),
            'weight' => isset($v['weight']) ? (float) $v['weight'] : null,
            'option1' => $v['option1'] ?? null,
            'option2' => $v['option2'] ?? null,
            'option3' => $v['option3'] ?? null,
            'inventory_item_id' => $v['inventory_item_id'] ?? null,
        ], $variants);
    }

    protected function buildProductPayload(PlatformProduct $product): array
    {
        $payload = [
            'title' => $product->title,
            'body_html' => $product->description,
            'vendor' => $product->brand,
            'product_type' => $product->category,
            'status' => $product->status === 'active' ? 'active' : 'draft',
        ];

        if (! empty($product->variants)) {
            $payload['variants'] = array_map(fn ($v) => [
                'sku' => $v['sku'] ?? $product->sku,
                'barcode' => $v['barcode'] ?? $product->barcode,
                'price' => $v['price'] ?? $product->price,
                'compare_at_price' => $v['compare_at_price'] ?? $product->compareAtPrice,
                'inventory_quantity' => $v['quantity'] ?? $product->quantity,
                'weight' => $v['weight'] ?? $product->weight,
                'weight_unit' => $product->weightUnit,
            ], $product->variants);
        } else {
            $payload['variants'] = [[
                'sku' => $product->sku,
                'barcode' => $product->barcode,
                'price' => $product->price,
                'compare_at_price' => $product->compareAtPrice,
                'inventory_quantity' => $product->quantity,
                'weight' => $product->weight,
                'weight_unit' => $product->weightUnit,
            ]];
        }

        if (! empty($product->images)) {
            $payload['images'] = array_map(fn ($url) => ['src' => $url], $product->images);
        }

        return $payload;
    }

    // ========================================
    // Order Operations
    // ========================================

    public function getOrders(?\DateTimeInterface $since = null, int $limit = 250): array
    {
        $params = [
            'limit' => min($limit, 250),
            'status' => 'any',
        ];

        if ($since) {
            $params['created_at_min'] = $since->format('c');
        }

        $response = $this->request('GET', '/orders.json', $params);
        $data = $response->json('orders', []);

        return array_map(fn ($order) => $this->transformOrder($order), $data);
    }

    public function getOrder(string $externalId): ?PlatformOrder
    {
        try {
            $response = $this->request('GET', "/orders/{$externalId}.json");
            $data = $response->json('order');

            return $data ? $this->transformOrder($data) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function fulfillOrder(string $externalId, array $fulfillmentData): bool
    {
        try {
            // First get the fulfillment order
            $response = $this->request('GET', "/orders/{$externalId}/fulfillment_orders.json");
            $fulfillmentOrders = $response->json('fulfillment_orders', []);

            if (empty($fulfillmentOrders)) {
                return false;
            }

            $fulfillmentOrderId = $fulfillmentOrders[0]['id'];

            // Create fulfillment
            $payload = [
                'fulfillment' => [
                    'line_items_by_fulfillment_order' => [
                        [
                            'fulfillment_order_id' => $fulfillmentOrderId,
                        ],
                    ],
                    'tracking_info' => [
                        'number' => $fulfillmentData['tracking_number'] ?? null,
                        'company' => $fulfillmentData['carrier'] ?? null,
                        'url' => $fulfillmentData['tracking_url'] ?? null,
                    ],
                    'notify_customer' => $fulfillmentData['notify_customer'] ?? true,
                ],
            ];

            $this->request('POST', '/fulfillments.json', $payload);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    protected function transformOrder(array $data): PlatformOrder
    {
        return new PlatformOrder(
            externalId: (string) $data['id'],
            orderNumber: $data['name'] ?? $data['order_number'] ?? null,
            status: $this->mapOrderStatus($data),
            fulfillmentStatus: $data['fulfillment_status'] ?? 'unfulfilled',
            paymentStatus: $data['financial_status'] ?? 'pending',
            total: (float) ($data['total_price'] ?? 0),
            subtotal: (float) ($data['subtotal_price'] ?? 0),
            shippingCost: (float) ($data['total_shipping_price_set']['shop_money']['amount'] ?? 0),
            tax: (float) ($data['total_tax'] ?? 0),
            discount: (float) ($data['total_discounts'] ?? 0),
            currency: $data['currency'] ?? 'USD',
            customer: $this->transformCustomer($data['customer'] ?? []),
            shippingAddress: $data['shipping_address'] ?? [],
            billingAddress: $data['billing_address'] ?? [],
            lineItems: $this->transformLineItems($data['line_items'] ?? []),
            orderedAt: isset($data['created_at']) ? Carbon::parse($data['created_at']) : null,
            metadata: [
                'note' => $data['note'] ?? null,
                'tags' => $data['tags'] ?? '',
                'source_name' => $data['source_name'] ?? null,
            ],
        );
    }

    protected function mapOrderStatus(array $data): string
    {
        if ($data['cancelled_at'] ?? null) {
            return 'cancelled';
        }

        if ($data['closed_at'] ?? null) {
            return 'completed';
        }

        return 'pending';
    }

    protected function transformCustomer(array $customer): array
    {
        if (empty($customer)) {
            return [];
        }

        return [
            'external_id' => (string) ($customer['id'] ?? ''),
            'email' => $customer['email'] ?? null,
            'first_name' => $customer['first_name'] ?? null,
            'last_name' => $customer['last_name'] ?? null,
            'phone' => $customer['phone'] ?? null,
        ];
    }

    protected function transformLineItems(array $lineItems): array
    {
        return array_map(fn ($item) => [
            'external_id' => (string) ($item['id'] ?? ''),
            'product_id' => (string) ($item['product_id'] ?? ''),
            'variant_id' => (string) ($item['variant_id'] ?? ''),
            'sku' => $item['sku'] ?? null,
            'title' => $item['title'] ?? '',
            'quantity' => (int) ($item['quantity'] ?? 1),
            'price' => (float) ($item['price'] ?? 0),
            'total' => (float) ($item['price'] ?? 0) * (int) ($item['quantity'] ?? 1),
        ], $lineItems);
    }

    // ========================================
    // Inventory Operations
    // ========================================

    public function updateInventory(InventoryUpdate $update): bool
    {
        try {
            // Need to get the inventory item ID and location ID
            if (! $update->externalVariantId) {
                return false;
            }

            $response = $this->request('GET', "/variants/{$update->externalVariantId}.json");
            $inventoryItemId = $response->json('variant.inventory_item_id');

            if (! $inventoryItemId) {
                return false;
            }

            // Get locations
            $locationId = $update->locationId;
            if (! $locationId) {
                $locResponse = $this->request('GET', '/locations.json');
                $locations = $locResponse->json('locations', []);
                $locationId = $locations[0]['id'] ?? null;
            }

            if (! $locationId) {
                return false;
            }

            if ($update->adjustmentType === 'set') {
                $this->request('POST', '/inventory_levels/set.json', [
                    'inventory_item_id' => $inventoryItemId,
                    'location_id' => $locationId,
                    'available' => $update->quantity,
                ]);
            } else {
                $this->request('POST', '/inventory_levels/adjust.json', [
                    'inventory_item_id' => $inventoryItemId,
                    'location_id' => $locationId,
                    'available_adjustment' => $update->quantity,
                ]);
            }

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
        // Shopify doesn't have a categories API, product_type is freeform
        // Return custom collections as a proxy
        try {
            $response = $this->request('GET', '/custom_collections.json');
            $collections = $response->json('custom_collections', []);

            return array_map(fn ($c) => [
                'id' => (string) $c['id'],
                'name' => $c['title'],
                'handle' => $c['handle'],
            ], $collections);
        } catch (\Throwable) {
            return [];
        }
    }

    public function getCategoryAttributes(string $categoryId): array
    {
        // Shopify uses product options, not category-specific attributes
        return [
            'options' => [
                ['name' => 'Size', 'type' => 'string'],
                ['name' => 'Color', 'type' => 'string'],
                ['name' => 'Material', 'type' => 'string'],
            ],
        ];
    }
}
