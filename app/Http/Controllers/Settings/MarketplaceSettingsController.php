<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateMarketplaceSettingsRequest;
use App\Jobs\CreateSalesChannelListingsJob;
use App\Models\MarketplacePolicy;
use App\Models\SalesChannel;
use App\Models\StoreMarketplace;
use App\Models\Warehouse;
use App\Services\Platforms\Ebay\EbayService;
use App\Services\Platforms\Etsy\EtsyService;
use App\Services\Platforms\PlatformManager;
use App\Services\Platforms\Shopify\ShopifyService;
use App\Services\StoreContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class MarketplaceSettingsController extends Controller
{
    public function __construct(
        protected StoreContext $storeContext
    ) {}

    /**
     * Show the marketplace settings page.
     */
    public function show(StoreMarketplace $marketplace): Response
    {
        $store = $this->storeContext->getCurrentStore();

        abort_unless($marketplace->store_id === $store->id, 403);

        $warehouses = Warehouse::where('store_id', $store->id)
            ->active()
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'is_default']);

        $salesChannel = SalesChannel::where('store_marketplace_id', $marketplace->id)->first();
        $listingCount = $salesChannel?->platformListings()->count() ?? 0;

        return Inertia::render('settings/MarketplaceSettings', [
            'marketplace' => [
                'id' => $marketplace->id,
                'platform' => $marketplace->platform->value,
                'platform_label' => $marketplace->platform->label(),
                'name' => $marketplace->name,
                'status' => $marketplace->status,
                'settings' => $marketplace->settings ?? [],
            ],
            'warehouses' => $warehouses,
            'listingCount' => $listingCount,
            'hasSalesChannel' => $salesChannel !== null,
            'metafieldDefinitionsCount' => $marketplace->metafieldDefinitions()->count(),
        ]);
    }

    /**
     * Update marketplace settings.
     */
    public function update(UpdateMarketplaceSettingsRequest $request, StoreMarketplace $marketplace): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        abort_unless($marketplace->store_id === $store->id, 403);

        $settings = $marketplace->settings ?? [];

        $settingsFields = [
            // Common
            'price_markup',
            'use_ai_details',

            // eBay
            'marketplace_id',
            'default_condition',
            'listing_type',
            'listing_duration_fixed',
            'listing_duration_auction',
            'return_policy_id',
            'payment_policy_id',
            'fulfillment_policy_id',
            'auction_markup',
            'fixed_price_markup',
            'best_offer_enabled',
            'location_key',
            'location_mappings',

            // Amazon
            'fulfillment_channel',
            'language_tag',

            // Etsy
            'currency',
            'who_made',
            'when_made',
            'is_supply',
            'shipping_profile_id',
            'auto_renew',

            // Walmart
            'product_id_type',
            'fulfillment_type',
            'shipping_method',
            'weight_unit',

            // Shopify
            'default_product_status',
            'inventory_tracking',
        ];

        foreach ($settingsFields as $field) {
            if ($request->has($field)) {
                $settings[$field] = $request->input($field);
            }
        }

        $marketplace->update(['settings' => $settings]);

        // Mark selected policies as defaults
        $policyFields = [
            'return_policy_id' => MarketplacePolicy::TYPE_RETURN,
            'payment_policy_id' => MarketplacePolicy::TYPE_PAYMENT,
            'fulfillment_policy_id' => MarketplacePolicy::TYPE_FULFILLMENT,
        ];

        foreach ($policyFields as $field => $type) {
            if ($request->has($field) && $request->input($field)) {
                $policy = MarketplacePolicy::withoutGlobalScopes()
                    ->where('store_marketplace_id', $marketplace->id)
                    ->where('type', $type)
                    ->where('external_id', $request->input($field))
                    ->first();

                if ($policy) {
                    $policy->setAsDefault();
                }
            }
        }

        return back()->with('success', 'Marketplace settings updated.');
    }

    /**
     * Fetch business policies from eBay.
     */
    public function fetchPolicies(StoreMarketplace $marketplace, PlatformManager $platformManager): JsonResponse
    {
        $store = $this->storeContext->getCurrentStore();

        abort_unless($marketplace->store_id === $store->id, 403);

        try {
            /** @var EbayService $ebayService */
            $ebayService = $platformManager->driver($marketplace->platform);
            $policies = $ebayService->syncBusinessPolicies($marketplace);

            return response()->json($policies);
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'Failed to fetch policies: '.$e->getMessage(),
            ], 422);
        }
    }

    /**
     * Fetch inventory locations from eBay.
     */
    public function fetchLocations(StoreMarketplace $marketplace, PlatformManager $platformManager): JsonResponse
    {
        $store = $this->storeContext->getCurrentStore();

        abort_unless($marketplace->store_id === $store->id, 403);

        try {
            /** @var EbayService $ebayService */
            $ebayService = $platformManager->driver($marketplace->platform);
            $locations = $ebayService->getInventoryLocations($marketplace);

            return response()->json($locations);
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'Failed to fetch locations: '.$e->getMessage(),
            ], 422);
        }
    }

    /**
     * Fetch shipping profiles from Etsy.
     */
    public function fetchShippingProfiles(StoreMarketplace $marketplace, PlatformManager $platformManager): JsonResponse
    {
        $store = $this->storeContext->getCurrentStore();

        abort_unless($marketplace->store_id === $store->id, 403);

        try {
            /** @var EtsyService $etsyService */
            $etsyService = $platformManager->driver($marketplace->platform);
            $profiles = $etsyService->getShippingProfiles($marketplace);

            return response()->json($profiles);
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'Failed to fetch shipping profiles: '.$e->getMessage(),
            ], 422);
        }
    }

    /**
     * Fetch return policies from Etsy.
     */
    public function fetchReturnPolicies(StoreMarketplace $marketplace, PlatformManager $platformManager): JsonResponse
    {
        $store = $this->storeContext->getCurrentStore();

        abort_unless($marketplace->store_id === $store->id, 403);

        try {
            /** @var EtsyService $etsyService */
            $etsyService = $platformManager->driver($marketplace->platform);
            $policies = $etsyService->getReturnPolicies($marketplace);

            return response()->json($policies);
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'Failed to fetch return policies: '.$e->getMessage(),
            ], 422);
        }
    }

    /**
     * Sync metafield definitions from Shopify.
     */
    public function syncMetafieldDefinitions(StoreMarketplace $marketplace, ShopifyService $shopifyService): JsonResponse
    {
        $store = $this->storeContext->getCurrentStore();

        abort_unless($marketplace->store_id === $store->id, 403);

        try {
            $count = $shopifyService->syncMetafieldDefinitions($marketplace);

            return response()->json([
                'count' => $count,
                'message' => "Synced {$count} metafield definitions from Shopify.",
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'Failed to sync metafield definitions: '.$e->getMessage(),
            ], 422);
        }
    }

    /**
     * Create listings for all products on this marketplace's sales channel.
     */
    public function createListings(StoreMarketplace $marketplace): JsonResponse
    {
        $store = $this->storeContext->getCurrentStore();

        abort_unless($marketplace->store_id === $store->id, 403);

        $salesChannel = SalesChannel::where('store_marketplace_id', $marketplace->id)->first();

        if (! $salesChannel) {
            return response()->json([
                'error' => 'No sales channel found for this marketplace. Please reconnect.',
            ], 422);
        }

        CreateSalesChannelListingsJob::dispatch($salesChannel);

        return response()->json([
            'message' => 'Listing creation has been queued. This may take a few minutes.',
        ]);
    }
}
