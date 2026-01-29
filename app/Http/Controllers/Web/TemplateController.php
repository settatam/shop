<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\ProductTemplate;
use App\Models\ProductTemplateField;
use App\Services\StoreContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TemplateController extends Controller
{
    public function __construct(protected StoreContext $storeContext) {}

    public function index(): Response|RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return redirect()->route('dashboard')
                ->with('error', 'Please select a store first.');
        }

        $templates = ProductTemplate::where('store_id', $store->id)
            ->withCount('fields')
            ->withCount('categories')
            ->orderBy('name')
            ->get()
            ->map(fn ($template) => [
                'id' => $template->id,
                'name' => $template->name,
                'description' => $template->description,
                'is_active' => $template->is_active,
                'fields_count' => $template->fields_count,
                'categories_count' => $template->categories_count,
                'created_at' => $template->created_at,
                'updated_at' => $template->updated_at,
            ]);

        return Inertia::render('templates/Index', [
            'templates' => $templates,
        ]);
    }

    public function create(): Response|RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return redirect()->route('dashboard')
                ->with('error', 'Please select a store first.');
        }

        return Inertia::render('templates/Create', [
            'fieldTypes' => ProductTemplateField::TYPES,
            'typesWithOptions' => ProductTemplateField::TYPES_WITH_OPTIONS,
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
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:500',
            'is_active' => 'boolean',
            'fields' => 'nullable|array',
            'fields.*.name' => 'required|string|max:255',
            'fields.*.label' => 'required|string|max:255',
            'fields.*.type' => 'required|string|in:'.implode(',', ProductTemplateField::TYPES),
            'fields.*.placeholder' => 'nullable|string|max:255',
            'fields.*.help_text' => 'nullable|string|max:500',
            'fields.*.default_value' => 'nullable|string',
            'fields.*.is_required' => 'boolean',
            'fields.*.is_searchable' => 'boolean',
            'fields.*.is_filterable' => 'boolean',
            'fields.*.show_in_listing' => 'boolean',
            'fields.*.group_name' => 'nullable|string|max:255',
            'fields.*.group_position' => 'nullable|integer|min:1',
            'fields.*.width_class' => 'nullable|string|in:full,half,third,quarter',
            'fields.*.options' => 'nullable|array',
            'fields.*.options.*.label' => 'required|string|max:255',
            'fields.*.options.*.value' => 'required|string|max:255',
        ]);

        $template = ProductTemplate::create([
            'store_id' => $store->id,
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        if (! empty($validated['fields'])) {
            foreach ($validated['fields'] as $index => $fieldData) {
                $field = $template->fields()->create([
                    'name' => $fieldData['name'],
                    'label' => $fieldData['label'],
                    'type' => $fieldData['type'],
                    'placeholder' => $fieldData['placeholder'] ?? null,
                    'help_text' => $fieldData['help_text'] ?? null,
                    'default_value' => $fieldData['default_value'] ?? null,
                    'is_required' => $fieldData['is_required'] ?? false,
                    'is_searchable' => $fieldData['is_searchable'] ?? false,
                    'is_filterable' => $fieldData['is_filterable'] ?? false,
                    'show_in_listing' => $fieldData['show_in_listing'] ?? false,
                    'group_name' => $fieldData['group_name'] ?? null,
                    'group_position' => $fieldData['group_position'] ?? 1,
                    'width_class' => $fieldData['width_class'] ?? 'full',
                    'sort_order' => $index,
                ]);

                if (! empty($fieldData['options'])) {
                    foreach ($fieldData['options'] as $optIndex => $optionData) {
                        $field->options()->create([
                            'label' => $optionData['label'],
                            'value' => $optionData['value'],
                            'sort_order' => $optIndex,
                        ]);
                    }
                }
            }
        }

        return redirect()->route('templates.show', $template)
            ->with('success', 'Template created successfully.');
    }

    public function show(ProductTemplate $template): Response|RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store || $template->store_id !== $store->id) {
            abort(404);
        }

        $template->load(['fields.options', 'fields.platformMappings.platform', 'categories']);

        return Inertia::render('templates/Show', [
            'template' => [
                'id' => $template->id,
                'name' => $template->name,
                'description' => $template->description,
                'is_active' => $template->is_active,
                'ai_generated' => $template->ai_generated,
                'generation_prompt' => $template->generation_prompt,
                'created_at' => $template->created_at,
                'updated_at' => $template->updated_at,
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
                    'is_searchable' => $field->is_searchable,
                    'is_filterable' => $field->is_filterable,
                    'show_in_listing' => $field->show_in_listing,
                    'group_name' => $field->group_name,
                    'group_position' => $field->group_position,
                    'width_class' => $field->width_class,
                    'sort_order' => $field->sort_order,
                    'ai_generated' => $field->ai_generated,
                    'options' => $field->options->map(fn ($opt) => [
                        'id' => $opt->id,
                        'label' => $opt->label,
                        'value' => $opt->value,
                    ]),
                    'platform_mappings' => $field->platformMappings->map(fn ($mapping) => [
                        'id' => $mapping->id,
                        'platform_id' => $mapping->platform_id,
                        'platform_name' => $mapping->platform->name,
                        'platform_slug' => $mapping->platform->slug,
                        'platform_field_name' => $mapping->platform_field_name,
                        'is_required' => $mapping->is_required,
                        'is_recommended' => $mapping->is_recommended,
                    ]),
                ]),
                'categories' => $template->categories->map(fn ($cat) => [
                    'id' => $cat->id,
                    'name' => $cat->name,
                    'full_path' => $cat->full_path,
                ]),
            ],
        ]);
    }

    public function edit(ProductTemplate $template): Response|RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store || $template->store_id !== $store->id) {
            abort(404);
        }

        $template->load(['fields.options', 'categories']);

        // Get all leaf categories (categories without children) for assignment
        $leafCategories = $this->getLeafCategories($store->id);

        return Inertia::render('templates/Edit', [
            'template' => [
                'id' => $template->id,
                'name' => $template->name,
                'description' => $template->description,
                'is_active' => $template->is_active,
                'created_at' => $template->created_at,
                'updated_at' => $template->updated_at,
                'fields' => $template->fields->map(fn ($field) => [
                    'id' => $field->id,
                    'name' => $field->name,
                    'label' => $field->label,
                    'type' => $field->type,
                    'placeholder' => $field->placeholder,
                    'help_text' => $field->help_text,
                    'default_value' => $field->default_value,
                    'is_required' => $field->is_required,
                    'is_searchable' => $field->is_searchable,
                    'is_filterable' => $field->is_filterable,
                    'show_in_listing' => $field->show_in_listing,
                    'group_name' => $field->group_name,
                    'group_position' => $field->group_position,
                    'width_class' => $field->width_class,
                    'sort_order' => $field->sort_order,
                    'options' => $field->options->map(fn ($opt) => [
                        'id' => $opt->id,
                        'label' => $opt->label,
                        'value' => $opt->value,
                    ]),
                ]),
                'category_ids' => $template->categories->pluck('id')->toArray(),
            ],
            'fieldTypes' => ProductTemplateField::TYPES,
            'typesWithOptions' => ProductTemplateField::TYPES_WITH_OPTIONS,
            'leafCategories' => $leafCategories,
        ]);
    }

    public function update(Request $request, ProductTemplate $template): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store || $template->store_id !== $store->id) {
            abort(404);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:500',
            'is_active' => 'boolean',
            'fields' => 'nullable|array',
            'fields.*.id' => 'nullable|integer|exists:product_template_fields,id',
            'fields.*.name' => 'required|string|max:255',
            'fields.*.label' => 'required|string|max:255',
            'fields.*.type' => 'required|string|in:'.implode(',', ProductTemplateField::TYPES),
            'fields.*.placeholder' => 'nullable|string|max:255',
            'fields.*.help_text' => 'nullable|string|max:500',
            'fields.*.default_value' => 'nullable|string',
            'fields.*.is_required' => 'boolean',
            'fields.*.is_searchable' => 'boolean',
            'fields.*.is_filterable' => 'boolean',
            'fields.*.show_in_listing' => 'boolean',
            'fields.*.group_name' => 'nullable|string|max:255',
            'fields.*.group_position' => 'nullable|integer|min:1',
            'fields.*.width_class' => 'nullable|string|in:full,half,third,quarter',
            'fields.*.options' => 'nullable|array',
            'fields.*.options.*.label' => 'required|string|max:255',
            'fields.*.options.*.value' => 'required|string|max:255',
            'category_ids' => 'nullable|array',
            'category_ids.*' => 'integer|exists:categories,id',
        ]);

        $template->update([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        // Update fields
        $existingFieldIds = [];
        if (! empty($validated['fields'])) {
            foreach ($validated['fields'] as $index => $fieldData) {
                if (isset($fieldData['id'])) {
                    // Update existing field
                    $field = $template->fields()->where('id', $fieldData['id'])->first();
                    if ($field) {
                        $field->update([
                            'name' => $fieldData['name'],
                            'label' => $fieldData['label'],
                            'type' => $fieldData['type'],
                            'placeholder' => $fieldData['placeholder'] ?? null,
                            'help_text' => $fieldData['help_text'] ?? null,
                            'default_value' => $fieldData['default_value'] ?? null,
                            'is_required' => $fieldData['is_required'] ?? false,
                            'is_searchable' => $fieldData['is_searchable'] ?? false,
                            'is_filterable' => $fieldData['is_filterable'] ?? false,
                            'show_in_listing' => $fieldData['show_in_listing'] ?? false,
                            'group_name' => $fieldData['group_name'] ?? null,
                            'group_position' => $fieldData['group_position'] ?? 1,
                            'width_class' => $fieldData['width_class'] ?? 'full',
                            'sort_order' => $index,
                        ]);

                        // Update options
                        $field->options()->delete();
                        if (! empty($fieldData['options'])) {
                            foreach ($fieldData['options'] as $optIndex => $optionData) {
                                $field->options()->create([
                                    'label' => $optionData['label'],
                                    'value' => $optionData['value'],
                                    'sort_order' => $optIndex,
                                ]);
                            }
                        }

                        $existingFieldIds[] = $field->id;
                    }
                } else {
                    // Create new field
                    $field = $template->fields()->create([
                        'name' => $fieldData['name'],
                        'label' => $fieldData['label'],
                        'type' => $fieldData['type'],
                        'placeholder' => $fieldData['placeholder'] ?? null,
                        'help_text' => $fieldData['help_text'] ?? null,
                        'default_value' => $fieldData['default_value'] ?? null,
                        'is_required' => $fieldData['is_required'] ?? false,
                        'is_searchable' => $fieldData['is_searchable'] ?? false,
                        'is_filterable' => $fieldData['is_filterable'] ?? false,
                        'show_in_listing' => $fieldData['show_in_listing'] ?? false,
                        'group_name' => $fieldData['group_name'] ?? null,
                        'group_position' => $fieldData['group_position'] ?? 1,
                        'width_class' => $fieldData['width_class'] ?? 'full',
                        'sort_order' => $index,
                    ]);

                    if (! empty($fieldData['options'])) {
                        foreach ($fieldData['options'] as $optIndex => $optionData) {
                            $field->options()->create([
                                'label' => $optionData['label'],
                                'value' => $optionData['value'],
                                'sort_order' => $optIndex,
                            ]);
                        }
                    }

                    $existingFieldIds[] = $field->id;
                }
            }
        }

        // Delete removed fields
        $template->fields()->whereNotIn('id', $existingFieldIds)->delete();

        // Update category assignments
        if (isset($validated['category_ids'])) {
            // Remove template from categories no longer selected
            Category::where('store_id', $store->id)
                ->where('template_id', $template->id)
                ->whereNotIn('id', $validated['category_ids'])
                ->update(['template_id' => null]);

            // Assign template to selected categories
            Category::where('store_id', $store->id)
                ->whereIn('id', $validated['category_ids'])
                ->update(['template_id' => $template->id]);
        }

        return redirect()->route('templates.show', $template)
            ->with('success', 'Template updated successfully.');
    }

    public function destroy(ProductTemplate $template): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store || $template->store_id !== $store->id) {
            abort(404);
        }

        // Remove template from categories first
        Category::where('template_id', $template->id)->update(['template_id' => null]);

        $template->delete();

        return redirect()->route('templates.index')
            ->with('success', 'Template deleted successfully.');
    }

    public function duplicate(ProductTemplate $template): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store || $template->store_id !== $store->id) {
            abort(404);
        }

        $newTemplate = $template->replicate();
        $newTemplate->name = $template->name.' (Copy)';
        $newTemplate->save();

        foreach ($template->fields as $field) {
            $newField = $field->replicate();
            $newField->product_template_id = $newTemplate->id;
            $newField->save();

            foreach ($field->options as $option) {
                $newOption = $option->replicate();
                $newOption->product_template_field_id = $newField->id;
                $newOption->save();
            }
        }

        return redirect()->route('templates.edit', $newTemplate)
            ->with('success', 'Template duplicated successfully.');
    }

    /**
     * Get all leaf categories (categories without children).
     *
     * @return array<int, array<string, mixed>>
     */
    protected function getLeafCategories(int $storeId): array
    {
        return Category::where('store_id', $storeId)
            ->whereDoesntHave('children')
            ->with('template')
            ->orderBy('name')
            ->get()
            ->map(fn ($cat) => [
                'id' => $cat->id,
                'name' => $cat->name,
                'full_path' => $cat->full_path,
                'template_id' => $cat->template_id,
                'template_name' => $cat->template?->name,
            ])
            ->toArray();
    }
}
