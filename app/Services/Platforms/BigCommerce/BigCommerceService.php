<?php

namespace App\Services\Platforms\BigCommerce;

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

class BigCommerceService extends BasePlatformService
{
    public function getPlatform(): string
    {
        return Platform::BigCommerce->value;
    }

    public function connect(Store $store, array $params = []): RedirectResponse
    {
        $clientId = config('services.bigcommerce.client_id');
        $redirectUri = config('services.bigcommerce.redirect_uri');
        $scopes = 'store_v2_orders store_v2_products store_v2_information store_inventory store_products_read_only';

        session(['bigcommerce_store_id' => $store->id]);

        $url = "https://login.bigcommerce.com/app/{$clientId}/install"
            .'?'.http_build_query([
                'context' => 'stores/*',
                'scope' => $scopes,
                'redirect_uri' => $redirectUri,
            ]);

        return redirect()->away($url);
    }

    public function handleCallback(Request $request, Store $store): StoreMarketplace
    {
        $code = $request->input('code');
        $scope = $request->input('scope');
        $context = $request->input('context'); // e.g. "stores/abc123"

        $storeHash = str_replace('stores/', '', $context);

        $response = Http::post('https://login.bigcommerce.com/oauth2/token', [
            'client_id' => config('services.bigcommerce.client_id'),
            'client_secret' => config('services.bigcommerce.client_secret'),
            'code' => $code,
            'scope' => $scope,
            'context' => $context,
            'redirect_uri' => config('services.bigcommerce.redirect_uri'),
            'grant_type' => 'authorization_code',
        ]);

        if ($response->failed()) {
            throw new \Exception('Failed to exchange BigCommerce auth code: '.$response->body());
        }

        $data = $response->json();
        $accessToken = $data['access_token'];

        $connection = StoreMarketplace::updateOrCreate(
            [
                'store_id' => $store->id,
                'platform' => Platform::BigCommerce,
                'external_store_id' => $storeHash,
            ],
            [
                'name' => $data['context'] ?? 'BigCommerce Store',
                'shop_domain' => "store-{$storeHash}.mybigcommerce.com",
                'access_token' => $accessToken,
                'credentials' => [
                    'store_hash' => $storeHash,
                    'client_id' => config('services.bigcommerce.client_id'),
                    'access_token' => $accessToken,
                    'scope' => $scope,
                ],
                'status' => 'active',
                'connected_successfully' => true,
            ]
        );

        return $connection;
    }

    public function disconnect(StoreMarketplace $connection): void
    {
        $connection->update(['status' => 'inactive']);
        $connection->delete();
    }

    public function refreshToken(StoreMarketplace $connection): StoreMarketplace
    {
        // BigCommerce access tokens don't expire
        return $connection;
    }

    public function validateCredentials(StoreMarketplace $connection): bool
    {
        try {
            $response = $this->bigCommerceRequest($connection, 'GET', '/v2/store');

            return $response->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    public function pullProducts(StoreMarketplace $connection): Collection
    {
        $syncLog = $this->logSync($connection, 'products', 'pull');
        $products = collect();

        try {
            $page = 1;
            $perPage = 250;

            do {
                $response = $this->bigCommerceRequest($connection, 'GET', '/v3/catalog/products', [
                    'page' => $page,
                    'limit' => $perPage,
                    'include' => 'images,variants',
                ]);

                $data = $response->json('data', []);

                foreach ($data as $bcProduct) {
                    $products->push($this->mapBigCommerceProduct($bcProduct));
                    $syncLog->incrementProcessed();
                    $syncLog->incrementSuccess();
                }

                $page++;
            } while (count($data) === $perPage);

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
        $payload = $this->mapToApiProduct($product);

        $response = $this->bigCommerceRequest($connection, 'POST', '/v3/catalog/products', $payload);
        $bcProduct = $response->json('data');

        return PlatformListing::create([
            'store_marketplace_id' => $connection->id,
            'product_id' => $product->id,
            'external_listing_id' => (string) $bcProduct['id'],
            'status' => ($bcProduct['is_visible'] ?? false) ? 'active' : 'draft',
            'listing_url' => $bcProduct['custom_url']['url'] ?? null,
            'platform_data' => $bcProduct,
            'last_synced_at' => now(),
            'published_at' => ($bcProduct['is_visible'] ?? false) ? now() : null,
        ]);
    }

    public function updateListing(PlatformListing $listing): PlatformListing
    {
        $product = $listing->product;
        $payload = $this->mapToApiProduct($product);

        $response = $this->bigCommerceRequest(
            $listing->connection,
            'PUT',
            "/v3/catalog/products/{$listing->external_listing_id}",
            $payload
        );

        $listing->update([
            'platform_data' => $response->json('data'),
            'last_synced_at' => now(),
        ]);

        return $listing;
    }

    public function deleteListing(PlatformListing $listing): void
    {
        $this->bigCommerceRequest(
            $listing->connection,
            'DELETE',
            "/v3/catalog/products/{$listing->external_listing_id}"
        );

        $listing->delete();
    }

    public function unlistListing(PlatformListing $listing): PlatformListing
    {
        $this->bigCommerceRequest(
            $listing->marketplace,
            'PUT',
            "/v3/catalog/products/{$listing->external_listing_id}",
            ['is_visible' => false]
        );

        $listing->update([
            'status' => PlatformListing::STATUS_UNLISTED,
            'last_synced_at' => now(),
        ]);

        return $listing->fresh();
    }

    public function relistListing(PlatformListing $listing): PlatformListing
    {
        $this->bigCommerceRequest(
            $listing->marketplace,
            'PUT',
            "/v3/catalog/products/{$listing->external_listing_id}",
            ['is_visible' => true]
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
        $listings = $connection->listings()->with('variant')->get();

        foreach ($listings as $listing) {
            if (! $listing->variant || ! $listing->external_listing_id) {
                continue;
            }

            $this->bigCommerceRequest($connection, 'PUT', "/v3/catalog/products/{$listing->external_listing_id}", [
                'inventory_level' => $listing->variant->quantity,
            ]);
        }
    }

    public function pullOrders(StoreMarketplace $connection, ?string $since = null): Collection
    {
        $syncLog = $this->logSync($connection, 'orders', 'pull');
        $orders = collect();

        try {
            $params = ['limit' => 250];
            if ($since) {
                $params['min_date_created'] = $since;
            }

            $response = $this->bigCommerceRequest($connection, 'GET', '/v2/orders', $params);
            $data = $response->json() ?? [];

            foreach ($data as $bcOrder) {
                $platformOrder = $this->importOrder($bcOrder, $connection);
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
        $connection = $order->connection;

        $payload = [
            'tracking_number' => $fulfillmentData['tracking_number'] ?? '',
            'shipping_method' => $fulfillmentData['method'] ?? 'Standard',
            'shipping_provider' => $fulfillmentData['carrier'] ?? '',
            'order_address_id' => $fulfillmentData['address_id'] ?? 1,
            'items' => $fulfillmentData['items'] ?? [],
        ];

        $this->bigCommerceRequest(
            $connection,
            'POST',
            "/v2/orders/{$order->external_order_id}/shipments",
            $payload
        );

        $order->update(['fulfillment_status' => 'fulfilled']);
    }

    public function getCategories(StoreMarketplace $connection): Collection
    {
        $response = $this->bigCommerceRequest($connection, 'GET', '/v3/catalog/categories', [
            'limit' => 250,
        ]);

        $data = $response->json('data', []);

        return collect($data)->map(fn ($c) => [
            'id' => $c['id'],
            'name' => $c['name'],
            'parent_id' => $c['parent_id'] ?? null,
        ]);
    }

    public function getWebhookUrl(StoreMarketplace $connection): string
    {
        return route('webhooks.bigcommerce', [
            'connectionId' => $connection->id,
        ]);
    }

    public function registerWebhooks(StoreMarketplace $connection): void
    {
        $topics = [
            'store/order/created',
            'store/order/updated',
            'store/product/updated',
            'store/product/deleted',
            'store/app/uninstalled',
        ];

        $webhookUrl = $this->getWebhookUrl($connection);

        foreach ($topics as $topic) {
            try {
                $this->bigCommerceRequest($connection, 'POST', '/v3/hooks', [
                    'scope' => $topic,
                    'destination' => $webhookUrl,
                    'is_active' => true,
                ]);
            } catch (\Throwable) {
                // Webhook may already exist
            }
        }
    }

    public function handleWebhook(Request $request, StoreMarketplace $connection): void
    {
        $scope = $request->input('scope');
        $data = $request->input('data', []);

        match ($scope) {
            'store/order/created', 'store/order/updated' => $this->handleOrderWebhook($data, $connection),
            'store/product/updated' => $this->handleProductWebhook($data, $connection),
            'store/product/deleted' => $this->handleProductDeletedWebhook($data, $connection),
            'store/app/uninstalled' => $this->handleUninstallWebhook($connection),
            default => null,
        };
    }

    /**
     * Make an authenticated request to the BigCommerce API.
     */
    protected function bigCommerceRequest(
        StoreMarketplace $connection,
        string $method,
        string $endpoint,
        array $data = []
    ): \Illuminate\Http\Client\Response {
        $storeHash = $connection->external_store_id
            ?? $connection->credentials['store_hash']
            ?? null;

        $baseUrl = "https://api.bigcommerce.com/stores/{$storeHash}";
        $url = $baseUrl.$endpoint;

        $request = Http::withHeaders([
            'X-Auth-Token' => $connection->access_token,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ])->timeout(30);

        $response = match (strtoupper($method)) {
            'GET' => $request->get($url, $data),
            'POST' => $request->post($url, $data),
            'PUT' => $request->put($url, $data),
            'DELETE' => $request->delete($url, $data),
            default => throw new \InvalidArgumentException("Unsupported method: {$method}"),
        };

        if ($response->failed()) {
            throw new \Exception("BigCommerce API error: {$response->body()}");
        }

        return $response;
    }

    /**
     * @param  array<string, mixed>  $bcProduct
     * @return array<string, mixed>
     */
    protected function mapBigCommerceProduct(array $bcProduct): array
    {
        $images = collect($bcProduct['images'] ?? [])->pluck('url_standard')->all();
        $variants = $bcProduct['variants'] ?? [];

        return [
            'external_id' => (string) $bcProduct['id'],
            'title' => $bcProduct['name'],
            'description' => $bcProduct['description'] ?? '',
            'sku' => $bcProduct['sku'] ?? null,
            'price' => (string) ($bcProduct['price'] ?? 0),
            'regular_price' => (string) ($bcProduct['price'] ?? 0),
            'sale_price' => '',
            'quantity' => (int) ($bcProduct['inventory_level'] ?? 0),
            'status' => ($bcProduct['is_visible'] ?? false) ? 'active' : 'draft',
            'categories' => [],
            'images' => $images,
            'variants' => array_map(fn ($v) => [
                'external_id' => (string) $v['id'],
                'sku' => $v['sku'] ?? null,
                'price' => (float) ($v['price'] ?? 0),
                'quantity' => (int) ($v['inventory_level'] ?? 0),
            ], $variants),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function mapToApiProduct(Product $product): array
    {
        $payload = [
            'name' => $product->title,
            'type' => 'physical',
            'description' => $product->description ?? '',
            'price' => (float) ($product->variants->first()?->price ?? 0),
            'sku' => $product->variants->first()?->sku ?? $product->handle,
            'weight' => $product->weight ?? 0,
            'is_visible' => $product->is_published,
        ];

        if ($product->images->isNotEmpty()) {
            $payload['images'] = $product->images->map(fn ($img) => [
                'image_url' => $img->url,
                'is_thumbnail' => $img->position === 0,
            ])->all();
        }

        return $payload;
    }

    protected function importOrder(array $bcOrder, StoreMarketplace $connection): PlatformOrder
    {
        return PlatformOrder::updateOrCreate(
            [
                'store_marketplace_id' => $connection->id,
                'external_order_id' => (string) $bcOrder['id'],
            ],
            [
                'external_order_number' => (string) $bcOrder['id'],
                'status' => strtolower($bcOrder['status'] ?? 'pending'),
                'fulfillment_status' => $this->mapFulfillmentStatus($bcOrder['status_id'] ?? 0),
                'payment_status' => $bcOrder['payment_status'] ?? 'pending',
                'total' => $bcOrder['total_inc_tax'] ?? 0,
                'subtotal' => $bcOrder['subtotal_inc_tax'] ?? 0,
                'shipping_cost' => $bcOrder['shipping_cost_inc_tax'] ?? 0,
                'tax' => $bcOrder['total_tax'] ?? 0,
                'discount' => $bcOrder['discount_amount'] ?? 0,
                'currency' => $bcOrder['currency_code'] ?? 'USD',
                'customer_data' => [
                    'id' => $bcOrder['customer_id'] ?? null,
                    'email' => $bcOrder['billing_address']['email'] ?? null,
                    'first_name' => $bcOrder['billing_address']['first_name'] ?? null,
                    'last_name' => $bcOrder['billing_address']['last_name'] ?? null,
                ],
                'shipping_address' => $bcOrder['shipping_addresses'][0] ?? $bcOrder['billing_address'] ?? [],
                'billing_address' => $bcOrder['billing_address'] ?? [],
                'line_items' => $bcOrder['products'] ?? [],
                'platform_data' => $bcOrder,
                'ordered_at' => $bcOrder['date_created'] ?? now(),
                'last_synced_at' => now(),
            ]
        );
    }

    protected function mapFulfillmentStatus(int $statusId): string
    {
        return match ($statusId) {
            2, 10 => 'fulfilled',
            5 => 'cancelled',
            default => 'unfulfilled',
        };
    }

    protected function handleOrderWebhook(array $data, StoreMarketplace $connection): void
    {
        $orderId = $data['id'] ?? null;
        if (! $orderId) {
            return;
        }

        try {
            $response = $this->bigCommerceRequest($connection, 'GET', "/v2/orders/{$orderId}");
            $this->importOrder($response->json(), $connection);
        } catch (\Throwable) {
            // Log and continue
        }
    }

    protected function handleProductWebhook(array $data, StoreMarketplace $connection): void
    {
        $productId = $data['id'] ?? null;
        if (! $productId) {
            return;
        }

        PlatformListing::where('store_marketplace_id', $connection->id)
            ->where('external_listing_id', $productId)
            ->update(['last_synced_at' => now()]);
    }

    protected function handleProductDeletedWebhook(array $data, StoreMarketplace $connection): void
    {
        $productId = $data['id'] ?? null;
        if (! $productId) {
            return;
        }

        PlatformListing::where('store_marketplace_id', $connection->id)
            ->where('external_listing_id', $productId)
            ->update(['status' => 'deleted']);
    }

    protected function handleUninstallWebhook(StoreMarketplace $connection): void
    {
        $connection->update(['status' => 'inactive']);
    }
}
