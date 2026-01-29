<?php

namespace App\Services\Platforms\WooCommerce;

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

class WooCommerceService extends BasePlatformService
{
    protected string $apiVersion = 'wc/v3';

    public function getPlatform(): string
    {
        return Platform::WooCommerce->value;
    }

    public function connect(Store $store, array $params = []): RedirectResponse
    {
        // WooCommerce uses API key authentication
        // Redirect to settings page for manual credential entry
        return redirect()->route('settings.integrations.woocommerce', [
            'store' => $store->id,
        ]);
    }

    public function connectWithCredentials(Store $store, array $credentials): StoreMarketplace
    {
        $siteUrl = rtrim($credentials['site_url'], '/');
        $consumerKey = $credentials['consumer_key'];
        $consumerSecret = $credentials['consumer_secret'];

        // Validate credentials by making a test request
        $response = Http::withBasicAuth($consumerKey, $consumerSecret)
            ->get("{$siteUrl}/wp-json/{$this->apiVersion}/system_status");

        if ($response->failed()) {
            throw new \Exception('Failed to connect to WooCommerce store: '.$response->body());
        }

        return StoreMarketplace::updateOrCreate(
            [
                'store_id' => $store->id,
                'platform' => Platform::WooCommerce,
                'shop_domain' => $siteUrl,
            ],
            [
                'name' => $this->extractStoreName($siteUrl),
                'access_token' => $consumerKey, // Store consumer key as access token
                'credentials' => [
                    'site_url' => $siteUrl,
                    'consumer_key' => $consumerKey,
                    'consumer_secret' => encrypt($consumerSecret),
                ],
                'status' => 'active',
            ]
        );
    }

    public function handleCallback(Request $request, Store $store): StoreMarketplace
    {
        return $this->connectWithCredentials($store, [
            'site_url' => $request->input('site_url'),
            'consumer_key' => $request->input('consumer_key'),
            'consumer_secret' => $request->input('consumer_secret'),
        ]);
    }

    public function disconnect(StoreMarketplace $connection): void
    {
        $connection->update(['status' => 'inactive']);
        $connection->delete();
    }

    public function refreshToken(StoreMarketplace $connection): StoreMarketplace
    {
        // WooCommerce doesn't use tokens that expire
        return $connection;
    }

    public function validateCredentials(StoreMarketplace $connection): bool
    {
        try {
            $response = $this->wooRequest($connection, 'GET', 'system_status');

            return isset($response['environment']);
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function pullProducts(StoreMarketplace $connection): Collection
    {
        $syncLog = $this->logSync($connection, 'products', 'pull');
        $products = collect();

        try {
            $page = 1;
            $perPage = 100;

            do {
                $response = $this->wooRequest($connection, 'GET', 'products', [
                    'page' => $page,
                    'per_page' => $perPage,
                ]);

                foreach ($response as $wooProduct) {
                    $products->push($this->mapWooProduct($wooProduct, $connection));
                    $syncLog->incrementProcessed();
                    $syncLog->incrementSuccess();
                }

                $page++;
            } while (count($response) === $perPage);

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
        $wooProduct = $this->mapToWooProduct($product);

        $response = $this->wooRequest($connection, 'POST', 'products', $wooProduct);

        return PlatformListing::create([
            'store_marketplace_id' => $connection->id,
            'product_id' => $product->id,
            'external_listing_id' => (string) $response['id'],
            'status' => $response['status'] === 'publish' ? 'active' : 'draft',
            'listing_url' => $response['permalink'],
            'platform_data' => $response,
            'last_synced_at' => now(),
            'published_at' => $response['status'] === 'publish' ? now() : null,
        ]);
    }

    public function updateListing(PlatformListing $listing): PlatformListing
    {
        $product = $listing->product;
        $connection = $listing->connection;
        $wooProduct = $this->mapToWooProduct($product);

        $response = $this->wooRequest(
            $connection,
            'PUT',
            "products/{$listing->external_listing_id}",
            $wooProduct
        );

        $listing->update([
            'platform_data' => $response,
            'last_synced_at' => now(),
        ]);

        return $listing;
    }

    public function deleteListing(PlatformListing $listing): void
    {
        $this->wooRequest(
            $listing->connection,
            'DELETE',
            "products/{$listing->external_listing_id}",
            ['force' => true]
        );

        $listing->delete();
    }

    public function syncInventory(StoreMarketplace $connection): void
    {
        $listings = $connection->listings()->with('variant')->get();

        $batch = [];
        foreach ($listings as $listing) {
            if (! $listing->variant) {
                continue;
            }

            $batch[] = [
                'id' => (int) $listing->external_listing_id,
                'stock_quantity' => $listing->variant->quantity,
                'manage_stock' => true,
            ];
        }

        if (! empty($batch)) {
            $this->wooRequest($connection, 'POST', 'products/batch', [
                'update' => $batch,
            ]);
        }
    }

    public function pullOrders(StoreMarketplace $connection, ?string $since = null): Collection
    {
        $syncLog = $this->logSync($connection, 'orders', 'pull');
        $orders = collect();

        try {
            $params = ['per_page' => 100];
            if ($since) {
                $params['after'] = $since;
            }

            $response = $this->wooRequest($connection, 'GET', 'orders', $params);

            foreach ($response as $wooOrder) {
                $platformOrder = $this->importOrder($wooOrder, $connection);
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
        $updateData = [
            'status' => 'completed',
        ];

        // Add tracking info via order notes
        if (! empty($fulfillmentData['tracking_number'])) {
            $this->wooRequest(
                $order->connection,
                'POST',
                "orders/{$order->external_order_id}/notes",
                [
                    'note' => sprintf(
                        'Order shipped via %s. Tracking: %s',
                        $fulfillmentData['carrier'] ?? 'Carrier',
                        $fulfillmentData['tracking_number']
                    ),
                    'customer_note' => true,
                ]
            );
        }

        $this->wooRequest(
            $order->connection,
            'PUT',
            "orders/{$order->external_order_id}",
            $updateData
        );

        $order->update(['fulfillment_status' => 'completed']);
    }

    public function getCategories(StoreMarketplace $connection): Collection
    {
        $response = $this->wooRequest($connection, 'GET', 'products/categories', [
            'per_page' => 100,
        ]);

        return collect($response)->map(fn ($c) => [
            'id' => $c['id'],
            'name' => $c['name'],
            'slug' => $c['slug'],
            'parent' => $c['parent'],
        ]);
    }

    public function registerWebhooks(StoreMarketplace $connection): void
    {
        $topics = [
            'order.created',
            'order.updated',
            'product.updated',
            'product.deleted',
        ];

        foreach ($topics as $topic) {
            try {
                $this->wooRequest($connection, 'POST', 'webhooks', [
                    'name' => "Shopmata - {$topic}",
                    'topic' => $topic,
                    'delivery_url' => $this->getWebhookUrl($connection),
                    'status' => 'active',
                ]);
            } catch (\Throwable $e) {
                // May already exist
            }
        }
    }

    public function handleWebhook(Request $request, StoreMarketplace $connection): void
    {
        $topic = $request->header('X-WC-Webhook-Topic');
        $data = $request->all();

        match ($topic) {
            'order.created', 'order.updated' => $this->handleOrderWebhook($data, $connection),
            'product.updated' => $this->handleProductWebhook($data, $connection),
            'product.deleted' => $this->handleProductDeletedWebhook($data, $connection),
            default => null,
        };
    }

    // Helper methods

    protected function wooRequest(
        StoreMarketplace $connection,
        string $method,
        string $endpoint,
        array $data = []
    ): array {
        $siteUrl = $connection->credentials['site_url'];
        $consumerKey = $connection->credentials['consumer_key'];
        $consumerSecret = decrypt($connection->credentials['consumer_secret']);

        $url = "{$siteUrl}/wp-json/{$this->apiVersion}/{$endpoint}";

        $request = Http::withBasicAuth($consumerKey, $consumerSecret)
            ->withHeaders([
                'Content-Type' => 'application/json',
            ]);

        $response = match (strtoupper($method)) {
            'GET' => $request->get($url, $data),
            'POST' => $request->post($url, $data),
            'PUT' => $request->put($url, $data),
            'DELETE' => $request->delete($url, $data),
            default => throw new \InvalidArgumentException("Unsupported method: {$method}"),
        };

        if ($response->failed()) {
            throw new \Exception("WooCommerce API error: {$response->body()}");
        }

        return $response->json() ?? [];
    }

    protected function extractStoreName(string $url): string
    {
        $parsed = parse_url($url);

        return $parsed['host'] ?? 'WooCommerce Store';
    }

    protected function mapWooProduct(array $wooProduct, StoreMarketplace $connection): array
    {
        return [
            'external_id' => $wooProduct['id'],
            'title' => $wooProduct['name'],
            'description' => $wooProduct['description'],
            'short_description' => $wooProduct['short_description'],
            'sku' => $wooProduct['sku'],
            'price' => $wooProduct['price'],
            'regular_price' => $wooProduct['regular_price'],
            'sale_price' => $wooProduct['sale_price'],
            'quantity' => $wooProduct['stock_quantity'],
            'status' => $wooProduct['status'],
            'categories' => collect($wooProduct['categories'] ?? [])->pluck('name')->all(),
            'images' => collect($wooProduct['images'] ?? [])->pluck('src')->all(),
            'variants' => $this->mapWooVariations($wooProduct['variations'] ?? [], $connection),
        ];
    }

    protected function mapWooVariations(array $variationIds, StoreMarketplace $connection): array
    {
        // In a real implementation, you'd fetch each variation
        return [];
    }

    protected function mapToWooProduct(Product $product): array
    {
        $wooProduct = [
            'name' => $product->title,
            'type' => $product->has_variants ? 'variable' : 'simple',
            'description' => $product->description ?? '',
            'short_description' => $product->short_description ?? '',
            'sku' => $product->variants->first()?->sku ?? $product->handle,
            'regular_price' => (string) ($product->variants->first()?->price ?? 0),
            'manage_stock' => true,
            'stock_quantity' => $product->variants->first()?->quantity ?? $product->quantity ?? 0,
            'status' => $product->is_published ? 'publish' : 'draft',
        ];

        // Add images
        if ($product->images->isNotEmpty()) {
            $wooProduct['images'] = $product->images->map(fn ($img) => [
                'src' => $img->url,
                'position' => $img->position,
            ])->all();
        }

        // Add categories
        if ($product->category) {
            $wooProduct['categories'] = [
                ['name' => $product->category->name],
            ];
        }

        // Handle variants for variable products
        if ($product->has_variants && $product->variants->count() > 1) {
            $wooProduct['attributes'] = $this->buildWooAttributes($product);
            $wooProduct['variations'] = $product->variants->map(fn ($v) => [
                'sku' => $v->sku,
                'regular_price' => (string) $v->price,
                'stock_quantity' => $v->quantity,
                'attributes' => $this->buildVariantAttributes($v),
            ])->all();
        }

        return $wooProduct;
    }

    protected function buildWooAttributes(Product $product): array
    {
        $attributes = [];
        $options = [[], [], []];

        foreach ($product->variants as $variant) {
            if ($variant->option1_value) {
                $options[0][] = $variant->option1_value;
            }
            if ($variant->option2_value) {
                $options[1][] = $variant->option2_value;
            }
            if ($variant->option3_value) {
                $options[2][] = $variant->option3_value;
            }
        }

        $optionNames = ['Option 1', 'Option 2', 'Option 3'];

        foreach ($options as $index => $values) {
            $uniqueValues = array_unique(array_filter($values));
            if (! empty($uniqueValues)) {
                $attributes[] = [
                    'name' => $optionNames[$index],
                    'options' => array_values($uniqueValues),
                    'visible' => true,
                    'variation' => true,
                ];
            }
        }

        return $attributes;
    }

    protected function buildVariantAttributes($variant): array
    {
        $attributes = [];

        if ($variant->option1_value) {
            $attributes[] = ['name' => 'Option 1', 'option' => $variant->option1_value];
        }
        if ($variant->option2_value) {
            $attributes[] = ['name' => 'Option 2', 'option' => $variant->option2_value];
        }
        if ($variant->option3_value) {
            $attributes[] = ['name' => 'Option 3', 'option' => $variant->option3_value];
        }

        return $attributes;
    }

    protected function importOrder(array $wooOrder, StoreMarketplace $connection): PlatformOrder
    {
        return PlatformOrder::updateOrCreate(
            [
                'store_marketplace_id' => $connection->id,
                'external_order_id' => (string) $wooOrder['id'],
            ],
            [
                'external_order_number' => $wooOrder['number'],
                'status' => $wooOrder['status'],
                'fulfillment_status' => $this->mapWooStatus($wooOrder['status']),
                'payment_status' => $wooOrder['date_paid'] ? 'paid' : 'pending',
                'total' => $wooOrder['total'],
                'subtotal' => $wooOrder['subtotal'] ?? $wooOrder['total'],
                'shipping_cost' => $wooOrder['shipping_total'] ?? 0,
                'tax' => $wooOrder['total_tax'] ?? 0,
                'discount' => $wooOrder['discount_total'] ?? 0,
                'currency' => $wooOrder['currency'],
                'customer_data' => [
                    'id' => $wooOrder['customer_id'],
                    'email' => $wooOrder['billing']['email'] ?? null,
                    'first_name' => $wooOrder['billing']['first_name'] ?? null,
                    'last_name' => $wooOrder['billing']['last_name'] ?? null,
                ],
                'shipping_address' => $wooOrder['shipping'],
                'billing_address' => $wooOrder['billing'],
                'line_items' => $wooOrder['line_items'],
                'platform_data' => $wooOrder,
                'ordered_at' => $wooOrder['date_created'],
                'last_synced_at' => now(),
            ]
        );
    }

    protected function mapWooStatus(string $status): string
    {
        return match ($status) {
            'completed' => 'fulfilled',
            'processing' => 'processing',
            'on-hold' => 'on_hold',
            'pending' => 'pending',
            'cancelled' => 'cancelled',
            'refunded' => 'refunded',
            'failed' => 'failed',
            default => $status,
        };
    }

    protected function handleOrderWebhook(array $data, StoreMarketplace $connection): void
    {
        $this->importOrder($data, $connection);
    }

    protected function handleProductWebhook(array $data, StoreMarketplace $connection): void
    {
        PlatformListing::where('store_marketplace_id', $connection->id)
            ->where('external_listing_id', $data['id'])
            ->update([
                'platform_data' => $data,
                'last_synced_at' => now(),
            ]);
    }

    protected function handleProductDeletedWebhook(array $data, StoreMarketplace $connection): void
    {
        PlatformListing::where('store_marketplace_id', $connection->id)
            ->where('external_listing_id', $data['id'])
            ->update(['status' => 'deleted']);
    }
}
