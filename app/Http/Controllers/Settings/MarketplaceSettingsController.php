<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateMarketplaceSettingsRequest;
use App\Models\StoreMarketplace;
use App\Models\Warehouse;
use App\Services\Platforms\Ebay\EbayService;
use App\Services\Platforms\Etsy\EtsyService;
use App\Services\Platforms\PlatformManager;
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
            $policies = $ebayService->getBusinessPolicies($marketplace);

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
}
