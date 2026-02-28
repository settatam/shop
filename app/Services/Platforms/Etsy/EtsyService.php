<?php

namespace App\Services\Platforms\Etsy;

use App\Enums\Platform;
use App\Models\CategoryPlatformMapping;
use App\Models\PlatformListing;
use App\Models\PlatformOrder;
use App\Models\Product;
use App\Models\Store;
use App\Models\StoreMarketplace;
use App\Services\Platforms\BasePlatformService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class EtsyService extends BasePlatformService
{
    protected string $apiBaseUrl = 'https://openapi.etsy.com/v3';

    protected string $authUrl = 'https://www.etsy.com/oauth/connect';

    protected string $tokenUrl = 'https://api.etsy.com/v3/public/oauth/token';

    public function getPlatform(): string
    {
        return Platform::Etsy->value;
    }

    public function connect(Store $store, array $params = []): RedirectResponse
    {
        $codeVerifier = Str::random(64);
        $codeChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');

        // Store code verifier for callback
        Cache::put("etsy_code_verifier_{$store->id}", $codeVerifier, now()->addMinutes(10));

        $scopes = implode(' ', $this->getRequiredScopes());

        $authUrl = $this->authUrl.'?'.http_build_query([
            'response_type' => 'code',
            'client_id' => config('services.etsy.keystring'),
            'redirect_uri' => config('services.etsy.redirect_uri'),
            'scope' => $scopes,
            'state' => encrypt(['store_id' => $store->id]),
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ]);

        return redirect()->away($authUrl);
    }

    public function handleCallback(Request $request, Store $store): StoreMarketplace
    {
        $code = $request->input('code');
        $codeVerifier = Cache::pull("etsy_code_verifier_{$store->id}");

        if (! $codeVerifier) {
            throw new \Exception('Code verifier not found. Please try connecting again.');
        }

        $response = Http::asForm()->post($this->tokenUrl, [
            'grant_type' => 'authorization_code',
            'client_id' => config('services.etsy.keystring'),
            'redirect_uri' => config('services.etsy.redirect_uri'),
            'code' => $code,
            'code_verifier' => $codeVerifier,
        ]);

        if ($response->failed()) {
            throw new \Exception('Failed to obtain access token: '.$response->body());
        }

        $data = $response->json();

        // Get user/shop info
        $shopInfo = $this->getShopInfo($data['access_token']);

        $shopId = (string) $shopInfo['shop_id'];

        // Use shop_id as external_store_id to support multiple accounts
        return StoreMarketplace::updateOrCreate(
            [
                'store_id' => $store->id,
                'platform' => Platform::Etsy,
                'external_store_id' => $shopId,
            ],
            [
                'name' => $shopInfo['shop_name'] ?? 'Etsy Shop',
                'external_store_id' => $shopId,
                'access_token' => $data['access_token'],
                'refresh_token' => $data['refresh_token'],
                'token_expires_at' => now()->addSeconds($data['expires_in'] ?? 3600),
                'credentials' => [
                    'shop_id' => $shopInfo['shop_id'],
                    'user_id' => $shopInfo['user_id'],
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
            'client_id' => config('services.etsy.keystring'),
            'refresh_token' => $connection->refresh_token,
        ]);

        if ($response->failed()) {
            throw new \Exception('Failed to refresh token: '.$response->body());
        }

        $data = $response->json();

        $connection->update([
            'access_token' => $data['access_token'],
            'refresh_token' => $data['refresh_token'],
            'token_expires_at' => now()->addSeconds($data['expires_in'] ?? 3600),
        ]);

        return $connection->fresh();
    }

    public function validateCredentials(StoreMarketplace $connection): bool
    {
        try {
            $this->ensureValidToken($connection);
            $shopId = $connection->credentials['shop_id'];
            $response = $this->etsyRequest($connection, 'GET', "/application/shops/{$shopId}");

            return isset($response['shop_id']);
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
            $shopId = $connection->credentials['shop_id'];
            $offset = 0;
            $limit = 100;

            do {
                $response = $this->etsyRequest($connection, 'GET', "/application/shops/{$shopId}/listings/active", [
                    'limit' => $limit,
                    'offset' => $offset,
                ]);

                foreach ($response['results'] ?? [] as $listing) {
                    $products->push($this->mapEtsyProduct($listing, $connection));
                    $syncLog->incrementProcessed();
                    $syncLog->incrementSuccess();
                }

                $offset += $limit;
                $count = $response['count'] ?? 0;
            } while ($offset < $count);

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

        $shopId = $connection->credentials['shop_id'];
        $listingData = $this->mapToEtsyListing($product, $connection);

        $response = $this->etsyRequest(
            $connection,
            'POST',
            "/application/shops/{$shopId}/listings",
            $listingData
        );

        $listingId = $response['listing_id'];

        // Upload images
        if ($product->images->isNotEmpty()) {
            $this->uploadListingImages($connection, $listingId, $product);
        }

        return PlatformListing::create([
            'store_marketplace_id' => $connection->id,
            'product_id' => $product->id,
            'external_listing_id' => (string) $listingId,
            'status' => $response['state'] === 'active' ? PlatformListing::STATUS_LISTED : PlatformListing::STATUS_PENDING,
            'listing_url' => $response['url'] ?? "https://www.etsy.com/listing/{$listingId}",
            'platform_data' => $response,
            'last_synced_at' => now(),
            'published_at' => $response['state'] === 'active' ? now() : null,
        ]);
    }

    public function updateListing(PlatformListing $listing): PlatformListing
    {
        $product = $listing->product;
        $connection = $listing->marketplace;

        $this->ensureValidToken($connection);

        $listingData = $this->mapToEtsyListing($product, $connection);

        $response = $this->etsyRequest(
            $connection,
            'PATCH',
            "/application/listings/{$listing->external_listing_id}",
            $listingData
        );

        $listing->update([
            'platform_data' => $response,
            'last_synced_at' => now(),
        ]);

        return $listing;
    }

    public function deleteListing(PlatformListing $listing): void
    {
        $connection = $listing->marketplace;
        $this->ensureValidToken($connection);

        $this->etsyRequest(
            $connection,
            'DELETE',
            "/application/listings/{$listing->external_listing_id}"
        );

        $listing->delete();
    }

    public function unlistListing(PlatformListing $listing): PlatformListing
    {
        $connection = $listing->marketplace;
        $this->ensureValidToken($connection);

        $shopId = $connection->credentials['shop_id'];

        // Set listing state to 'inactive' (deactivates without deleting)
        $this->etsyRequest(
            $connection,
            'PUT',
            "/application/shops/{$shopId}/listings/{$listing->external_listing_id}",
            [
                'state' => 'inactive',
            ]
        );

        $listing->update([
            'status' => PlatformListing::STATUS_ENDED,
            'last_synced_at' => now(),
        ]);

        return $listing->fresh();
    }

    public function relistListing(PlatformListing $listing): PlatformListing
    {
        $connection = $listing->marketplace;
        $this->ensureValidToken($connection);

        $shopId = $connection->credentials['shop_id'];

        // Set listing state back to 'active'
        $this->etsyRequest(
            $connection,
            'PUT',
            "/application/shops/{$shopId}/listings/{$listing->external_listing_id}",
            [
                'state' => 'active',
            ]
        );

        $listing->update([
            'status' => PlatformListing::STATUS_LISTED,
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
                $this->etsyRequest(
                    $connection,
                    'PUT',
                    "/application/listings/{$listing->external_listing_id}/inventory",
                    [
                        'products' => [[
                            'offerings' => [[
                                'price' => ['amount' => (int) ($listing->variant->price * 100), 'divisor' => 100, 'currency_code' => 'USD'],
                                'quantity' => $listing->variant->quantity,
                                'is_enabled' => true,
                            ]],
                        ]],
                    ]
                );
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
            $shopId = $connection->credentials['shop_id'];

            $params = ['limit' => 100];
            if ($since) {
                $params['min_created'] = strtotime($since);
            }

            $response = $this->etsyRequest(
                $connection,
                'GET',
                "/application/shops/{$shopId}/receipts",
                $params
            );

            foreach ($response['results'] ?? [] as $receipt) {
                $platformOrder = $this->importOrder($receipt, $connection);
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
        $this->ensureValidToken($order->marketplace);
        $shopId = $order->marketplace->credentials['shop_id'];

        $this->etsyRequest(
            $order->marketplace,
            'POST',
            "/application/shops/{$shopId}/receipts/{$order->external_order_id}/tracking",
            [
                'tracking_code' => $fulfillmentData['tracking_number'] ?? '',
                'carrier_name' => $fulfillmentData['carrier'] ?? 'other',
                'send_bcc' => false,
            ]
        );

        $order->update(['fulfillment_status' => 'shipped']);
    }

    public function getCategories(StoreMarketplace $connection): Collection
    {
        $this->ensureValidToken($connection);

        $response = $this->etsyRequest($connection, 'GET', '/application/seller-taxonomy/nodes');

        return $this->flattenTaxonomy($response['results'] ?? []);
    }

    public function registerWebhooks(StoreMarketplace $connection): void
    {
        // Etsy doesn't have traditional webhooks
        // Instead, you poll for updates or use push notifications via their ping endpoint
    }

    public function handleWebhook(Request $request, StoreMarketplace $connection): void
    {
        // Etsy uses a ping/callback model rather than traditional webhooks
    }

    /**
     * Fetch shipping profiles for the connected Etsy shop.
     *
     * @return array{shipping_profiles: array<int, array<string, mixed>>}
     */
    public function getShippingProfiles(StoreMarketplace $connection): array
    {
        $this->ensureValidToken($connection);
        $shopId = $connection->credentials['shop_id'];

        $response = $this->etsyRequest($connection, 'GET', "/application/shops/{$shopId}/shipping-profiles");

        return [
            'shipping_profiles' => collect($response['results'] ?? [])->map(fn (array $profile) => [
                'shipping_profile_id' => $profile['shipping_profile_id'],
                'title' => $profile['title'] ?? '',
                'origin_country_iso' => $profile['origin_country_iso'] ?? '',
                'processing_days_display_label' => $profile['processing_days_display_label'] ?? '',
            ])->values()->all(),
        ];
    }

    /**
     * Fetch return policies for the connected Etsy shop.
     *
     * @return array{return_policies: array<int, array<string, mixed>>}
     */
    public function getReturnPolicies(StoreMarketplace $connection): array
    {
        $this->ensureValidToken($connection);
        $shopId = $connection->credentials['shop_id'];

        $response = $this->etsyRequest($connection, 'GET', "/application/shops/{$shopId}/policies/return");

        return [
            'return_policies' => collect($response['results'] ?? [])->map(fn (array $policy) => [
                'return_policy_id' => $policy['return_policy_id'] ?? $policy['policy_id'] ?? '',
                'accepts_returns' => $policy['accepts_returns'] ?? false,
                'accepts_exchanges' => $policy['accepts_exchanges'] ?? false,
                'return_deadline' => $policy['return_deadline'] ?? null,
            ])->values()->all(),
        ];
    }

    // Helper methods

    public function etsyRequest(
        StoreMarketplace $connection,
        string $method,
        string $endpoint,
        array $data = []
    ): array {
        $url = $this->apiBaseUrl.$endpoint;

        $request = Http::withHeaders([
            'Authorization' => 'Bearer '.$connection->access_token,
            'x-api-key' => config('services.etsy.keystring'),
            'Content-Type' => 'application/json',
        ]);

        $response = match (strtoupper($method)) {
            'GET' => $request->get($url, $data),
            'POST' => $request->post($url, $data),
            'PUT' => $request->put($url, $data),
            'PATCH' => $request->patch($url, $data),
            'DELETE' => $request->delete($url),
            default => throw new \InvalidArgumentException("Unsupported method: {$method}"),
        };

        if ($response->failed()) {
            throw new \Exception("Etsy API error: {$response->body()}");
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
            'listings_r',
            'listings_w',
            'transactions_r',
            'transactions_w',
            'shops_r',
            'shops_w',
        ];
    }

    protected function getShopInfo(string $accessToken): array
    {
        $response = Http::withHeaders([
            'Authorization' => "Bearer {$accessToken}",
            'x-api-key' => config('services.etsy.keystring'),
        ])->get("{$this->apiBaseUrl}/application/users/me");

        if ($response->failed()) {
            throw new \Exception('Failed to get user info: '.$response->body());
        }

        $user = $response->json();
        $userId = $user['user_id'];

        // Get shop
        $shopResponse = Http::withHeaders([
            'Authorization' => "Bearer {$accessToken}",
            'x-api-key' => config('services.etsy.keystring'),
        ])->get("{$this->apiBaseUrl}/application/users/{$userId}/shops");

        $shops = $shopResponse->json();

        return [
            'user_id' => $userId,
            'shop_id' => $shops['results'][0]['shop_id'] ?? null,
            'shop_name' => $shops['results'][0]['shop_name'] ?? null,
        ];
    }

    protected function mapEtsyProduct(array $listing, StoreMarketplace $connection): array
    {
        return [
            'external_id' => $listing['listing_id'],
            'title' => $listing['title'],
            'description' => $listing['description'],
            'price' => $listing['price']['amount'] / $listing['price']['divisor'],
            'quantity' => $listing['quantity'],
            'tags' => $listing['tags'] ?? [],
            'materials' => $listing['materials'] ?? [],
            'category_id' => $listing['taxonomy_id'] ?? null,
        ];
    }

    public function mapToEtsyListing(Product $product, ?StoreMarketplace $connection = null): array
    {
        $variant = $product->variants->first();
        $settings = $connection?->settings ?? [];
        $priceMarkup = ($settings['price_markup'] ?? 0) / 100;
        $basePrice = $variant?->price ?? 0;
        $adjustedPrice = $basePrice + ($basePrice * $priceMarkup);

        $listing = [
            'title' => $product->title,
            'description' => $product->description ?? '',
            'price' => [
                'amount' => (int) ($adjustedPrice * 100),
                'divisor' => 100,
                'currency_code' => $settings['currency'] ?? 'USD',
            ],
            'quantity' => $variant?->quantity ?? $product->quantity ?? 0,
            'who_made' => $settings['who_made'] ?? 'i_did',
            'when_made' => $settings['when_made'] ?? 'made_to_order',
            'taxonomy_id' => $this->resolveEtsyTaxonomyId($product, $connection),
            'is_supply' => $settings['is_supply'] ?? false,
            'tags' => $product->tags ?? [],
            'materials' => [],
        ];

        if (! empty($settings['shipping_profile_id'])) {
            $listing['shipping_profile_id'] = (int) $settings['shipping_profile_id'];
        }

        if (! empty($settings['return_policy_id'])) {
            $listing['return_policy_id'] = (int) $settings['return_policy_id'];
        }

        return $listing;
    }

    /**
     * Resolve the Etsy taxonomy ID from category mapping, falling back to product category.
     */
    public function resolveEtsyTaxonomyId(Product $product, ?StoreMarketplace $connection = null): int
    {
        if ($connection && $product->category_id) {
            $mapping = CategoryPlatformMapping::where('category_id', $product->category_id)
                ->where('store_marketplace_id', $connection->id)
                ->first();

            if ($mapping && $mapping->primary_category_id) {
                return (int) $mapping->primary_category_id;
            }
        }

        return (int) ($product->category?->external_id ?? 1);
    }

    protected function uploadListingImages(StoreMarketplace $connection, int $listingId, Product $product): void
    {
        foreach ($product->images as $index => $image) {
            try {
                // In a real implementation, you'd download the image and upload it
                // Etsy requires multipart form upload for images
                $this->etsyRequest(
                    $connection,
                    'POST',
                    "/application/shops/{$connection->credentials['shop_id']}/listings/{$listingId}/images",
                    [
                        'image' => $image->url,
                        'rank' => $index + 1,
                    ]
                );
            } catch (\Throwable $e) {
                // Continue with other images
            }
        }
    }

    protected function importOrder(array $receipt, StoreMarketplace $connection): PlatformOrder
    {
        return PlatformOrder::updateOrCreate(
            [
                'store_marketplace_id' => $connection->id,
                'external_order_id' => (string) $receipt['receipt_id'],
            ],
            [
                'external_order_number' => (string) $receipt['receipt_id'],
                'status' => $receipt['status'],
                'fulfillment_status' => $receipt['is_shipped'] ? 'shipped' : 'pending',
                'payment_status' => $receipt['is_paid'] ? 'paid' : 'pending',
                'total' => ($receipt['grandtotal']['amount'] ?? 0) / ($receipt['grandtotal']['divisor'] ?? 100),
                'subtotal' => ($receipt['subtotal']['amount'] ?? 0) / ($receipt['subtotal']['divisor'] ?? 100),
                'shipping_cost' => ($receipt['total_shipping_cost']['amount'] ?? 0) / ($receipt['total_shipping_cost']['divisor'] ?? 100),
                'tax' => ($receipt['total_tax_cost']['amount'] ?? 0) / ($receipt['total_tax_cost']['divisor'] ?? 100),
                'discount' => ($receipt['discount_amt']['amount'] ?? 0) / ($receipt['discount_amt']['divisor'] ?? 100),
                'currency' => $receipt['grandtotal']['currency_code'] ?? 'USD',
                'customer_data' => [
                    'name' => $receipt['name'] ?? null,
                    'email' => $receipt['buyer_email'] ?? null,
                ],
                'shipping_address' => [
                    'name' => $receipt['name'] ?? '',
                    'address1' => $receipt['first_line'] ?? '',
                    'address2' => $receipt['second_line'] ?? '',
                    'city' => $receipt['city'] ?? '',
                    'state' => $receipt['state'] ?? '',
                    'zip' => $receipt['zip'] ?? '',
                    'country' => $receipt['country_iso'] ?? '',
                ],
                'billing_address' => null,
                'line_items' => $receipt['transactions'] ?? [],
                'platform_data' => $receipt,
                'ordered_at' => date('Y-m-d H:i:s', $receipt['create_timestamp']),
                'last_synced_at' => now(),
            ]
        );
    }

    protected function flattenTaxonomy(array $nodes, array &$result = []): Collection
    {
        foreach ($nodes as $node) {
            $result[] = [
                'id' => $node['id'],
                'name' => $node['name'],
                'full_path' => $node['full_path_taxonomy_ids'] ?? [],
            ];

            if (! empty($node['children'])) {
                $this->flattenTaxonomy($node['children'], $result);
            }
        }

        return collect($result);
    }
}
