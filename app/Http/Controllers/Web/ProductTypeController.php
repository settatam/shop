<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Bucket;
use App\Models\Category;
use App\Models\LabelTemplate;
use App\Models\ProductTemplate;
use App\Models\ProductTemplateField;
use App\Rules\ValidSkuFormat;
use App\Services\Sku\SkuGeneratorService;
use App\Services\StoreContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProductTypeController extends Controller
{
    public function __construct(protected StoreContext $storeContext) {}

    /**
     * Display the list of product types (leaf categories).
     */
    public function index(): Response|RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return redirect()->route('dashboard')
                ->with('error', 'Please select a store first.');
        }

        return Inertia::render('product-types/Index', [
            'storeId' => $store->id,
        ]);
    }

    /**
     * Show the settings page for a product type (leaf category).
     */
    public function settings(Category $category): Response|RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store || $category->store_id !== $store->id) {
            abort(404);
        }

        // Only leaf categories can be product types
        if (! $category->isLeaf()) {
            return redirect()->route('product-types.index')
                ->with('error', 'Only leaf categories (categories without children) can be configured as product types.');
        }

        $category->load(['template:id,name', 'labelTemplate:id,name', 'defaultBucket:id,name', 'skuSequence', 'parent']);

        // Get templates for dropdown
        $templates = ProductTemplate::where('store_id', $store->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        // Get label templates for dropdown
        $labelTemplates = LabelTemplate::where('store_id', $store->id)
            ->where('type', LabelTemplate::TYPE_PRODUCT)
            ->orderBy('name')
            ->get(['id', 'name']);

        // Get buckets for dropdown
        $buckets = Bucket::where('store_id', $store->id)
            ->orderBy('name')
            ->get(['id', 'name']);

        // Get SKU format preview
        $skuGenerator = new SkuGeneratorService;
        $skuPreview = $category->sku_format ? $skuGenerator->preview($category) : null;

        // Get available variables for the format builder
        $availableVariables = SkuGeneratorService::getAvailableVariables();

        // Get available barcode attributes
        $availableAttributes = $this->getAvailableBarcodeAttributes($category);

        return Inertia::render('product-types/Settings', [
            'category' => [
                'id' => $category->id,
                'name' => $category->name,
                'full_path' => $category->full_path,
                'products_count' => $category->products()->count(),
                'template_id' => $category->template_id,
                'template_name' => $category->template?->name,
                'effective_template_name' => $category->getEffectiveTemplate()?->name,
                'sku_format' => $category->sku_format,
                'sku_prefix' => $category->sku_prefix,
                'sku_suffix' => $category->sku_suffix,
                'effective_sku_format' => $category->getEffectiveSkuFormat(),
                'effective_sku_prefix' => $category->getEffectiveSkuPrefix(),
                'effective_sku_suffix' => $category->getEffectiveSkuSuffix(),
                'default_bucket_id' => $category->default_bucket_id,
                'default_bucket_name' => $category->defaultBucket?->name,
                'effective_default_bucket_name' => $category->getEffectiveDefaultBucket()?->name,
                'barcode_attributes' => $category->barcode_attributes ?? [],
                'effective_barcode_attributes' => $category->getEffectiveBarcodeAttributes(),
                'label_template_id' => $category->label_template_id,
                'label_template_name' => $category->labelTemplate?->name,
                'effective_label_template_name' => $category->getEffectiveLabelTemplate()?->name,
                'current_sequence' => $category->skuSequence?->current_value ?? 0,
            ],
            'templates' => $templates,
            'labelTemplates' => $labelTemplates,
            'buckets' => $buckets,
            'skuPreview' => $skuPreview,
            'availableVariables' => $availableVariables,
            'availableAttributes' => $availableAttributes,
        ]);
    }

    /**
     * Update settings for a product type (leaf category).
     */
    public function updateSettings(Request $request, Category $category): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store || $category->store_id !== $store->id) {
            abort(404);
        }

        if (! $category->isLeaf()) {
            return redirect()->route('product-types.index')
                ->with('error', 'Only leaf categories can have settings configured.');
        }

        $validated = $request->validate([
            'template_id' => 'nullable|integer|exists:product_templates,id',
            'sku_format' => ['nullable', 'string', 'max:255', new ValidSkuFormat],
            'sku_prefix' => 'nullable|string|max:50',
            'sku_suffix' => 'nullable|string|max:50',
            'default_bucket_id' => 'nullable|integer|exists:buckets,id',
            'barcode_attributes' => 'nullable|array',
            'barcode_attributes.*' => 'string|max:100',
            'label_template_id' => 'nullable|integer|exists:label_templates,id',
        ]);

        // Validate default_bucket_id belongs to the store
        if (! empty($validated['default_bucket_id'])) {
            $bucket = Bucket::where('id', $validated['default_bucket_id'])
                ->where('store_id', $store->id)
                ->first();

            if (! $bucket) {
                return redirect()->back()->withErrors(['default_bucket_id' => 'The selected bucket does not belong to this store.']);
            }
        }

        $category->update($validated);

        return redirect()->route('product-types.settings', $category)
            ->with('success', 'Product type settings updated successfully.');
    }

    /**
     * Get available barcode attributes for a category.
     */
    public function getAvailableAttributes(Category $category): JsonResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store || $category->store_id !== $store->id) {
            return response()->json(['error' => 'Category not found'], 404);
        }

        return response()->json([
            'attributes' => $this->getAvailableBarcodeAttributes($category),
        ]);
    }

    /**
     * Build the list of available barcode attributes for a category.
     *
     * @return array<string, array<int, array<string, string>>>
     */
    protected function getAvailableBarcodeAttributes(Category $category): array
    {
        $builtIn = [
            ['key' => 'category', 'label' => 'Category Name', 'description' => 'The product\'s category name'],
            ['key' => 'sku', 'label' => 'Product SKU', 'description' => 'The product variant SKU'],
            ['key' => 'price', 'label' => 'Price', 'description' => 'The product price'],
            ['key' => 'price_code', 'label' => 'Price Code', 'description' => 'Custom price code if set'],
            ['key' => 'material', 'label' => 'Metal Type/Material', 'description' => 'The metal type or material'],
            ['key' => 'weight', 'label' => 'Weight', 'description' => 'The product weight'],
            ['key' => 'condition', 'label' => 'Condition', 'description' => 'The product condition'],
            ['key' => 'title', 'label' => 'Product Title', 'description' => 'The product title'],
            ['key' => 'brand', 'label' => 'Brand', 'description' => 'The brand name'],
        ];

        // Get template fields from the effective template
        $templateFields = [];
        $effectiveTemplate = $category->getEffectiveTemplate();

        if ($effectiveTemplate) {
            $fields = ProductTemplateField::where('product_template_id', $effectiveTemplate->id)
                ->orderBy('group_position')
                ->orderBy('sort_order')
                ->get();

            foreach ($fields as $field) {
                // Skip brand type as it's already in built-in
                if ($field->type === ProductTemplateField::TYPE_BRAND) {
                    continue;
                }

                $templateFields[] = [
                    'key' => 'template_'.$field->id,
                    'label' => $field->label ?: $field->name,
                    'description' => 'Template field: '.$field->name,
                    'canonical_name' => $field->canonical_name,
                ];
            }
        }

        return [
            'built_in' => $builtIn,
            'template' => $templateFields,
        ];
    }
}
