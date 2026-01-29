<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\BulkUpdateProductsRequest;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Image;
use App\Models\Inventory;
use App\Models\PrinterSetting;
use App\Models\Product;
use App\Models\ProductTemplateField;
use App\Models\ProductVariant;
use App\Models\Tag;
use App\Models\Warehouse;
use App\Services\ActivityLogFormatter;
use App\Services\Image\ImageService;
use App\Services\Sku\SkuGeneratorService;
use App\Services\StoreContext;
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
        protected ImageService $imageService
    ) {}

    public function index(Request $request): Response|RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return redirect()->route('dashboard')
                ->with('error', 'Please select a store first.');
        }

        // Get categories for filters (with hierarchy support)
        $categories = Category::where('store_id', $store->id)
            ->orderBy('name')
            ->get(['id', 'name', 'parent_id']);

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
            'brands' => $brands,
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

        return $sizes->merge($productRingSizes)
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

        return Inertia::render('products/Create', [
            'categories' => $categories,
            'brands' => $brands,
            'warehouses' => $warehouses,
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
            'is_published' => $validated['is_published'] ?? false,
            'is_draft' => ! ($validated['is_published'] ?? false),
            'has_variants' => $hasVariants,
            'track_quantity' => $validated['track_quantity'] ?? true,
            'sell_out_of_stock' => $validated['sell_out_of_stock'] ?? false,
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

            // Create inventory record if quantity > 0
            if (($variantData['quantity'] ?? 0) > 0) {
                $warehouseId = $variantData['warehouse_id'] ?? $defaultWarehouse?->id;

                if ($warehouseId) {
                    Inventory::create([
                        'store_id' => $store->id,
                        'product_variant_id' => $variant->id,
                        'warehouse_id' => $warehouseId,
                        'quantity' => $variantData['quantity'],
                        'unit_cost' => $variantData['cost'] ?? null,
                    ]);
                }
            }
        }

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

        return redirect()->route('products.show', $product)
            ->with('success', 'Product created successfully.');
    }

    public function show(Product $product): Response|RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store || $product->store_id !== $store->id) {
            abort(404);
        }

        $product->load(['category', 'brand', 'variants', 'images', 'tags', 'attributeValues.field']);

        // Get template and fields with values
        $template = null;
        $templateFieldsWithValues = [];

        if ($product->template_id) {
            $template = $product->template;
        } elseif ($product->category) {
            $template = $product->category->getEffectiveTemplate();
        }

        if ($template) {
            $template->load('fields');
            $attributeValues = $product->attributeValues->keyBy('product_template_field_id');

            $templateFieldsWithValues = $template->fields->map(function ($field) use ($attributeValues) {
                $attrValue = $attributeValues->get($field->id);

                return [
                    'id' => $field->id,
                    'label' => $field->label,
                    'name' => $field->name,
                    'type' => $field->type,
                    'value' => $attrValue?->value,
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
        ]);
    }

    public function edit(Product $product): Response|RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store || $product->store_id !== $store->id) {
            abort(404);
        }

        $product->load(['category', 'brand', 'variants', 'images', 'attributeValues', 'tags']);

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
                'is_published' => $product->is_published,
                'is_draft' => $product->is_draft,
                'has_variants' => $product->has_variants,
                'track_quantity' => $product->track_quantity,
                'sell_out_of_stock' => $product->sell_out_of_stock,
                'charge_taxes' => $product->charge_taxes,
                'category_id' => $product->category_id,
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
        ]);
    }

    public function update(Request $request, Product $product): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store || $product->store_id !== $store->id) {
            abort(404);
        }

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'handle' => 'nullable|string|max:255',
            'sku' => 'nullable|string|max:255',
            'upc' => 'nullable|string|max:255',
            'category_id' => 'nullable|exists:categories,id',
            'vendor_id' => 'nullable|exists:vendors,id',
            'tag_ids' => 'nullable|array',
            'tag_ids.*' => 'exists:tags,id',
            'template_id' => 'nullable|exists:product_templates,id',
            'is_published' => 'boolean',
            'has_variants' => 'boolean',
            'track_quantity' => 'boolean',
            'sell_out_of_stock' => 'boolean',
            'charge_taxes' => 'boolean',
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

        $product->update([
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'handle' => $validated['handle'] ?? $product->handle,
            'upc' => $validated['upc'] ?? null,
            'category_id' => $validated['category_id'] ?? null,
            'vendor_id' => $validated['vendor_id'] ?? null,
            'template_id' => $validated['template_id'] ?? null,
            'is_published' => $validated['is_published'] ?? false,
            'is_draft' => ! ($validated['is_published'] ?? false),
            'has_variants' => $hasVariants,
            'track_quantity' => $validated['track_quantity'] ?? true,
            'sell_out_of_stock' => $validated['sell_out_of_stock'] ?? false,
            'charge_taxes' => $validated['charge_taxes'] ?? true,
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

                // Update or create inventory record
                if ($warehouseId) {
                    Inventory::updateOrCreate(
                        [
                            'product_variant_id' => $variantData['id'],
                            'warehouse_id' => $warehouseId,
                        ],
                        [
                            'store_id' => $store->id,
                            'quantity' => $variantData['quantity'],
                            'unit_cost' => $variantData['cost'] ?? null,
                        ]
                    );
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

                // Create inventory record for new variant
                if ($warehouseId && ($variantData['quantity'] ?? 0) > 0) {
                    Inventory::create([
                        'store_id' => $store->id,
                        'product_variant_id' => $variant->id,
                        'warehouse_id' => $warehouseId,
                        'quantity' => $variantData['quantity'],
                        'unit_cost' => $variantData['cost'] ?? null,
                    ]);
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

        $product->load(['variants', 'category']);

        $printerSettings = PrinterSetting::where('store_id', $store->id)
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get()
            ->map(fn (PrinterSetting $setting) => [
                'id' => $setting->id,
                'name' => $setting->name,
                'top_offset' => $setting->top_offset,
                'left_offset' => $setting->left_offset,
                'right_offset' => $setting->right_offset,
                'text_size' => $setting->text_size,
                'barcode_height' => $setting->barcode_height,
                'line_height' => $setting->line_height,
                'label_width' => $setting->label_width,
                'label_height' => $setting->label_height,
                'is_default' => $setting->is_default,
            ]);

        return Inertia::render('products/PrintBarcode', [
            'product' => [
                'id' => $product->id,
                'title' => $product->title,
                'sku' => $product->sku,
                'category' => $product->category?->name,
                'variants' => $product->variants->map(fn (ProductVariant $variant) => [
                    'id' => $variant->id,
                    'sku' => $variant->sku,
                    'barcode' => $variant->barcode,
                    'title' => $variant->options_title ?: null,
                    'price' => $variant->price,
                ]),
            ],
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
            'action' => 'required|string|in:delete,publish,unpublish',
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|exists:products,id',
        ]);

        $products = Product::where('store_id', $store->id)
            ->whereIn('id', $validated['ids'])
            ->get();

        $count = $products->count();

        match ($validated['action']) {
            'delete' => $products->each->delete(),
            'publish' => $products->each->update(['is_published' => true, 'is_draft' => false]),
            'unpublish' => $products->each->update(['is_published' => false, 'is_draft' => true]),
        };

        $actionLabel = match ($validated['action']) {
            'delete' => 'deleted',
            'publish' => 'published',
            'unpublish' => 'unpublished',
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
            ->get();

        if ($products->isEmpty()) {
            return redirect()->route('products.index')
                ->with('error', 'No valid products found to update.');
        }

        // Build update data from provided fields (excluding ids)
        $updateData = collect($validated)
            ->except('ids')
            ->filter(fn ($value) => $value !== null)
            ->toArray();

        if (empty($updateData)) {
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
            $product->update($updateData);
            $count++;
        }

        $fieldCount = count($updateData);
        $fieldLabel = $fieldCount === 1 ? 'field' : 'fields';

        return redirect()->route('products.index')
            ->with('success', "{$count} product(s) updated ({$fieldCount} {$fieldLabel} changed).");
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
