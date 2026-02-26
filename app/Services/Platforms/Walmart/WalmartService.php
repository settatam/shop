<?php

namespace App\Services\Platforms\Walmart;

use App\Enums\Platform;
use App\Models\PlatformListing;
use App\Models\PlatformOrder;
use App\Models\Product;
use App\Models\Store;
use App\Models\StoreMarketplace;
use App\Services\Platforms\BasePlatformService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

class WalmartService extends BasePlatformService
{
    protected string $apiBaseUrl = 'https://marketplace.walmartapis.com';

    protected string $tokenUrl = 'https://marketplace.walmartapis.com/v3/token';

    public function getPlatform(): string
    {
        return Platform::Walmart->value;
    }

    public function connect(Store $store, array $params = []): RedirectResponse
    {
        // Walmart uses API key authentication, not OAuth
        // Redirect to settings page where user enters credentials
        return redirect()->route('settings.integrations.walmart', [
            'store' => $store->id,
        ]);
    }

    public function connectWithCredentials(Store $store, array $credentials): StoreMarketplace
    {
        $clientId = $credentials['client_id'];
        $clientSecret = $credentials['client_secret'];
        $sellerId = $credentials['seller_id'] ?? null;
        $name = $credentials['name'] ?? 'Walmart Marketplace';

        // Get access token
        $response = Http::withHeaders([
            'WM_SVC.NAME' => 'Walmart Marketplace',
            'WM_QOS.CORRELATION_ID' => uniqid(),
            'Accept' => 'application/json',
        ])->withBasicAuth($clientId, $clientSecret)
            ->asForm()
            ->post($this->tokenUrl, [
                'grant_type' => 'client_credentials',
            ]);

        if ($response->failed()) {
            throw new \Exception('Failed to authenticate with Walmart: '.$response->body());
        }

        $data = $response->json();

        // Use seller_id as external_store_id to support multiple accounts
        $uniqueKeys = [
            'store_id' => $store->id,
            'platform' => Platform::Walmart,
        ];
        if ($sellerId) {
            $uniqueKeys['external_store_id'] = $sellerId;
        }

        return StoreMarketplace::updateOrCreate(
            $uniqueKeys,
            [
                'name' => $name,
                'external_store_id' => $sellerId,
                'access_token' => $data['access_token'],
                'token_expires_at' => now()->addSeconds($data['expires_in'] ?? 900),
                'credentials' => [
                    'client_id' => $clientId,
                    'client_secret' => encrypt($clientSecret),
                    'seller_id' => $sellerId,
                ],
                'status' => 'active',
                'connected_successfully' => true,
            ]
        );
    }

    public function handleCallback(Request $request, Store $store): StoreMarketplace
    {
        // Walmart doesn't use OAuth callback
        // This method is called after manual credential entry
        return $this->connectWithCredentials($store, [
            'client_id' => $request->input('client_id'),
            'client_secret' => $request->input('client_secret'),
        ]);
    }

    public function disconnect(StoreMarketplace $connection): void
    {
        $connection->update(['status' => 'inactive']);
        $connection->delete();
    }

    public function refreshToken(StoreMarketplace $connection): StoreMarketplace
    {
        $clientId = $connection->credentials['client_id'];
        $clientSecret = decrypt($connection->credentials['client_secret']);

        $response = Http::withHeaders([
            'WM_SVC.NAME' => 'Walmart Marketplace',
            'WM_QOS.CORRELATION_ID' => uniqid(),
            'Accept' => 'application/json',
        ])->withBasicAuth($clientId, $clientSecret)
            ->asForm()
            ->post($this->tokenUrl, [
                'grant_type' => 'client_credentials',
            ]);

        if ($response->failed()) {
            throw new \Exception('Failed to refresh token: '.$response->body());
        }

        $data = $response->json();

        $connection->update([
            'access_token' => $data['access_token'],
            'token_expires_at' => now()->addSeconds($data['expires_in'] ?? 900),
        ]);

        return $connection->fresh();
    }

    public function validateCredentials(StoreMarketplace $connection): bool
    {
        try {
            $this->ensureValidToken($connection);
            $response = $this->walmartRequest($connection, 'GET', '/v3/feeds');

            return isset($response['totalResults']) || isset($response['elements']);
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function pullProducts(StoreMarketplace $connection): Collection
    {
        $syncLog = $this->logSync($connection, 'products', 'pull');
        $products = collect();

        try {
            $this->ensureValidToken($connection);
            $offset = 0;
            $limit = 50;

            do {
                $response = $this->walmartRequest($connection, 'GET', '/v3/items', [
                    'limit' => $limit,
                    'offset' => $offset,
                ]);

                $items = $response['ItemResponse'] ?? [];

                foreach ($items as $item) {
                    $products->push($this->mapWalmartProduct($item, $connection));
                    $syncLog->incrementProcessed();
                    $syncLog->incrementSuccess();
                }

                $offset += $limit;
                $total = $response['totalItems'] ?? 0;
            } while ($offset < $total);

            $syncLog->markCompleted(['imported_count' => $products->count()]);
            $connection->recordSync();
        } catch (\Throwable $e) {
            $this->handleApiError($connection, $e, 'Pull products failed');
            $syncLog->markFailed([$e->getMessage()]);
        }

        return $products;
    }

    public function pushProduct(Product $product, StoreMarketplace $connection): PlatformListing
    {
        $this->ensureValidToken($connection);

        $itemData = $this->mapToWalmartItem($product, $connection);

        // Create feed for item
        $feedResponse = $this->walmartRequest(
            $connection,
            'POST',
            '/v3/feeds?feedType=item',
            ['ItemFeed' => ['item' => [$itemData]]],
            ['Content-Type' => 'application/json']
        );

        $feedId = $feedResponse['feedId'];

        return PlatformListing::create([
            'store_marketplace_id' => $connection->id,
            'product_id' => $product->id,
            'external_listing_id' => $product->variants->first()?->sku ?? $product->handle,
            'status' => 'pending', // Will update when feed is processed
            'platform_data' => [
                'feed_id' => $feedId,
                'feed_status' => 'RECEIVED',
            ],
            'last_synced_at' => now(),
        ]);
    }

    public function updateListing(PlatformListing $listing): PlatformListing
    {
        $product = $listing->product;
        $connection = $listing->connection;

        $this->ensureValidToken($connection);

        $itemData = $this->mapToWalmartItem($product, $connection);

        $this->walmartRequest(
            $connection,
            'POST',
            '/v3/feeds?feedType=item',
            ['ItemFeed' => ['item' => [$itemData]]],
            ['Content-Type' => 'application/json']
        );

        $listing->update(['last_synced_at' => now()]);

        return $listing;
    }

    public function deleteListing(PlatformListing $listing): void
    {
        $connection = $listing->connection;
        $this->ensureValidToken($connection);

        $this->walmartRequest(
            $connection,
            'DELETE',
            "/v3/items/{$listing->external_listing_id}"
        );

        $listing->delete();
    }

    public function unlistListing(PlatformListing $listing): PlatformListing
    {
        $connection = $listing->marketplace;
        $this->ensureValidToken($connection);

        // Retire the item (unpublish without deleting)
        $this->walmartRequest(
            $connection,
            'DELETE',
            "/v3/items/{$listing->external_listing_id}",
            [],
            ['WM_QOS.CORRELATION_ID' => uniqid()]
        );

        $listing->update([
            'status' => PlatformListing::STATUS_UNLISTED,
            'last_synced_at' => now(),
        ]);

        return $listing->fresh();
    }

    public function relistListing(PlatformListing $listing): PlatformListing
    {
        $connection = $listing->marketplace;
        $this->ensureValidToken($connection);

        $product = $listing->product;

        // Re-create the item on Walmart using the stored product data
        $walmartItem = $this->mapToWalmartItem($product, $connection);

        $this->walmartRequest(
            $connection,
            'POST',
            '/v3/items',
            ['items' => [$walmartItem]]
        );

        $listing->update([
            'status' => PlatformListing::STATUS_ACTIVE,
            'published_at' => now(),
            'last_synced_at' => now(),
        ]);

        return $listing->fresh();
    }

    public function syncInventory(StoreMarketplace $connection): void
    {
        $this->ensureValidToken($connection);
        $listings = $connection->listings()->with('variant')->get();

        $inventoryUpdates = [];
        foreach ($listings as $listing) {
            if (! $listing->variant) {
                continue;
            }

            $inventoryUpdates[] = [
                'sku' => $listing->external_listing_id,
                'quantity' => [
                    'unit' => 'EACH',
                    'amount' => $listing->variant->quantity,
                ],
            ];
        }

        if (! empty($inventoryUpdates)) {
            $this->walmartRequest(
                $connection,
                'POST',
                '/v3/feeds?feedType=inventory',
                ['InventoryFeed' => ['inventory' => $inventoryUpdates]],
                ['Content-Type' => 'application/json']
            );
        }
    }

    public function pullOrders(StoreMarketplace $connection, ?string $since = null): Collection
    {
        $syncLog = $this->logSync($connection, 'orders', 'pull');
        $orders = collect();

        try {
            $this->ensureValidToken($connection);

            $params = ['limit' => 100];
            if ($since) {
                $params['createdStartDate'] = $since;
            } else {
                $params['createdStartDate'] = now()->subDays(30)->toIso8601String();
            }

            $response = $this->walmartRequest($connection, 'GET', '/v3/orders', $params);

            foreach ($response['list']['elements']['order'] ?? [] as $walmartOrder) {
                $platformOrder = $this->importOrder($walmartOrder, $connection);
                $orders->push($platformOrder);
                $syncLog->incrementProcessed();
                $syncLog->incrementSuccess();
            }

            $syncLog->markCompleted(['imported_count' => $orders->count()]);
            $connection->recordSync();
        } catch (\Throwable $e) {
            $this->handleApiError($connection, $e, 'Pull orders failed');
            $syncLog->markFailed([$e->getMessage()]);
        }

        return $orders;
    }

    public function updateOrderFulfillment(PlatformOrder $order, array $fulfillmentData): void
    {
        $this->ensureValidToken($order->connection);

        $orderLines = collect($order->line_items)->map(fn ($item) => [
            'lineNumber' => $item['lineNumber'],
            'orderLineStatuses' => [
                'orderLineStatus' => [[
                    'status' => 'Shipped',
                    'statusQuantity' => [
                        'unitOfMeasurement' => 'EACH',
                        'amount' => (string) $item['orderLineQuantity']['amount'],
                    ],
                    'trackingInfo' => [
                        'shipDateTime' => now()->toIso8601String(),
                        'carrierName' => [
                            'carrier' => $fulfillmentData['carrier'] ?? 'OTHER',
                        ],
                        'trackingNumber' => $fulfillmentData['tracking_number'] ?? '',
                    ],
                ]],
            ],
        ])->all();

        $this->walmartRequest(
            $order->connection,
            'POST',
            "/v3/orders/{$order->external_order_id}/shipping",
            ['orderShipment' => ['orderLines' => ['orderLine' => $orderLines]]]
        );

        $order->update(['fulfillment_status' => 'shipped']);
    }

    public function getCategories(StoreMarketplace $connection): Collection
    {
        $this->ensureValidToken($connection);

        $response = $this->walmartRequest($connection, 'GET', '/v3/items/taxonomy');

        return collect($response['payload'] ?? [])->map(fn ($c) => [
            'id' => $c['category'] ?? $c['categoryId'],
            'name' => $c['categoryName'] ?? $c['category'],
        ]);
    }

    public function registerWebhooks(StoreMarketplace $connection): void
    {
        $this->ensureValidToken($connection);

        $eventTypes = [
            'PO_CREATED',
            'PO_LINE_UPDATED',
            'ITEM_UPDATED',
        ];

        foreach ($eventTypes as $eventType) {
            try {
                $this->walmartRequest($connection, 'POST', '/v3/webhooks/subscriptions', [
                    'eventType' => $eventType,
                    'authHeaderType' => 'BASIC',
                    'resourceName' => 'ITEM',
                    'destinationUrl' => $this->getWebhookUrl($connection),
                    'status' => 'ACTIVE',
                ]);
            } catch (\Throwable $e) {
                // May already exist
            }
        }
    }

    public function handleWebhook(Request $request, StoreMarketplace $connection): void
    {
        $eventType = $request->input('eventType');
        $payload = $request->input('payload');

        match ($eventType) {
            'PO_CREATED', 'PO_LINE_UPDATED' => $this->handleOrderWebhook($payload, $connection),
            'ITEM_UPDATED' => $this->handleItemWebhook($payload, $connection),
            default => null,
        };
    }

    // Helper methods

    protected function walmartRequest(
        StoreMarketplace $connection,
        string $method,
        string $endpoint,
        array $data = [],
        array $headers = []
    ): array {
        $url = $this->apiBaseUrl.$endpoint;

        $defaultHeaders = [
            'Authorization' => 'Basic '.base64_encode("{$connection->credentials['client_id']}:"),
            'WM_SEC.ACCESS_TOKEN' => $connection->access_token,
            'WM_SVC.NAME' => 'Walmart Marketplace',
            'WM_QOS.CORRELATION_ID' => uniqid(),
            'Accept' => 'application/json',
        ];

        $request = Http::withHeaders(array_merge($defaultHeaders, $headers));

        $response = match (strtoupper($method)) {
            'GET' => $request->get($url, $data),
            'POST' => $request->post($url, $data),
            'PUT' => $request->put($url, $data),
            'DELETE' => $request->delete($url),
            default => throw new \InvalidArgumentException("Unsupported method: {$method}"),
        };

        if ($response->failed()) {
            throw new \Exception("Walmart API error: {$response->body()}");
        }

        return $response->json() ?? [];
    }

    protected function ensureValidToken(StoreMarketplace $connection): void
    {
        if ($connection->token_expires_at && $connection->token_expires_at->isPast()) {
            $this->refreshToken($connection);
        }
    }

    protected function mapWalmartProduct(array $item, StoreMarketplace $connection): array
    {
        return [
            'external_id' => $item['sku'],
            'title' => $item['productName'] ?? $item['sku'],
            'description' => $item['shortDescription'] ?? '',
            'sku' => $item['sku'],
            'price' => $item['price']['amount'] ?? 0,
            'quantity' => $item['availableQuantity']['amount'] ?? 0,
            'status' => $item['publishedStatus'] ?? 'UNPUBLISHED',
            'upc' => $item['upc'] ?? null,
        ];
    }

    protected function mapToWalmartItem(Product $product, ?StoreMarketplace $connection = null): array
    {
        $variant = $product->variants->first();
        $settings = $connection?->settings ?? [];
        $priceMarkup = ($settings['price_markup'] ?? 0) / 100;
        $basePrice = $variant?->price ?? 0;
        $adjustedPrice = $basePrice + ($basePrice * $priceMarkup);

        return [
            'sku' => $variant?->sku ?? $product->handle,
            'productIdentifiers' => [
                'productIdType' => $settings['product_id_type'] ?? 'UPC',
                'productId' => $variant?->barcode ?? '000000000000',
            ],
            'productName' => $product->title,
            'brand' => $product->brand?->name ?? 'Generic',
            'shortDescription' => substr($product->description ?? '', 0, 1000),
            'mainImageUrl' => $product->images->first()?->url ?? '',
            'price' => [
                'currency' => 'USD',
                'amount' => $adjustedPrice,
            ],
            'category' => $product->category?->name ?? 'Other',
            'shippingWeight' => [
                'value' => $variant?->weight ?? 1,
                'unit' => $settings['weight_unit'] ?? 'LB',
            ],
            'fulfillmentType' => $settings['fulfillment_type'] ?? 'seller',
        ];
    }

    protected function importOrder(array $walmartOrder, StoreMarketplace $connection): PlatformOrder
    {
        $shippingInfo = $walmartOrder['shippingInfo'] ?? [];
        $orderLines = $walmartOrder['orderLines']['orderLine'] ?? [];

        return PlatformOrder::updateOrCreate(
            [
                'store_marketplace_id' => $connection->id,
                'external_order_id' => $walmartOrder['purchaseOrderId'],
            ],
            [
                'external_order_number' => $walmartOrder['customerOrderId'],
                'status' => $walmartOrder['orderStatus'] ?? 'Created',
                'fulfillment_status' => $this->mapWalmartFulfillmentStatus($orderLines),
                'payment_status' => 'paid', // Walmart handles payment
                'total' => $this->calculateOrderTotal($orderLines),
                'subtotal' => $this->calculateOrderSubtotal($orderLines),
                'shipping_cost' => $this->calculateShippingCost($orderLines),
                'tax' => $this->calculateTax($orderLines),
                'discount' => 0,
                'currency' => 'USD',
                'customer_data' => [
                    'name' => $shippingInfo['postalAddress']['name'] ?? null,
                    'phone' => $shippingInfo['phone'] ?? null,
                ],
                'shipping_address' => [
                    'name' => $shippingInfo['postalAddress']['name'] ?? '',
                    'address1' => $shippingInfo['postalAddress']['address1'] ?? '',
                    'address2' => $shippingInfo['postalAddress']['address2'] ?? '',
                    'city' => $shippingInfo['postalAddress']['city'] ?? '',
                    'state' => $shippingInfo['postalAddress']['state'] ?? '',
                    'zip' => $shippingInfo['postalAddress']['postalCode'] ?? '',
                    'country' => $shippingInfo['postalAddress']['country'] ?? 'USA',
                ],
                'billing_address' => null,
                'line_items' => $orderLines,
                'platform_data' => $walmartOrder,
                'ordered_at' => $walmartOrder['orderDate'],
                'last_synced_at' => now(),
            ]
        );
    }

    protected function mapWalmartFulfillmentStatus(array $orderLines): string
    {
        $allShipped = collect($orderLines)->every(fn ($line) => ($line['orderLineStatuses']['orderLineStatus'][0]['status'] ?? '') === 'Shipped'
        );

        return $allShipped ? 'shipped' : 'pending';
    }

    protected function calculateOrderTotal(array $orderLines): float
    {
        return collect($orderLines)->sum(fn ($line) => $line['charges']['charge'][0]['chargeAmount']['amount'] ?? 0
        );
    }

    protected function calculateOrderSubtotal(array $orderLines): float
    {
        return collect($orderLines)->sum(function ($line) {
            $charge = collect($line['charges']['charge'] ?? [])->firstWhere('chargeType', 'PRODUCT');

            return $charge['chargeAmount']['amount'] ?? 0;
        });
    }

    protected function calculateShippingCost(array $orderLines): float
    {
        return collect($orderLines)->sum(function ($line) {
            $charge = collect($line['charges']['charge'] ?? [])->firstWhere('chargeType', 'SHIPPING');

            return $charge['chargeAmount']['amount'] ?? 0;
        });
    }

    protected function calculateTax(array $orderLines): float
    {
        return collect($orderLines)->sum(function ($line) {
            return collect($line['charges']['charge'] ?? [])->sum(fn ($charge) => collect($charge['tax'] ?? [])->sum(fn ($tax) => $tax['taxAmount']['amount'] ?? 0));
        });
    }

    protected function handleOrderWebhook(array $payload, StoreMarketplace $connection): void
    {
        // Re-sync the order
        $this->pullOrders($connection, now()->subHour()->toIso8601String());
    }

    protected function handleItemWebhook(array $payload, StoreMarketplace $connection): void
    {
        $sku = $payload['sku'] ?? null;
        if (! $sku) {
            return;
        }

        PlatformListing::where('store_marketplace_id', $connection->id)
            ->where('external_listing_id', $sku)
            ->update(['last_synced_at' => now()]);
    }
}
