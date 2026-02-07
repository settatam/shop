<?php

namespace App\Services\Marketplace\Connectors;

use App\Enums\Platform;
use App\Services\Marketplace\DTOs\InventoryUpdate;
use App\Services\Marketplace\DTOs\PlatformOrder;
use App\Services\Marketplace\DTOs\PlatformProduct;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;

class WalmartConnector extends BasePlatformConnector
{
    protected const API_VERSION = 'v3';

    public function getPlatform(): Platform
    {
        return Platform::Walmart;
    }

    protected function getBaseUrl(): string
    {
        return 'https://marketplace.walmartapis.com/'.self::API_VERSION;
    }

    protected function getAuthHeaders(): array
    {
        $this->ensureInitialized();

        $credentials = $this->marketplace->credentials;
        $clientId = $credentials['client_id'] ?? '';
        $clientSecret = $credentials['client_secret'] ?? '';

        // Walmart uses OAuth 2.0 with client credentials
        return [
            'Authorization' => 'Basic '.base64_encode("{$clientId}:{$clientSecret}"),
            'WM_SEC.ACCESS_TOKEN' => $this->marketplace->access_token,
            'WM_QOS.CORRELATION_ID' => uniqid('walmart_'),
            'WM_SVC.NAME' => 'Shopmata',
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
    }

    protected function refreshTokens(): bool
    {
        if (! $this->marketplace) {
            return false;
        }

        $credentials = $this->marketplace->credentials;
        $clientId = $credentials['client_id'] ?? '';
        $clientSecret = $credentials['client_secret'] ?? '';

        if (! $clientId || ! $clientSecret) {
            return false;
        }

        try {
            $response = Http::asForm()
                ->withBasicAuth($clientId, $clientSecret)
                ->post('https://marketplace.walmartapis.com/v3/token', [
                    'grant_type' => 'client_credentials',
                ]);

            if ($response->successful()) {
                $data = $response->json();
                $this->marketplace->update([
                    'access_token' => $data['access_token'],
                    'token_expires_at' => now()->addSeconds($data['expires_in'] ?? 900),
                ]);

                return true;
            }
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
        }

        return false;
    }

    // ========================================
    // Product Operations
    // ========================================

    public function getProducts(int $limit = 250, ?string $cursor = null): array
    {
        $params = ['limit' => min($limit, 200)];

        if ($cursor) {
            $params['nextCursor'] = $cursor;
        }

        try {
            $response = $this->request('GET', '/items', $params);
            $data = $response->json('ItemResponse', []);

            return array_map(fn ($item) => $this->transformProduct($item), $data);
        } catch (\Throwable) {
            return [];
        }
    }

    public function getProduct(string $externalId): ?PlatformProduct
    {
        try {
            $response = $this->request('GET', "/items/{$externalId}");
            $data = $response->json();

            return $data ? $this->transformProduct($data) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function createProduct(PlatformProduct $product): ?string
    {
        try {
            $payload = $this->buildProductPayload($product);

            $response = $this->request('POST', '/feeds', [
                'feedType' => 'item',
                'file' => json_encode($payload),
            ]);

            return $response->json('feedId');
        } catch (\Throwable) {
            return null;
        }
    }

    public function updateProduct(string $externalId, PlatformProduct $product): bool
    {
        try {
            $payload = $this->buildProductPayload($product);

            $this->request('PUT', "/items/{$externalId}", $payload);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function deleteProduct(string $externalId): bool
    {
        try {
            $this->request('DELETE', "/items/{$externalId}");

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    protected function transformProduct(array $data): PlatformProduct
    {
        return new PlatformProduct(
            externalId: $data['sku'] ?? $data['wpid'] ?? '',
            title: $data['productName'] ?? '',
            description: $data['shortDescription'] ?? '',
            sku: $data['sku'] ?? null,
            barcode: $data['upc'] ?? $data['gtin'] ?? null,
            price: (float) ($data['price']['amount'] ?? 0),
            compareAtPrice: isset($data['msrp']) ? (float) $data['msrp'] : null,
            quantity: (int) ($data['availableQuantity'] ?? 0),
            weight: isset($data['weight']['value']) ? (float) $data['weight']['value'] : null,
            weightUnit: $data['weight']['unit'] ?? 'lb',
            brand: $data['brand'] ?? null,
            category: $data['productCategory'] ?? null,
            images: $this->extractImages($data),
            attributes: $data['attributes'] ?? [],
            condition: $data['condition'] ?? 'new',
            status: $data['publishedStatus'] ?? 'active',
            metadata: [
                'wpid' => $data['wpid'] ?? null,
                'lifecycle_status' => $data['lifecycleStatus'] ?? null,
                'shelf_name' => $data['shelfName'] ?? null,
            ],
        );
    }

    protected function extractImages(array $data): array
    {
        $images = [];

        if (isset($data['mainImageUrl'])) {
            $images[] = $data['mainImageUrl'];
        }

        foreach ($data['additionalProductImages'] ?? [] as $img) {
            $images[] = $img['url'] ?? $img;
        }

        return $images;
    }

    protected function buildProductPayload(PlatformProduct $product): array
    {
        return [
            'sku' => $product->sku,
            'productIdentifiers' => [
                'productIdType' => 'UPC',
                'productId' => $product->barcode,
            ],
            'productName' => $product->title,
            'shortDescription' => substr($product->description, 0, 1000),
            'brand' => $product->brand,
            'price' => [
                'currency' => 'USD',
                'amount' => $product->price,
            ],
            'ShippingWeight' => [
                'value' => $product->weight ?? 1,
                'unit' => strtoupper($product->weightUnit ?? 'LB'),
            ],
        ];
    }

    // ========================================
    // Order Operations
    // ========================================

    public function getOrders(?\DateTimeInterface $since = null, int $limit = 250): array
    {
        $params = ['limit' => min($limit, 200)];

        if ($since) {
            $params['createdStartDate'] = $since->format('Y-m-d');
        }

        try {
            $response = $this->request('GET', '/orders', $params);
            $data = $response->json('list.elements.order', []);

            return array_map(fn ($order) => $this->transformOrder($order), $data);
        } catch (\Throwable) {
            return [];
        }
    }

    public function getOrder(string $externalId): ?PlatformOrder
    {
        try {
            $response = $this->request('GET', "/orders/{$externalId}");
            $data = $response->json('order');

            return $data ? $this->transformOrder($data) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function fulfillOrder(string $externalId, array $fulfillmentData): bool
    {
        try {
            $payload = [
                'orderShipment' => [
                    'orderLines' => [
                        'orderLine' => [
                            [
                                'lineNumber' => '1',
                                'orderLineStatuses' => [
                                    'orderLineStatus' => [
                                        [
                                            'status' => 'Shipped',
                                            'trackingInfo' => [
                                                'shipDateTime' => now()->format('Y-m-d\TH:i:s.000\Z'),
                                                'carrierName' => [
                                                    'carrier' => $fulfillmentData['carrier'] ?? 'USPS',
                                                ],
                                                'trackingNumber' => $fulfillmentData['tracking_number'] ?? '',
                                                'trackingURL' => $fulfillmentData['tracking_url'] ?? '',
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ];

            $this->request('POST', "/orders/{$externalId}/shipping", $payload);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    protected function transformOrder(array $data): PlatformOrder
    {
        $orderLines = $data['orderLines']['orderLine'] ?? [];

        return new PlatformOrder(
            externalId: $data['purchaseOrderId'] ?? '',
            orderNumber: $data['customerOrderId'] ?? null,
            status: strtolower($data['orderStatus'] ?? 'created'),
            fulfillmentStatus: $this->mapWalmartFulfillment($data['orderStatus'] ?? ''),
            paymentStatus: 'paid', // Walmart handles payment
            total: (float) ($data['orderTotal']['amount'] ?? 0),
            subtotal: (float) ($data['orderSubTotal']['amount'] ?? 0),
            shippingCost: (float) ($data['shippingInfo']['estimatedShipCost']['amount'] ?? 0),
            tax: (float) ($data['orderTax']['amount'] ?? 0),
            discount: 0.0,
            currency: 'USD',
            customer: [
                'name' => $data['shippingInfo']['postalAddress']['name'] ?? null,
                'phone' => $data['shippingInfo']['phone'] ?? null,
            ],
            shippingAddress: $this->transformWalmartAddress($data['shippingInfo']['postalAddress'] ?? []),
            lineItems: $this->transformWalmartLineItems($orderLines),
            orderedAt: isset($data['orderDate']) ? Carbon::parse($data['orderDate']) : null,
            metadata: [
                'customer_order_id' => $data['customerOrderId'] ?? null,
                'estimated_delivery' => $data['shippingInfo']['estimatedDeliveryDate'] ?? null,
            ],
        );
    }

    protected function mapWalmartFulfillment(string $status): string
    {
        return match (strtolower($status)) {
            'shipped' => 'fulfilled',
            'delivered' => 'delivered',
            'cancelled' => 'cancelled',
            default => 'unfulfilled',
        };
    }

    protected function transformWalmartAddress(array $address): array
    {
        return [
            'name' => $address['name'] ?? null,
            'address1' => $address['address1'] ?? null,
            'address2' => $address['address2'] ?? null,
            'city' => $address['city'] ?? null,
            'state' => $address['state'] ?? null,
            'postal_code' => $address['postalCode'] ?? null,
            'country' => $address['country'] ?? 'US',
        ];
    }

    protected function transformWalmartLineItems(array $lines): array
    {
        return array_map(fn ($line) => [
            'sku' => $line['item']['sku'] ?? null,
            'title' => $line['item']['productName'] ?? '',
            'quantity' => (int) ($line['orderLineQuantity']['amount'] ?? 1),
            'price' => (float) ($line['charges']['charge'][0]['chargeAmount']['amount'] ?? 0),
        ], $lines);
    }

    // ========================================
    // Inventory Operations
    // ========================================

    public function updateInventory(InventoryUpdate $update): bool
    {
        try {
            $payload = [
                'sku' => $update->sku,
                'quantity' => [
                    'unit' => 'EACH',
                    'amount' => $update->quantity,
                ],
            ];

            $this->request('PUT', '/inventory', $payload);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function bulkUpdateInventory(array $updates): array
    {
        try {
            $items = array_map(fn ($u) => [
                'sku' => $u->sku,
                'quantity' => [
                    'unit' => 'EACH',
                    'amount' => $u->quantity,
                ],
            ], $updates);

            $this->request('PUT', '/feeds', [
                'feedType' => 'inventory',
                'InventoryFeed' => ['inventory' => $items],
            ]);

            return array_fill_keys(array_map(fn ($u) => $u->sku, $updates), true);
        } catch (\Throwable) {
            return array_fill_keys(array_map(fn ($u) => $u->sku, $updates), false);
        }
    }

    // ========================================
    // Category Operations
    // ========================================

    public function getCategories(): array
    {
        try {
            $response = $this->request('GET', '/items/taxonomy');
            $data = $response->json('categories', []);

            return array_map(fn ($cat) => [
                'id' => $cat['id'] ?? '',
                'name' => $cat['name'] ?? '',
                'path' => $cat['path'] ?? '',
            ], $data);
        } catch (\Throwable) {
            return [];
        }
    }

    public function getCategoryAttributes(string $categoryId): array
    {
        try {
            $response = $this->request('GET', "/items/taxonomy/{$categoryId}");

            return $response->json('attributes', []);
        } catch (\Throwable) {
            return [];
        }
    }
}
