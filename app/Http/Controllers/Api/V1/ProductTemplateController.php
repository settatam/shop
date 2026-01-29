<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ProductTemplate;
use App\Models\ProductTemplateField;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductTemplateController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = ProductTemplate::query()->with(['fields.options']);

        if ($request->boolean('active_only')) {
            $query->active();
        }

        if ($request->has('search')) {
            $query->where('name', 'like', '%'.$request->input('search').'%');
        }

        $templates = $request->boolean('all')
            ? $query->orderBy('name')->get()
            : $query->orderBy('name')->paginate($request->input('per_page', 15));

        return response()->json($templates);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:500'],
            'is_active' => ['nullable', 'boolean'],
            'fields' => ['nullable', 'array'],
            'fields.*.name' => ['required', 'string', 'max:255'],
            'fields.*.label' => ['required', 'string', 'max:255'],
            'fields.*.type' => ['required', 'string', 'in:'.implode(',', ProductTemplateField::TYPES)],
            'fields.*.placeholder' => ['nullable', 'string', 'max:255'],
            'fields.*.help_text' => ['nullable', 'string', 'max:500'],
            'fields.*.default_value' => ['nullable', 'string'],
            'fields.*.is_required' => ['nullable', 'boolean'],
            'fields.*.is_searchable' => ['nullable', 'boolean'],
            'fields.*.is_filterable' => ['nullable', 'boolean'],
            'fields.*.show_in_listing' => ['nullable', 'boolean'],
            'fields.*.sort_order' => ['nullable', 'integer'],
            'fields.*.options' => ['nullable', 'array'],
            'fields.*.options.*.label' => ['required', 'string', 'max:255'],
            'fields.*.options.*.value' => ['required', 'string', 'max:255'],
            'fields.*.options.*.sort_order' => ['nullable', 'integer'],
        ]);

        $template = ProductTemplate::create([
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
                    'sort_order' => $fieldData['sort_order'] ?? $index,
                ]);

                if (! empty($fieldData['options'])) {
                    foreach ($fieldData['options'] as $optIndex => $optionData) {
                        $field->options()->create([
                            'label' => $optionData['label'],
                            'value' => $optionData['value'],
                            'sort_order' => $optionData['sort_order'] ?? $optIndex,
                        ]);
                    }
                }
            }
        }

        $template->load('fields.options');

        return response()->json($template, 201);
    }

    public function show(ProductTemplate $productTemplate): JsonResponse
    {
        $productTemplate->load(['fields.options', 'categories']);

        return response()->json($productTemplate);
    }

    public function update(Request $request, ProductTemplate $productTemplate): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:500'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $productTemplate->update($validated);

        return response()->json($productTemplate);
    }

    public function destroy(ProductTemplate $productTemplate): JsonResponse
    {
        $productTemplate->delete();

        return response()->json(null, 204);
    }

    /**
     * Add a field to the template.
     */
    public function addField(Request $request, ProductTemplate $productTemplate): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'label' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'in:'.implode(',', ProductTemplateField::TYPES)],
            'placeholder' => ['nullable', 'string', 'max:255'],
            'help_text' => ['nullable', 'string', 'max:500'],
            'default_value' => ['nullable', 'string'],
            'is_required' => ['nullable', 'boolean'],
            'is_searchable' => ['nullable', 'boolean'],
            'is_filterable' => ['nullable', 'boolean'],
            'show_in_listing' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer'],
            'options' => ['nullable', 'array'],
            'options.*.label' => ['required', 'string', 'max:255'],
            'options.*.value' => ['required', 'string', 'max:255'],
            'options.*.sort_order' => ['nullable', 'integer'],
        ]);

        $maxSortOrder = $productTemplate->fields()->max('sort_order') ?? -1;

        $field = $productTemplate->fields()->create([
            'name' => $validated['name'],
            'label' => $validated['label'],
            'type' => $validated['type'],
            'placeholder' => $validated['placeholder'] ?? null,
            'help_text' => $validated['help_text'] ?? null,
            'default_value' => $validated['default_value'] ?? null,
            'is_required' => $validated['is_required'] ?? false,
            'is_searchable' => $validated['is_searchable'] ?? false,
            'is_filterable' => $validated['is_filterable'] ?? false,
            'show_in_listing' => $validated['show_in_listing'] ?? false,
            'sort_order' => $validated['sort_order'] ?? ($maxSortOrder + 1),
        ]);

        if (! empty($validated['options'])) {
            foreach ($validated['options'] as $index => $optionData) {
                $field->options()->create([
                    'label' => $optionData['label'],
                    'value' => $optionData['value'],
                    'sort_order' => $optionData['sort_order'] ?? $index,
                ]);
            }
        }

        $field->load('options');

        return response()->json($field, 201);
    }

    /**
     * Update a field.
     */
    public function updateField(Request $request, ProductTemplate $productTemplate, ProductTemplateField $field): JsonResponse
    {
        if ($field->product_template_id !== $productTemplate->id) {
            return response()->json(['error' => 'Field does not belong to this template'], 404);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'label' => ['sometimes', 'string', 'max:255'],
            'type' => ['sometimes', 'string', 'in:'.implode(',', ProductTemplateField::TYPES)],
            'placeholder' => ['nullable', 'string', 'max:255'],
            'help_text' => ['nullable', 'string', 'max:500'],
            'default_value' => ['nullable', 'string'],
            'is_required' => ['nullable', 'boolean'],
            'is_searchable' => ['nullable', 'boolean'],
            'is_filterable' => ['nullable', 'boolean'],
            'show_in_listing' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer'],
        ]);

        $field->update($validated);

        return response()->json($field);
    }

    /**
     * Delete a field.
     */
    public function deleteField(ProductTemplate $productTemplate, ProductTemplateField $field): JsonResponse
    {
        if ($field->product_template_id !== $productTemplate->id) {
            return response()->json(['error' => 'Field does not belong to this template'], 404);
        }

        $field->delete();

        return response()->json(null, 204);
    }

    /**
     * Update field options (replace all options).
     */
    public function updateFieldOptions(Request $request, ProductTemplate $productTemplate, ProductTemplateField $field): JsonResponse
    {
        if ($field->product_template_id !== $productTemplate->id) {
            return response()->json(['error' => 'Field does not belong to this template'], 404);
        }

        if (! $field->hasOptions()) {
            return response()->json(['error' => 'This field type does not support options'], 422);
        }

        $validated = $request->validate([
            'options' => ['required', 'array'],
            'options.*.label' => ['required', 'string', 'max:255'],
            'options.*.value' => ['required', 'string', 'max:255'],
            'options.*.sort_order' => ['nullable', 'integer'],
        ]);

        // Delete existing options and create new ones
        $field->options()->delete();

        foreach ($validated['options'] as $index => $optionData) {
            $field->options()->create([
                'label' => $optionData['label'],
                'value' => $optionData['value'],
                'sort_order' => $optionData['sort_order'] ?? $index,
            ]);
        }

        $field->load('options');

        return response()->json($field);
    }

    /**
     * Reorder fields within a template.
     */
    public function reorderFields(Request $request, ProductTemplate $productTemplate): JsonResponse
    {
        $validated = $request->validate([
            'field_ids' => ['required', 'array'],
            'field_ids.*' => ['required', 'integer', 'exists:product_template_fields,id'],
        ]);

        foreach ($validated['field_ids'] as $index => $fieldId) {
            $productTemplate->fields()
                ->where('id', $fieldId)
                ->update(['sort_order' => $index]);
        }

        $productTemplate->load('fields.options');

        return response()->json($productTemplate);
    }

    /**
     * Duplicate a template.
     */
    public function duplicate(Request $request, ProductTemplate $productTemplate): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
        ]);

        $newTemplate = $productTemplate->replicate();
        $newTemplate->name = $validated['name'] ?? $productTemplate->name.' (Copy)';
        $newTemplate->save();

        foreach ($productTemplate->fields as $field) {
            $newField = $field->replicate();
            $newField->product_template_id = $newTemplate->id;
            $newField->save();

            foreach ($field->options as $option) {
                $newOption = $option->replicate();
                $newOption->product_template_field_id = $newField->id;
                $newOption->save();
            }
        }

        $newTemplate->load('fields.options');

        return response()->json($newTemplate, 201);
    }
}
