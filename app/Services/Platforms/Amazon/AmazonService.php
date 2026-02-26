<?php

namespace App\Services\Platforms\Amazon;

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

class AmazonService extends BasePlatformService
{
    protected array $endpoints = [
        'na' => 'https://sellingpartnerapi-na.amazon.com',
        'eu' => 'https://sellingpartnerapi-eu.amazon.com',
        'fe' => 'https://sellingpartnerapi-fe.amazon.com',
    ];

    protected string $authUrl = 'https://sellercentral.amazon.com/apps/authorize/consent';

    protected string $tokenUrl = 'https://api.amazon.com/auth/o2/token';

    public function getPlatform(): string
    {
        return Platform::Amazon->value;
    }

    public function connect(Store $store, array $params = []): RedirectResponse
    {
        $region = $params['region'] ?? 'na';

        $authUrl = $this->authUrl.'?'.http_build_query([
            'application_id' => config('services.amazon.app_id'),
            'state' => encrypt([
                'store_id' => $store->id,
                'region' => $region,
            ]),
            'version' => 'beta',
        ]);

        return redirect()->away($authUrl);
    }

    public function handleCallback(Request $request, Store $store): StoreMarketplace
    {
        $spApiOauthCode = $request->input('spapi_oauth_code');
        $sellingPartnerId = $request->input('selling_partner_id');
        $state = decrypt($request->input('state'));
        $region = $state['region'] ?? 'na';

        $response = Http::asForm()->post($this->tokenUrl, [
            'grant_type' => 'authorization_code',
            'code' => $spApiOauthCode,
            'client_id' => config('services.amazon.client_id'),
            'client_secret' => config('services.amazon.client_secret'),
        ]);

        if ($response->failed()) {
            throw new \Exception('Failed to obtain access token: '.$response->body());
        }

        $data = $response->json();

        // Use selling_partner_id as external_store_id to support multiple accounts
        return StoreMarketplace::updateOrCreate(
            [
                'store_id' => $store->id,
                'platform' => Platform::Amazon,
                'external_store_id' => $sellingPartnerId,
            ],
            [
                'name' => "Amazon ({$sellingPartnerId})",
                'external_store_id' => $sellingPartnerId,
                'access_token' => $data['access_token'],
                'refresh_token' => $data['refresh_token'],
                'token_expires_at' => now()->addSeconds($data['expires_in'] ?? 3600),
                'credentials' => [
                    'selling_partner_id' => $sellingPartnerId,
                    'region' => $region,
                    'marketplace_ids' => $this->getMarketplaceIds($region),
                ],
                'status' => 'active',
                'connected_successfully' => true,
            ]
        );
    }

    public function disconnect(StoreMarketplace $connection): void
    {
        $connection->update(['status' => 'inactive']);
        $connection->delete();
    }

    public function refreshToken(StoreMarketplace $connection): StoreMarketplace
    {
        $response = Http::asForm()->post($this->tokenUrl, [
            'grant_type' => 'refresh_token',
            'refresh_token' => $connection->refresh_token,
            'client_id' => config('services.amazon.client_id'),
            'client_secret' => config('services.amazon.client_secret'),
        ]);

        if ($response->failed()) {
            throw new \Exception('Failed to refresh token: '.$response->body());
        }

        $data = $response->json();

        $connection->update([
            'access_token' => $data['access_token'],
            'token_expires_at' => now()->addSeconds($data['expires_in'] ?? 3600),
        ]);

        return $connection->fresh();
    }

    public function validateCredentials(StoreMarketplace $connection): bool
    {
        try {
            $this->ensureValidToken($connection);
            $response = $this->amazonRequest($connection, 'GET', '/sellers/v1/marketplaceParticipations');

            return isset($response['payload']);
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
            $marketplaceId = $connection->credentials['marketplace_ids'][0] ?? 'ATVPDKIKX0DER';
            $nextToken = null;

            do {
                $params = ['MarketplaceIds' => $marketplaceId];
                if ($nextToken) {
                    $params['NextToken'] = $nextToken;
                }

                $response = $this->amazonRequest(
                    $connection,
                    'GET',
                    '/listings/2021-08-01/items/'.$connection->external_id,
                    $params
                );

                foreach ($response['items'] ?? [] as $item) {
                    $products->push($this->mapAmazonProduct($item, $connection));
                    $syncLog->incrementProcessed();
                    $syncLog->incrementSuccess();
                }

                $nextToken = $response['nextToken'] ?? null;
            } while ($nextToken);

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

        $sku = $product->variants->first()?->sku ?? $product->handle;
        $marketplaceId = $connection->credentials['marketplace_ids'][0] ?? 'ATVPDKIKX0DER';

        $listingData = $this->mapToAmazonListing($product, $connection);

        $response = $this->amazonRequest(
            $connection,
            'PUT',
            "/listings/2021-08-01/items/{$connection->external_id}/{$sku}",
            [
                'marketplaceIds' => [$marketplaceId],
                'body' => $listingData,
            ]
        );

        return PlatformListing::create([
            'store_marketplace_id' => $connection->id,
            'product_id' => $product->id,
            'external_listing_id' => $sku,
            'status' => $response['status'] ?? 'ACCEPTED' ? 'active' : 'pending',
            'listing_url' => "https://www.amazon.com/dp/{$response['asin']}",
            'platform_data' => $response,
            'last_synced_at' => now(),
            'published_at' => now(),
        ]);
    }

    public function updateListing(PlatformListing $listing): PlatformListing
    {
        $product = $listing->product;
        $connection = $listing->connection;

        $this->ensureValidToken($connection);

        $sku = $listing->external_listing_id;
        $marketplaceId = $connection->credentials['marketplace_ids'][0] ?? 'ATVPDKIKX0DER';
        $listingData = $this->mapToAmazonListing($product, $connection);

        $this->amazonRequest(
            $connection,
            'PATCH',
            "/listings/2021-08-01/items/{$connection->external_id}/{$sku}",
            [
                'marketplaceIds' => [$marketplaceId],
                'body' => $listingData,
            ]
        );

        $listing->update(['last_synced_at' => now()]);

        return $listing;
    }

    public function deleteListing(PlatformListing $listing): void
    {
        $connection = $listing->connection;
        $this->ensureValidToken($connection);

        $marketplaceId = $connection->credentials['marketplace_ids'][0] ?? 'ATVPDKIKX0DER';

        $this->amazonRequest(
            $connection,
            'DELETE',
            "/listings/2021-08-01/items/{$connection->external_id}/{$listing->external_listing_id}",
            ['marketplaceIds' => [$marketplaceId]]
        );

        $listing->delete();
    }

    public function unlistListing(PlatformListing $listing): PlatformListing
    {
        $connection = $listing->marketplace;
        $this->ensureValidToken($connection);

        $marketplaceId = $connection->credentials['marketplace_ids'][0] ?? 'ATVPDKIKX0DER';

        // Set fulfillment availability to 0 to effectively unlist
        $this->amazonRequest(
            $connection,
            'PATCH',
            "/listings/2021-08-01/items/{$connection->external_store_id}/{$listing->external_listing_id}",
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
            'status' => PlatformListing::STATUS_UNLISTED,
            'last_synced_at' => now(),
        ]);

        return $listing->fresh();
    }

    public function relistListing(PlatformListing $listing): PlatformListing
    {
        $connection = $listing->marketplace;
        $this->ensureValidToken($connection);

        $marketplaceId = $connection->credentials['marketplace_ids'][0] ?? 'ATVPDKIKX0DER';
        $quantity = $listing->product?->total_quantity ?? $listing->platform_quantity ?? 1;

        // Restore fulfillment availability
        $this->amazonRequest(
            $connection,
            'PATCH',
            "/listings/2021-08-01/items/{$connection->external_store_id}/{$listing->external_listing_id}",
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

        foreach ($listings as $listing) {
            if (! $listing->variant) {
                continue;
            }

            try {
                $this->amazonRequest($connection, 'PUT', '/fba/inventory/v1/items/'.$listing->external_listing_id, [
                    'sellerSku' => $listing->external_listing_id,
                    'quantity' => $listing->variant->quantity,
                ]);
            } catch (\Throwable $e) {
                // Log but continue
            }
        }
    }

    public function pullOrders(StoreMarketplace $connection, ?string $since = null): Collection
    {
        $syncLog = $this->logSync($connection, 'orders', 'pull');
        $orders = collect();

        try {
            $this->ensureValidToken($connection);

            $marketplaceIds = $connection->credentials['marketplace_ids'] ?? ['ATVPDKIKX0DER'];
            $params = [
                'MarketplaceIds' => implode(',', $marketplaceIds),
            ];

            if ($since) {
                $params['CreatedAfter'] = $since;
            } else {
                $params['CreatedAfter'] = now()->subDays(30)->toIso8601String();
            }

            $response = $this->amazonRequest($connection, 'GET', '/orders/v0/orders', $params);

            foreach ($response['payload']['Orders'] ?? [] as $amazonOrder) {
                $platformOrder = $this->importOrder($amazonOrder, $connection);
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

        $this->amazonRequest(
            $order->connection,
            'POST',
            '/feeds/2021-06-30/feeds',
            [
                'feedType' => 'POST_ORDER_FULFILLMENT_DATA',
                'marketplaceIds' => $order->connection->credentials['marketplace_ids'] ?? ['ATVPDKIKX0DER'],
                'inputFeedDocumentId' => $this->createFulfillmentFeed($order, $fulfillmentData),
            ]
        );

        $order->update(['fulfillment_status' => 'fulfilled']);
    }

    public function getCategories(StoreMarketplace $connection): Collection
    {
        $this->ensureValidToken($connection);

        $marketplaceId = $connection->credentials['marketplace_ids'][0] ?? 'ATVPDKIKX0DER';

        $response = $this->amazonRequest(
            $connection,
            'GET',
            '/catalog/2022-04-01/categories',
            ['marketplaceIds' => $marketplaceId]
        );

        return collect($response['payload'] ?? [])->map(fn ($c) => [
            'id' => $c['categoryId'] ?? $c['ProductCategoryId'],
            'name' => $c['categoryName'] ?? $c['ProductCategoryName'],
        ]);
    }

    public function registerWebhooks(StoreMarketplace $connection): void
    {
        $this->ensureValidToken($connection);

        $notificationTypes = [
            'ANY_OFFER_CHANGED',
            'FEED_PROCESSING_FINISHED',
            'ORDER_STATUS_CHANGE',
            'REPORT_PROCESSING_FINISHED',
        ];

        foreach ($notificationTypes as $type) {
            try {
                $this->amazonRequest($connection, 'POST', '/notifications/v1/subscriptions/'.$type, [
                    'payloadVersion' => '1.0',
                    'destinationId' => $this->getOrCreateDestination($connection),
                ]);
            } catch (\Throwable $e) {
                // May already exist
            }
        }
    }

    public function handleWebhook(Request $request, StoreMarketplace $connection): void
    {
        $notificationType = $request->input('NotificationType');
        $payload = $request->input('Payload');

        match ($notificationType) {
            'ORDER_STATUS_CHANGE' => $this->handleOrderStatusChange($payload, $connection),
            'ANY_OFFER_CHANGED' => $this->handleOfferChange($payload, $connection),
            default => null,
        };
    }

    // Helper methods

    protected function amazonRequest(
        StoreMarketplace $connection,
        string $method,
        string $endpoint,
        array $data = []
    ): array {
        $region = $connection->credentials['region'] ?? 'na';
        $baseUrl = $this->endpoints[$region] ?? $this->endpoints['na'];
        $url = $baseUrl.$endpoint;

        $request = Http::withHeaders([
            'x-amz-access-token' => $connection->access_token,
            'Content-Type' => 'application/json',
        ]);

        // Add query params for GET requests
        if (strtoupper($method) === 'GET' && ! empty($data)) {
            $url .= '?'.http_build_query($data);
            $data = [];
        }

        $response = match (strtoupper($method)) {
            'GET' => $request->get($url),
            'POST' => $request->post($url, $data),
            'PUT' => $request->put($url, $data),
            'PATCH' => $request->patch($url, $data),
            'DELETE' => $request->delete($url),
            default => throw new \InvalidArgumentException("Unsupported method: {$method}"),
        };

        if ($response->failed()) {
            throw new \Exception("Amazon API error: {$response->body()}");
        }

        return $response->json() ?? [];
    }

    protected function ensureValidToken(StoreMarketplace $connection): void
    {
        if ($connection->token_expires_at && $connection->token_expires_at->isPast()) {
            $this->refreshToken($connection);
        }
    }

    protected function getMarketplaceIds(string $region): array
    {
        return match ($region) {
            'na' => ['ATVPDKIKX0DER'], // US
            'eu' => ['A1PA6795UKMFR9'], // DE
            'fe' => ['A1VC38T7YXB528'], // JP
            default => ['ATVPDKIKX0DER'],
        };
    }

    protected function mapAmazonProduct(array $item, StoreMarketplace $connection): array
    {
        return [
            'external_id' => $item['sku'],
            'title' => $item['summaries'][0]['itemName'] ?? $item['sku'],
            'description' => '',
            'sku' => $item['sku'],
            'asin' => $item['asin'] ?? null,
            'price' => $item['offers'][0]['price']['amount'] ?? 0,
            'quantity' => $item['fulfillmentAvailability'][0]['quantity'] ?? 0,
            'condition' => $item['condition'] ?? 'new_new',
        ];
    }

    protected function mapToAmazonListing(Product $product, ?StoreMarketplace $connection = null): array
    {
        $variant = $product->variants->first();
        $settings = $connection?->settings ?? [];
        $languageTag = $settings['language_tag'] ?? 'en_US';
        $fulfillmentChannel = $settings['fulfillment_channel'] ?? 'DEFAULT';
        $priceMarkup = ($settings['price_markup'] ?? 0) / 100;
        $basePrice = $variant?->price ?? 0;
        $adjustedPrice = $basePrice + ($basePrice * $priceMarkup);

        return [
            'productType' => $product->category?->external_id ?? 'PRODUCT',
            'attributes' => [
                'item_name' => [['value' => $product->title, 'language_tag' => $languageTag]],
                'brand' => [['value' => $product->brand?->name ?? 'Generic']],
                'bullet_point' => $this->getBulletPoints($product, $languageTag),
                'product_description' => [['value' => $product->description ?? '', 'language_tag' => $languageTag]],
                'purchasable_offer' => [[
                    'currency' => 'USD',
                    'our_price' => [['schedule' => [['value_with_tax' => $adjustedPrice]]]],
                ]],
                'fulfillment_availability' => [[
                    'fulfillment_channel_code' => $fulfillmentChannel,
                    'quantity' => $variant?->quantity ?? 0,
                ]],
            ],
        ];
    }

    protected function getBulletPoints(Product $product, string $languageTag = 'en_US'): array
    {
        $points = [];
        if ($product->short_description) {
            $points[] = ['value' => $product->short_description, 'language_tag' => $languageTag];
        }

        return $points;
    }

    protected function importOrder(array $amazonOrder, StoreMarketplace $connection): PlatformOrder
    {
        $shippingAddress = $amazonOrder['ShippingAddress'] ?? null;

        return PlatformOrder::updateOrCreate(
            [
                'store_marketplace_id' => $connection->id,
                'external_order_id' => $amazonOrder['AmazonOrderId'],
            ],
            [
                'external_order_number' => $amazonOrder['AmazonOrderId'],
                'status' => $amazonOrder['OrderStatus'],
                'fulfillment_status' => $amazonOrder['FulfillmentChannel'] === 'AFN' ? 'fba' : strtolower($amazonOrder['OrderStatus']),
                'payment_status' => $amazonOrder['PaymentMethodDetails'][0] ?? 'pending',
                'total' => $amazonOrder['OrderTotal']['Amount'] ?? 0,
                'subtotal' => $amazonOrder['OrderTotal']['Amount'] ?? 0,
                'shipping_cost' => 0,
                'tax' => 0,
                'discount' => 0,
                'currency' => $amazonOrder['OrderTotal']['CurrencyCode'] ?? 'USD',
                'customer_data' => [
                    'name' => $amazonOrder['BuyerInfo']['BuyerName'] ?? null,
                    'email' => $amazonOrder['BuyerInfo']['BuyerEmail'] ?? null,
                ],
                'shipping_address' => $shippingAddress,
                'billing_address' => null,
                'line_items' => [],
                'platform_data' => $amazonOrder,
                'ordered_at' => $amazonOrder['PurchaseDate'],
                'last_synced_at' => now(),
            ]
        );
    }

    protected function createFulfillmentFeed(PlatformOrder $order, array $fulfillmentData): string
    {
        // In a real implementation, this would create an XML feed document
        // and upload it to Amazon, returning the document ID
        return 'feed-document-id';
    }

    protected function getOrCreateDestination(StoreMarketplace $connection): string
    {
        // Get or create SQS destination for notifications
        $response = $this->amazonRequest($connection, 'GET', '/notifications/v1/destinations');

        foreach ($response['payload'] ?? [] as $destination) {
            if ($destination['name'] === 'shopmata-notifications') {
                return $destination['destinationId'];
            }
        }

        // Create new destination
        $createResponse = $this->amazonRequest($connection, 'POST', '/notifications/v1/destinations', [
            'name' => 'shopmata-notifications',
            'resourceSpecification' => [
                'eventBridge' => [
                    'region' => config('services.amazon.aws_region', 'us-east-1'),
                    'accountId' => config('services.amazon.aws_account_id'),
                ],
            ],
        ]);

        return $createResponse['payload']['destinationId'];
    }

    protected function handleOrderStatusChange(array $payload, StoreMarketplace $connection): void
    {
        $orderId = $payload['AmazonOrderId'] ?? null;
        if (! $orderId) {
            return;
        }

        PlatformOrder::where('store_marketplace_id', $connection->id)
            ->where('external_order_id', $orderId)
            ->update([
                'status' => $payload['OrderStatus'] ?? 'Unknown',
                'last_synced_at' => now(),
            ]);
    }

    protected function handleOfferChange(array $payload, StoreMarketplace $connection): void
    {
        // Update listing data when offer changes
    }
}
