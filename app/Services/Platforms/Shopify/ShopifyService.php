<?php

namespace App\Services\Platforms\Shopify;

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

class ShopifyService extends BasePlatformService
{
    protected string $apiVersion = '2024-01';

    public function getPlatform(): string
    {
        return Platform::Shopify->value;
    }

    public function connect(Store $store, array $params = []): RedirectResponse
    {
        $shopDomain = $params['shop_domain'] ?? null;

        if (! $shopDomain) {
            throw new \InvalidArgumentException('Shop domain is required');
        }

        $shopDomain = $this->normalizeShopDomain($shopDomain);

        $redirectUri = route('platforms.shopify.callback');
        $scopes = implode(',', $this->getRequiredScopes());

        $authUrl = "https://{$shopDomain}/admin/oauth/authorize?"
            .http_build_query([
                'client_id' => config('services.shopify.client_id'),
                'scope' => $scopes,
                'redirect_uri' => $redirectUri,
                'state' => encrypt([
                    'store_id' => $store->id,
                    'shop_domain' => $shopDomain,
                ]),
            ]);

        return redirect()->away($authUrl);
    }

    public function handleCallback(Request $request, Store $store): StoreMarketplace
    {
        $code = $request->input('code');
        $shopDomain = $request->input('shop');

        $response = Http::post("https://{$shopDomain}/admin/oauth/access_token", [
            'client_id' => config('services.shopify.client_id'),
            'client_secret' => config('services.shopify.client_secret'),
            'code' => $code,
        ]);

        if ($response->failed()) {
            throw new \Exception('Failed to obtain access token: '.$response->body());
        }

        $data = $response->json();

        return StoreMarketplace::updateOrCreate(
            [
                'store_id' => $store->id,
                'platform' => Platform::Shopify,
                'shop_domain' => $shopDomain,
            ],
            [
                'name' => $shopDomain,
                'access_token' => $data['access_token'],
                'credentials' => [
                    'scope' => $data['scope'] ?? null,
                ],
                'status' => 'active',
            ]
        );
    }

    public function disconnect(StoreMarketplace $connection): void
    {
        // Shopify doesn't have a revoke endpoint, just delete the connection
        $connection->update(['status' => 'inactive']);
        $connection->delete();
    }

    public function refreshToken(StoreMarketplace $connection): StoreMarketplace
    {
        // Shopify access tokens don't expire
        return $connection;
    }

    public function validateCredentials(StoreMarketplace $connection): bool
    {
        try {
            $response = $this->shopifyRequest($connection, 'GET', 'shop.json');

            return isset($response['shop']);
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function pullProducts(StoreMarketplace $connection): Collection
    {
        $syncLog = $this->logSync($connection, 'products', 'pull');
        $products = collect();

        try {
            $page = null;

            do {
                $params = ['limit' => 250];
                if ($page) {
                    $params['page_info'] = $page;
                }

                $response = $this->shopifyRequest($connection, 'GET', 'products.json', $params);
                $shopifyProducts = $response['products'] ?? [];

                foreach ($shopifyProducts as $shopifyProduct) {
                    $products->push($this->mapShopifyProduct($shopifyProduct, $connection));
                    $syncLog->incrementProcessed();
                    $syncLog->incrementSuccess();
                }

                // Get next page from Link header
                $page = $this->getNextPage($response);
            } while ($page);

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
        $product->load('variants');

        // Find or create the listing
        $listing = PlatformListing::where('product_id', $product->id)
            ->where('store_marketplace_id', $connection->id)
            ->first();

        $shopifyProduct = $this->mapToShopifyProduct($product, $listing);

        $response = $this->shopifyRequest($connection, 'POST', 'products.json', [
            'product' => $shopifyProduct,
        ]);

        $shopifyData = $response['product'];
        $status = $shopifyData['status'] === 'active'
            ? PlatformListing::STATUS_LISTED
            : PlatformListing::STATUS_NOT_LISTED;

        if ($listing) {
            $listing->update([
                'external_listing_id' => $shopifyData['id'],
                'status' => $status,
                'listing_url' => "https://{$connection->shop_domain}/products/{$shopifyData['handle']}",
                'platform_data' => $shopifyData,
                'last_synced_at' => now(),
                'published_at' => $status === PlatformListing::STATUS_LISTED ? now() : null,
            ]);
        } else {
            $listing = PlatformListing::create([
                'store_marketplace_id' => $connection->id,
                'product_id' => $product->id,
                'external_listing_id' => $shopifyData['id'],
                'status' => $status,
                'listing_url' => "https://{$connection->shop_domain}/products/{$shopifyData['handle']}",
                'platform_data' => $shopifyData,
                'last_synced_at' => now(),
                'published_at' => $status === PlatformListing::STATUS_LISTED ? now() : null,
            ]);

            // Create listing variants
            foreach ($product->variants as $variant) {
                $listing->listingVariants()->create([
                    'product_variant_id' => $variant->id,
                    'price' => $variant->price,
                    'quantity' => $variant->quantity,
                ]);
            }
        }

        // Match Shopify variants to listing variants by SKU and update external IDs
        $this->syncVariantExternalIds($listing, $shopifyData['variants'] ?? []);

        return $listing;
    }

    public function updateListing(PlatformListing $listing): PlatformListing
    {
        $product = $listing->product;
        $product->load('variants');
        $connection = $listing->marketplace;
        $shopifyProduct = $this->mapToShopifyProduct($product, $listing);

        $response = $this->shopifyRequest(
            $connection,
            'PUT',
            "products/{$listing->external_listing_id}.json",
            ['product' => $shopifyProduct]
        );

        $listing->update([
            'platform_data' => $response['product'],
            'last_synced_at' => now(),
        ]);

        // Sync variant external IDs
        $this->syncVariantExternalIds($listing, $response['product']['variants'] ?? []);

        return $listing;
    }

    public function deleteListing(PlatformListing $listing): void
    {
        $this->shopifyRequest(
            $listing->marketplace,
            'DELETE',
            "products/{$listing->external_listing_id}.json"
        );

        $listing->delete();
    }

    public function unlistListing(PlatformListing $listing): PlatformListing
    {
        // Set product status to 'draft' on Shopify (hides from storefront but keeps the product)
        $this->shopifyRequest(
            $listing->marketplace,
            'PUT',
            "products/{$listing->external_listing_id}.json",
            [
                'product' => [
                    'id' => $listing->external_listing_id,
                    'status' => 'draft',
                ],
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
        // Set product status to 'active' on Shopify
        $this->shopifyRequest(
            $listing->marketplace,
            'PUT',
            "products/{$listing->external_listing_id}.json",
            [
                'product' => [
                    'id' => $listing->external_listing_id,
                    'status' => 'active',
                ],
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
        $listings = $connection->listings()->with('listingVariants.productVariant')->get();
        $locationId = $this->getDefaultLocationId($connection);

        foreach ($listings as $listing) {
            foreach ($listing->listingVariants as $listingVariant) {
                $inventoryItemId = $listingVariant->external_inventory_item_id;
                if (! $inventoryItemId) {
                    continue;
                }

                try {
                    $this->shopifyRequest($connection, 'POST', 'inventory_levels/set.json', [
                        'location_id' => $locationId,
                        'inventory_item_id' => $inventoryItemId,
                        'available' => $listingVariant->getEffectiveQuantity(),
                    ]);
                } catch (\Throwable $e) {
                    // Log but continue
                }
            }
        }
    }

    public function pullOrders(StoreMarketplace $connection, ?string $since = null): Collection
    {
        $syncLog = $this->logSync($connection, 'orders', 'pull');
        $orders = collect();

        try {
            $params = ['limit' => 250, 'status' => 'any'];
            if ($since) {
                $params['created_at_min'] = $since;
            }

            $response = $this->shopifyRequest($connection, 'GET', 'orders.json', $params);

            foreach ($response['orders'] ?? [] as $shopifyOrder) {
                $platformOrder = $this->importOrder($shopifyOrder, $connection);
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
        $this->shopifyRequest(
            $order->marketplace,
            'POST',
            "orders/{$order->external_order_id}/fulfillments.json",
            ['fulfillment' => $fulfillmentData]
        );

        $order->update(['fulfillment_status' => 'fulfilled']);
    }

    /**
     * Refresh a single order from Shopify by fetching the latest data.
     */
    public function refreshOrder(PlatformOrder $platformOrder): PlatformOrder
    {
        $connection = $platformOrder->marketplace;

        $response = $this->shopifyRequest(
            $connection,
            'GET',
            "orders/{$platformOrder->external_order_id}.json"
        );

        if (! isset($response['order'])) {
            throw new \Exception('Order not found in Shopify');
        }

        return $this->importOrder($response['order'], $connection);
    }

    /**
     * Fetch refunds for an order from Shopify.
     */
    public function getOrderRefunds(PlatformOrder $platformOrder): Collection
    {
        $connection = $platformOrder->marketplace;

        $response = $this->shopifyRequest(
            $connection,
            'GET',
            "orders/{$platformOrder->external_order_id}/refunds.json"
        );

        return collect($response['refunds'] ?? []);
    }

    /**
     * Create a refund on Shopify for specific line items.
     *
     * @param  array  $lineItems  Array of ['line_item_id' => ..., 'quantity' => ..., 'restock_type' => 'return'|'cancel'|'no_restock']
     * @param  bool  $notify  Whether to notify customer
     * @param  string|null  $note  Refund note
     */
    public function createRefund(
        PlatformOrder $platformOrder,
        array $lineItems,
        bool $notify = true,
        ?string $note = null
    ): array {
        $connection = $platformOrder->marketplace;

        // First calculate the refund to get proper amounts
        $calculateResponse = $this->shopifyRequest(
            $connection,
            'POST',
            "orders/{$platformOrder->external_order_id}/refunds/calculate.json",
            [
                'refund' => [
                    'refund_line_items' => collect($lineItems)->map(fn ($item) => [
                        'line_item_id' => $item['line_item_id'],
                        'quantity' => $item['quantity'],
                        'restock_type' => $item['restock_type'] ?? 'return',
                    ])->values()->all(),
                ],
            ]
        );

        $calculatedRefund = $calculateResponse['refund'] ?? [];
        $transactions = $calculatedRefund['transactions'] ?? [];

        // Create the actual refund
        $refundData = [
            'refund' => [
                'notify' => $notify,
                'refund_line_items' => collect($lineItems)->map(fn ($item) => [
                    'line_item_id' => $item['line_item_id'],
                    'quantity' => $item['quantity'],
                    'restock_type' => $item['restock_type'] ?? 'return',
                ])->values()->all(),
            ],
        ];

        if ($note) {
            $refundData['refund']['note'] = $note;
        }

        // Include transactions from calculation for proper refund amounts
        if (! empty($transactions)) {
            $refundData['refund']['transactions'] = collect($transactions)->map(fn ($t) => [
                'parent_id' => $t['parent_id'],
                'amount' => $t['amount'],
                'kind' => 'refund',
                'gateway' => $t['gateway'],
            ])->values()->all();
        }

        $response = $this->shopifyRequest(
            $connection,
            'POST',
            "orders/{$platformOrder->external_order_id}/refunds.json",
            $refundData
        );

        return $response['refund'] ?? [];
    }

    /**
     * Get Shopify line item IDs for an order.
     */
    public function getOrderLineItems(PlatformOrder $platformOrder): Collection
    {
        return collect($platformOrder->line_items ?? []);
    }

    public function getCategories(StoreMarketplace $connection): Collection
    {
        $response = $this->shopifyRequest($connection, 'GET', 'custom_collections.json');

        return collect($response['custom_collections'] ?? [])->map(fn ($c) => [
            'id' => $c['id'],
            'name' => $c['title'],
            'handle' => $c['handle'],
        ]);
    }

    public function registerWebhooks(StoreMarketplace $connection): void
    {
        $webhooks = [
            'orders/create',
            'orders/updated',
            'products/update',
            'inventory_levels/update',
        ];

        foreach ($webhooks as $topic) {
            $this->shopifyRequest($connection, 'POST', 'webhooks.json', [
                'webhook' => [
                    'topic' => $topic,
                    'address' => $this->getWebhookUrl($connection),
                    'format' => 'json',
                ],
            ]);
        }
    }

    public function handleWebhook(Request $request, StoreMarketplace $connection): void
    {
        $topic = $request->header('X-Shopify-Topic');
        $data = $request->all();

        match ($topic) {
            'orders/create', 'orders/updated' => $this->handleOrderWebhook($data, $connection),
            'products/update' => $this->handleProductWebhook($data, $connection),
            'inventory_levels/update' => $this->handleInventoryWebhook($data, $connection),
            default => null,
        };
    }

    // Helper methods

    protected function shopifyRequest(
        StoreMarketplace $connection,
        string $method,
        string $endpoint,
        array $data = []
    ): array {
        $url = "https://{$connection->shop_domain}/admin/api/{$this->apiVersion}/{$endpoint}";

        $response = Http::withHeaders([
            'X-Shopify-Access-Token' => $connection->access_token,
            'Content-Type' => 'application/json',
        ])->{strtolower($method)}($url, $data);

        if ($response->failed()) {
            throw new \Exception("Shopify API error: {$response->body()}");
        }

        return $response->json() ?? [];
    }

    protected function getRequiredScopes(): array
    {
        return [
            'read_products',
            'write_products',
            'read_orders',
            'write_orders',
            'read_inventory',
            'write_inventory',
            'read_locations',
        ];
    }

    protected function normalizeShopDomain(string $domain): string
    {
        $domain = preg_replace('#^https?://#', '', $domain);
        $domain = rtrim($domain, '/');

        if (! str_contains($domain, '.myshopify.com')) {
            $domain .= '.myshopify.com';
        }

        return $domain;
    }

    protected function mapShopifyProduct(array $shopifyProduct, StoreMarketplace $connection): array
    {
        return [
            'external_id' => $shopifyProduct['id'],
            'title' => $shopifyProduct['title'],
            'description' => $shopifyProduct['body_html'],
            'handle' => $shopifyProduct['handle'],
            'vendor' => $shopifyProduct['vendor'],
            'product_type' => $shopifyProduct['product_type'],
            'variants' => collect($shopifyProduct['variants'] ?? [])->map(fn ($v) => [
                'external_id' => $v['id'],
                'sku' => $v['sku'],
                'price' => $v['price'],
                'quantity' => $v['inventory_quantity'] ?? 0,
                'barcode' => $v['barcode'],
            ])->all(),
            'images' => collect($shopifyProduct['images'] ?? [])->pluck('src')->all(),
        ];
    }

    protected function mapToShopifyProduct(Product $product, ?PlatformListing $listing = null): array
    {
        $shopifyProduct = [
            'title' => $listing?->getEffectiveTitle() ?? $product->title,
            'body_html' => $listing?->getEffectiveDescription() ?? $product->description,
            'handle' => $product->handle,
            'vendor' => $product->brand?->name,
            'product_type' => $listing?->platform_category_id ?? $product->category?->name,
            'status' => $product->is_published ? 'active' : 'draft',
        ];

        // Build variants from listing variants if available
        if ($listing) {
            $listing->loadMissing('listingVariants.productVariant');

            if ($listing->listingVariants->isNotEmpty()) {
                $shopifyProduct['variants'] = $listing->listingVariants->map(function ($lv) {
                    $variant = [
                        'sku' => $lv->getEffectiveSku(),
                        'price' => $lv->getEffectivePrice(),
                        'inventory_quantity' => $lv->getEffectiveQuantity(),
                        'barcode' => $lv->getEffectiveBarcode(),
                        'inventory_management' => 'shopify',
                    ];

                    if ($lv->productVariant?->option1_value) {
                        $variant['option1'] = $lv->productVariant->option1_value;
                    }
                    if ($lv->productVariant?->option2_value) {
                        $variant['option2'] = $lv->productVariant->option2_value;
                    }
                    if ($lv->productVariant?->option3_value) {
                        $variant['option3'] = $lv->productVariant->option3_value;
                    }

                    // Include external variant ID for updates
                    if ($lv->external_variant_id) {
                        $variant['id'] = $lv->external_variant_id;
                    }

                    return $variant;
                })->all();

                return $shopifyProduct;
            }
        }

        // Fallback: build from product variants directly
        if ($product->has_variants) {
            $shopifyProduct['variants'] = $product->variants->map(fn ($v) => [
                'sku' => $v->sku,
                'price' => $v->price,
                'inventory_quantity' => $v->quantity,
                'barcode' => $v->barcode,
                'option1' => $v->option1_value,
                'option2' => $v->option2_value,
                'option3' => $v->option3_value,
                'inventory_management' => 'shopify',
            ])->all();
        } else {
            $shopifyProduct['variants'] = [[
                'price' => $product->variants->first()?->price ?? 0,
                'inventory_quantity' => $product->quantity,
                'inventory_management' => 'shopify',
            ]];
        }

        return $shopifyProduct;
    }

    /**
     * Match Shopify response variants to listing variants by SKU and update external IDs.
     *
     * @param  array<array<string, mixed>>  $shopifyVariants
     */
    protected function syncVariantExternalIds(PlatformListing $listing, array $shopifyVariants): void
    {
        $listing->loadMissing('listingVariants');

        foreach ($shopifyVariants as $shopifyVariant) {
            $sku = $shopifyVariant['sku'] ?? null;
            $externalVariantId = (string) ($shopifyVariant['id'] ?? '');
            $inventoryItemId = (string) ($shopifyVariant['inventory_item_id'] ?? '');

            // Match by SKU
            $listingVariant = $listing->listingVariants
                ->first(fn ($lv) => $lv->getEffectiveSku() === $sku);

            // If no SKU match and only one variant, match by position
            if (! $listingVariant && $listing->listingVariants->count() === 1 && count($shopifyVariants) === 1) {
                $listingVariant = $listing->listingVariants->first();
            }

            if ($listingVariant) {
                $listingVariant->update([
                    'external_variant_id' => $externalVariantId,
                    'external_inventory_item_id' => $inventoryItemId,
                    'platform_data' => $shopifyVariant,
                ]);
            }
        }
    }

    protected function importOrder(array $shopifyOrder, StoreMarketplace $connection): PlatformOrder
    {
        return PlatformOrder::updateOrCreate(
            [
                'store_marketplace_id' => $connection->id,
                'external_order_id' => $shopifyOrder['id'],
            ],
            [
                'external_order_number' => $shopifyOrder['order_number'],
                'status' => $shopifyOrder['financial_status'],
                'fulfillment_status' => $shopifyOrder['fulfillment_status'],
                'payment_status' => $shopifyOrder['financial_status'],
                'total' => $shopifyOrder['total_price'],
                'subtotal' => $shopifyOrder['subtotal_price'],
                'shipping_cost' => collect($shopifyOrder['shipping_lines'] ?? [])->sum('price'),
                'tax' => $shopifyOrder['total_tax'],
                'discount' => collect($shopifyOrder['discount_codes'] ?? [])->sum('amount'),
                'currency' => $shopifyOrder['currency'],
                'customer_data' => $shopifyOrder['customer'] ?? null,
                'shipping_address' => $shopifyOrder['shipping_address'] ?? null,
                'billing_address' => $shopifyOrder['billing_address'] ?? null,
                'line_items' => $shopifyOrder['line_items'],
                'platform_data' => $shopifyOrder,
                'ordered_at' => $shopifyOrder['created_at'],
                'last_synced_at' => now(),
            ]
        );
    }

    protected function getDefaultLocationId(StoreMarketplace $connection): ?string
    {
        $response = $this->shopifyRequest($connection, 'GET', 'locations.json');

        return $response['locations'][0]['id'] ?? null;
    }

    protected function getNextPage(array $response): ?string
    {
        // Implement cursor-based pagination parsing
        return null;
    }

    protected function handleOrderWebhook(array $data, StoreMarketplace $connection): void
    {
        $this->importOrder($data, $connection);
    }

    protected function handleProductWebhook(array $data, StoreMarketplace $connection): void
    {
        // Update local listing if exists
        PlatformListing::where('store_marketplace_id', $connection->id)
            ->where('external_listing_id', $data['id'])
            ->update(['platform_data' => $data, 'last_synced_at' => now()]);
    }

    protected function handleInventoryWebhook(array $data, StoreMarketplace $connection): void
    {
        // Inventory sync logic
    }
}
