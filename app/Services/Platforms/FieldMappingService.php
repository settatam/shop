<?php

namespace App\Services\Platforms;

use App\Enums\Platform;
use App\Models\Product;
use App\Models\ProductTemplate;
use App\Models\TemplatePlatformMapping;
use App\Services\AI\AIManager;
use Illuminate\Support\Facades\Log;

class FieldMappingService
{
    /**
     * Platform-specific field requirements.
     * Each platform has required and optional fields.
     * Fields can be:
     * - Standard fields (directly mapped)
     * - Item specifics (eBay) - sent as name/value pairs
     * - Metafields (Shopify) - sent with namespace/key
     *
     * @var array<string, array<string, array{label: string, type: string, required: bool, description?: string, field_type?: string}>>
     */
    protected array $platformFields = [
        'ebay' => [
            'title' => ['label' => 'Title', 'type' => 'text', 'required' => true, 'description' => 'Listing title (max 80 characters)', 'field_type' => 'standard'],
            'description' => ['label' => 'Description', 'type' => 'textarea', 'required' => true, 'description' => 'HTML description', 'field_type' => 'standard'],
            'condition' => ['label' => 'Condition', 'type' => 'select', 'required' => true, 'description' => 'Item condition', 'field_type' => 'standard'],
            'category_id' => ['label' => 'Category ID', 'type' => 'text', 'required' => true, 'description' => 'eBay category ID', 'field_type' => 'standard'],
            'brand' => ['label' => 'Brand', 'type' => 'text', 'required' => false, 'description' => 'Brand name (item specific)', 'field_type' => 'item_specific'],
            'mpn' => ['label' => 'MPN', 'type' => 'text', 'required' => false, 'description' => 'Manufacturer Part Number', 'field_type' => 'item_specific'],
            'upc' => ['label' => 'UPC', 'type' => 'text', 'required' => false, 'description' => 'Universal Product Code', 'field_type' => 'standard'],
            'ean' => ['label' => 'EAN', 'type' => 'text', 'required' => false, 'description' => 'European Article Number', 'field_type' => 'standard'],
            'isbn' => ['label' => 'ISBN', 'type' => 'text', 'required' => false, 'description' => 'ISBN for books', 'field_type' => 'standard'],
            'color' => ['label' => 'Color', 'type' => 'text', 'required' => false, 'description' => 'Item color', 'field_type' => 'item_specific'],
            'size' => ['label' => 'Size', 'type' => 'text', 'required' => false, 'description' => 'Item size', 'field_type' => 'item_specific'],
            'material' => ['label' => 'Material', 'type' => 'text', 'required' => false, 'description' => 'Primary material', 'field_type' => 'item_specific'],
            'style' => ['label' => 'Style', 'type' => 'text', 'required' => false, 'description' => 'Style or design', 'field_type' => 'item_specific'],
        ],
        'shopify' => [
            'title' => ['label' => 'Title', 'type' => 'text', 'required' => true, 'description' => 'Product title', 'field_type' => 'standard'],
            'body_html' => ['label' => 'Description', 'type' => 'textarea', 'required' => false, 'description' => 'HTML description', 'field_type' => 'standard'],
            'vendor' => ['label' => 'Vendor', 'type' => 'text', 'required' => false, 'description' => 'Vendor name', 'field_type' => 'standard'],
            'product_type' => ['label' => 'Product Type', 'type' => 'text', 'required' => false, 'description' => 'Product category', 'field_type' => 'standard'],
            'tags' => ['label' => 'Tags', 'type' => 'text', 'required' => false, 'description' => 'Comma-separated tags', 'field_type' => 'standard'],
        ],
        'amazon' => [
            'item_name' => ['label' => 'Item Name', 'type' => 'text', 'required' => true, 'description' => 'Product title', 'field_type' => 'standard'],
            'product_description' => ['label' => 'Description', 'type' => 'textarea', 'required' => true, 'description' => 'Product description', 'field_type' => 'standard'],
            'brand_name' => ['label' => 'Brand Name', 'type' => 'text', 'required' => true, 'description' => 'Brand name', 'field_type' => 'standard'],
            'manufacturer' => ['label' => 'Manufacturer', 'type' => 'text', 'required' => false, 'description' => 'Manufacturer name', 'field_type' => 'standard'],
            'part_number' => ['label' => 'Part Number', 'type' => 'text', 'required' => false, 'description' => 'MPN', 'field_type' => 'standard'],
            'model_number' => ['label' => 'Model Number', 'type' => 'text', 'required' => false, 'description' => 'Model number', 'field_type' => 'standard'],
            'product_type' => ['label' => 'Product Type', 'type' => 'text', 'required' => true, 'description' => 'Amazon product type', 'field_type' => 'standard'],
            'color_name' => ['label' => 'Color', 'type' => 'text', 'required' => false, 'description' => 'Color name', 'field_type' => 'standard'],
            'size_name' => ['label' => 'Size', 'type' => 'text', 'required' => false, 'description' => 'Size name', 'field_type' => 'standard'],
            'material_type' => ['label' => 'Material', 'type' => 'text', 'required' => false, 'description' => 'Material type', 'field_type' => 'standard'],
        ],
        'etsy' => [
            'title' => ['label' => 'Title', 'type' => 'text', 'required' => true, 'description' => 'Listing title', 'field_type' => 'standard'],
            'description' => ['label' => 'Description', 'type' => 'textarea', 'required' => true, 'description' => 'Listing description', 'field_type' => 'standard'],
            'who_made' => ['label' => 'Who Made It', 'type' => 'select', 'required' => true, 'description' => 'i_did, someone_else, collective', 'field_type' => 'standard'],
            'when_made' => ['label' => 'When Made', 'type' => 'select', 'required' => true, 'description' => 'When the item was made', 'field_type' => 'standard'],
            'taxonomy_id' => ['label' => 'Taxonomy ID', 'type' => 'text', 'required' => true, 'description' => 'Etsy taxonomy category', 'field_type' => 'standard'],
            'tags' => ['label' => 'Tags', 'type' => 'text', 'required' => false, 'description' => 'Comma-separated tags (max 13)', 'field_type' => 'standard'],
            'materials' => ['label' => 'Materials', 'type' => 'text', 'required' => false, 'description' => 'Comma-separated materials', 'field_type' => 'standard'],
            'primary_color' => ['label' => 'Primary Color', 'type' => 'text', 'required' => false, 'description' => 'Primary color', 'field_type' => 'standard'],
            'secondary_color' => ['label' => 'Secondary Color', 'type' => 'text', 'required' => false, 'description' => 'Secondary color', 'field_type' => 'standard'],
            'occasion' => ['label' => 'Occasion', 'type' => 'text', 'required' => false, 'description' => 'Occasion', 'field_type' => 'standard'],
            'style' => ['label' => 'Style', 'type' => 'text', 'required' => false, 'description' => 'Style', 'field_type' => 'standard'],
        ],
        'walmart' => [
            'productName' => ['label' => 'Product Name', 'type' => 'text', 'required' => true, 'description' => 'Product title', 'field_type' => 'standard'],
            'shortDescription' => ['label' => 'Short Description', 'type' => 'textarea', 'required' => true, 'description' => 'Short product description', 'field_type' => 'standard'],
            'longDescription' => ['label' => 'Long Description', 'type' => 'textarea', 'required' => false, 'description' => 'Full product description', 'field_type' => 'standard'],
            'brand' => ['label' => 'Brand', 'type' => 'text', 'required' => true, 'description' => 'Brand name', 'field_type' => 'standard'],
            'category' => ['label' => 'Category', 'type' => 'text', 'required' => true, 'description' => 'Walmart category', 'field_type' => 'standard'],
            'mainImageUrl' => ['label' => 'Main Image URL', 'type' => 'text', 'required' => true, 'description' => 'Primary product image', 'field_type' => 'standard'],
            'color' => ['label' => 'Color', 'type' => 'text', 'required' => false, 'description' => 'Product color', 'field_type' => 'attribute'],
            'size' => ['label' => 'Size', 'type' => 'text', 'required' => false, 'description' => 'Product size', 'field_type' => 'attribute'],
            'material' => ['label' => 'Material', 'type' => 'text', 'required' => false, 'description' => 'Product material', 'field_type' => 'attribute'],
        ],
    ];

    /**
     * Platforms that support custom metafields/attributes.
     * For these platforms, any unmapped template field can be sent as a metafield.
     *
     * @var array<string, array{namespace: string, supports_custom: bool}>
     */
    protected array $metafieldPlatforms = [
        'shopify' => ['namespace' => 'custom', 'supports_custom' => true],
        'woocommerce' => ['namespace' => 'custom', 'supports_custom' => true],
        'bigcommerce' => ['namespace' => 'custom', 'supports_custom' => true],
    ];

    public function __construct(protected AIManager $aiManager) {}

    /**
     * Get all platform fields for a given platform.
     *
     * @return array<string, array{label: string, type: string, required: bool, description?: string}>
     */
    public function getPlatformFields(string|Platform $platform): array
    {
        $key = $platform instanceof Platform ? $platform->value : $platform;

        return $this->platformFields[$key] ?? [];
    }

    /**
     * Get required platform fields.
     *
     * @return array<string, array{label: string, type: string, required: bool, description?: string}>
     */
    public function getRequiredPlatformFields(string|Platform $platform): array
    {
        $fields = $this->getPlatformFields($platform);

        return array_filter($fields, fn ($field) => $field['required']);
    }

    /**
     * Get optional platform fields.
     *
     * @return array<string, array{label: string, type: string, required: bool, description?: string}>
     */
    public function getOptionalPlatformFields(string|Platform $platform): array
    {
        $fields = $this->getPlatformFields($platform);

        return array_filter($fields, fn ($field) => ! $field['required']);
    }

    /**
     * Check if a platform supports custom metafields.
     */
    public function supportsMetafields(string|Platform $platform): bool
    {
        $key = $platform instanceof Platform ? $platform->value : $platform;

        return isset($this->metafieldPlatforms[$key]) && $this->metafieldPlatforms[$key]['supports_custom'];
    }

    /**
     * Get the default namespace for metafields on a platform.
     */
    public function getDefaultMetafieldNamespace(string|Platform $platform): string
    {
        $key = $platform instanceof Platform ? $platform->value : $platform;

        return $this->metafieldPlatforms[$key]['namespace'] ?? 'custom';
    }

    /**
     * Generate default metafield key from field name.
     */
    public function generateMetafieldKey(string $fieldName): string
    {
        return str($fieldName)->snake()->toString();
    }

    /**
     * Save metafield mappings for a template and platform.
     *
     * @param  array<string, array{namespace?: string, key: string, enabled: bool}>  $metafieldMappings
     */
    public function saveMetafieldMappings(
        ProductTemplate $template,
        string|Platform $platform,
        array $metafieldMappings
    ): TemplatePlatformMapping {
        $platformValue = $platform instanceof Platform ? $platform->value : $platform;

        $mapping = TemplatePlatformMapping::firstOrCreate(
            [
                'product_template_id' => $template->id,
                'platform' => $platformValue,
            ],
            [
                'field_mappings' => [],
                'default_values' => [],
            ]
        );

        // Ensure each metafield has a namespace
        $defaultNamespace = $this->getDefaultMetafieldNamespace($platformValue);
        foreach ($metafieldMappings as $fieldName => &$config) {
            $config['namespace'] = $config['namespace'] ?? $defaultNamespace;
            $config['key'] = $config['key'] ?? $this->generateMetafieldKey($fieldName);
        }

        $mapping->update(['metafield_mappings' => $metafieldMappings]);

        return $mapping;
    }

    /**
     * Get metafield configuration for a template and platform.
     *
     * @return array<string, array{namespace: string, key: string, enabled: bool}>
     */
    public function getMetafieldMappings(ProductTemplate $template, string|Platform $platform): array
    {
        $mapping = $this->getMappings($template, $platform);

        return $mapping?->metafield_mappings ?? [];
    }

    /**
     * Build default metafield mappings for all unmapped template fields.
     *
     * @return array<string, array{namespace: string, key: string, enabled: bool}>
     */
    public function suggestMetafieldMappings(ProductTemplate $template, string|Platform $platform): array
    {
        $template->load('fields');
        $platformValue = $platform instanceof Platform ? $platform->value : $platform;

        if (! $this->supportsMetafields($platformValue)) {
            return [];
        }

        $mapping = $this->getMappings($template, $platformValue);
        $mappedFields = array_keys($mapping?->field_mappings ?? []);
        $existingMetafields = $mapping?->metafield_mappings ?? [];
        $defaultNamespace = $this->getDefaultMetafieldNamespace($platformValue);

        $suggestions = [];
        foreach ($template->fields as $field) {
            // Skip fields that are already mapped to standard platform fields
            if (in_array($field->name, $mappedFields)) {
                continue;
            }

            // Use existing config if available, otherwise create default
            if (isset($existingMetafields[$field->name])) {
                $suggestions[$field->name] = $existingMetafields[$field->name];
            } else {
                $suggestions[$field->name] = [
                    'namespace' => $defaultNamespace,
                    'key' => $this->generateMetafieldKey($field->name),
                    'enabled' => true, // Enable by default for suggestions
                ];
            }
        }

        return $suggestions;
    }

    /**
     * Suggest field mappings using AI.
     *
     * @return array{mappings: array<string, array{maps_to: string, confidence: float}>, unmapped_template_fields: array<string>, unmapped_required_platform_fields: array<string>}
     */
    public function suggestMappings(ProductTemplate $template, string|Platform $platform): array
    {
        $template->load('fields');
        $platformKey = $platform instanceof Platform ? $platform->value : $platform;
        $platformFields = $this->getPlatformFields($platformKey);

        if (empty($platformFields) || $template->fields->isEmpty()) {
            return [
                'mappings' => [],
                'unmapped_template_fields' => $template->fields->pluck('name')->all(),
                'unmapped_required_platform_fields' => array_keys($this->getRequiredPlatformFields($platformKey)),
            ];
        }

        // Build template fields info
        $templateFieldsInfo = $template->fields->map(fn ($field) => [
            'name' => $field->name,
            'label' => $field->label,
            'type' => $field->type,
        ])->toArray();

        // Build platform fields info
        $platformFieldsInfo = collect($platformFields)->map(fn ($field, $name) => [
            'name' => $name,
            'label' => $field['label'],
            'type' => $field['type'],
            'required' => $field['required'],
            'description' => $field['description'] ?? '',
        ])->values()->toArray();

        $prompt = $this->buildMappingPrompt($template->name, $templateFieldsInfo, $platformKey, $platformFieldsInfo);

        try {
            $response = $this->aiManager->chat($prompt, [
                'feature' => 'field_mapping',
            ]);

            return $this->parseMappingResponse($response->content, $templateFieldsInfo, $platformFieldsInfo);
        } catch (\Throwable $e) {
            Log::warning('AI mapping suggestion failed, using fallback', [
                'template_id' => $template->id,
                'platform' => $platformKey,
                'error' => $e->getMessage(),
            ]);

            return $this->generateFallbackMappings($templateFieldsInfo, $platformFieldsInfo);
        }
    }

    /**
     * Save mappings for a template and platform.
     */
    public function saveMappings(
        ProductTemplate $template,
        string|Platform $platform,
        array $fieldMappings,
        array $defaultValues = [],
        bool $isAiGenerated = false
    ): TemplatePlatformMapping {
        $platformValue = $platform instanceof Platform ? $platform->value : $platform;

        return TemplatePlatformMapping::updateOrCreate(
            [
                'product_template_id' => $template->id,
                'platform' => $platformValue,
            ],
            [
                'field_mappings' => $fieldMappings,
                'default_values' => $defaultValues,
                'is_ai_generated' => $isAiGenerated,
            ]
        );
    }

    /**
     * Get existing mappings for a template and platform.
     */
    public function getMappings(ProductTemplate $template, string|Platform $platform): ?TemplatePlatformMapping
    {
        $platformValue = $platform instanceof Platform ? $platform->value : $platform;

        return TemplatePlatformMapping::where('product_template_id', $template->id)
            ->where('platform', $platformValue)
            ->first();
    }

    /**
     * Transform product attributes using template mappings.
     *
     * @return array<string, mixed>
     */
    public function transformAttributes(Product $product, string|Platform $platform): array
    {
        $template = $product->getTemplate();
        $platformValue = $platform instanceof Platform ? $platform->value : $platform;

        if (! $template) {
            return [];
        }

        $mapping = $this->getMappings($template, $platformValue);

        if (! $mapping) {
            return [];
        }

        $transformed = [];
        $fieldMappings = $mapping->field_mappings ?? [];
        $defaultValues = $mapping->default_values ?? [];

        // Get product attribute values
        $attributeValues = $product->attributeValues->keyBy('product_template_field_id');
        $templateFields = $template->fields->keyBy('id');

        // Apply mappings
        foreach ($fieldMappings as $templateFieldName => $platformFieldName) {
            // Find the template field
            $templateField = $template->fields->firstWhere('name', $templateFieldName);

            if ($templateField) {
                $attributeValue = $attributeValues->get($templateField->id);
                $value = $attributeValue?->value;

                if ($value !== null && $value !== '') {
                    $transformed[$platformFieldName] = $value;
                }
            }
        }

        // Apply default values for unmapped required fields
        foreach ($defaultValues as $platformField => $defaultValue) {
            if (! isset($transformed[$platformField]) && $defaultValue !== null) {
                $transformed[$platformField] = $defaultValue;
            }
        }

        return $transformed;
    }

    /**
     * Get unmapped required fields for a platform.
     *
     * @return array<string>
     */
    public function getUnmappedRequiredFields(ProductTemplate $template, string|Platform $platform): array
    {
        $platformValue = $platform instanceof Platform ? $platform->value : $platform;
        $mapping = $this->getMappings($template, $platformValue);
        $requiredFields = $this->getRequiredPlatformFields($platformValue);

        if (! $mapping) {
            return array_keys($requiredFields);
        }

        $mappedPlatformFields = array_values($mapping->field_mappings ?? []);
        $defaultValueFields = array_keys($mapping->default_values ?? []);

        return array_values(array_diff(
            array_keys($requiredFields),
            $mappedPlatformFields,
            $defaultValueFields
        ));
    }

    /**
     * Build the AI prompt for mapping suggestions.
     */
    protected function buildMappingPrompt(string $templateName, array $templateFields, string $platform, array $platformFields): string
    {
        $templateFieldsJson = json_encode($templateFields, JSON_PRETTY_PRINT);
        $platformFieldsJson = json_encode($platformFields, JSON_PRETTY_PRINT);

        return <<<PROMPT
You are mapping product template fields to platform-specific fields for {$platform}.

Template: "{$templateName}"
Template Fields:
{$templateFieldsJson}

Platform: {$platform}
Platform Fields:
{$platformFieldsJson}

Analyze the template fields and suggest the best mappings to platform fields based on:
1. Field names and labels (semantic similarity)
2. Field types (text to text, number to number, etc.)
3. Common e-commerce conventions

Return ONLY valid JSON in this exact format (no other text):
{
  "mappings": {
    "template_field_name": {"maps_to": "platform_field_name", "confidence": 0.95}
  },
  "unmapped_template_fields": ["field_name"],
  "unmapped_required_platform_fields": ["platform_field"]
}

Rules:
- Map fields with confidence scores from 0.0 to 1.0
- Only include mappings with confidence >= 0.5
- Include all unmapped template fields
- Include all unmapped required platform fields
- Common mappings: title->title, description->description/body_html, brand->brand/vendor
PROMPT;
    }

    /**
     * Parse the AI response for mappings.
     *
     * @return array{mappings: array<string, array{maps_to: string, confidence: float}>, unmapped_template_fields: array<string>, unmapped_required_platform_fields: array<string>}
     */
    protected function parseMappingResponse(string $response, array $templateFields, array $platformFields): array
    {
        // Extract JSON from response (handle markdown code blocks)
        $json = $response;
        if (preg_match('/```(?:json)?\s*([\s\S]*?)\s*```/', $response, $matches)) {
            $json = $matches[1];
        }

        $parsed = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE || ! isset($parsed['mappings'])) {
            Log::warning('Failed to parse AI mapping response', [
                'error' => json_last_error_msg(),
                'response' => $response,
            ]);

            return $this->generateFallbackMappings($templateFields, $platformFields);
        }

        return [
            'mappings' => $parsed['mappings'] ?? [],
            'unmapped_template_fields' => $parsed['unmapped_template_fields'] ?? [],
            'unmapped_required_platform_fields' => $parsed['unmapped_required_platform_fields'] ?? [],
        ];
    }

    /**
     * Generate fallback mappings based on field name matching.
     *
     * @return array{mappings: array<string, array{maps_to: string, confidence: float}>, unmapped_template_fields: array<string>, unmapped_required_platform_fields: array<string>}
     */
    protected function generateFallbackMappings(array $templateFields, array $platformFields): array
    {
        $mappings = [];
        $mappedTemplateFields = [];
        $mappedPlatformFields = [];

        // Common name mappings
        $nameAliases = [
            'title' => ['title', 'name', 'item_name', 'productName', 'product_name'],
            'description' => ['description', 'body_html', 'product_description', 'shortDescription', 'longDescription'],
            'brand' => ['brand', 'brand_name', 'vendor', 'manufacturer'],
            'color' => ['color', 'color_name', 'primary_color'],
            'size' => ['size', 'size_name'],
            'material' => ['material', 'material_type', 'materials'],
            'condition' => ['condition'],
            'mpn' => ['mpn', 'part_number', 'model_number'],
        ];

        // Try to match fields
        foreach ($templateFields as $templateField) {
            $templateName = strtolower($templateField['name']);

            foreach ($platformFields as $platformField) {
                $platformName = $platformField['name'];
                $platformNameLower = strtolower($platformName);

                // Direct match
                if ($templateName === $platformNameLower) {
                    $mappings[$templateField['name']] = [
                        'maps_to' => $platformName,
                        'confidence' => 1.0,
                    ];
                    $mappedTemplateFields[] = $templateField['name'];
                    $mappedPlatformFields[] = $platformName;

                    continue 2;
                }

                // Alias match
                foreach ($nameAliases as $canonical => $aliases) {
                    if (in_array($templateName, $aliases) && in_array($platformNameLower, $aliases)) {
                        $mappings[$templateField['name']] = [
                            'maps_to' => $platformName,
                            'confidence' => 0.9,
                        ];
                        $mappedTemplateFields[] = $templateField['name'];
                        $mappedPlatformFields[] = $platformName;

                        continue 3;
                    }
                }
            }
        }

        $unmappedTemplateFields = array_values(array_diff(
            array_column($templateFields, 'name'),
            $mappedTemplateFields
        ));

        $requiredPlatformFields = array_filter(
            $platformFields,
            fn ($f) => $f['required']
        );
        $unmappedRequiredPlatformFields = array_values(array_diff(
            array_column($requiredPlatformFields, 'name'),
            $mappedPlatformFields
        ));

        return [
            'mappings' => $mappings,
            'unmapped_template_fields' => $unmappedTemplateFields,
            'unmapped_required_platform_fields' => $unmappedRequiredPlatformFields,
        ];
    }
}
