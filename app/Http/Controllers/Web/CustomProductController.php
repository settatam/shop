<?php

namespace App\Http\Controllers\Web;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\ProductTemplateField;
use App\Models\ProductVariant;
use App\Models\Tag;
use App\Models\Vendor;
use App\Models\Warehouse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CustomProductController extends ProductController
{
    /**
     * Display a listing of products with custom search features.
     */
    public function index(Request $request): Response|RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return redirect()->route('dashboard')
                ->with('error', 'Please select a store first.');
        }

        // Get categories and brands for filters
        $categories = Category::where('store_id', $store->id)
            ->orderBy('name')
            ->get(['id', 'name']);

        $brands = Brand::where('store_id', $store->id)
            ->orderBy('name')
            ->get(['id', 'name']);

        // Get warehouses for GIA scanner
        $warehouses = Warehouse::where('store_id', $store->id)
            ->where('is_active', true)
            ->orderBy('priority')
            ->get(['id', 'name', 'code', 'is_default']);

        // Get vendors for filter
        $vendors = Vendor::where('store_id', $store->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'company_name']);

        // Get tags for filter
        $tags = Tag::where('store_id', $store->id)
            ->orderBy('name')
            ->get(['id', 'name', 'color']);

        return Inertia::render('products-custom/Index', [
            'categories' => $categories,
            'brands' => $brands,
            'warehouses' => $warehouses,
            'vendors' => $vendors,
            'tags' => $tags,
        ]);
    }

    /**
     * Display the specified product with custom features.
     */
    public function show(Product $product): Response|RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store || $product->store_id !== $store->id) {
            abort(404);
        }

        $product->load(['category', 'brand', 'vendor', 'variants', 'images', 'tags']);

        return Inertia::render('products-custom/Show', [
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
                    'company_name' => $product->vendor->company_name,
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
        ]);
    }

    /**
     * Show the form for editing the specified product with custom features.
     */
    public function edit(Product $product): Response|RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store || $product->store_id !== $store->id) {
            abort(404);
        }

        $product->load(['category', 'brand', 'vendor', 'variants', 'images', 'attributeValues', 'tags']);

        $categories = Category::where('store_id', $store->id)
            ->with('template')
            ->orderBy('level')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'parent_id', 'level', 'template_id'])
            ->map(fn (Category $category) => [
                'id' => $category->id,
                'name' => $category->name,
                'full_path' => $category->full_path,
                'parent_id' => $category->parent_id,
                'level' => $category->level ?? 0,
                'template_id' => $category->template_id,
            ]);

        $brands = Brand::where('store_id', $store->id)
            ->orderBy('name')
            ->get(['id', 'name']);

        $warehouses = Warehouse::where('store_id', $store->id)
            ->where('is_active', true)
            ->orderBy('priority')
            ->get(['id', 'name', 'code', 'is_default']);

        // Get vendors for selection
        $vendors = Vendor::where('store_id', $store->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'company_name']);

        // Get tags for selection
        $availableTags = Tag::where('store_id', $store->id)
            ->orderBy('name')
            ->get(['id', 'name', 'color']);

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

        return Inertia::render('products-custom/Edit', [
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
                'category_id' => $product->category_id,
                'vendor_id' => $product->vendor_id,
                'template_id' => $product->template_id,
                'tag_ids' => $product->tags->pluck('id')->toArray(),
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
            'vendors' => $vendors,
            'availableTags' => $availableTags,
        ]);
    }
}
