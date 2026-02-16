<?php

namespace App\Http\Controllers\Web;

use App\Enums\Platform;
use App\Http\Controllers\Controller;
use App\Models\ProductTemplate;
use App\Models\StoreMarketplace;
use App\Services\Platforms\FieldMappingService;
use App\Services\StoreContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TemplateMappingController extends Controller
{
    public function __construct(
        protected StoreContext $storeContext,
        protected FieldMappingService $fieldMappingService,
    ) {}

    /**
     * List all templates with their mapping status.
     */
    public function index(): Response
    {
        $store = $this->storeContext->getCurrentStore();

        $templates = ProductTemplate::where('store_id', $store->id)
            ->where('is_active', true)
            ->with(['platformMappings', 'fields'])
            ->withCount('fields')
            ->orderBy('name')
            ->get();

        $marketplaces = StoreMarketplace::where('store_id', $store->id)
            ->sellingPlatforms()
            ->connected()
            ->get(['id', 'name', 'platform']);

        // Available platforms from connected marketplaces
        $platforms = $marketplaces->unique('platform')
            ->map(fn ($m) => [
                'value' => $m->platform->value,
                'label' => $m->platform->label(),
            ])
            ->values();

        return Inertia::render('settings/TemplateMappings', [
            'templates' => $templates->map(fn ($template) => [
                'id' => $template->id,
                'name' => $template->name,
                'description' => $template->description,
                'fields' => $template->fields->map(fn ($field) => [
                    'name' => $field->name,
                    'label' => $field->label,
                    'type' => $field->type,
                ]),
                'mappings' => $template->platformMappings->map(fn ($m) => [
                    'id' => $m->id,
                    'platform' => $m->platform->value,
                    'platform_label' => $m->platform->label(),
                    'field_mappings' => $m->field_mappings ?? [],
                    'metafield_mappings' => $m->metafield_mappings ?? [],
                    'is_ai_generated' => $m->is_ai_generated,
                    'mapped_count' => count($m->field_mappings ?? []),
                    'required_count' => count($this->fieldMappingService->getRequiredPlatformFields($m->platform)),
                    'unmapped_required_count' => count($this->fieldMappingService->getUnmappedRequiredFields($template, $m->platform)),
                ]),
            ]),
            'availablePlatforms' => $platforms->map(fn ($p) => [
                'value' => $p['value'],
                'label' => $p['label'],
                'supports_metafields' => $this->fieldMappingService->supportsMetafields($p['value']),
            ]),
        ]);
    }

    /**
     * Get mapping details for a template and platform.
     */
    public function show(ProductTemplate $template, string $platform): JsonResponse
    {
        $this->authorize('view', $template);

        $template->load('fields');
        $platformEnum = Platform::from($platform);

        $mapping = $this->fieldMappingService->getMappings($template, $platformEnum);
        $platformFields = $this->fieldMappingService->getPlatformFields($platformEnum);
        $requiredFields = $this->fieldMappingService->getRequiredPlatformFields($platformEnum);

        // Build template fields with current mappings
        $templateFields = $template->fields->map(fn ($field) => [
            'id' => $field->id,
            'name' => $field->name,
            'label' => $field->label,
            'type' => $field->type,
            'mapped_to' => $mapping?->field_mappings[$field->name] ?? null,
        ]);

        // Build platform fields with mapping status
        $platformFieldsList = collect($platformFields)->map(fn ($field, $name) => [
            'name' => $name,
            'label' => $field['label'],
            'type' => $field['type'],
            'required' => $field['required'],
            'description' => $field['description'] ?? '',
            'mapped_from' => $mapping ? collect($mapping->field_mappings)->search($name) : null,
            'has_default' => isset($mapping?->default_values[$name]),
            'default_value' => $mapping?->default_values[$name] ?? null,
        ])->values();

        return response()->json([
            'template' => [
                'id' => $template->id,
                'name' => $template->name,
                'fields' => $templateFields,
            ],
            'platform' => [
                'value' => $platform,
                'label' => $platformEnum->label(),
                'fields' => $platformFieldsList,
            ],
            'mapping' => $mapping ? [
                'id' => $mapping->id,
                'field_mappings' => $mapping->field_mappings,
                'default_values' => $mapping->default_values,
                'is_ai_generated' => $mapping->is_ai_generated,
                'updated_at' => $mapping->updated_at->toIso8601String(),
            ] : null,
            'unmapped_required' => $this->fieldMappingService->getUnmappedRequiredFields($template, $platformEnum),
        ]);
    }

    /**
     * Get AI-suggested mappings.
     */
    public function suggest(ProductTemplate $template, string $platform): JsonResponse
    {
        $this->authorize('update', $template);

        $platformEnum = Platform::from($platform);

        $suggestions = $this->fieldMappingService->suggestMappings($template, $platformEnum);

        // Convert to format expected by frontend FieldMapper component
        $formattedSuggestions = collect($suggestions['mappings'])
            ->map(fn ($data, $templateField) => [
                'templateField' => $templateField,
                'platformField' => $data['maps_to'],
                'confidence' => $data['confidence'],
            ])
            ->values()
            ->toArray();

        return response()->json([
            'suggestions' => $formattedSuggestions,
            'unmapped_template_fields' => $suggestions['unmapped_template_fields'],
            'unmapped_required_platform_fields' => $suggestions['unmapped_required_platform_fields'],
        ]);
    }

    /**
     * Save mappings for a template and platform.
     */
    public function update(Request $request, ProductTemplate $template, string $platform): JsonResponse
    {
        $this->authorize('update', $template);

        $validated = $request->validate([
            'field_mappings' => ['required', 'array'],
            'field_mappings.*' => ['nullable', 'string'],
            'default_values' => ['nullable', 'array'],
            'default_values.*' => ['nullable', 'string'],
            'metafield_mappings' => ['nullable', 'array'],
            'metafield_mappings.*.namespace' => ['required_with:metafield_mappings.*', 'string'],
            'metafield_mappings.*.key' => ['required_with:metafield_mappings.*', 'string'],
            'metafield_mappings.*.enabled' => ['required_with:metafield_mappings.*', 'boolean'],
            'is_ai_generated' => ['nullable', 'boolean'],
        ]);

        // Filter out null/empty mappings
        $fieldMappings = array_filter($validated['field_mappings'], fn ($v) => $v !== null && $v !== '');
        $defaultValues = array_filter($validated['default_values'] ?? [], fn ($v) => $v !== null && $v !== '');

        $mapping = $this->fieldMappingService->saveMappings(
            $template,
            $platform,
            $fieldMappings,
            $defaultValues,
            $validated['is_ai_generated'] ?? false
        );

        // Save metafield mappings if provided
        if (isset($validated['metafield_mappings'])) {
            $this->fieldMappingService->saveMetafieldMappings(
                $template,
                $platform,
                $validated['metafield_mappings']
            );
            $mapping->refresh();
        }

        return response()->json([
            'success' => true,
            'message' => 'Mappings saved successfully',
            'mapping' => [
                'id' => $mapping->id,
                'field_mappings' => $mapping->field_mappings,
                'metafield_mappings' => $mapping->metafield_mappings,
                'default_values' => $mapping->default_values,
                'is_ai_generated' => $mapping->is_ai_generated,
                'updated_at' => $mapping->updated_at->toIso8601String(),
            ],
            'unmapped_required' => $this->fieldMappingService->getUnmappedRequiredFields($template, $platform),
        ]);
    }

    /**
     * Delete mappings for a template and platform.
     */
    public function destroy(ProductTemplate $template, string $platform): JsonResponse
    {
        $this->authorize('update', $template);

        $mapping = $this->fieldMappingService->getMappings($template, $platform);

        if ($mapping) {
            $mapping->delete();
        }

        return response()->json([
            'success' => true,
            'message' => 'Mappings deleted successfully',
        ]);
    }

    /**
     * Get available platform fields for a platform.
     */
    public function platformFields(string $platform): JsonResponse
    {
        $platformEnum = Platform::from($platform);
        $fields = $this->fieldMappingService->getPlatformFields($platformEnum);

        return response()->json([
            'platform' => $platform,
            'label' => $platformEnum->label(),
            'fields' => collect($fields)->map(fn ($field, $name) => [
                'name' => $name,
                'label' => $field['label'],
                'type' => $field['type'],
                'required' => $field['required'],
                'description' => $field['description'] ?? '',
            ])->values(),
        ]);
    }

    /**
     * Get platform fields for a specific template mapping context.
     */
    public function templatePlatformFields(ProductTemplate $template, string $platform): JsonResponse
    {
        $this->authorize('view', $template);

        $platformEnum = Platform::from($platform);
        $fields = $this->fieldMappingService->getPlatformFields($platformEnum);

        return response()->json([
            'fields' => collect($fields)->map(fn ($field, $name) => [
                'name' => $name,
                'label' => $field['label'],
                'type' => $field['type'],
                'is_required' => $field['required'],
                'field_type' => $field['field_type'] ?? 'standard',
            ])->values(),
            'supports_metafields' => $this->fieldMappingService->supportsMetafields($platformEnum),
        ]);
    }
}
