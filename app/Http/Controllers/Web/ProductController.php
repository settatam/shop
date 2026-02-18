<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\BulkUpdateProductsRequest;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Image;
use App\Models\Inventory;
use App\Models\InventoryAdjustment;
use App\Models\PlatformListing;
use App\Models\PrinterSetting;
use App\Models\Product;
use App\Models\ProductTemplateField;
use App\Models\ProductVariant;
use App\Models\StoreMarketplace;
use App\Models\Tag;
use App\Models\Vendor;
use App\Models\Warehouse;
use App\Services\ActivityLogFormatter;
use App\Services\FeatureManager;
use App\Services\Image\ImageService;
use App\Services\Sku\SkuGeneratorService;
use App\Services\StoreContext;
use App\Services\Video\VideoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class ProductController extends Controller
{
    public function __construct(
        protected StoreContext $storeContext,
        protected ImageService $imageService,
        protected VideoService $videoService,
        protected FeatureManager $featureManager,
    ) {}

    public function index(Request $request): Response|RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return redirect()->route('dashboard')
                ->with('error', 'Please select a store first.');
        }

        // Get all categories with hierarchy
        $allCategories = Category::where('store_id', $store->id)
            ->orderBy('name')
            ->get(['id', 'name', 'parent_id']);

        // Build category hierarchy for cascading dropdowns
        // Level 1 = categories with parent_id = null
        // Level 2 = categories whose parent has parent_id = null
        // Level 3 = categories whose grandparent has parent_id = null
        $level1Ids = $allCategories->whereNull('parent_id')->pluck('id')->toArray();

        // Level 2 categories (parent is level 1)
        $level2Categories = $allCategories->whereIn('parent_id', $level1Ids)->values();

        // Level 3 categories grouped by their Level 2 parent
        $level2Ids = $level2Categories->pluck('id')->toArray();
        $level3ByParent = $allCategories->whereIn('parent_id', $level2Ids)
            ->groupBy('parent_id')
            ->map(fn ($items) => $items->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
            ])->values())
            ->toArray();

        // Keep flat categories list for other uses (mass edit, etc.)
        $categories = $allCategories;

        // Get brands for filters
        $brands = Brand::where('store_id', $store->id)
            ->orderBy('name')
            ->get(['id', 'name']);

        // Get warehouses for GIA scanner
        $warehouses = Warehouse::where('store_id', $store->id)
            ->where('is_active', true)
            ->orderBy('priority')
            ->get(['id', 'name', 'code', 'is_default']);

        // Get tags for filters
        $tags = Tag::where('store_id', $store->id)
            ->orderBy('name')
            ->get(['id', 'name', 'color']);

        // Get vendors for mass edit
        $vendors = Vendor::where('store_id', $store->id)
            ->orderBy('name')
            ->get(['id', 'name']);

        // Get marketplaces for "Listed In" filter
        $marketplaces = \App\Models\StoreMarketplace::where('store_id', $store->id)
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name', 'platform'])
            ->map(fn ($m) => [
                'id' => $m->id,
                'name' => $m->name ?: ucfirst($m->platform),
            ]);

        // Get distinct jewelry types for Type filter
        $types = Product::where('store_id', $store->id)
            ->whereNotNull('jewelry_type')
            ->distinct()
            ->pluck('jewelry_type')
            ->filter()
            ->map(fn ($type) => [
                'value' => $type,
                'label' => ucfirst(str_replace('_', ' ', $type)),
            ])
            ->values();

        // Get stone shapes from attribute values
        $stoneShapes = $this->getStoneShapeOptions($store->id);

        // Get ring sizes
        $ringSizes = $this->getRingSizeOptions($store->id);

        return Inertia::render('products/Index', [
            'categories' => $categories,
            'level2Categories' => $level2Categories,
            'level3ByParent' => $level3ByParent,
            'brands' => $brands,
            'vendors' => $vendors,
            'warehouses' => $warehouses,
            'tags' => $tags,
            'marketplaces' => $marketplaces,
            'types' => $types,
            'stoneShapes' => $stoneShapes,
            'ringSizes' => $ringSizes,
        ]);
    }

    /**
     * Get stone shape options for filters.
     *
     * @return \Illuminate\Support\Collection<int, array{value: string, label: string}>
     */
    protected function getStoneShapeOptions(int $storeId): \Illuminate\Support\Collection
    {
        // Get from attribute values
        $stoneShapeFieldIds = ProductTemplateField::whereHas('template', fn ($q) => $q->where('store_id', $storeId))
            ->where(fn ($q) => $q->where('name', 'like', '%stone_shape%')
                ->orWhere('name', 'like', '%shape%'))
            ->pluck('id');

        $shapes = collect();
        if ($stoneShapeFieldIds->isNotEmpty()) {
            $shapes = \App\Models\ProductAttributeValue::whereIn('product_template_field_id', $stoneShapeFieldIds)
                ->whereHas('product', fn ($q) => $q->where('store_id', $storeId))
                ->distinct()
                ->pluck('value')
                ->filter()
                ->map(fn ($shape) => [
                    'value' => $shape,
                    'label' => ucfirst($shape),
                ]);
        }

        // Include main_stone_type values
        $mainStoneTypes = Product::where('store_id', $storeId)
            ->whereNotNull('main_stone_type')
            ->distinct()
            ->pluck('main_stone_type')
            ->filter()
            ->map(fn ($type) => [
                'value' => $type,
                'label' => ucfirst(str_replace('_', ' ', $type)),
            ]);

        return $shapes->merge($mainStoneTypes)->unique('value')->values();
    }

    /**
     * Get ring size options for filters.
     *
     * @return \Illuminate\Support\Collection<int, array{value: string, label: string}>
     */
    protected function getRingSizeOptions(int $storeId): \Illuminate\Support\Collection
    {
        // Get from attribute values
        $ringSizeFieldIds = ProductTemplateField::whereHas('template', fn ($q) => $q->where('store_id', $storeId))
            ->where(fn ($q) => $q->where('name', 'like', '%ring_size%')
                ->orWhere('name', '=', 'size'))
            ->pluck('id');

        $sizes = collect();
        if ($ringSizeFieldIds->isNotEmpty()) {
            $sizes = \App\Models\ProductAttributeValue::whereIn('product_template_field_id', $ringSizeFieldIds)
                ->whereHas('product', fn ($q) => $q->where('store_id', $storeId))
                ->distinct()
                ->pluck('value')
                ->filter()
                ->map(fn ($size) => [
                    'value' => $size,
                    'label' => $size,
                ]);
        }

        // Include ring_size column values
        $productRingSizes = Product::where('store_id', $storeId)
            ->whereNotNull('ring_size')
            ->distinct()
            ->pluck('ring_size')
            ->filter()
            ->map(fn ($size) => [
                'value' => (string) $size,
                'label' => (string) $size,
            ]);

        // Include sizes from product variants (option1 or option2 named 'Size' or 'Ring Size')
        $variantSizes = ProductVariant::whereHas('product', fn ($q) => $q->where('store_id', $storeId))
            ->where(function ($q) {
                $q->whereIn('option1_name', ['Size', 'Ring Size'])
                    ->orWhereIn('option2_name', ['Size', 'Ring Size']);
            })
            ->get()
            ->flatMap(function ($variant) {
                $sizes = [];
                if (in_array($variant->option1_name, ['Size', 'Ring Size']) && $variant->option1_value) {
                    $sizes[] = $variant->option1_value;
                }
                if (in_array($variant->option2_name, ['Size', 'Ring Size']) && $variant->option2_value) {
                    $sizes[] = $variant->option2_value;
                }

                return $sizes;
            })
            ->unique()
            ->filter()
            ->map(fn ($size) => [
                'value' => (string) $size,
                'label' => (string) $size,
            ]);

        return $sizes->merge($productRingSizes)
            ->merge($variantSizes)
            ->unique('value')
            ->sortBy(fn ($item) => is_numeric($item['value']) ? (float) $item['value'] : $item['value'])
            ->values();
    }

    public function create(): Response|RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return redirect()->route('dashboard')
                ->with('error', 'Please select a store first.');
        }

        $categories = Category::where('store_id', $store->id)
            ->orderBy('level')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'parent_id', 'level', 'template_id', 'sku_format', 'sku_prefix'])
            ->map(fn (Category $category) => [
                'id' => $category->id,
                'name' => $category->name,
                'full_path' => $category->full_path,
                'parent_id' => $category->parent_id,
                'level' => $category->level ?? 0,
                'template_id' => $category->template_id,
                'has_sku_format' => (bool) $category->getEffectiveSkuFormat(),
            ]);

        $brands = Brand::where('store_id', $store->id)
            ->orderBy('name')
            ->get(['id', 'name']);

        $warehouses = Warehouse::where('store_id', $store->id)
            ->where('is_active', true)
            ->orderBy('priority')
            ->get(['id', 'name', 'code', 'is_default']);

        $vendors = Vendor::where('store_id', $store->id)
            ->orderBy('name')
            ->get(['id', 'name', 'code']);

        return Inertia::render('products/Create', [
            'categories' => $categories,
            'brands' => $brands,
            'warehouses' => $warehouses,
            'vendors' => $vendors,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return redirect()->route('dashboard')
                ->with('error', 'Please select a store first.');
        }

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'handle' => 'nullable|string|max:255',
            'category_id' => 'nullable|exists:categories,id',
            'template_id' => 'nullable|exists:product_templates,id',
            'vendor_id' => 'required|exists:vendors,id',
            'brand_id' => 'nullable|exists:brands,id',
            'charge_taxes' => 'boolean',
            'compare_at_price' => 'nullable|numeric|min:0',
            'price_code' => 'nullable|string|max:50',
            'is_published' => 'boolean',
            'has_variants' => 'boolean',
            'track_quantity' => 'boolean',
            'sell_out_of_stock' => 'boolean',
            'variants' => 'required|array|min:1',
            'variants.*.sku' => 'required|string|max:255',
            'variants.*.price' => 'required|numeric|min:0',
            'variants.*.cost' => 'nullable|numeric|min:0',
            'variants.*.wholesale_price' => 'nullable|numeric|min:0',
            'variants.*.quantity' => 'required|integer|min:0',
            'variants.*.warehouse_id' => 'nullable|exists:warehouses,id',
            'variants.*.option1_name' => 'nullable|string|max:255',
            'variants.*.option1_value' => 'nullable|string|max:255',
            'attributes' => 'nullable|array',
            'attributes.*' => 'nullable|string|max:65535',
            'images' => 'nullable|array',
            'images.*' => 'image|max:10240', // 10MB max per image
            'internal_images' => 'nullable|array',
            'internal_images.*' => 'image|max:10240',
            'video_files' => 'nullable|array',
            'video_files.*' => 'file|mimetypes:video/mp4,video/quicktime,video/x-msvideo,video/webm|max:102400', // 100MB max
            'video_titles' => 'nullable|array',
            'video_titles.*' => 'nullable|string|max:255',
            'videos' => 'nullable|array',
            'videos.*.url' => 'nullable|url',
            'videos.*.title' => 'nullable|string|max:255',
            'condition' => 'nullable|string|in:new,like_new,excellent,very_good,good,fair,poor',
            'weight' => 'nullable|numeric|min:0',
            'weight_unit' => 'nullable|string|in:g,kg,oz,lb',
            'length' => 'nullable|numeric|min:0',
            'width' => 'nullable|numeric|min:0',
            'height' => 'nullable|numeric|min:0',
            'length_class' => 'nullable|string|in:cm,m,in,ft',
            'domestic_shipping_cost' => 'nullable|numeric|min:0',
            'international_shipping_cost' => 'nullable|numeric|min:0',
        ]);

        // Determine has_variants based on user input (not just count)
        $hasVariants = $validated['has_variants'] ?? (count($validated['variants']) > 1);

        $product = Product::create([
            'store_id' => $store->id,
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'handle' => $validated['handle'] ?? Str::slug($validated['title']),
            'category_id' => $validated['category_id'] ?? null,
            'template_id' => $validated['template_id'] ?? null,
            'vendor_id' => $validated['vendor_id'],
            'brand_id' => $validated['brand_id'] ?? null,
            'is_published' => $validated['is_published'] ?? false,
            'is_draft' => ! ($validated['is_published'] ?? false),
            'has_variants' => $hasVariants,
            'track_quantity' => $validated['track_quantity'] ?? true,
            'sell_out_of_stock' => $validated['sell_out_of_stock'] ?? false,
            'charge_taxes' => $validated['charge_taxes'] ?? true,
            'compare_at_price' => $validated['compare_at_price'] ?? null,
            'price_code' => $validated['price_code'] ?? null,
            'condition' => $validated['condition'] ?? null,
            'weight' => $validated['weight'] ?? null,
            'weight_unit' => $validated['weight_unit'] ?? 'g',
            'length' => $validated['length'] ?? null,
            'width' => $validated['width'] ?? null,
            'height' => $validated['height'] ?? null,
            'length_class' => $validated['length_class'] ?? 'cm',
            'domestic_shipping_cost' => $validated['domestic_shipping_cost'] ?? null,
            'international_shipping_cost' => $validated['international_shipping_cost'] ?? null,
        ]);

        // Get default warehouse
        $defaultWarehouse = Warehouse::where('store_id', $store->id)
            ->where('is_default', true)
            ->first();

        // Create variants
        foreach ($validated['variants'] as $variantData) {
            // Clear option fields if has_variants is false
            $optionName = $hasVariants ? ($variantData['option1_name'] ?? null) : null;
            $optionValue = $hasVariants ? ($variantData['option1_value'] ?? null) : null;

            $variant = $product->variants()->create([
                'sku' => $variantData['sku'],
                'price' => $variantData['price'],
                'cost' => $variantData['cost'] ?? null,
                'wholesale_price' => $variantData['wholesale_price'] ?? null,
                'quantity' => $variantData['quantity'],
                'option1_name' => $optionName,
                'option1_value' => $optionValue,
            ]);

            // Create inventory record with adjustment tracking
            $warehouseId = $variantData['warehouse_id'] ?? $defaultWarehouse?->id;
            $newQuantity = (int) ($variantData['quantity'] ?? 0);

            if ($warehouseId) {
                $inventory = Inventory::create([
                    'store_id' => $store->id,
                    'product_variant_id' => $variant->id,
                    'warehouse_id' => $warehouseId,
                    'quantity' => 0, // Start at 0, adjustment will set correct quantity
                    'unit_cost' => $variantData['cost'] ?? null,
                ]);

                if ($newQuantity > 0) {
                    $inventory->adjustQuantity(
                        $newQuantity,
                        InventoryAdjustment::TYPE_INITIAL,
                        $request->user()?->id,
                        'Initial inventory',
                        'Initial quantity set via product creation'
                    );
                }
            }
        }

        // Auto-generate SKU and barcode in format: CATEGORY_PREFIX-product_id
        $product->generateSkusForVariants();

        // Save template attribute values
        if (! empty($validated['attributes'])) {
            foreach ($validated['attributes'] as $fieldId => $value) {
                if ($value !== null && $value !== '') {
                    $product->setTemplateAttributeValue((int) $fieldId, $value);
                }
            }
        }

        // Handle image uploads
        if ($request->hasFile('images')) {
            $this->imageService->uploadMultiple(
                files: $request->file('images'),
                imageable: $product,
                store: $store,
                folder: 'products',
                altText: $product->title,
                setFirstAsPrimary: true
            );
        }

        // Handle internal image uploads
        if ($request->hasFile('internal_images')) {
            $this->imageService->uploadMultiple(
                files: $request->file('internal_images'),
                imageable: $product,
                store: $store,
                folder: 'products/internal',
                altText: $product->title.' (internal)',
                setFirstAsPrimary: false,
                isInternal: true
            );
        }

        // Handle video file uploads
        if ($request->hasFile('video_files')) {
            $videoTitles = $validated['video_titles'] ?? [];
            $this->videoService->uploadMultiple(
                files: $request->file('video_files'),
                product: $product,
                store: $store,
                titles: $videoTitles
            );
        }

        // Handle external video URLs
        if (! empty($validated['videos'])) {
            $sortOrder = $product->videos()->max('sort_order') ?? -1;
            foreach ($validated['videos'] as $videoData) {
                if (! empty($videoData['url'])) {
                    $this->videoService->createFromUrl(
                        product: $product,
                        url: $videoData['url'],
                        title: $videoData['title'] ?? null,
                        sortOrder: ++$sortOrder
                    );
                }
            }
        }

        return redirect()->route('products.show', $product)
            ->with('success', 'Product created successfully.');
    }

    public function show(Product $product): Response|RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store || $product->store_id !== $store->id) {
            abort(404);
        }

        $product->load(['category', 'brand', 'vendor', 'variants', 'images', 'tags', 'attributeValues.field']);

        // Get template and fields with values
        $template = null;
        $templateFieldsWithValues = [];

        if ($product->template_id) {
            $template = $product->template;
        } elseif ($product->category) {
            $template = $product->category->getEffectiveTemplate();
        }

        if ($template) {
            $template->load('fields.options');
            $attributeValues = $product->attributeValues->keyBy('product_template_field_id');

            $templateFieldsWithValues = $template->fields->map(function ($field) use ($attributeValues) {
                $attrValue = $attributeValues->get($field->id);
                $storedValue = $attrValue?->value;
                $displayValue = $storedValue;

                // For select/radio/checkbox fields, map the stored value to the option label
                if ($storedValue && in_array($field->type, [ProductTemplateField::TYPE_SELECT, ProductTemplateField::TYPE_RADIO, ProductTemplateField::TYPE_CHECKBOX])) {
                    $option = $field->options->firstWhere('value', $storedValue);
                    $displayValue = $option?->label ?? $storedValue;
                }

                // For brand fields, get the brand name
                if ($field->type === ProductTemplateField::TYPE_BRAND && $storedValue) {
                    $brand = Brand::find($storedValue);
                    $displayValue = $brand?->name ?? $storedValue;
                }

                return [
                    'id' => $field->id,
                    'label' => $field->label,
                    'name' => $field->name,
                    'type' => $field->type,
                    'value' => $displayValue,
                ];
            })->values();
        }

        return Inertia::render('products/Show', [
            'product' => [
                'id' => $product->id,
                'title' => $product->title,
                'description' => $product->description,
                'handle' => $product->handle,
                'is_published' => $product->is_published,
                'is_draft' => $product->is_draft,
                'has_variants' => $product->has_variants,
                'track_quantity' => $product->track_quantity,
                'sell_out_of_stock' => $product->sell_out_of_stock,
                'charge_taxes' => $product->charge_taxes,
                'total_quantity' => $product->total_quantity,
                'created_at' => $product->created_at,
                'updated_at' => $product->updated_at,
                'category' => $product->category ? [
                    'id' => $product->category->id,
                    'name' => $product->category->name,
                ] : null,
                'brand' => $product->brand ? [
                    'id' => $product->brand->id,
                    'name' => $product->brand->name,
                ] : null,
                'vendor' => $product->vendor ? [
                    'id' => $product->vendor->id,
                    'name' => $product->vendor->name,
                ] : null,
                'tags' => $product->tags->map(fn (Tag $tag) => [
                    'id' => $tag->id,
                    'name' => $tag->name,
                    'color' => $tag->color,
                ]),
                'variants' => $product->variants->map(fn (ProductVariant $variant) => [
                    'id' => $variant->id,
                    'sku' => $variant->sku,
                    'title' => $variant->options_title ?: null,
                    'price' => $variant->price,
                    'cost' => $variant->cost,
                    'quantity' => $variant->quantity,
                ]),
                'images' => $product->images->map(fn ($image) => [
                    'id' => $image->id,
                    'url' => $image->url,
                    'alt' => $image->alt,
                    'is_primary' => $image->is_primary,
                ]),
            ],
            'template' => $template ? [
                'id' => $template->id,
                'name' => $template->name,
            ] : null,
            'templateFields' => $templateFieldsWithValues,
            'activityLogs' => app(ActivityLogFormatter::class)->formatForSubject($product),
            'platformListings' => PlatformListing::where('product_id', $product->id)
                ->with(['storeMarketplace', 'salesChannel'])
                ->get()
                ->map(function (PlatformListing $listing) {
                    // Handle external platforms (have storeMarketplace)
                    if ($listing->storeMarketplace) {
                        return [
                            'id' => $listing->id,
                            'platform' => $listing->storeMarketplace->platform->value,
                            'platform_label' => $listing->storeMarketplace->platform->label(),
                            'platform_product_id' => $listing->external_listing_id,
                            'status' => $listing->status,
                            'listing_url' => $listing->listing_url,
                            'price' => $listing->platform_price,
                            'quantity' => $listing->platform_quantity,
                            'last_synced_at' => $listing->last_synced_at?->toIso8601String(),
                            'error_message' => $listing->last_error,
                            'is_local' => false,
                            'marketplace' => [
                                'id' => $listing->store_marketplace_id,
                                'name' => $listing->storeMarketplace->name,
                            ],
                        ];
                    }

                    // Handle local channels (In Store, etc.)
                    return [
                        'id' => $listing->id,
                        'platform' => $listing->salesChannel?->code ?? 'local',
                        'platform_label' => $listing->salesChannel?->name ?? 'In Store',
                        'platform_product_id' => null,
                        'status' => $listing->status,
                        'listing_url' => null,
                        'price' => $listing->platform_price,
                        'quantity' => $listing->platform_quantity,
                        'last_synced_at' => $listing->last_synced_at?->toIso8601String(),
                        'error_message' => $listing->last_error,
                        'is_local' => true,
                        'marketplace' => [
                            'id' => $listing->sales_channel_id,
                            'name' => $listing->salesChannel?->name ?? 'In Store',
                        ],
                    ];
                }),
            'availableMarketplaces' => StoreMarketplace::where('store_id', $store->id)
                ->sellingPlatforms()
                ->connected()
                ->get()
                ->map(fn (StoreMarketplace $marketplace) => [
                    'id' => $marketplace->id,
                    'name' => $marketplace->name,
                    'platform' => $marketplace->platform->value,
                    'platform_label' => $marketplace->platform->label(),
                ]),
        ]);
    }

    public function edit(Product $product): Response|RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store || $product->store_id !== $store->id) {
            abort(404);
        }

        $product->load(['category', 'brand', 'variants', 'images', 'attributeValues', 'tags', 'orderItems.order', 'memoItems.memo', 'repairItems.repair']);

        $categories = Category::where('store_id', $store->id)
            ->with('template')
            ->orderBy('level')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'parent_id', 'level', 'template_id', 'charge_taxes'])
            ->map(fn (Category $category) => [
                'id' => $category->id,
                'name' => $category->name,
                'full_path' => $category->full_path,
                'parent_id' => $category->parent_id,
                'level' => $category->level ?? 0,
                'template_id' => $category->template_id,
                'charge_taxes' => $category->getEffectiveChargeTaxes(),
            ]);

        $brands = Brand::where('store_id', $store->id)
            ->orderBy('name')
            ->get(['id', 'name']);

        $warehouses = Warehouse::where('store_id', $store->id)
            ->where('is_active', true)
            ->orderBy('priority')
            ->get(['id', 'name', 'code', 'is_default']);

        $vendors = Vendor::where('store_id', $store->id)
            ->orderBy('name')
            ->get(['id', 'name', 'code']);

        // Get inventory by variant
        $variantInventory = [];
        foreach ($product->variants as $variant) {
            $inventory = Inventory::where('product_variant_id', $variant->id)->first();
            $variantInventory[$variant->id] = [
                'warehouse_id' => $inventory?->warehouse_id,
                'quantity' => $inventory?->quantity ?? $variant->quantity,
            ];
        }

        // Get template fields if product has a category with a template
        $template = $product->getTemplate();
        $templateFields = [];
        $attributeValues = [];
        $templateBrands = [];

        if ($template) {
            $template->load('fields.options');
            $templateFields = $template->fields->map(fn ($field) => [
                'id' => $field->id,
                'name' => $field->name,
                'label' => $field->label,
                'type' => $field->type,
                'placeholder' => $field->placeholder,
                'help_text' => $field->help_text,
                'default_value' => $field->default_value,
                'is_required' => $field->is_required,
                'group_name' => $field->group_name,
                'group_position' => $field->group_position,
                'width_class' => $field->width_class,
                'options' => $field->hasOptions() ? $field->options->map(fn ($opt) => [
                    'label' => $opt->label,
                    'value' => $opt->value,
                ]) : [],
            ]);

            // Build attribute values keyed by field ID
            foreach ($product->attributeValues as $attrValue) {
                $attributeValues[$attrValue->product_template_field_id] = $attrValue->value;
            }

            // Check if template has a brand field and load brands if so
            $hasBrandField = $template->fields->contains(fn ($field) => $field->type === ProductTemplateField::TYPE_BRAND);
            if ($hasBrandField) {
                $templateBrands = Brand::where('store_id', $store->id)
                    ->orderBy('name')
                    ->get(['id', 'name'])
                    ->map(fn ($brand) => [
                        'id' => $brand->id,
                        'name' => $brand->name,
                    ]);
            }
        }

        // Get available tags for the store
        $availableTags = Tag::where('store_id', $store->id)
            ->orderBy('name')
            ->get(['id', 'name', 'color'])
            ->map(fn (Tag $tag) => [
                'id' => $tag->id,
                'name' => $tag->name,
                'color' => $tag->color,
            ]);

        return Inertia::render('products/Edit', [
            'product' => [
                'id' => $product->id,
                'title' => $product->title,
                'description' => $product->description,
                'handle' => $product->handle,
                'sku' => $product->sku,
                'upc' => $product->upc,
                'status' => $product->status,
                'is_published' => $product->is_published,
                'is_draft' => $product->is_draft,
                'has_variants' => $product->has_variants,
                'track_quantity' => $product->track_quantity,
                'sell_out_of_stock' => $product->sell_out_of_stock,
                'charge_taxes' => $product->charge_taxes,
                'price_code' => $product->price_code,
                'category_id' => $product->category_id,
                'vendor_id' => $product->vendor_id,
                'template_id' => $product->template_id,
                'compare_at_price' => $product->compare_at_price,
                'weight' => $product->weight,
                'weight_unit' => $product->weight_unit ?? 'lb',
                'length' => $product->length,
                'width' => $product->width,
                'height' => $product->height,
                'length_class' => $product->length_class ?? 'in',
                'minimum_order' => $product->minimum_order ?? 1,
                'total_quantity' => $product->total_quantity,
                'seo_page_title' => $product->seo_page_title,
                'seo_description' => $product->seo_description,
                'created_at' => $product->created_at,
                'updated_at' => $product->updated_at,
                'tag_ids' => $product->tags->pluck('id')->toArray(),
                'variants' => $product->variants->map(fn (ProductVariant $variant) => [
                    'id' => $variant->id,
                    'sku' => $variant->sku,
                    'barcode' => $variant->barcode,
                    'title' => $variant->options_title ?: null,
                    'price' => $variant->price,
                    'cost' => $variant->cost,
                    'wholesale_price' => $variant->wholesale_price,
                    'quantity' => $variant->quantity,
                    'weight' => $variant->weight,
                    'weight_unit' => $variant->weight_unit ?? 'lb',
                    'option1_name' => $variant->option1_name,
                    'option1_value' => $variant->option1_value,
                    'option2_name' => $variant->option2_name,
                    'option2_value' => $variant->option2_value,
                    'option3_name' => $variant->option3_name,
                    'option3_value' => $variant->option3_value,
                    'is_active' => $variant->is_active,
                ]),
                'images' => $product->images->map(fn ($image) => [
                    'id' => $image->id,
                    'url' => $image->url,
                    'alt' => $image->alt,
                    'is_primary' => $image->is_primary,
                ]),
            ],
            'categories' => $categories,
            'availableTags' => $availableTags,
            'brands' => $brands,
            'vendors' => $vendors,
            'warehouses' => $warehouses,
            'variantInventory' => $variantInventory,
            'template' => $template ? [
                'id' => $template->id,
                'name' => $template->name,
                'description' => $template->description,
            ] : null,
            'templateFields' => $templateFields,
            'templateBrands' => $templateBrands,
            'attributeValues' => $attributeValues,
            'availableStatuses' => Product::getStatusesForStore($store),
            'fieldRequirements' => $this->featureManager->getFieldRequirements($store, 'products'),
            'activity' => [
                'orders' => $product->orderItems->filter(fn ($item) => $item->order)->map(fn ($item) => [
                    'id' => $item->order->id,
                    'title' => $item->order->order_number ?? "Order #{$item->order->id}",
                    'status' => $item->order->status,
                    'date' => $item->order->created_at->format('M j, Y'),
                    'price' => $item->price,
                ])->unique('id')->values(),
                'memos' => $product->memoItems->filter(fn ($item) => $item->memo)->map(fn ($item) => [
                    'id' => $item->memo->id,
                    'title' => $item->memo->memo_number ?? "Memo #{$item->memo->id}",
                    'status' => $item->is_returned ? 'returned' : 'on_memo',
                    'date' => $item->created_at->format('M j, Y'),
                    'due_date' => $item->effective_due_date?->format('M j, Y'),
                    'price' => $item->price,
                ])->unique('id')->values(),
                'repairs' => $product->repairItems->filter(fn ($item) => $item->repair)->map(fn ($item) => [
                    'id' => $item->repair->id,
                    'title' => $item->repair->repair_number ?? "Repair #{$item->repair->id}",
                    'status' => $item->status,
                    'date' => $item->created_at->format('M j, Y'),
                    'price' => $item->customer_cost,
                ])->unique('id')->values(),
            ],
        ]);
    }

    public function update(Request $request, Product $product): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store || $product->store_id !== $store->id) {
            abort(404);
        }

        // Get edition-based field requirements
        $fieldRequirements = $this->featureManager->getFieldRequirements($store, 'products');

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'handle' => 'nullable|string|max:255',
            'sku' => 'nullable|string|max:255',
            'upc' => 'nullable|string|max:255',
            'category_id' => ($fieldRequirements['category_id']['required'] ?? false ? 'required' : 'nullable').'|exists:categories,id',
            'vendor_id' => 'required|exists:vendors,id',
            'brand_id' => ($fieldRequirements['brand_id']['required'] ?? false ? 'required' : 'nullable').'|exists:brands,id',
            'tag_ids' => 'nullable|array',
            'tag_ids.*' => 'exists:tags,id',
            'template_id' => 'nullable|exists:product_templates,id',
            'status' => 'nullable|string|in:draft,active,awaiting_confirmation,sold,in_repair,in_memo,archive,in_bucket',
            'is_published' => 'boolean',
            'has_variants' => 'boolean',
            'track_quantity' => 'boolean',
            'sell_out_of_stock' => 'boolean',
            'charge_taxes' => 'boolean',
            'price_code' => 'nullable|string|max:50',
            'compare_at_price' => 'nullable|numeric|min:0',
            'weight' => 'nullable|numeric|min:0',
            'weight_unit' => 'nullable|string|max:10',
            'length' => 'nullable|numeric|min:0',
            'width' => 'nullable|numeric|min:0',
            'height' => 'nullable|numeric|min:0',
            'length_class' => 'nullable|string|max:10',
            'minimum_order' => 'nullable|integer|min:1',
            'seo_page_title' => 'nullable|string|max:70',
            'seo_description' => 'nullable|string|max:320',
            'variants' => 'required|array|min:1',
            'variants.*.id' => 'nullable|exists:product_variants,id',
            'variants.*.sku' => 'required|string|max:255',
            'variants.*.barcode' => 'nullable|string|max:255',
            'variants.*.price' => 'required|numeric|min:0',
            'variants.*.cost' => 'nullable|numeric|min:0',
            'variants.*.wholesale_price' => 'nullable|numeric|min:0',
            'variants.*.quantity' => 'required|integer|min:0',
            'variants.*.weight' => 'nullable|numeric|min:0',
            'variants.*.weight_unit' => 'nullable|string|max:10',
            'variants.*.option1_name' => 'nullable|string|max:255',
            'variants.*.option1_value' => 'nullable|string|max:255',
            'variants.*.option2_name' => 'nullable|string|max:255',
            'variants.*.option2_value' => 'nullable|string|max:255',
            'variants.*.option3_name' => 'nullable|string|max:255',
            'variants.*.option3_value' => 'nullable|string|max:255',
            'variants.*.warehouse_id' => 'nullable|exists:warehouses,id',
            'attributes' => 'nullable|array',
            'attributes.*' => 'nullable|string|max:65535',
            'images' => 'nullable|array',
            'images.*' => 'image|max:10240',
        ]);

        // Determine has_variants based on user input (not just count)
        $hasVariants = $validated['has_variants'] ?? (count($validated['variants']) > 1);

        // Determine status and publishing state
        $status = $validated['status'] ?? $product->status ?? Product::STATUS_DRAFT;
        $isPublished = $status === Product::STATUS_ACTIVE;
        $isDraft = $status === Product::STATUS_DRAFT;

        $product->update([
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'handle' => $validated['handle'] ?? $product->handle,
            'upc' => $validated['upc'] ?? null,
            'category_id' => $validated['category_id'] ?? null,
            'vendor_id' => $validated['vendor_id'] ?? null,
            'template_id' => $validated['template_id'] ?? null,
            'status' => $status,
            'is_published' => $isPublished,
            'is_draft' => $isDraft,
            'has_variants' => $hasVariants,
            'track_quantity' => $validated['track_quantity'] ?? true,
            'sell_out_of_stock' => $validated['sell_out_of_stock'] ?? false,
            'charge_taxes' => $validated['charge_taxes'] ?? true,
            'price_code' => $validated['price_code'] ?? null,
            'compare_at_price' => $validated['compare_at_price'] ?? null,
            'weight' => $validated['weight'] ?? null,
            'weight_unit' => $validated['weight_unit'] ?? 'lb',
            'length' => $validated['length'] ?? null,
            'width' => $validated['width'] ?? null,
            'height' => $validated['height'] ?? null,
            'length_class' => $validated['length_class'] ?? 'in',
            'minimum_order' => $validated['minimum_order'] ?? 1,
            'seo_page_title' => $validated['seo_page_title'] ?? null,
            'seo_description' => $validated['seo_description'] ?? null,
        ]);

        // Sync tags
        if (isset($validated['tag_ids'])) {
            $product->syncTags($validated['tag_ids']);
        }

        // Get default warehouse
        $defaultWarehouse = Warehouse::where('store_id', $store->id)
            ->where('is_default', true)
            ->first();

        // Update variants
        $existingIds = [];
        foreach ($validated['variants'] as $variantData) {
            $warehouseId = $variantData['warehouse_id'] ?? $defaultWarehouse?->id;

            // Clear option fields if has_variants is false
            $option1Name = $hasVariants ? ($variantData['option1_name'] ?? null) : null;
            $option1Value = $hasVariants ? ($variantData['option1_value'] ?? null) : null;
            $option2Name = $hasVariants ? ($variantData['option2_name'] ?? null) : null;
            $option2Value = $hasVariants ? ($variantData['option2_value'] ?? null) : null;
            $option3Name = $hasVariants ? ($variantData['option3_name'] ?? null) : null;
            $option3Value = $hasVariants ? ($variantData['option3_value'] ?? null) : null;

            if (isset($variantData['id'])) {
                $product->variants()->where('id', $variantData['id'])->update([
                    'sku' => $variantData['sku'],
                    'price' => $variantData['price'],
                    'cost' => $variantData['cost'] ?? null,
                    'wholesale_price' => $variantData['wholesale_price'] ?? null,
                    'quantity' => $variantData['quantity'],
                    'option1_name' => $option1Name,
                    'option1_value' => $option1Value,
                    'option2_name' => $option2Name,
                    'option2_value' => $option2Value,
                    'option3_name' => $option3Name,
                    'option3_value' => $option3Value,
                ]);
                $existingIds[] = $variantData['id'];

                // Update or create inventory record with adjustment tracking
                if ($warehouseId) {
                    $newQuantity = (int) $variantData['quantity'];
                    $inventory = Inventory::where('product_variant_id', $variantData['id'])
                        ->where('warehouse_id', $warehouseId)
                        ->first();

                    if ($inventory) {
                        $oldQuantity = $inventory->quantity;
                        $difference = $newQuantity - $oldQuantity;

                        // Update cost regardless
                        $inventory->unit_cost = $variantData['cost'] ?? $inventory->unit_cost;
                        $inventory->save();

                        // Create adjustment if quantity changed
                        if ($difference !== 0) {
                            $inventory->adjustQuantity(
                                $difference,
                                InventoryAdjustment::TYPE_CORRECTION,
                                $request->user()?->id,
                                'Product edit',
                                'Quantity updated via product edit page'
                            );
                        }
                    } else {
                        // Create new inventory with initial adjustment
                        $inventory = Inventory::create([
                            'store_id' => $store->id,
                            'product_variant_id' => $variantData['id'],
                            'warehouse_id' => $warehouseId,
                            'quantity' => 0, // Start at 0, adjustment will set correct quantity
                            'unit_cost' => $variantData['cost'] ?? null,
                        ]);

                        if ($newQuantity > 0) {
                            $inventory->adjustQuantity(
                                $newQuantity,
                                InventoryAdjustment::TYPE_INITIAL,
                                $request->user()?->id,
                                'Initial inventory',
                                'Initial quantity set via product edit page'
                            );
                        }
                    }
                }
            } else {
                $variant = $product->variants()->create([
                    'sku' => $variantData['sku'],
                    'price' => $variantData['price'],
                    'cost' => $variantData['cost'] ?? null,
                    'wholesale_price' => $variantData['wholesale_price'] ?? null,
                    'quantity' => $variantData['quantity'],
                    'option1_name' => $option1Name,
                    'option1_value' => $option1Value,
                    'option2_name' => $option2Name,
                    'option2_value' => $option2Value,
                    'option3_name' => $option3Name,
                    'option3_value' => $option3Value,
                ]);
                $existingIds[] = $variant->id;

                // Create inventory record for new variant with adjustment tracking
                if ($warehouseId) {
                    $newQuantity = (int) ($variantData['quantity'] ?? 0);
                    $inventory = Inventory::create([
                        'store_id' => $store->id,
                        'product_variant_id' => $variant->id,
                        'warehouse_id' => $warehouseId,
                        'quantity' => 0, // Start at 0, adjustment will set correct quantity
                        'unit_cost' => $variantData['cost'] ?? null,
                    ]);

                    if ($newQuantity > 0) {
                        $inventory->adjustQuantity(
                            $newQuantity,
                            InventoryAdjustment::TYPE_INITIAL,
                            $request->user()?->id,
                            'Initial inventory',
                            'Initial quantity set via product creation'
                        );
                    }
                }
            }
        }

        // Delete removed variants (inventory will cascade delete)
        $product->variants()->whereNotIn('id', $existingIds)->delete();

        // Update attribute values from template
        if (! empty($validated['attributes'])) {
            foreach ($validated['attributes'] as $fieldId => $value) {
                $product->setTemplateAttributeValue((int) $fieldId, $value);
            }
        }

        // Handle new image uploads
        if ($request->hasFile('images')) {
            $lastSortOrder = $product->images()->max('sort_order') ?? -1;
            $setFirstAsPrimary = ! $product->hasPrimaryImage();

            $this->imageService->uploadMultiple(
                files: $request->file('images'),
                imageable: $product,
                store: $store,
                folder: 'products',
                altText: $product->title,
                startSortOrder: $lastSortOrder + 1,
                setFirstAsPrimary: $setFirstAsPrimary
            );
        }

        return redirect()->route('products.show', $product)
            ->with('success', 'Product updated successfully.');
    }

    public function destroy(Product $product): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store || $product->store_id !== $store->id) {
            abort(404);
        }

        $product->delete();

        return redirect()->route('products.index')
            ->with('success', 'Product deleted successfully.');
    }

    public function printBarcode(Product $product): Response|RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store || $product->store_id !== $store->id) {
            abort(404);
        }

        $product->load(['variants', 'category', 'attributeValues.field']);

        // Get effective barcode attributes from category
        $barcodeAttributes = $product->category?->getEffectiveBarcodeAttributes() ?? ['price_code', 'category', 'price'];

        // Get template field values for the product
        $templateFieldValues = [];
        $template = $product->getTemplate();
        if ($template) {
            $template->load('fields.options');
            foreach ($product->attributeValues as $attrValue) {
                $field = $attrValue->field;
                if ($field) {
                    $storedValue = $attrValue->value;
                    $displayValue = $storedValue;

                    // Map to label for select/radio/checkbox fields
                    if ($storedValue && in_array($field->type, [ProductTemplateField::TYPE_SELECT, ProductTemplateField::TYPE_RADIO, ProductTemplateField::TYPE_CHECKBOX])) {
                        $option = $field->options->firstWhere('value', $storedValue);
                        $displayValue = $option?->label ?? $storedValue;
                    }

                    // For brand fields, get the brand name
                    if ($field->type === ProductTemplateField::TYPE_BRAND && $storedValue) {
                        $brand = Brand::find($storedValue);
                        $displayValue = $brand?->name ?? $storedValue;
                    }

                    $templateFieldValues[$field->name] = $displayValue;
                    // Also store by canonical name for easier matching
                    if ($field->canonical_name) {
                        $templateFieldValues[$field->canonical_name] = $displayValue;
                    }
                }
            }
        }

        $printerSettings = PrinterSetting::where('store_id', $store->id)
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get()
            ->map(fn (PrinterSetting $setting) => [
                'id' => $setting->id,
                'name' => $setting->name,
                'ip_address' => $setting->ip_address,
                'port' => $setting->port,
                'top_offset' => $setting->top_offset,
                'left_offset' => $setting->left_offset,
                'right_offset' => $setting->right_offset,
                'text_size' => $setting->text_size,
                'barcode_height' => $setting->barcode_height,
                'line_height' => $setting->line_height,
                'label_width' => $setting->label_width,
                'label_height' => $setting->label_height,
                'is_default' => $setting->is_default,
                'network_print_enabled' => $setting->isNetworkPrintingEnabled(),
            ]);

        return Inertia::render('products/PrintBarcode', [
            'product' => [
                'id' => $product->id,
                'title' => $product->title,
                'sku' => $product->sku,
                'price_code' => $product->price_code,
                'category' => $product->category?->name,
                'variants' => $product->variants->map(fn (ProductVariant $variant) => [
                    'id' => $variant->id,
                    'sku' => $variant->sku,
                    'barcode' => $variant->barcode,
                    'title' => $variant->options_title ?: null,
                    'price' => $variant->price,
                ]),
            ],
            'barcodeAttributes' => $barcodeAttributes,
            'templateFieldValues' => $templateFieldValues,
            'printerSettings' => $printerSettings,
        ]);
    }

    /**
     * Bulk action on products.
     */
    public function bulkAction(Request $request): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return redirect()->route('dashboard')
                ->with('error', 'Please select a store first.');
        }

        $validated = $request->validate([
            'action' => 'required|string|in:delete,activate,archive,draft',
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|exists:products,id',
        ]);

        $products = Product::where('store_id', $store->id)
            ->whereIn('id', $validated['ids'])
            ->get();

        $count = $products->count();

        match ($validated['action']) {
            'delete' => $products->each->delete(),
            'activate' => $products->each->update(['status' => Product::STATUS_ACTIVE]),
            'archive' => $products->each->update(['status' => Product::STATUS_ARCHIVE]),
            'draft' => $products->each->update(['status' => Product::STATUS_DRAFT]),
        };

        $actionLabel = match ($validated['action']) {
            'delete' => 'deleted',
            'activate' => 'set to active',
            'archive' => 'archived',
            'draft' => 'set to draft',
        };

        return redirect()->route('products.index')
            ->with('success', "{$count} product(s) {$actionLabel} successfully.");
    }

    /**
     * Delete a product image.
     */
    public function deleteImage(Product $product, Image $image): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store || $product->store_id !== $store->id || $image->imageable_id !== $product->id) {
            abort(404);
        }

        // Track if this was the primary image
        $wasPrimary = $image->is_primary;

        // Delete from storage and database
        $this->imageService->delete($image);

        // If this was the primary image, make another one primary
        if ($wasPrimary) {
            $newPrimary = $product->images()->orderBy('sort_order')->first();
            if ($newPrimary) {
                $newPrimary->update(['is_primary' => true]);
            }
        }

        return redirect()->back()->with('success', 'Image deleted successfully.');
    }

    /**
     * Set a product image as primary.
     */
    public function setPrimaryImage(Product $product, Image $image): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store || $product->store_id !== $store->id || $image->imageable_id !== $product->id) {
            abort(404);
        }

        // Use the trait method to set primary
        $product->setPrimaryImage($image);

        return redirect()->back()->with('success', 'Primary image updated.');
    }

    /**
     * Bulk update products with common fields.
     */
    public function bulkUpdate(BulkUpdateProductsRequest $request): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return redirect()->route('dashboard')
                ->with('error', 'Please select a store first.');
        }

        $validated = $request->validated();

        // Get only products that belong to this store
        $products = Product::where('store_id', $store->id)
            ->whereIn('id', $validated['ids'])
            ->with('variants')
            ->get();

        if ($products->isEmpty()) {
            return redirect()->route('products.index')
                ->with('error', 'No valid products found to update.');
        }

        // Separate variant fields from product fields
        $variantFields = ['price', 'wholesale_price', 'cost'];
        $variantData = collect($validated)
            ->only($variantFields)
            ->filter(fn ($value) => $value !== null)
            ->toArray();

        // Build update data from provided fields (excluding ids and variant fields)
        $updateData = collect($validated)
            ->except(['ids', ...$variantFields])
            ->filter(fn ($value) => $value !== null)
            ->toArray();

        if (empty($updateData) && empty($variantData)) {
            return redirect()->route('products.index')
                ->with('error', 'No fields provided to update.');
        }

        // Handle is_published -> is_draft sync
        if (isset($updateData['is_published'])) {
            $updateData['is_draft'] = ! $updateData['is_published'];
        }

        // Update all products
        $count = 0;
        foreach ($products as $product) {
            // Update product fields
            if (! empty($updateData)) {
                $product->update($updateData);
            }

            // Update variant fields (price, wholesale_price, cost)
            if (! empty($variantData)) {
                foreach ($product->variants as $variant) {
                    $variant->update($variantData);
                }
            }

            $count++;
        }

        $totalFieldCount = count($updateData) + count($variantData);
        $fieldLabel = $totalFieldCount === 1 ? 'field' : 'fields';

        return redirect()->route('products.index')
            ->with('success', "{$count} product(s) updated ({$totalFieldCount} {$fieldLabel} changed).");
    }

    /**
     * Bulk inline update - each product can have different values.
     */
    public function bulkInlineUpdate(Request $request): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return redirect()->route('dashboard')
                ->with('error', 'Please select a store first.');
        }

        $validated = $request->validate([
            'products' => ['required', 'array', 'min:1'],
            'products.*.id' => ['required', 'integer', 'exists:products,id'],
            'products.*.title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'products.*.price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'products.*.wholesale_price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'products.*.cost' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'products.*.category_id' => ['sometimes', 'nullable', 'integer', "exists:categories,id,store_id,{$store->id}"],
            'products.*.vendor_id' => ['sometimes', 'nullable', 'integer', "exists:vendors,id,store_id,{$store->id}"],
            'products.*.status' => ['sometimes', 'nullable', 'string', 'in:draft,active,archive,sold'],
        ]);

        $productIds = collect($validated['products'])->pluck('id')->toArray();

        // Get only products that belong to this store
        $products = Product::where('store_id', $store->id)
            ->whereIn('id', $productIds)
            ->with('variants')
            ->get()
            ->keyBy('id');

        if ($products->isEmpty()) {
            return redirect()->route('products.index')
                ->with('error', 'No valid products found to update.');
        }

        $count = 0;
        foreach ($validated['products'] as $productData) {
            $product = $products->get($productData['id']);
            if (! $product) {
                continue;
            }

            // Separate product fields from variant fields
            $productFields = ['title', 'category_id', 'vendor_id', 'status'];
            $variantFields = ['price', 'wholesale_price', 'cost'];

            $productUpdate = [];
            $variantUpdate = [];

            foreach ($productData as $key => $value) {
                if ($key === 'id') {
                    continue;
                }
                if (in_array($key, $productFields)) {
                    $productUpdate[$key] = $value;
                } elseif (in_array($key, $variantFields)) {
                    $variantUpdate[$key] = $value;
                }
            }

            // Update product fields
            if (! empty($productUpdate)) {
                $product->update($productUpdate);
            }

            // Update variant fields
            if (! empty($variantUpdate)) {
                foreach ($product->variants as $variant) {
                    $variant->update($variantUpdate);
                }
            }

            $count++;
        }

        return redirect()->route('products.index')
            ->with('success', "{$count} product(s) updated.");
    }

    /**
     * Get products for inline editing.
     */
    public function getForInlineEdit(Request $request): JsonResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return response()->json(['error' => 'Store not found'], 404);
        }

        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        $products = Product::where('store_id', $store->id)
            ->whereIn('id', $validated['ids'])
            ->with(['variants', 'category', 'brand', 'vendor', 'template'])
            ->get()
            ->map(fn ($product) => [
                'id' => $product->id,
                'title' => $product->title,
                'category_id' => $product->category_id,
                'category_name' => $product->category?->name,
                'brand_id' => $product->brand_id,
                'brand_name' => $product->brand?->name,
                'vendor_id' => $product->vendor_id,
                'vendor_name' => $product->vendor?->name,
                'price' => $product->variants->first()?->price,
                'wholesale_price' => $product->variants->first()?->wholesale_price,
                'cost' => $product->variants->first()?->cost,
                'status' => $product->status,
                'is_published' => $product->is_published,
                'template_name' => $product->template?->name,
            ]);

        return response()->json(['products' => $products]);
    }

    /**
     * Generate SKU for a product based on category's SKU format.
     */
    public function generateSku(Request $request): JsonResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return response()->json(['error' => 'Store not found'], 404);
        }

        $validated = $request->validate([
            'category_id' => 'required|exists:categories,id',
            'product_id' => 'nullable|exists:products,id',
        ]);

        $category = Category::where('store_id', $store->id)
            ->where('id', $validated['category_id'])
            ->first();

        if (! $category) {
            return response()->json(['error' => 'Category not found'], 404);
        }

        $skuFormat = $category->getEffectiveSkuFormat();

        if (! $skuFormat) {
            return response()->json([
                'error' => 'Category has no SKU format configured',
                'sku' => null,
            ], 400);
        }

        $skuGenerator = new SkuGeneratorService;

        // For preview/generation without a real product, we create a temporary one
        if (! empty($validated['product_id'])) {
            $product = Product::where('store_id', $store->id)
                ->where('id', $validated['product_id'])
                ->first();

            if (! $product) {
                return response()->json(['error' => 'Product not found'], 404);
            }
        } else {
            // Create a temporary product for SKU generation preview
            // We'll use a fake product with ID based on next potential product
            $product = new Product;
            $product->id = (Product::max('id') ?? 0) + 1;
            $product->store_id = $store->id;
            $product->setAttribute('store', $store);
        }

        try {
            $sku = $skuGenerator->generate($category, $product, null, $store);

            return response()->json([
                'sku' => $sku,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
                'sku' => null,
            ], 400);
        }
    }

    /**
     * Preview SKU format for a category (without incrementing sequence).
     */
    public function previewCategorySku(Request $request): JsonResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return response()->json(['error' => 'Store not found'], 404);
        }

        $validated = $request->validate([
            'category_id' => 'required|exists:categories,id',
        ]);

        $category = Category::where('store_id', $store->id)
            ->where('id', $validated['category_id'])
            ->first();

        if (! $category) {
            return response()->json(['error' => 'Category not found'], 404);
        }

        $skuFormat = $category->getEffectiveSkuFormat();

        if (! $skuFormat) {
            return response()->json([
                'has_format' => false,
                'preview' => null,
            ]);
        }

        $skuGenerator = new SkuGeneratorService;
        $preview = $skuGenerator->preview($category);

        return response()->json([
            'has_format' => true,
            'preview' => $preview,
            'format' => $skuFormat,
        ]);
    }

    /**
     * Lookup a product by barcode or SKU for global barcode scanning.
     */
    public function lookupBarcode(Request $request): JsonResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return response()->json([
                'found' => false,
                'error' => 'Store not found',
            ], 404);
        }

        $barcode = $request->query('barcode');

        if (empty($barcode)) {
            return response()->json([
                'found' => false,
                'error' => 'No barcode provided',
            ], 400);
        }

        // Look for a product variant with matching barcode or SKU
        $variant = ProductVariant::query()
            ->whereHas('product', function ($query) use ($store) {
                $query->where('store_id', $store->id);
            })
            ->where(function ($query) use ($barcode) {
                $query->where('barcode', $barcode)
                    ->orWhere('sku', $barcode);
            })
            ->with('product:id,title,handle')
            ->first();

        if (! $variant) {
            return response()->json([
                'found' => false,
                'product' => null,
            ]);
        }

        return response()->json([
            'found' => true,
            'product' => [
                'id' => $variant->product->id,
                'variant_id' => $variant->id,
                'title' => $variant->product->title,
                'sku' => $variant->sku,
                'barcode' => $variant->barcode,
            ],
        ]);
    }
}
