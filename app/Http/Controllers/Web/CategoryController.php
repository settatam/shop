<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Category;
use App\Models\LabelTemplate;
use App\Models\ProductTemplate;
use App\Models\ProductTemplateField;
use App\Models\SkuSequence;
use App\Rules\ValidSkuFormat;
use App\Services\Sku\SkuGeneratorService;
use App\Services\StoreContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CategoryController extends Controller
{
    public function __construct(protected StoreContext $storeContext) {}

    public function index(): Response|RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return redirect()->route('dashboard')
                ->with('error', 'Please select a store first.');
        }

        // Get categories as a tree structure
        $categories = $this->getCategoryTree($store->id);

        // Get templates for assignment dropdown
        $templates = ProductTemplate::where('store_id', $store->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        return Inertia::render('categories/Index', [
            'categories' => $categories,
            'templates' => $templates,
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
            'name' => 'required|string|max:191',
            'slug' => 'nullable|string|max:80',
            'description' => 'nullable|string|max:191',
            'parent_id' => 'nullable|integer|exists:categories,id',
            'template_id' => 'nullable|integer|exists:product_templates,id',
        ]);

        // Generate slug if not provided
        if (empty($validated['slug'])) {
            $validated['slug'] = str($validated['name'])->slug();
        }

        // Calculate level based on parent
        $level = 0;
        if (! empty($validated['parent_id'])) {
            $parent = Category::find($validated['parent_id']);
            if ($parent) {
                $level = ($parent->level ?? 0) + 1;
            }
        }

        // Get max sort order for siblings
        $maxSortOrder = Category::where('store_id', $store->id)
            ->where('parent_id', $validated['parent_id'] ?? null)
            ->max('sort_order') ?? 0;

        Category::create([
            ...$validated,
            'store_id' => $store->id,
            'level' => $level,
            'sort_order' => $maxSortOrder + 1,
        ]);

        return redirect()->route('categories.index')
            ->with('success', 'Category created successfully.');
    }

    public function update(Request $request, Category $category): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store || $category->store_id !== $store->id) {
            abort(404);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:191',
            'slug' => 'nullable|string|max:80',
            'description' => 'nullable|string|max:191',
            'parent_id' => 'nullable|integer|exists:categories,id',
            'template_id' => 'nullable|integer|exists:product_templates,id',
        ]);

        // Prevent setting parent to self or descendants
        if (! empty($validated['parent_id'])) {
            if ($validated['parent_id'] == $category->id) {
                return redirect()->back()->withErrors(['parent_id' => 'A category cannot be its own parent.']);
            }

            // Check if new parent is a descendant
            if ($this->isDescendant($category->id, $validated['parent_id'])) {
                return redirect()->back()->withErrors(['parent_id' => 'Cannot move a category under its own descendant.']);
            }
        }

        // Calculate new level
        $level = 0;
        if (! empty($validated['parent_id'])) {
            $parent = Category::find($validated['parent_id']);
            if ($parent) {
                $level = ($parent->level ?? 0) + 1;
            }
        }

        $category->update([
            ...$validated,
            'level' => $level,
        ]);

        // Update descendant levels if parent changed
        $this->updateDescendantLevels($category);

        return redirect()->route('categories.index')
            ->with('success', 'Category updated successfully.');
    }

    public function destroy(Category $category): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store || $category->store_id !== $store->id) {
            abort(404);
        }

        // Check if category has children
        if ($category->children()->count() > 0) {
            return redirect()->back()->withErrors(['category' => 'Cannot delete a category that has subcategories.']);
        }

        // Check if category has products
        if ($category->products()->count() > 0) {
            return redirect()->back()->withErrors(['category' => 'Cannot delete a category that has products.']);
        }

        $category->delete();

        return redirect()->route('categories.index')
            ->with('success', 'Category deleted successfully.');
    }

    public function reorder(Request $request): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return redirect()->route('dashboard')
                ->with('error', 'Please select a store first.');
        }

        $validated = $request->validate([
            'categories' => 'required|array',
            'categories.*.id' => 'required|integer|exists:categories,id',
            'categories.*.parent_id' => 'nullable|integer|exists:categories,id',
            'categories.*.sort_order' => 'required|integer|min:0',
        ]);

        foreach ($validated['categories'] as $item) {
            $category = Category::where('store_id', $store->id)
                ->where('id', $item['id'])
                ->first();

            if ($category) {
                $level = 0;
                if (! empty($item['parent_id'])) {
                    $parent = Category::find($item['parent_id']);
                    if ($parent) {
                        $level = ($parent->level ?? 0) + 1;
                    }
                }

                $category->update([
                    'parent_id' => $item['parent_id'],
                    'sort_order' => $item['sort_order'],
                    'level' => $level,
                ]);

                $this->updateDescendantLevels($category);
            }
        }

        return redirect()->route('categories.index')
            ->with('success', 'Categories reordered successfully.');
    }

    /**
     * Get categories as a tree structure.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function getCategoryTree(int $storeId): array
    {
        $categories = Category::where('store_id', $storeId)
            ->with('template:id,name')
            ->orderBy('sort_order')
            ->get();

        return $this->buildTree($categories);
    }

    /**
     * Build tree from flat collection.
     *
     * @param  \Illuminate\Database\Eloquent\Collection  $categories
     * @return array<int, array<string, mixed>>
     */
    protected function buildTree($categories, ?int $parentId = null): array
    {
        $tree = [];

        foreach ($categories as $category) {
            if ($category->parent_id == $parentId) {
                $children = $this->buildTree($categories, $category->id);

                $tree[] = [
                    'id' => $category->id,
                    'name' => $category->name,
                    'slug' => $category->slug,
                    'description' => $category->description,
                    'parent_id' => $category->parent_id,
                    'template_id' => $category->template_id,
                    'template_name' => $category->template?->name,
                    'sku_format' => $category->sku_format,
                    'sku_prefix' => $category->sku_prefix,
                    'sort_order' => $category->sort_order,
                    'level' => $category->level,
                    'products_count' => $category->products()->count(),
                    'is_leaf' => empty($children),
                    'children' => $children,
                ];
            }
        }

        return $tree;
    }

    /**
     * Check if targetId is a descendant of categoryId.
     */
    protected function isDescendant(int $categoryId, int $targetId): bool
    {
        $target = Category::find($targetId);

        while ($target) {
            if ($target->parent_id == $categoryId) {
                return true;
            }
            $target = $target->parent;
        }

        return false;
    }

    /**
     * Update levels for all descendants of a category.
     */
    protected function updateDescendantLevels(Category $category): void
    {
        $children = $category->children;

        foreach ($children as $child) {
            $child->update(['level' => ($category->level ?? 0) + 1]);
            $this->updateDescendantLevels($child);
        }
    }

    /**
     * Get template fields for a category.
     */
    public function templateFields(Category $category): JsonResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store || $category->store_id !== $store->id) {
            return response()->json(['error' => 'Category not found'], 404);
        }

        $template = $category->getEffectiveTemplate();

        if (! $template) {
            return response()->json([
                'template' => null,
                'fields' => [],
                'brands' => [],
            ]);
        }

        $template->load('fields.options');

        // Check if template has a brand field
        $hasBrandField = $template->fields->contains(fn ($field) => $field->type === ProductTemplateField::TYPE_BRAND);

        // Load brands if there's a brand field
        $brands = [];
        if ($hasBrandField) {
            $brands = Brand::where('store_id', $store->id)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn ($brand) => [
                    'id' => $brand->id,
                    'name' => $brand->name,
                ]);
        }

        return response()->json([
            'template' => [
                'id' => $template->id,
                'name' => $template->name,
                'description' => $template->description,
            ],
            'fields' => $template->fields->map(fn ($field) => [
                'id' => $field->id,
                'name' => $field->name,
                'canonical_name' => $field->canonical_name,
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
            ]),
            'brands' => $brands,
        ]);
    }

    /**
     * Show the settings page for a category.
     */
    public function settings(Category $category): Response|RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store || $category->store_id !== $store->id) {
            abort(404);
        }

        // Only leaf categories can have settings configured
        if (! $category->isLeaf()) {
            return redirect()->route('categories.index')
                ->with('error', 'Only leaf categories (categories without children) can have settings configured.');
        }

        $category->load(['template:id,name', 'labelTemplate:id,name', 'skuSequence', 'parent']);

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

        // Get SKU format preview
        $skuGenerator = new SkuGeneratorService;
        $skuPreview = $category->sku_format ? $skuGenerator->preview($category) : null;

        // Get available variables for the format builder
        $availableVariables = SkuGeneratorService::getAvailableVariables();

        return Inertia::render('categories/Settings', [
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
                'effective_sku_format' => $category->getEffectiveSkuFormat(),
                'effective_sku_prefix' => $category->getEffectiveSkuPrefix(),
                'title_format' => $category->title_format,
                'effective_title_format' => $category->getEffectiveTitleFormat(),
                'label_template_id' => $category->label_template_id,
                'label_template_name' => $category->labelTemplate?->name,
                'effective_label_template_name' => $category->getEffectiveLabelTemplate()?->name,
                'current_sequence' => $category->skuSequence?->current_value ?? 0,
            ],
            'templates' => $templates,
            'labelTemplates' => $labelTemplates,
            'skuPreview' => $skuPreview,
            'availableVariables' => $availableVariables,
        ]);
    }

    /**
     * Update settings for a category.
     */
    public function updateSettings(Request $request, Category $category): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store || $category->store_id !== $store->id) {
            abort(404);
        }

        if (! $category->isLeaf()) {
            return redirect()->route('categories.index')
                ->with('error', 'Only leaf categories can have settings configured.');
        }

        $validated = $request->validate([
            'template_id' => 'nullable|integer|exists:product_templates,id',
            'sku_format' => ['nullable', 'string', 'max:255', new ValidSkuFormat],
            'sku_prefix' => 'nullable|string|max:50',
            'title_format' => 'nullable|string|max:255',
            'label_template_id' => 'nullable|integer|exists:label_templates,id',
        ]);

        $category->update($validated);

        return redirect()->route('categories.settings', $category)
            ->with('success', 'Category settings updated successfully.');
    }

    /**
     * Preview SKU for a given format.
     */
    public function previewSku(Request $request, Category $category): JsonResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store || $category->store_id !== $store->id) {
            return response()->json(['error' => 'Category not found'], 404);
        }

        $format = $request->input('format', '');

        if (empty($format)) {
            return response()->json(['preview' => '', 'valid' => true, 'errors' => []]);
        }

        $skuGenerator = new SkuGeneratorService;

        // Validate format
        $validation = $skuGenerator->validateFormat($format);

        if (! $validation['valid']) {
            return response()->json([
                'preview' => '',
                'valid' => false,
                'errors' => $validation['errors'],
            ]);
        }

        // Generate preview with the provided format
        $category->sku_format = $format;

        if ($request->has('sku_prefix')) {
            $category->sku_prefix = $request->input('sku_prefix');
        }

        $preview = $skuGenerator->preview($category, $format);

        return response()->json([
            'preview' => $preview,
            'valid' => true,
            'errors' => [],
        ]);
    }

    /**
     * Reset the SKU sequence for a category.
     */
    public function resetSequence(Request $request, Category $category): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store || $category->store_id !== $store->id) {
            abort(404);
        }

        $resetTo = (int) $request->input('reset_to', 0);

        $sequence = SkuSequence::getOrCreate($category, $store);
        $sequence->resetTo($resetTo);

        return redirect()->route('categories.settings', $category)
            ->with('success', 'SKU sequence reset successfully.');
    }
}
