<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\ActivityLog;
use App\Models\EbayItemSpecific;
use App\Models\MarketplacePolicy;
use App\Models\PlatformListing;
use App\Models\Product;
use App\Models\ShopifyMetafieldDefinition;
use App\Models\StoreMarketplace;
use App\Models\Warehouse;
use App\Services\Platforms\CategoryMappingService;
use App\Services\Platforms\ListingAIService;
use App\Services\Platforms\ListingBuilderService;
use App\Services\Platforms\PlatformManager;
use App\Services\StoreContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProductPlatformController extends Controller
{
    public function __construct(
        protected StoreContext $storeContext,
        protected ListingBuilderService $listingBuilder,
        protected PlatformManager $platformManager,
        protected CategoryMappingService $categoryMappingService,
    ) {}

    /**
     * Show the platform listing page for a product.
     */
    public function show(Product $product, StoreMarketplace $marketplace): Response
    {
        $this->authorize('view', $product);
        $this->authorizeMarketplace($marketplace);

        $product->load([
            'images',
            'legacyImages',
            'variants',
            'template.fields.options',
            'attributeValues.field',
            'category',
            'brand',
        ]);

        // Get existing listing (which now holds override data)
        $listing = PlatformListing::where('product_id', $product->id)
            ->where('store_marketplace_id', $marketplace->id)
            ->with('listingVariants')
            ->first();

        // Build preview data
        $preview = $this->listingBuilder->previewListing($product, $marketplace);

        // Get template fields with values
        $templateFields = $this->getTemplateFieldsWithValues($product);

        // Get platform field requirements/options
        $platformFields = $this->getPlatformFields($marketplace);

        // Get all images with selection state
        $images = $this->getImagesWithState($product, $listing);

        // Get metafields configuration (for Shopify-like platforms)
        $metafields = $this->getMetafieldsConfig($product, $marketplace, $listing, $preview);

        $isEbay = $marketplace->platform->value === 'ebay';
        $isShopify = $marketplace->platform->value === 'shopify';

        return Inertia::render('products/platforms/Show', [
            'product' => [
                'id' => $product->id,
                'title' => $product->title,
                'description' => $product->description,
                'handle' => $product->handle,
                'category' => $product->category?->name,
                'brand' => $product->brand?->name,
            ],
            'marketplace' => [
                'id' => $marketplace->id,
                'name' => $marketplace->name ?: $marketplace->platform->label(),
                'platform' => $marketplace->platform->value,
                'platform_label' => $marketplace->platform->label(),
            ],
            'listing' => $listing ? [
                'id' => $listing->id,
                'status' => $listing->status,
                'should_list' => $listing->should_list,
                'external_listing_id' => $listing->external_listing_id,
                'listing_url' => $listing->listing_url,
                'platform_price' => $listing->platform_price,
                'platform_quantity' => $listing->platform_quantity,
                'published_at' => $listing->published_at?->toIso8601String(),
                'last_synced_at' => $listing->last_synced_at?->toIso8601String(),
                'last_error' => $listing->last_error,
                'variants' => $listing->listingVariants->map(fn ($lv) => [
                    'id' => $lv->id,
                    'product_variant_id' => $lv->product_variant_id,
                    'price' => $lv->getEffectivePrice(),
                    'quantity' => $lv->getEffectiveQuantity(),
                    'sku' => $lv->getEffectiveSku(),
                    'external_variant_id' => $lv->external_variant_id,
                    'status' => $lv->status,
                ]),
            ] : null,
            'override' => $listing ? [
                'id' => $listing->id,
                'title' => $listing->title,
                'description' => $listing->description,
                'price' => $listing->platform_price,
                'quantity' => $listing->platform_quantity,
                'platform_category_id' => $listing->platform_category_id,
                'attributes' => $listing->attributes,
                'images' => $listing->images,
                'metafield_overrides' => $listing->metafield_overrides ?? [],
                'platform_settings' => $listing->platform_settings ?? [],
            ] : null,
            'preview' => $preview,
            'templateFields' => $templateFields,
            'platformFields' => $platformFields,
            'images' => $images,
            'metafields' => $metafields,
            'supportsMetafields' => $this->platformSupportsMetafields($marketplace),
            'marketplaceSettings' => $isEbay ? $this->getMarketplaceSettingsData($marketplace) : [],
            'policies' => $isEbay ? $this->getPoliciesForMarketplace($marketplace) : [],
            'categoryMapping' => $isEbay ? $this->getCategoryMappingData($product, $marketplace) : [],
            'calculatedPrice' => $isEbay ? $this->getCalculatedPrice($product, $marketplace, $listing) : null,
            'warehouses' => $isEbay ? $this->getWarehousesForStore() : [],
            'ebayItemSpecifics' => $isEbay ? $this->getEbayItemSpecificsData($product, $marketplace, $listing) : null,
            'shopifyMetafields' => $isShopify ? $this->getShopifyMetafieldDefinitionsData($product, $marketplace, $listing) : null,
        ]);
    }

    /**
     * Update the platform override for a product.
     */
    public function update(Request $request, Product $product, StoreMarketplace $marketplace): JsonResponse
    {
        $this->authorize('update', $product);
        $this->authorizeMarketplace($marketplace);

        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'string'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'compare_at_price' => ['nullable', 'numeric', 'min:0'],
            'quantity' => ['nullable', 'integer', 'min:0'],
            'category_id' => ['nullable', 'string', 'max:255'],
            'attributes' => ['nullable', 'array'],
            'excluded_image_ids' => ['nullable', 'array'],
            'excluded_image_ids.*' => ['integer'],
            'image_order' => ['nullable', 'array'],
            'image_order.*' => ['integer'],
            'excluded_metafields' => ['nullable', 'array'],
            'excluded_metafields.*' => ['string'],
            'custom_metafields' => ['nullable', 'array'],
            'attribute_overrides' => ['nullable', 'array'],
            'platform_settings' => ['nullable', 'array'],
        ]);

        $listing = $this->listingBuilder->saveOverride($product, $marketplace, $validated);

        // Refresh preview with new overrides
        $preview = $this->listingBuilder->previewListing($product, $marketplace);

        return response()->json([
            'success' => true,
            'message' => 'Platform listing saved',
            'override' => [
                'id' => $listing->id,
                'title' => $listing->title,
                'description' => $listing->description,
                'price' => $listing->platform_price,
                'quantity' => $listing->platform_quantity,
                'attributes' => $listing->attributes,
                'platform_category_id' => $listing->platform_category_id,
                'platform_settings' => $listing->platform_settings,
            ],
            'preview' => $preview,
        ]);
    }

    /**
     * Publish the product to the platform.
     */
    public function publish(Request $request, Product $product, StoreMarketplace $marketplace): JsonResponse
    {
        $this->authorize('update', $product);
        $this->authorizeMarketplace($marketplace);

        // Check if the product is excluded from this marketplace
        $existingListing = PlatformListing::where('product_id', $product->id)
            ->where('store_marketplace_id', $marketplace->id)
            ->first();

        if ($existingListing && ! $existingListing->should_list) {
            return response()->json([
                'success' => false,
                'message' => "This product is excluded from {$marketplace->name}. Toggle 'Should List' to enable publishing.",
            ], 422);
        }

        // Validate before publishing
        $validation = $this->listingBuilder->validateListing($product, $marketplace);

        if (! $validation['valid']) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot publish product',
                'errors' => $validation['errors'],
                'warnings' => $validation['warnings'],
            ], 422);
        }

        try {
            $platformService = $this->platformManager->driver($marketplace->platform);
            $listing = $platformService->pushProduct($product, $marketplace);

            return response()->json([
                'success' => true,
                'message' => 'Product published successfully',
                'listing' => [
                    'id' => $listing->id,
                    'status' => $listing->status,
                    'listing_url' => $listing->listing_url,
                    'external_listing_id' => $listing->external_listing_id,
                ],
                'warnings' => $validation['warnings'],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to publish: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Unpublish the product from the platform (keeps the listing record for relisting later).
     */
    public function unpublish(Product $product, StoreMarketplace $marketplace): JsonResponse
    {
        $this->authorize('update', $product);
        $this->authorizeMarketplace($marketplace);

        $listing = PlatformListing::where('product_id', $product->id)
            ->where('store_marketplace_id', $marketplace->id)
            ->first();

        if (! $listing) {
            return response()->json([
                'success' => false,
                'message' => 'Product is not listed on this platform',
            ], 404);
        }

        try {
            $platformService = $this->platformManager->driver($marketplace->platform);
            $updatedListing = $platformService->unlistListing($listing);

            // Log the activity on the product
            ActivityLog::log(
                Activity::LISTINGS_UNLIST,
                $product,
                null,
                [
                    'platform' => $marketplace->platform->value,
                    'marketplace_name' => $marketplace->name,
                    'listing_id' => $listing->id,
                ],
                "Unlisted from {$marketplace->name}"
            );

            return response()->json([
                'success' => true,
                'message' => 'Product unlisted successfully. You can relist it at any time.',
                'listing' => [
                    'id' => $updatedListing->id,
                    'status' => $updatedListing->status,
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to unlist: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Relist a previously unlisted product on the platform.
     */
    public function relist(Product $product, StoreMarketplace $marketplace): JsonResponse
    {
        $this->authorize('update', $product);
        $this->authorizeMarketplace($marketplace);

        $listing = PlatformListing::where('product_id', $product->id)
            ->where('store_marketplace_id', $marketplace->id)
            ->first();

        if (! $listing) {
            return response()->json([
                'success' => false,
                'message' => 'No listing found for this product on this platform',
            ], 404);
        }

        if ($listing->status !== PlatformListing::STATUS_ENDED) {
            return response()->json([
                'success' => false,
                'message' => 'This listing is not in unlisted status',
            ], 400);
        }

        try {
            $platformService = $this->platformManager->driver($marketplace->platform);
            $updatedListing = $platformService->relistListing($listing);

            // Log the activity on the product
            ActivityLog::log(
                Activity::LISTINGS_RELIST,
                $product,
                null,
                [
                    'platform' => $marketplace->platform->value,
                    'marketplace_name' => $marketplace->name,
                    'listing_id' => $listing->id,
                ],
                "Relisted on {$marketplace->name}"
            );

            return response()->json([
                'success' => true,
                'message' => 'Product relisted successfully',
                'listing' => [
                    'id' => $updatedListing->id,
                    'status' => $updatedListing->status,
                    'listing_url' => $updatedListing->listing_url,
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to relist: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get a live preview of the listing data.
     */
    public function preview(Request $request, Product $product, StoreMarketplace $marketplace): JsonResponse
    {
        $this->authorize('view', $product);
        $this->authorizeMarketplace($marketplace);

        // Apply temporary overrides from request for preview
        $tempOverride = $request->input('override', []);
        if (! empty($tempOverride)) {
            $this->listingBuilder->saveOverride($product, $marketplace, $tempOverride);
        }

        $preview = $this->listingBuilder->previewListing($product, $marketplace);

        return response()->json($preview);
    }

    /**
     * Sync the listing with the platform (update existing listing).
     */
    public function sync(Product $product, StoreMarketplace $marketplace): JsonResponse
    {
        $this->authorize('update', $product);
        $this->authorizeMarketplace($marketplace);

        $listing = PlatformListing::where('product_id', $product->id)
            ->where('store_marketplace_id', $marketplace->id)
            ->first();

        if (! $listing) {
            return response()->json([
                'success' => false,
                'message' => 'Product is not listed on this platform',
            ], 404);
        }

        try {
            $platformService = $this->platformManager->driver($marketplace->platform);
            $updatedListing = $platformService->updateListing($listing);

            return response()->json([
                'success' => true,
                'message' => 'Listing synced successfully',
                'listing' => [
                    'id' => $updatedListing->id,
                    'status' => $updatedListing->status,
                    'last_synced_at' => $updatedListing->last_synced_at?->toIso8601String(),
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to sync: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get AI suggestions for listing values.
     */
    public function aiSuggest(Request $request, Product $product, StoreMarketplace $marketplace): JsonResponse
    {
        $this->authorize('update', $product);
        $this->authorizeMarketplace($marketplace);

        $validated = $request->validate([
            'type' => ['required', 'string', 'in:auto_fill,title,description,ebay_listing,shopify_metafields'],
            'include_title' => ['sometimes', 'boolean'],
            'include_description' => ['sometimes', 'boolean'],
        ]);

        $product->load(['images', 'variants', 'template.fields', 'attributeValues.field', 'category', 'brand']);

        $aiService = app(ListingAIService::class);

        $result = match ($validated['type']) {
            'auto_fill' => $aiService->autoFillListingValues($product, $marketplace),
            'title' => $aiService->generateTitle($product, $marketplace),
            'description' => $aiService->generateDescription($product, $marketplace),
            'ebay_listing' => $this->handleEbayListingSuggest($aiService, $product, $marketplace, $validated),
            'shopify_metafields' => $this->handleShopifyMetafieldsSuggest($aiService, $product, $marketplace),
        };

        return response()->json($result);
    }

    /**
     * Handle the eBay listing AI suggest request by resolving item specifics and delegating to the AI service.
     *
     * @param  array<string, mixed>  $validated
     * @return array{success: bool, suggestions?: array<string, mixed>, error?: string}
     */
    protected function handleEbayListingSuggest(
        ListingAIService $aiService,
        Product $product,
        StoreMarketplace $marketplace,
        array $validated,
    ): array {
        $resolution = $this->categoryMappingService->resolveCategory($product, $marketplace);
        $primaryCategoryId = $resolution['primary_category_id'];

        if (! $primaryCategoryId) {
            return ['success' => false, 'error' => 'No eBay category mapped for this product'];
        }

        $specifics = EbayItemSpecific::where('ebay_category_id', $primaryCategoryId)
            ->with('values')
            ->get()
            ->map(fn (EbayItemSpecific $s) => [
                'name' => $s->name,
                'is_required' => $s->is_required,
                'is_recommended' => $s->is_recommended,
                'aspect_mode' => $s->aspect_mode,
                'allowed_values' => $s->values->pluck('value')->toArray(),
            ])
            ->toArray();

        return $aiService->suggestEbayListing($product, $marketplace, $specifics, [
            'include_title' => $validated['include_title'] ?? true,
            'include_description' => $validated['include_description'] ?? true,
        ]);
    }

    /**
     * Get eBay item specifics data with resolved values for the frontend.
     *
     * @return array{specifics: array<int, array<string, mixed>>, category_mapping_id: int|null, category_id: int|null, synced_at: string|null, needs_sync: bool}
     */
    protected function getEbayItemSpecificsData(Product $product, StoreMarketplace $marketplace, ?PlatformListing $listing): array
    {
        $resolution = $this->categoryMappingService->resolveCategory($product, $marketplace);
        $mapping = $resolution['mapping'];
        $primaryCategoryId = $resolution['primary_category_id'];

        if (! $primaryCategoryId || ! $mapping) {
            return [
                'specifics' => [],
                'category_mapping_id' => null,
                'category_id' => null,
                'synced_at' => null,
                'needs_sync' => true,
            ];
        }

        $specifics = EbayItemSpecific::where('ebay_category_id', $primaryCategoryId)
            ->with('values')
            ->orderByDesc('is_required')
            ->orderByDesc('is_recommended')
            ->orderBy('name')
            ->get();

        $fieldMappings = $mapping->getEffectiveFieldMappings();
        $listingAttributes = $listing?->attributes ?? [];

        // Build a lookup of template field values
        $templateFieldValues = [];
        $template = $product->getTemplate();
        if ($template) {
            $attributeValues = $product->attributeValues->keyBy('product_template_field_id');
            foreach ($template->fields as $field) {
                $attrValue = $attributeValues->get($field->id);
                if ($attrValue?->value !== null && $attrValue->value !== '') {
                    $templateFieldValues[$field->name] = $attrValue->value;
                }
            }
        }

        $specificsData = $specifics->map(function (EbayItemSpecific $specific) use ($fieldMappings, $listingAttributes, $templateFieldValues) {
            $mappedTemplateField = $fieldMappings[$specific->name] ?? null;
            $isListingOverride = isset($listingAttributes[$specific->name]);
            $resolvedValue = $listingAttributes[$specific->name]
                ?? ($mappedTemplateField ? ($templateFieldValues[$mappedTemplateField] ?? null) : null);

            return [
                'id' => $specific->id,
                'name' => $specific->name,
                'is_required' => $specific->is_required,
                'is_recommended' => $specific->is_recommended,
                'aspect_mode' => $specific->aspect_mode,
                'allowed_values' => $specific->values->pluck('value')->toArray(),
                'mapped_template_field' => $mappedTemplateField,
                'resolved_value' => $resolvedValue,
                'is_listing_override' => $isListingOverride,
            ];
        })->toArray();

        return [
            'specifics' => $specificsData,
            'category_mapping_id' => $mapping->id,
            'category_id' => $mapping->category_id,
            'synced_at' => $mapping->item_specifics_synced_at?->toIso8601String(),
            'needs_sync' => $mapping->needsItemSpecificsSync(),
        ];
    }

    /**
     * Handle Shopify metafields AI suggest request.
     *
     * @return array{success: bool, suggestions?: array<string, string>, error?: string}
     */
    protected function handleShopifyMetafieldsSuggest(
        ListingAIService $aiService,
        Product $product,
        StoreMarketplace $marketplace,
    ): array {
        $definitions = ShopifyMetafieldDefinition::where('store_marketplace_id', $marketplace->id)
            ->get()
            ->map(fn (ShopifyMetafieldDefinition $d) => [
                'name' => $d->name,
                'key' => $d->key,
                'namespace' => $d->namespace,
                'type' => $d->type,
                'description' => $d->description,
            ])
            ->toArray();

        if (empty($definitions)) {
            return ['success' => false, 'error' => 'No metafield definitions found. Sync from Shopify first.'];
        }

        return $aiService->suggestShopifyMetafields($product, $marketplace, $definitions);
    }

    /**
     * Get Shopify metafield definitions data for the frontend.
     *
     * @return array{definitions: array<int, array<string, mixed>>, has_definitions: bool}
     */
    protected function getShopifyMetafieldDefinitionsData(Product $product, StoreMarketplace $marketplace, ?PlatformListing $listing): array
    {
        $definitions = ShopifyMetafieldDefinition::where('store_marketplace_id', $marketplace->id)
            ->orderBy('name')
            ->get();

        $listingAttributes = $listing?->attributes ?? [];

        // Build a lookup of template field values
        $templateFieldValues = [];
        $template = $product->getTemplate();
        if ($template) {
            $attributeValues = $product->attributeValues->keyBy('product_template_field_id');
            foreach ($template->fields as $field) {
                $attrValue = $attributeValues->get($field->id);
                if ($attrValue?->value !== null && $attrValue->value !== '') {
                    $templateFieldValues[$field->name] = $attrValue->resolveDisplayValue() ?? $attrValue->value;
                }
            }
        }

        // Get existing metafield mappings from listing's metafield_overrides
        $metafieldOverrides = $listing?->metafield_overrides ?? [];
        $fieldMappings = $metafieldOverrides['field_mappings'] ?? [];

        $definitionsData = $definitions->map(function (ShopifyMetafieldDefinition $def) use ($fieldMappings, $listingAttributes, $templateFieldValues) {
            $fullKey = $def->namespace.'.'.$def->key;
            $mappedTemplateField = $fieldMappings[$fullKey] ?? null;
            $isListingOverride = isset($listingAttributes[$fullKey]);
            $resolvedValue = $listingAttributes[$fullKey]
                ?? ($mappedTemplateField ? ($templateFieldValues[$mappedTemplateField] ?? null) : null);

            return [
                'id' => $def->id,
                'name' => $def->name,
                'key' => $def->key,
                'namespace' => $def->namespace,
                'type' => $def->type,
                'description' => $def->description,
                'mapped_template_field' => $mappedTemplateField,
                'resolved_value' => $resolvedValue,
                'is_listing_override' => $isListingOverride,
            ];
        })->toArray();

        return [
            'definitions' => $definitionsData,
            'has_definitions' => $definitions->isNotEmpty(),
        ];
    }

    /**
     * Get marketplace-level settings data for the frontend.
     *
     * @return array<string, mixed>
     */
    protected function getMarketplaceSettingsData(StoreMarketplace $marketplace): array
    {
        $settings = $marketplace->settings ?? [];

        return [
            'listing_type' => $settings['listing_type'] ?? 'FIXED_PRICE',
            'marketplace_id' => $settings['marketplace_id'] ?? 'EBAY_US',
            'auction_markup' => $settings['auction_markup'] ?? null,
            'fixed_price_markup' => $settings['fixed_price_markup'] ?? null,
            'fulfillment_policy_id' => $settings['fulfillment_policy_id'] ?? null,
            'payment_policy_id' => $settings['payment_policy_id'] ?? null,
            'return_policy_id' => $settings['return_policy_id'] ?? null,
            'location_key' => $settings['location_key'] ?? null,
            'listing_duration_auction' => $settings['listing_duration_auction'] ?? null,
            'listing_duration_fixed' => $settings['listing_duration_fixed'] ?? null,
            'best_offer_enabled' => $settings['best_offer_enabled'] ?? false,
            'default_condition' => $settings['default_condition'] ?? null,
        ];
    }

    /**
     * Get locally synced policies grouped by type.
     *
     * @return array{return: array, payment: array, fulfillment: array}
     */
    protected function getPoliciesForMarketplace(StoreMarketplace $marketplace): array
    {
        $policies = MarketplacePolicy::withoutGlobalScopes()
            ->where('store_marketplace_id', $marketplace->id)
            ->orderBy('name')
            ->get();

        return [
            'return' => $policies->where('type', MarketplacePolicy::TYPE_RETURN)->map(fn ($p) => [
                'id' => $p->external_id,
                'name' => $p->name,
                'is_default' => $p->is_default,
            ])->values()->toArray(),
            'payment' => $policies->where('type', MarketplacePolicy::TYPE_PAYMENT)->map(fn ($p) => [
                'id' => $p->external_id,
                'name' => $p->name,
                'is_default' => $p->is_default,
            ])->values()->toArray(),
            'fulfillment' => $policies->where('type', MarketplacePolicy::TYPE_FULFILLMENT)->map(fn ($p) => [
                'id' => $p->external_id,
                'name' => $p->name,
                'is_default' => $p->is_default,
            ])->values()->toArray(),
        ];
    }

    /**
     * Get category mapping data for the frontend.
     *
     * @return array<string, mixed>
     */
    protected function getCategoryMappingData(Product $product, StoreMarketplace $marketplace): array
    {
        $resolution = $this->categoryMappingService->resolveCategory($product, $marketplace);

        return [
            'primary_category_id' => $resolution['primary_category_id'],
            'secondary_category_id' => $resolution['secondary_category_id'],
            'primary_category_name' => $resolution['mapping']?->primary_category_name ?? null,
            'secondary_category_name' => $resolution['mapping']?->secondary_category_name ?? null,
        ];
    }

    /**
     * Calculate the eBay price with markup applied.
     */
    protected function getCalculatedPrice(Product $product, StoreMarketplace $marketplace, ?PlatformListing $listing): ?float
    {
        $variant = $product->variants->first();
        $basePrice = (float) ($listing?->platform_price ?? $variant?->price ?? 0);

        if ($basePrice <= 0) {
            return null;
        }

        $listingType = $listing?->getEffectiveSetting('listing_type') ?? $marketplace->settings['listing_type'] ?? 'FIXED_PRICE';
        $markupKey = $listingType === 'AUCTION' ? 'auction_markup' : 'fixed_price_markup';
        $markup = $listing?->getEffectiveSetting($markupKey) ?? $marketplace->settings[$markupKey] ?? 0;

        if ($markup > 0) {
            return round($basePrice * (1 + $markup / 100), 2);
        }

        return $basePrice;
    }

    /**
     * Get warehouses for the current store (to pre-fill location creation).
     *
     * @return array<int, array<string, mixed>>
     */
    protected function getWarehousesForStore(): array
    {
        $store = $this->storeContext->getCurrentStore();

        return Warehouse::where('store_id', $store->id)
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get()
            ->map(fn (Warehouse $w) => [
                'id' => $w->id,
                'name' => $w->name,
                'code' => $w->code,
                'address_line1' => $w->address_line1,
                'city' => $w->city,
                'state' => $w->state,
                'postal_code' => $w->postal_code,
                'country' => $w->country,
                'is_default' => $w->is_default,
            ])
            ->toArray();
    }

    /**
     * Authorize that the marketplace belongs to the current store.
     */
    protected function authorizeMarketplace(StoreMarketplace $marketplace): void
    {
        $store = $this->storeContext->getCurrentStore();

        if ($marketplace->store_id !== $store->id) {
            abort(403, 'Unauthorized');
        }
    }

    /**
     * Get template fields with their current values.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function getTemplateFieldsWithValues(Product $product): array
    {
        $template = $product->getTemplate();
        if (! $template) {
            return [];
        }

        return $template->fields->map(function ($field) use ($product) {
            $value = $product->attributeValues
                ->firstWhere('product_template_field_id', $field->id);

            return [
                'id' => $field->id,
                'name' => $field->name,
                'label' => $field->label,
                'type' => $field->type,
                'is_required' => $field->is_required,
                'is_private' => $field->is_private ?? false,
                'value' => $value?->value,
                'options' => $field->options->map(fn ($opt) => [
                    'value' => $opt->value,
                    'label' => $opt->label ?? $opt->value,
                ])->toArray(),
            ];
        })->toArray();
    }

    /**
     * Get platform-specific field requirements.
     *
     * @return array<string, mixed>
     */
    protected function getPlatformFields(StoreMarketplace $marketplace): array
    {
        $platform = $marketplace->platform->value;

        // Return platform-specific field requirements
        return match ($platform) {
            'ebay' => $this->getEbayFields(),
            'amazon' => $this->getAmazonFields(),
            'etsy' => $this->getEtsyFields(),
            'walmart' => $this->getWalmartFields(),
            'shopify' => $this->getShopifyFields(),
            default => [],
        };
    }

    /**
     * Get eBay-specific fields.
     *
     * @return array<string, mixed>
     */
    protected function getEbayFields(): array
    {
        return [
            'required' => ['Title', 'Price', 'Quantity', 'Condition'],
            'recommended' => ['Brand', 'MPN', 'UPC', 'Metal', 'Metal Purity', 'Main Stone'],
            'condition_options' => [
                ['value' => '1000', 'label' => 'New with tags'],
                ['value' => '1500', 'label' => 'New without tags'],
                ['value' => '1750', 'label' => 'New with defects'],
                ['value' => '3000', 'label' => 'Pre-owned'],
                ['value' => '7000', 'label' => 'For parts or not working'],
            ],
        ];
    }

    /**
     * Get Amazon-specific fields.
     *
     * @return array<string, mixed>
     */
    protected function getAmazonFields(): array
    {
        return [
            'required' => ['item_name', 'brand_name', 'item_type', 'price'],
            'recommended' => ['product_description', 'bullet_point', 'manufacturer'],
        ];
    }

    /**
     * Get Etsy-specific fields.
     *
     * @return array<string, mixed>
     */
    protected function getEtsyFields(): array
    {
        return [
            'required' => ['title', 'description', 'price', 'quantity', 'who_made', 'when_made', 'taxonomy_id'],
            'recommended' => ['materials', 'tags'],
            'who_made_options' => [
                ['value' => 'i_did', 'label' => 'I did'],
                ['value' => 'someone_else', 'label' => 'A member of my shop'],
                ['value' => 'collective', 'label' => 'Another company or person'],
            ],
            'when_made_options' => [
                ['value' => 'made_to_order', 'label' => 'Made to order'],
                ['value' => '2020_2026', 'label' => '2020-2026'],
                ['value' => '2010_2019', 'label' => '2010-2019'],
                ['value' => '2000_2009', 'label' => '2000-2009'],
                ['value' => 'before_2000', 'label' => 'Before 2000'],
                ['value' => '1990s', 'label' => '1990s'],
                ['value' => '1980s', 'label' => '1980s'],
                ['value' => '1970s', 'label' => '1970s'],
                ['value' => '1960s', 'label' => '1960s'],
            ],
        ];
    }

    /**
     * Get Walmart-specific fields.
     *
     * @return array<string, mixed>
     */
    protected function getWalmartFields(): array
    {
        return [
            'required' => ['productName', 'price', 'brand', 'mainImageUrl'],
            'recommended' => ['shortDescription', 'longDescription', 'msrp'],
        ];
    }

    /**
     * Get Shopify-specific fields.
     *
     * @return array<string, mixed>
     */
    protected function getShopifyFields(): array
    {
        return [
            'required' => ['title', 'body_html'],
            'recommended' => ['vendor', 'product_type', 'tags', 'handle'],
        ];
    }

    /**
     * Get images with their selection state.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function getImagesWithState(Product $product, ?PlatformListing $listing): array
    {
        $excludedIds = $listing?->platform_settings['excluded_image_ids'] ?? [];
        $imageOrder = $listing?->platform_settings['image_order'] ?? [];

        $images = $product->images->map(fn ($img) => [
            'id' => $img->id,
            'url' => $img->url,
            'alt' => $img->alt,
            'is_primary' => $img->is_primary,
            'included' => ! in_array($img->id, $excludedIds),
            'source' => 'images',
        ]);

        // Also include legacy images
        $legacyImages = $product->legacyImages->map(fn ($img) => [
            'id' => $img->id,
            'url' => $img->url,
            'alt' => $img->alt ?? $product->title,
            'is_primary' => $img->is_primary ?? false,
            'included' => ! in_array($img->id, $excludedIds),
            'source' => 'legacy',
        ]);

        $allImages = $images->merge($legacyImages);

        // Apply custom order if set
        if (! empty($imageOrder)) {
            $allImages = $allImages->sortBy(function ($img) use ($imageOrder) {
                $pos = array_search($img['id'], $imageOrder);

                return $pos === false ? 999 : $pos;
            })->values();
        }

        return $allImages->toArray();
    }

    /**
     * Get metafields configuration for the platform.
     *
     * @return array<string, mixed>
     */
    protected function getMetafieldsConfig(
        Product $product,
        StoreMarketplace $marketplace,
        ?PlatformListing $listing,
        array $preview
    ): array {
        if (! $this->platformSupportsMetafields($marketplace)) {
            return [];
        }

        $metafieldOverrides = $listing?->metafield_overrides ?? [];
        $excludedMetafields = $metafieldOverrides['excluded'] ?? [];
        $customMetafields = $metafieldOverrides['custom'] ?? [];

        // Get metafields from preview
        $previewMetafields = $preview['listing']['metafields'] ?? [];

        $metafields = collect($previewMetafields)->map(function ($mf) use ($excludedMetafields) {
            $key = ($mf['namespace'] ?? 'custom').'.'.$mf['key'];

            return [
                'namespace' => $mf['namespace'] ?? 'custom',
                'key' => $mf['key'],
                'value' => $mf['value'],
                'type' => $mf['type'] ?? 'single_line_text_field',
                'included' => ! in_array($key, $excludedMetafields),
                'source' => 'template',
            ];
        })->toArray();

        // Add custom metafields
        foreach ($customMetafields as $mf) {
            $metafields[] = [
                'namespace' => $mf['namespace'] ?? 'custom',
                'key' => $mf['key'],
                'value' => $mf['value'],
                'type' => $mf['type'] ?? 'single_line_text_field',
                'included' => true,
                'source' => 'custom',
            ];
        }

        return $metafields;
    }

    /**
     * Check if the platform supports metafields.
     */
    protected function platformSupportsMetafields(StoreMarketplace $marketplace): bool
    {
        return in_array($marketplace->platform->value, ['shopify', 'bigcommerce']);
    }
}
