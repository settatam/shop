<?php

namespace App\Services\Platforms\Ebay;

use App\Enums\Platform;
use App\Models\Activity;
use App\Models\ActivityLog;
use App\Models\MarketplacePolicy;
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
use Illuminate\Support\Facades\Log;

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

        $product->loadMissing('variants');
        $variants = $product->variants;

        if ($variants->count() > 1) {
            return $this->pushMultiVariantProduct($product, $connection);
        }

        // Load existing listing for per-listing setting overrides
        $listing = PlatformListing::where('product_id', $product->id)
            ->where('store_marketplace_id', $connection->id)
            ->first();

        $sku = $variants->first()?->sku ?? $product->handle;

        // Build the full listing data to get aspects/item specifics
        $listingBuilder = app(\App\Services\Platforms\ListingBuilderService::class);
        $builtListing = $listingBuilder->buildListing($product, $connection);
        $aspects = $builtListing['aspects'] ?? null;

        $inventoryItem = $this->mapToEbayInventoryItem($product, $aspects, $listing);

        // Create or update inventory item
        $this->ebayRequest(
            $connection,
            'PUT',
            "/sell/inventory/v1/inventory_item/{$sku}",
            $inventoryItem
        );

        // Create or update offer
        $offer = $this->mapToEbayOffer($product, $sku, $connection, $listing);
        $existingOfferId = $listing?->platform_data['offer_id'] ?? null;

        if ($existingOfferId) {
            // Try to update existing offer; if stale, fall through to create new
            try {
                $this->ebayRequest($connection, 'PUT', "/sell/inventory/v1/offer/{$existingOfferId}", $offer);
                $offerId = $existingOfferId;
            } catch (\Exception $e) {
                Log::warning("EbayService: Stale offer {$existingOfferId}, creating new offer: {$e->getMessage()}");
                $existingOfferId = null;
            }
        }

        if (! $existingOfferId) {
            // Create new offer, or recover if one already exists on eBay
            try {
                $offerResponse = $this->ebayRequest($connection, 'POST', '/sell/inventory/v1/offer', $offer);
                $offerId = $offerResponse['offerId'];
            } catch (\Exception $e) {
                $offerId = $this->extractExistingOfferId($e->getMessage());
                if ($offerId) {
                    // Offer already exists — update it instead
                    $this->ebayRequest($connection, 'PUT', "/sell/inventory/v1/offer/{$offerId}", $offer);
                } else {
                    throw $e;
                }
            }
        }

        // Save offer_id immediately so it survives if publish fails
        if (! $listing) {
            $listing = PlatformListing::create([
                'store_marketplace_id' => $connection->id,
                'product_id' => $product->id,
                'status' => PlatformListing::STATUS_PENDING,
                'platform_data' => ['sku' => $sku, 'offer_id' => $offerId],
            ]);
        } else {
            $listing->update([
                'platform_data' => array_merge($listing->platform_data ?? [], [
                    'sku' => $sku,
                    'offer_id' => $offerId,
                ]),
            ]);
        }

        // Publish offer
        $publishResponse = $this->ebayRequest(
            $connection,
            'POST',
            "/sell/inventory/v1/offer/{$offerId}/publish"
        );

        $listing->update([
            'external_listing_id' => $publishResponse['listingId'] ?? $offerId,
            'status' => PlatformListing::STATUS_LISTED,
            'listing_url' => "https://www.ebay.com/itm/{$publishResponse['listingId']}",
            'platform_data' => array_merge($listing->platform_data ?? [], [
                'listing_id' => $publishResponse['listingId'] ?? null,
            ]),
            'last_synced_at' => now(),
            'published_at' => now(),
        ]);

        return $listing;
    }

    /**
     * Push a multi-variant product to eBay using Inventory Item Groups.
     */
    protected function pushMultiVariantProduct(Product $product, StoreMarketplace $connection): PlatformListing
    {
        $listing = PlatformListing::where('product_id', $product->id)
            ->where('store_marketplace_id', $connection->id)
            ->first();

        $listingBuilder = app(\App\Services\Platforms\ListingBuilderService::class);
        $builtListing = $listingBuilder->buildListing($product, $connection);
        $aspects = $builtListing['aspects'] ?? null;

        $groupKey = $product->handle ?? "product-{$product->id}";
        $variants = $product->variants;
        $variantSkus = [];
        $offerIds = [];

        // Step 1: Create inventory items for each variant
        foreach ($variants as $variant) {
            $sku = $variant->sku ?? "{$groupKey}-{$variant->id}";
            $variantSkus[] = $sku;

            $inventoryItem = $this->mapVariantToInventoryItem($variant, $product, $aspects, $listing);

            $this->ebayRequest(
                $connection,
                'PUT',
                "/sell/inventory/v1/inventory_item/{$sku}",
                $inventoryItem
            );
        }

        // Step 2: Create/update inventory item group
        $variationAspects = $this->buildVariationAspects($variants);

        $groupPayload = [
            'title' => $listing?->getEffectiveTitle() ?? $product->title,
            'description' => $listing?->getEffectiveDescription() ?? $product->description ?? '',
            'imageUrls' => $listing?->getEffectiveImages() ?: $product->images->pluck('url')->all(),
            'aspects' => $aspects ?? [],
            'variantSKUs' => $variantSkus,
            'variesBy' => [
                'aspectsImageVariesBy' => array_keys($variationAspects),
                'specifications' => collect($variationAspects)->map(fn (array $values, string $name) => [
                    'name' => $name,
                    'values' => $values,
                ])->values()->all(),
            ],
        ];

        $this->ebayRequest(
            $connection,
            'PUT',
            "/sell/inventory/v1/inventory_item_group/{$groupKey}",
            $groupPayload
        );

        // Step 3: Create/update offers for each variant
        $existingOfferIds = $listing?->platform_data['offer_ids'] ?? [];

        foreach ($variants as $index => $variant) {
            $sku = $variantSkus[$index];
            $offer = $this->mapVariantToOffer($variant, $sku, $product, $connection, $listing);
            $existingOfferId = $existingOfferIds[$sku] ?? null;

            $offerId = $this->createOrUpdateOffer($connection, $offer, $existingOfferId);
            $offerIds[$sku] = $offerId;
        }

        // Step 4: Save state before publishing
        $platformData = array_merge($listing?->platform_data ?? [], [
            'group_key' => $groupKey,
            'variant_skus' => $variantSkus,
            'offer_ids' => $offerIds,
            'multi_variant' => true,
        ]);

        if (! $listing) {
            $listing = PlatformListing::create([
                'store_marketplace_id' => $connection->id,
                'product_id' => $product->id,
                'status' => PlatformListing::STATUS_PENDING,
                'platform_data' => $platformData,
            ]);
        } else {
            $listing->update(['platform_data' => $platformData]);
        }

        // Step 5: Publish via inventory item group
        $publishResponse = $this->ebayRequest(
            $connection,
            'POST',
            '/sell/inventory/v1/offer/publish_by_inventory_item_group',
            ['inventoryItemGroupKey' => $groupKey]
        );

        $listingId = $publishResponse['listingId'] ?? $groupKey;

        $listing->update([
            'external_listing_id' => $listingId,
            'status' => PlatformListing::STATUS_LISTED,
            'listing_url' => "https://www.ebay.com/itm/{$listingId}",
            'platform_data' => array_merge($listing->platform_data ?? [], [
                'listing_id' => $listingId,
            ]),
            'last_synced_at' => now(),
            'published_at' => now(),
        ]);

        // Step 6: Sync variant external IDs to PlatformListingVariant records
        foreach ($variants as $index => $variant) {
            $sku = $variantSkus[$index];

            \App\Models\PlatformListingVariant::updateOrCreate(
                [
                    'platform_listing_id' => $listing->id,
                    'product_variant_id' => $variant->id,
                ],
                [
                    'sku' => $sku,
                    'price' => $variant->price,
                    'quantity' => $variant->quantity,
                    'external_variant_id' => $offerIds[$sku] ?? null,
                    'external_inventory_item_id' => $sku,
                    'platform_data' => [
                        'offer_id' => $offerIds[$sku] ?? null,
                    ],
                ]
            );
        }

        return $listing;
    }

    /**
     * Map a product variant to an eBay inventory item payload.
     *
     * @param  array<string, array<string, string[]>>|null  $aspects  Pre-built aspects from ListingBuilderService
     */
    protected function mapVariantToInventoryItem(
        \App\Models\ProductVariant $variant,
        Product $product,
        ?array $aspects = null,
        ?PlatformListing $listing = null
    ): array {
        $item = [
            'product' => [
                'title' => $listing?->getEffectiveTitle() ?? $product->title,
                'description' => $listing?->getEffectiveDescription() ?? $product->description ?? '',
                'imageUrls' => $listing?->getEffectiveImages() ?: $product->images->pluck('url')->all(),
            ],
            'condition' => $this->resolveConditionEnum($listing?->getEffectiveSetting('default_condition') ?? $product->condition ?? 'NEW'),
            'availability' => [
                'shipToLocationAvailability' => [
                    'quantity' => $variant->quantity ?? 0,
                ],
            ],
        ];

        // Include product-level aspects plus variant-specific aspect values
        $variantAspects = $aspects ?? [];
        foreach ($variant->options as $name => $value) {
            $variantAspects[$name] = [$value];
        }

        if (! empty($variantAspects)) {
            $item['product']['aspects'] = $variantAspects;
        }

        // Include package weight
        $weight = $variant->weight ?? $product->weight;
        $weightUnit = strtoupper($variant->weight_unit ?? $product->weight_unit ?? 'lb');
        $ebayWeightUnit = match ($weightUnit) {
            'LB', 'POUND' => 'POUND',
            'OZ', 'OUNCE' => 'OUNCE',
            'KG', 'KILOGRAM' => 'KILOGRAM',
            'G', 'GRAM' => 'GRAM',
            default => 'POUND',
        };

        $item['packageWeightAndSize'] = [
            'weight' => [
                'value' => (float) ($weight ?: 1),
                'unit' => $ebayWeightUnit,
            ],
        ];

        return $item;
    }

    /**
     * Map a product variant to an eBay offer payload.
     */
    protected function mapVariantToOffer(
        \App\Models\ProductVariant $variant,
        string $sku,
        Product $product,
        StoreMarketplace $connection,
        ?PlatformListing $listing = null
    ): array {
        $credentials = $connection->credentials ?? [];

        $setting = function (string $key, mixed $default = null) use ($listing, $connection) {
            if ($listing) {
                return $listing->getEffectiveSetting($key, $default);
            }

            return $connection->settings[$key] ?? $default;
        };

        $format = $setting('listing_type', 'FIXED_PRICE');
        $price = (float) ($variant->price ?? 0);

        $auctionMarkup = $setting('auction_markup');
        $fixedMarkup = $setting('fixed_price_markup');
        if ($format === 'AUCTION' && ! empty($auctionMarkup)) {
            $price = $price * (1 + $auctionMarkup / 100);
        } elseif ($format === 'FIXED_PRICE' && ! empty($fixedMarkup)) {
            $price = $price * (1 + $fixedMarkup / 100);
        }

        $marketplaceId = $setting('marketplace_id', 'EBAY_US');

        return [
            'sku' => $sku,
            'marketplaceId' => $marketplaceId,
            'format' => $format,
            'listingDescription' => $listing?->getEffectiveDescription() ?? $product->description ?? '',
            'availableQuantity' => $variant->quantity ?? 0,
            'pricingSummary' => [
                'price' => [
                    'value' => (string) round($price, 2),
                    'currency' => $this->getCurrencyForMarketplace($marketplaceId),
                ],
            ],
            'listingPolicies' => [
                'fulfillmentPolicyId' => $setting('fulfillment_policy_id', $credentials['fulfillment_policy_id'] ?? null),
                'paymentPolicyId' => $setting('payment_policy_id', $credentials['payment_policy_id'] ?? null),
                'returnPolicyId' => $setting('return_policy_id', $credentials['return_policy_id'] ?? null),
            ],
            'categoryId' => $setting('primary_category_id', $listing?->platform_category_id ?? $product->category?->platform_category_id ?? '1'),
            'merchantLocationKey' => $setting('location_key', $credentials['location_key'] ?? 'default'),
        ];
    }

    /**
     * Build variation aspects from product variants.
     *
     * @return array<string, string[]> e.g. {"Color": ["Red", "Blue"], "Size": ["S", "M"]}
     */
    protected function buildVariationAspects(Collection $variants): array
    {
        $aspects = [];

        foreach ($variants as $variant) {
            foreach ($variant->options as $name => $value) {
                if (! isset($aspects[$name])) {
                    $aspects[$name] = [];
                }
                if (! in_array($value, $aspects[$name])) {
                    $aspects[$name][] = $value;
                }
            }
        }

        return $aspects;
    }

    /**
     * Create or update an eBay offer, handling duplicate offer recovery.
     */
    protected function createOrUpdateOffer(StoreMarketplace $connection, array $offer, ?string $existingOfferId = null): string
    {
        if ($existingOfferId) {
            try {
                $this->ebayRequest($connection, 'PUT', "/sell/inventory/v1/offer/{$existingOfferId}", $offer);

                return $existingOfferId;
            } catch (\Exception $e) {
                Log::warning("EbayService: Stale offer {$existingOfferId}, creating new: {$e->getMessage()}");
            }
        }

        try {
            $offerResponse = $this->ebayRequest($connection, 'POST', '/sell/inventory/v1/offer', $offer);

            return $offerResponse['offerId'];
        } catch (\Exception $e) {
            $recoveredId = $this->extractExistingOfferId($e->getMessage());
            if ($recoveredId) {
                $this->ebayRequest($connection, 'PUT', "/sell/inventory/v1/offer/{$recoveredId}", $offer);

                return $recoveredId;
            }

            throw $e;
        }
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
        $connection = $listing->marketplace;
        $this->ensureValidToken($connection);

        $isMultiVariant = $listing->platform_data['multi_variant'] ?? false;

        if ($isMultiVariant) {
            // Delete all variant offers
            foreach ($listing->platform_data['offer_ids'] ?? [] as $sku => $offerId) {
                try {
                    $this->ebayRequest($connection, 'DELETE', "/sell/inventory/v1/offer/{$offerId}");
                } catch (\Throwable $e) {
                    Log::warning("EbayService: Failed to delete offer {$offerId}: {$e->getMessage()}");
                }
            }

            // Delete inventory item group
            $groupKey = $listing->platform_data['group_key'] ?? null;
            if ($groupKey) {
                try {
                    $this->ebayRequest($connection, 'DELETE', "/sell/inventory/v1/inventory_item_group/{$groupKey}");
                } catch (\Throwable $e) {
                    Log::warning("EbayService: Failed to delete group {$groupKey}: {$e->getMessage()}");
                }
            }

            // Delete individual inventory items
            foreach ($listing->platform_data['variant_skus'] ?? [] as $sku) {
                try {
                    $this->ebayRequest($connection, 'DELETE', "/sell/inventory/v1/inventory_item/{$sku}");
                } catch (\Throwable $e) {
                    Log::warning("EbayService: Failed to delete inventory item {$sku}: {$e->getMessage()}");
                }
            }

            // Clean up listing variant records
            $listing->listingVariants()->delete();
        } else {
            $offerId = $listing->platform_data['offer_id'] ?? null;
            if ($offerId) {
                $this->ebayRequest($connection, 'DELETE', "/sell/inventory/v1/offer/{$offerId}");
            }

            $sku = $listing->platform_data['sku'] ?? null;
            if ($sku) {
                $this->ebayRequest($connection, 'DELETE', "/sell/inventory/v1/inventory_item/{$sku}");
            }
        }

        // Use query builder to bypass PlatformListing::delete() override which archives
        PlatformListing::withTrashed()->where('id', $listing->id)->forceDelete();
    }

    public function unlistListing(PlatformListing $listing): PlatformListing
    {
        $connection = $listing->marketplace;
        $this->ensureValidToken($connection);

        $isMultiVariant = $listing->platform_data['multi_variant'] ?? false;

        if ($isMultiVariant) {
            // Withdraw all variant offers
            foreach ($listing->platform_data['offer_ids'] ?? [] as $sku => $offerId) {
                try {
                    $this->ebayRequest($connection, 'POST', "/sell/inventory/v1/offer/{$offerId}/withdraw");
                } catch (\Throwable $e) {
                    Log::warning("EbayService: Failed to withdraw offer {$offerId}: {$e->getMessage()}");
                }
            }
        } else {
            $offerId = $listing->platform_data['offer_id'] ?? null;
            if ($offerId) {
                $this->ebayRequest($connection, 'POST', "/sell/inventory/v1/offer/{$offerId}/withdraw");
            }
        }

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
                'status' => PlatformListing::STATUS_LISTED,
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
            'ITEM_SOLD',
            'ITEM_CLOSED',
            'ITEM_SUSPENDED',
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
            'ITEM_SOLD' => $this->handleItemSold($data, $connection),
            'ITEM_CLOSED' => $this->handleItemClosed($data, $connection),
            'ITEM_SUSPENDED' => $this->handleItemSuspended($data, $connection),
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
     * Fetch business policies from eBay and sync them to local database.
     *
     * @return array{return_policies: array<mixed>, payment_policies: array<mixed>, fulfillment_policies: array<mixed>}
     */
    public function syncBusinessPolicies(StoreMarketplace $connection): array
    {
        $policies = $this->getBusinessPolicies($connection);

        MarketplacePolicy::syncFromEbay($connection, $policies);

        return $policies;
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

        $marketplaceId = $connection->settings['marketplace_id'] ?? 'EBAY_US';

        $request = Http::withHeaders([
            'Authorization' => 'Bearer '.$connection->access_token,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'Content-Language' => $this->getLocaleForMarketplace($marketplaceId),
            'X-EBAY-C-MARKETPLACE-ID' => $marketplaceId,
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
    protected function mapToEbayInventoryItem(Product $product, ?array $aspects = null, ?PlatformListing $listing = null): array
    {
        $variant = $product->variants->first();

        $item = [
            'product' => [
                'title' => $listing?->getEffectiveTitle() ?? $product->title,
                'description' => $listing?->getEffectiveDescription() ?? $product->description ?? '',
                'imageUrls' => $listing?->getEffectiveImages() ?: $product->images->pluck('url')->all(),
            ],
            'condition' => $this->resolveConditionEnum($listing?->getEffectiveSetting('default_condition') ?? $product->condition ?? 'NEW'),
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

        // Include package weight — eBay requires this for shipping
        $weight = $listing?->platform_settings['weight'] ?? $variant?->weight ?? $product->weight;
        $weightUnit = strtoupper($listing?->platform_settings['weight_unit'] ?? $product->weight_unit ?? 'lb');
        $ebayWeightUnit = match ($weightUnit) {
            'LB', 'POUND' => 'POUND',
            'OZ', 'OUNCE' => 'OUNCE',
            'KG', 'KILOGRAM' => 'KILOGRAM',
            'G', 'GRAM' => 'GRAM',
            default => 'POUND',
        };

        $item['packageWeightAndSize'] = [
            'weight' => [
                'value' => (float) ($weight ?: 1),
                'unit' => $ebayWeightUnit,
            ],
        ];

        return $item;
    }

    protected function mapToEbayOffer(Product $product, string $sku, StoreMarketplace $connection, ?PlatformListing $listing = null): array
    {
        $variant = $product->variants->first();
        $credentials = $connection->credentials ?? [];

        // Resolve settings through listing → marketplace → default
        $setting = function (string $key, mixed $default = null) use ($listing, $connection) {
            if ($listing) {
                return $listing->getEffectiveSetting($key, $default);
            }

            return $connection->settings[$key] ?? $default;
        };

        $format = $setting('listing_type', 'FIXED_PRICE');
        $price = (float) ($variant?->price ?? 0);

        // Apply markup based on listing type
        $auctionMarkup = $setting('auction_markup');
        $fixedMarkup = $setting('fixed_price_markup');
        if ($format === 'AUCTION' && ! empty($auctionMarkup)) {
            $price = $price * (1 + $auctionMarkup / 100);
        } elseif ($format === 'FIXED_PRICE' && ! empty($fixedMarkup)) {
            $price = $price * (1 + $fixedMarkup / 100);
        }

        $marketplaceId = $setting('marketplace_id', 'EBAY_US');

        $offer = [
            'sku' => $sku,
            'marketplaceId' => $marketplaceId,
            'format' => $format,
            'listingDescription' => $listing?->getEffectiveDescription() ?? $product->description ?? '',
            'availableQuantity' => $variant?->quantity ?? $product->quantity ?? 0,
            'pricingSummary' => [
                'price' => [
                    'value' => (string) round($price, 2),
                    'currency' => $this->getCurrencyForMarketplace($marketplaceId),
                ],
            ],
            'listingPolicies' => [
                'fulfillmentPolicyId' => $setting('fulfillment_policy_id', $credentials['fulfillment_policy_id'] ?? null),
                'paymentPolicyId' => $setting('payment_policy_id', $credentials['payment_policy_id'] ?? null),
                'returnPolicyId' => $setting('return_policy_id', $credentials['return_policy_id'] ?? null),
            ],
            'categoryId' => $setting('primary_category_id', $listing?->platform_category_id ?? $product->category?->platform_category_id ?? '1'),
            'merchantLocationKey' => $setting('location_key', $credentials['location_key'] ?? 'default'),
        ];

        // Add listing duration
        $durationKey = $format === 'AUCTION' ? 'listing_duration_auction' : 'listing_duration_fixed';
        $duration = $setting($durationKey);
        if (! empty($duration)) {
            $offer['listingDuration'] = $duration;
        }

        // Add best offer
        if (! empty($setting('best_offer_enabled'))) {
            $offer['bestOfferTerms'] = [
                'bestOfferEnabled' => true,
            ];
        }

        return $offer;
    }

    /**
     * Get the currency code for a given eBay marketplace.
     */
    public function getCurrencyForMarketplace(string $marketplaceId): string
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

    /**
     * Get the Content-Language locale for a given eBay marketplace.
     */
    protected function getLocaleForMarketplace(string $marketplaceId): string
    {
        return match ($marketplaceId) {
            'EBAY_GB' => 'en-GB',
            'EBAY_DE' => 'de-DE',
            'EBAY_FR', 'EBAY_BE_FR' => 'fr-FR',
            'EBAY_IT' => 'it-IT',
            'EBAY_ES' => 'es-ES',
            'EBAY_AT' => 'de-AT',
            'EBAY_BE_NL', 'EBAY_NL' => 'nl-NL',
            'EBAY_AU' => 'en-AU',
            'EBAY_CA' => 'en-CA',
            'EBAY_CH' => 'de-CH',
            'EBAY_IE' => 'en-IE',
            'EBAY_PL' => 'pl-PL',
            'EBAY_SG' => 'en-SG',
            'EBAY_HK' => 'zh-HK',
            'EBAY_PH' => 'en-PH',
            'EBAY_IN' => 'en-IN',
            'EBAY_MY' => 'en-MY',
            default => 'en-US',
        };
    }

    /**
     * Extract an existing offerId from an eBay "Offer entity already exists" error.
     */
    protected function extractExistingOfferId(string $errorMessage): ?string
    {
        $json = str_replace('eBay API error: ', '', $errorMessage);
        $data = json_decode($json, true);

        if (! $data || empty($data['errors'])) {
            return null;
        }

        foreach ($data['errors'] as $error) {
            if (($error['errorId'] ?? null) === 25002) {
                foreach ($error['parameters'] ?? [] as $param) {
                    if ($param['name'] === 'offerId') {
                        return $param['value'];
                    }
                }
            }
        }

        return null;
    }

    /**
     * Resolve a condition value to the eBay ConditionEnum string.
     * Accepts either a numeric condition ID (e.g. "3000") or an existing enum value (e.g. "NEW").
     */
    protected function resolveConditionEnum(string $condition): string
    {
        return match ($condition) {
            '1000' => 'NEW',
            '1500' => 'NEW_OTHER',
            '1750' => 'NEW_WITH_DEFECTS',
            '2000' => 'CERTIFIED_REFURBISHED',
            '2010' => 'EXCELLENT_REFURBISHED',
            '2020' => 'VERY_GOOD_REFURBISHED',
            '2030' => 'GOOD_REFURBISHED',
            '2500' => 'SELLER_REFURBISHED',
            '2750' => 'LIKE_NEW',
            '3000' => 'USED_EXCELLENT',
            '4000' => 'USED_GOOD',
            '5000' => 'USED_ACCEPTABLE',
            '7000' => 'FOR_PARTS_OR_NOT_WORKING',
            default => $condition,
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

    /**
     * Fetch the status of a single eBay offer.
     *
     * @return array{status: string, listingId: ?string, availableQuantity: ?int}|null
     */
    public function getOfferStatus(StoreMarketplace $connection, string $offerId): ?array
    {
        try {
            $data = $this->ebayRequest($connection, 'GET', "/sell/inventory/v1/offer/{$offerId}");

            return [
                'status' => $data['status'] ?? 'UNKNOWN',
                'listingId' => $data['listing']['listingId'] ?? null,
                'availableQuantity' => $data['availableQuantity'] ?? null,
            ];
        } catch (\Throwable $e) {
            if (str_contains($e->getMessage(), '404') || str_contains($e->getMessage(), 'Not Found')) {
                return null;
            }

            throw $e;
        }
    }

    /**
     * Update a listing's status based on what eBay reports and log the change.
     */
    public function updateListingStatusFromEbay(PlatformListing $listing, string $newStatus): void
    {
        $oldStatus = $listing->status;

        if ($oldStatus === $newStatus) {
            return;
        }

        $listing->update(['status' => $newStatus]);

        $storeId = $listing->marketplace?->store_id;

        if ($storeId) {
            ActivityLog::create([
                'store_id' => $storeId,
                'activity_slug' => Activity::LISTINGS_STATUS_CHANGE,
                'subject_type' => $listing->getMorphClass(),
                'subject_id' => $listing->getKey(),
                'properties' => [
                    'old_status' => $oldStatus,
                    'new_status' => $newStatus,
                    'source' => 'ebay_sync',
                ],
                'description' => "eBay listing status changed from {$oldStatus} to {$newStatus}",
            ]);
        }
    }

    protected function handleAccountDeletion(array $data, StoreMarketplace $connection): void
    {
        $connection->update(['status' => 'inactive']);
    }

    protected function handleItemSold(array $data, StoreMarketplace $connection): void
    {
        Log::info('[eBay] Item sold webhook received', [
            'connection_id' => $connection->id,
            'data' => $data,
        ]);

        // Order sync will pick up the actual order — just log here
    }

    protected function handleItemClosed(array $data, StoreMarketplace $connection): void
    {
        $listingId = $data['resource']['listingId'] ?? $data['listingId'] ?? null;

        if (! $listingId) {
            return;
        }

        $listing = PlatformListing::where('store_marketplace_id', $connection->id)
            ->where('external_listing_id', $listingId)
            ->first();

        if ($listing) {
            $this->updateListingStatusFromEbay($listing, PlatformListing::STATUS_ENDED);
        }
    }

    protected function handleItemSuspended(array $data, StoreMarketplace $connection): void
    {
        $listingId = $data['resource']['listingId'] ?? $data['listingId'] ?? null;

        if (! $listingId) {
            return;
        }

        $listing = PlatformListing::where('store_marketplace_id', $connection->id)
            ->where('external_listing_id', $listingId)
            ->first();

        if ($listing) {
            $this->updateListingStatusFromEbay($listing, PlatformListing::STATUS_ERROR);
        }
    }
}
