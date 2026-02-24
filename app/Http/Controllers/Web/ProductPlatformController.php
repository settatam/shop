<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\ActivityLog;
use App\Models\PlatformListing;
use App\Models\Product;
use App\Models\StoreMarketplace;
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
