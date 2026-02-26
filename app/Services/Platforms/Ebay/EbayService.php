<?php

namespace App\Services\Platforms\Ebay;

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

class EbayService extends BasePlatformService
{
    protected string $apiBaseUrl;

    protected string $authBaseUrl;

    public function __construct()
    {
        $this->apiBaseUrl = config('services.ebay.sandbox')
            ? 'https://api.sandbox.ebay.com'
            : 'https://api.ebay.com';

        $this->authBaseUrl = config('services.ebay.sandbox')
            ? 'https://auth.sandbox.ebay.com'
            : 'https://auth.ebay.com';
    }

    public function getPlatform(): string
    {
        return Platform::Ebay->value;
    }

    public function connect(Store $store, array $params = []): RedirectResponse
    {
        $this->ensureConfigured();

        $scopes = implode(' ', $this->getRequiredScopes());

        $authUrl = "{$this->authBaseUrl}/oauth2/authorize?"
            .http_build_query([
                'client_id' => config('services.ebay.client_id'),
                'response_type' => 'code',
                'redirect_uri' => config('services.ebay.redirect_uri'),
                'scope' => $scopes,
                'state' => encrypt(['store_id' => $store->id]),
            ]);

        return redirect()->away($authUrl);
    }

    public function handleCallback(Request $request, Store $store): StoreMarketplace
    {
        $this->ensureConfigured();

        $code = $request->input('code');

        $response = Http::withBasicAuth(
            config('services.ebay.client_id'),
            config('services.ebay.client_secret')
        )->asForm()->post("{$this->apiBaseUrl}/identity/v1/oauth2/token", [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => config('services.ebay.redirect_uri'),
        ]);

        if ($response->failed()) {
            throw new \Exception('Failed to obtain access token: '.$response->body());
        }

        $data = $response->json();

        // Get eBay user info to uniquely identify this account
        $userResponse = Http::withToken($data['access_token'])
            ->get("{$this->apiBaseUrl}/commerce/identity/v1/user/");

        $userId = null;
        $username = null;
        if ($userResponse->successful()) {
            $userData = $userResponse->json();
            $userId = $userData['userId'] ?? null;
            $username = $userData['username'] ?? null;
        }

        // Use updateOrCreate with external_store_id to support multiple accounts
        // If we have a userId, use it; otherwise create a new connection
        $uniqueKeys = ['store_id' => $store->id, 'platform' => Platform::Ebay];
        if ($userId) {
            $uniqueKeys['external_store_id'] = $userId;
        }

        return StoreMarketplace::updateOrCreate(
            $uniqueKeys,
            [
                'name' => $username ? "eBay ({$username})" : 'eBay Store',
                'external_store_id' => $userId,
                'access_token' => $data['access_token'],
                'refresh_token' => $data['refresh_token'] ?? null,
                'token_expires_at' => now()->addSeconds($data['expires_in'] ?? 7200),
                'credentials' => [
                    'scope' => $data['scope'] ?? null,
                    'refresh_token_expires_in' => $data['refresh_token_expires_in'] ?? null,
                    'username' => $username,
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
        $this->ensureConfigured();

        if (! $connection->refresh_token) {
            throw new \Exception('No refresh token available');
        }

        $response = Http::withBasicAuth(
            config('services.ebay.client_id'),
            config('services.ebay.client_secret')
        )->asForm()->post("{$this->apiBaseUrl}/identity/v1/oauth2/token", [
            'grant_type' => 'refresh_token',
            'refresh_token' => $connection->refresh_token,
            'scope' => implode(' ', $this->getRequiredScopes()),
        ]);

        if ($response->failed()) {
            throw new \Exception('Failed to refresh token: '.$response->body());
        }

        $data = $response->json();

        $connection->update([
            'access_token' => $data['access_token'],
            'token_expires_at' => now()->addSeconds($data['expires_in'] ?? 7200),
        ]);

        return $connection->fresh();
    }

    public function validateCredentials(StoreMarketplace $connection): bool
    {
        try {
            $this->ensureValidToken($connection);
            $response = $this->ebayRequest($connection, 'GET', '/sell/account/v1/privilege');

            return isset($response['sellingLimit']);
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
            $limit = 100;

            do {
                $response = $this->ebayRequest($connection, 'GET', '/sell/inventory/v1/inventory_item', [
                    'limit' => $limit,
                    'offset' => $offset,
                ]);

                $inventoryItems = $response['inventoryItems'] ?? [];

                foreach ($inventoryItems as $item) {
                    $products->push($this->mapEbayProduct($item, $connection));
                    $syncLog->incrementProcessed();
                    $syncLog->incrementSuccess();
                }

                $offset += $limit;
                $total = $response['total'] ?? 0;
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

        $sku = $product->variants->first()?->sku ?? $product->handle;
        $inventoryItem = $this->mapToEbayInventoryItem($product);

        // Create or update inventory item
        $this->ebayRequest(
            $connection,
            'PUT',
            "/sell/inventory/v1/inventory_item/{$sku}",
            $inventoryItem
        );

        // Create offer
        $offer = $this->mapToEbayOffer($product, $sku, $connection);
        $offerResponse = $this->ebayRequest($connection, 'POST', '/sell/inventory/v1/offer', $offer);

        $offerId = $offerResponse['offerId'];

        // Publish offer
        $publishResponse = $this->ebayRequest(
            $connection,
            'POST',
            "/sell/inventory/v1/offer/{$offerId}/publish"
        );

        return PlatformListing::create([
            'store_marketplace_id' => $connection->id,
            'product_id' => $product->id,
            'external_listing_id' => $publishResponse['listingId'] ?? $offerId,
            'status' => 'active',
            'listing_url' => "https://www.ebay.com/itm/{$publishResponse['listingId']}",
            'platform_data' => [
                'sku' => $sku,
                'offer_id' => $offerId,
                'listing_id' => $publishResponse['listingId'] ?? null,
            ],
            'last_synced_at' => now(),
            'published_at' => now(),
        ]);
    }

    public function updateListing(PlatformListing $listing): PlatformListing
    {
        $product = $listing->product;
        $connection = $listing->connection;

        $this->ensureValidToken($connection);

        $sku = $listing->platform_data['sku'] ?? $product->variants->first()?->sku;
        $inventoryItem = $this->mapToEbayInventoryItem($product);

        $this->ebayRequest(
            $connection,
            'PUT',
            "/sell/inventory/v1/inventory_item/{$sku}",
            $inventoryItem
        );

        $listing->update(['last_synced_at' => now()]);

        return $listing;
    }

    public function deleteListing(PlatformListing $listing): void
    {
        $connection = $listing->connection;
        $this->ensureValidToken($connection);

        $offerId = $listing->platform_data['offer_id'] ?? null;

        if ($offerId) {
            $this->ebayRequest($connection, 'DELETE', "/sell/inventory/v1/offer/{$offerId}");
        }

        $sku = $listing->platform_data['sku'] ?? null;
        if ($sku) {
            $this->ebayRequest($connection, 'DELETE', "/sell/inventory/v1/inventory_item/{$sku}");
        }

        $listing->delete();
    }

    public function unlistListing(PlatformListing $listing): PlatformListing
    {
        $connection = $listing->marketplace;
        $this->ensureValidToken($connection);

        $offerId = $listing->platform_data['offer_id'] ?? null;

        if ($offerId) {
            // Withdraw the offer (ends the listing but keeps the inventory item)
            $this->ebayRequest($connection, 'POST', "/sell/inventory/v1/offer/{$offerId}/withdraw");
        }

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

        $offerId = $listing->platform_data['offer_id'] ?? null;

        if ($offerId) {
            // Publish the offer again
            $publishResponse = $this->ebayRequest(
                $connection,
                'POST',
                "/sell/inventory/v1/offer/{$offerId}/publish"
            );

            // Update listing with new listing ID if returned
            $platformData = $listing->platform_data;
            if (isset($publishResponse['listingId'])) {
                $platformData['listing_id'] = $publishResponse['listingId'];
                $listing->listing_url = "https://www.ebay.com/itm/{$publishResponse['listingId']}";
            }

            $listing->update([
                'status' => PlatformListing::STATUS_ACTIVE,
                'platform_data' => $platformData,
                'published_at' => now(),
                'last_synced_at' => now(),
            ]);
        }

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
                $sku = $listing->platform_data['sku'] ?? null;
                if (! $sku) {
                    continue;
                }

                $this->ebayRequest($connection, 'PUT', "/sell/inventory/v1/inventory_item/{$sku}", [
                    'availability' => [
                        'shipToLocationAvailability' => [
                            'quantity' => $listing->variant->quantity,
                        ],
                    ],
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

            $params = ['limit' => 50];
            if ($since) {
                $params['filter'] = "creationdate:[{$since}..".now()->toIso8601String().']';
            }

            $response = $this->ebayRequest($connection, 'GET', '/sell/fulfillment/v1/order', $params);

            foreach ($response['orders'] ?? [] as $ebayOrder) {
                $platformOrder = $this->importOrder($ebayOrder, $connection);
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

        $this->ebayRequest(
            $order->connection,
            'POST',
            "/sell/fulfillment/v1/order/{$order->external_order_id}/shipping_fulfillment",
            [
                'lineItems' => collect($order->line_items)->map(fn ($item) => [
                    'lineItemId' => $item['lineItemId'],
                    'quantity' => $item['quantity'],
                ])->all(),
                'shippedDate' => now()->toIso8601String(),
                'shippingCarrierCode' => $fulfillmentData['carrier'] ?? 'OTHER',
                'trackingNumber' => $fulfillmentData['tracking_number'] ?? null,
            ]
        );

        $order->update(['fulfillment_status' => 'fulfilled']);
    }

    public function getCategories(StoreMarketplace $connection): Collection
    {
        $this->ensureValidToken($connection);

        $response = $this->ebayRequest(
            $connection,
            'GET',
            '/commerce/taxonomy/v1/category_tree/0'
        );

        return $this->flattenCategories($response['rootCategoryNode'] ?? []);
    }

    public function registerWebhooks(StoreMarketplace $connection): void
    {
        $this->ensureValidToken($connection);

        $topics = [
            'MARKETPLACE_ACCOUNT_DELETION',
        ];

        foreach ($topics as $topic) {
            $this->ebayRequest($connection, 'POST', '/commerce/notification/v1/subscription', [
                'topicId' => $topic,
                'status' => 'ENABLED',
                'payload' => [
                    'format' => 'JSON',
                    'deliveryMethod' => 'WEBHOOK',
                    'endpointUrl' => $this->getWebhookUrl($connection),
                ],
            ]);
        }
    }

    public function handleWebhook(Request $request, StoreMarketplace $connection): void
    {
        $topic = $request->input('metadata.topic');
        $data = $request->all();

        match ($topic) {
            'MARKETPLACE_ACCOUNT_DELETION' => $this->handleAccountDeletion($data, $connection),
            default => null,
        };
    }

    /**
     * Fetch business policies (return, payment, fulfillment) from eBay Account API.
     *
     * @return array{return_policies: array<mixed>, payment_policies: array<mixed>, fulfillment_policies: array<mixed>}
     */
    public function getBusinessPolicies(StoreMarketplace $connection): array
    {
        $this->ensureValidToken($connection);

        $marketplaceId = $connection->settings['marketplace_id'] ?? 'EBAY_US';

        $returnPolicies = $this->ebayRequest(
            $connection,
            'GET',
            '/sell/account/v1/return_policy',
            ['marketplace_id' => $marketplaceId]
        );

        $paymentPolicies = $this->ebayRequest(
            $connection,
            'GET',
            '/sell/account/v1/payment_policy',
            ['marketplace_id' => $marketplaceId]
        );

        $fulfillmentPolicies = $this->ebayRequest(
            $connection,
            'GET',
            '/sell/account/v1/fulfillment_policy',
            ['marketplace_id' => $marketplaceId]
        );

        return [
            'return_policies' => $returnPolicies['returnPolicies'] ?? [],
            'payment_policies' => $paymentPolicies['paymentPolicies'] ?? [],
            'fulfillment_policies' => $fulfillmentPolicies['fulfillmentPolicies'] ?? [],
        ];
    }

    /**
     * Fetch inventory locations from eBay Inventory API.
     *
     * @return array<mixed>
     */
    public function getInventoryLocations(StoreMarketplace $connection): array
    {
        $this->ensureValidToken($connection);

        $response = $this->ebayRequest(
            $connection,
            'GET',
            '/sell/inventory/v1/location',
            ['limit' => 100]
        );

        return $response['locations'] ?? [];
    }

    // Helper methods

    public function ebayRequest(
        StoreMarketplace $connection,
        string $method,
        string $endpoint,
        array $data = []
    ): array {
        $url = $this->apiBaseUrl.$endpoint;

        $request = Http::withHeaders([
            'Authorization' => 'Bearer '.$connection->access_token,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ]);

        $response = match (strtoupper($method)) {
            'GET' => $request->get($url, $data),
            'POST' => $request->post($url, $data),
            'PUT' => $request->put($url, $data),
            'DELETE' => $request->delete($url),
            default => throw new \InvalidArgumentException("Unsupported method: {$method}"),
        };

        if ($response->failed()) {
            throw new \Exception("eBay API error: {$response->body()}");
        }

        return $response->json() ?? [];
    }

    public function ensureValidToken(StoreMarketplace $connection): void
    {
        if ($connection->token_expires_at && $connection->token_expires_at->isPast()) {
            $this->refreshToken($connection);
        }
    }

    protected function getRequiredScopes(): array
    {
        return [
            'https://api.ebay.com/oauth/api_scope',
            'https://api.ebay.com/oauth/api_scope/sell.inventory',
            'https://api.ebay.com/oauth/api_scope/sell.fulfillment',
            'https://api.ebay.com/oauth/api_scope/sell.account',
            'https://api.ebay.com/oauth/api_scope/commerce.notification.subscription',
        ];
    }

    protected function ensureConfigured(): void
    {
        $missing = [];

        if (empty(config('services.ebay.client_id'))) {
            $missing[] = 'EBAY_CLIENT_ID';
        }

        if (empty(config('services.ebay.client_secret'))) {
            $missing[] = 'EBAY_CLIENT_SECRET';
        }

        if (empty(config('services.ebay.redirect_uri'))) {
            $missing[] = 'EBAY_REDIRECT_URI';
        }

        if (! empty($missing)) {
            throw new \Exception(
                'eBay integration is not configured. Missing environment variables: '.implode(', ', $missing)
            );
        }
    }

    protected function mapEbayProduct(array $item, StoreMarketplace $connection): array
    {
        $product = $item['product'] ?? [];

        return [
            'external_id' => $item['sku'],
            'title' => $product['title'] ?? $item['sku'],
            'description' => $product['description'] ?? '',
            'sku' => $item['sku'],
            'price' => $item['availability']['shipToLocationAvailability']['quantity'] ?? 0,
            'quantity' => $item['availability']['shipToLocationAvailability']['quantity'] ?? 0,
            'images' => $product['imageUrls'] ?? [],
            'condition' => $item['condition'] ?? 'NEW',
        ];
    }

    /**
     * Map a product to an eBay inventory item payload.
     *
     * @param  array<string, array<string, string[]>>|null  $aspects  Pre-built aspects from ListingBuilderService
     */
    protected function mapToEbayInventoryItem(Product $product, ?array $aspects = null): array
    {
        $variant = $product->variants->first();
        $settings = [];

        $item = [
            'product' => [
                'title' => $product->title,
                'description' => $product->description ?? '',
                'imageUrls' => $product->images->pluck('url')->all(),
            ],
            'condition' => $product->condition ?? 'NEW',
            'availability' => [
                'shipToLocationAvailability' => [
                    'quantity' => $variant?->quantity ?? $product->quantity ?? 0,
                ],
            ],
        ];

        // Include aspects (item specifics) if provided
        if (! empty($aspects)) {
            $item['product']['aspects'] = $aspects;
        }

        return $item;
    }

    protected function mapToEbayOffer(Product $product, string $sku, StoreMarketplace $connection): array
    {
        $variant = $product->variants->first();
        $settings = $connection->settings ?? [];
        $credentials = $connection->credentials ?? [];

        $format = $settings['listing_type'] ?? 'FIXED_PRICE';
        $price = (float) ($variant?->price ?? 0);

        // Apply markup based on listing type
        if ($format === 'AUCTION' && ! empty($settings['auction_markup'])) {
            $price = $price * (1 + $settings['auction_markup'] / 100);
        } elseif ($format === 'FIXED_PRICE' && ! empty($settings['fixed_price_markup'])) {
            $price = $price * (1 + $settings['fixed_price_markup'] / 100);
        }

        $offer = [
            'sku' => $sku,
            'marketplaceId' => $settings['marketplace_id'] ?? 'EBAY_US',
            'format' => $format,
            'listingDescription' => $product->description ?? '',
            'availableQuantity' => $variant?->quantity ?? $product->quantity ?? 0,
            'pricingSummary' => [
                'price' => [
                    'value' => (string) round($price, 2),
                    'currency' => $this->getCurrencyForMarketplace($settings['marketplace_id'] ?? 'EBAY_US'),
                ],
            ],
            'listingPolicies' => [
                'fulfillmentPolicyId' => $settings['fulfillment_policy_id'] ?? $credentials['fulfillment_policy_id'] ?? null,
                'paymentPolicyId' => $settings['payment_policy_id'] ?? $credentials['payment_policy_id'] ?? null,
                'returnPolicyId' => $settings['return_policy_id'] ?? $credentials['return_policy_id'] ?? null,
            ],
            'categoryId' => $product->category?->external_id ?? '1',
            'merchantLocationKey' => $settings['location_key'] ?? $credentials['location_key'] ?? 'default',
        ];

        // Add listing duration
        $durationKey = $format === 'AUCTION' ? 'listing_duration_auction' : 'listing_duration_fixed';
        if (! empty($settings[$durationKey])) {
            $offer['listingDuration'] = $settings[$durationKey];
        }

        // Add best offer
        if (! empty($settings['best_offer_enabled'])) {
            $offer['bestOfferTerms'] = [
                'bestOfferEnabled' => true,
            ];
        }

        // Add condition from settings
        if (! empty($settings['default_condition'])) {
            $offer['condition'] = $settings['default_condition'];
        }

        return $offer;
    }

    /**
     * Get the currency code for a given eBay marketplace.
     */
    protected function getCurrencyForMarketplace(string $marketplaceId): string
    {
        return match ($marketplaceId) {
            'EBAY_GB' => 'GBP',
            'EBAY_DE', 'EBAY_FR', 'EBAY_IT', 'EBAY_ES', 'EBAY_AT', 'EBAY_BE_FR', 'EBAY_BE_NL', 'EBAY_NL', 'EBAY_IE', 'EBAY_FI' => 'EUR',
            'EBAY_AU' => 'AUD',
            'EBAY_CA' => 'CAD',
            'EBAY_CH' => 'CHF',
            'EBAY_IN' => 'INR',
            'EBAY_SG', 'EBAY_MY' => 'SGD',
            'EBAY_HK' => 'HKD',
            'EBAY_PH' => 'PHP',
            'EBAY_PL' => 'PLN',
            default => 'USD',
        };
    }

    protected function importOrder(array $ebayOrder, StoreMarketplace $connection): PlatformOrder
    {
        $shippingAddress = $ebayOrder['fulfillmentStartInstructions'][0]['shippingStep']['shipTo'] ?? null;

        return PlatformOrder::updateOrCreate(
            [
                'store_marketplace_id' => $connection->id,
                'external_order_id' => $ebayOrder['orderId'],
            ],
            [
                'external_order_number' => $ebayOrder['orderId'],
                'status' => $ebayOrder['orderFulfillmentStatus'],
                'fulfillment_status' => $ebayOrder['orderFulfillmentStatus'],
                'payment_status' => $ebayOrder['orderPaymentStatus'],
                'total' => $ebayOrder['pricingSummary']['total']['value'] ?? 0,
                'subtotal' => $ebayOrder['pricingSummary']['priceSubtotal']['value'] ?? 0,
                'shipping_cost' => $ebayOrder['pricingSummary']['deliveryCost']['value'] ?? 0,
                'tax' => $ebayOrder['pricingSummary']['tax']['value'] ?? 0,
                'discount' => $ebayOrder['pricingSummary']['priceDiscount']['value'] ?? 0,
                'currency' => $ebayOrder['pricingSummary']['total']['currency'] ?? 'USD',
                'customer_data' => $ebayOrder['buyer'] ?? null,
                'shipping_address' => $shippingAddress,
                'billing_address' => null,
                'line_items' => $ebayOrder['lineItems'] ?? [],
                'platform_data' => $ebayOrder,
                'ordered_at' => $ebayOrder['creationDate'],
                'last_synced_at' => now(),
            ]
        );
    }

    protected function flattenCategories(array $node, array &$result = []): Collection
    {
        if (isset($node['category'])) {
            $result[] = [
                'id' => $node['category']['categoryId'],
                'name' => $node['category']['categoryName'],
            ];
        }

        foreach ($node['childCategoryTreeNodes'] ?? [] as $child) {
            $this->flattenCategories($child, $result);
        }

        return collect($result);
    }

    protected function handleAccountDeletion(array $data, StoreMarketplace $connection): void
    {
        $connection->update(['status' => 'inactive']);
    }
}
