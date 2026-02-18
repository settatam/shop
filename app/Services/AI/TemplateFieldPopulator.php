<?php

namespace App\Services\AI;

use App\Models\AiSuggestion;
use App\Models\AiUsageLog;
use App\Models\Category;
use App\Models\ProductTemplate;
use App\Models\StoreIntegration;
use App\Models\TransactionItem;
use Illuminate\Support\Facades\Http;

class TemplateFieldPopulator
{
    protected string $apiKey;

    protected string $model;

    protected string $baseUrl = 'https://api.anthropic.com/v1';

    public function __construct()
    {
        $this->apiKey = config('services.anthropic.api_key') ?? '';
        $this->model = config('services.anthropic.model') ?? 'claude-sonnet-4-20250514';
    }

    /**
     * Populate template fields for a transaction item using AI.
     *
     * @return array{fields: array<int, string>, product_info: array<string, mixed>, error?: string}
     */
    public function populateFields(TransactionItem $item): array
    {
        $item->load(['category', 'images', 'transaction']);

        // Get the template for this item's category
        $template = $this->getTemplateForItem($item);

        if (! $template) {
            return [
                'error' => 'No template found for this item\'s category.',
                'fields' => [],
                'product_info' => [],
            ];
        }

        // Check for store-specific Anthropic integration
        $storeId = $item->transaction->store_id;
        $integration = StoreIntegration::findActiveForStore($storeId, StoreIntegration::PROVIDER_ANTHROPIC);

        if ($integration) {
            $this->apiKey = $integration->getAnthropicApiKey();
            $this->model = $integration->getAnthropicModel();
        }

        if (empty($this->apiKey)) {
            return [
                'error' => 'Anthropic API key not configured. Please add your API key in Settings → Integrations.',
                'fields' => [],
                'product_info' => [],
            ];
        }

        $template->load('fields.options');
        $imageUrls = $item->images->take(4)->pluck('url')->filter()->toArray();

        return $this->callAI(
            storeId: $storeId,
            title: $item->title ?? '',
            description: $item->description,
            categoryName: $item->category?->full_path ?? $item->category?->name,
            template: $template,
            imageUrls: $imageUrls,
            subjectType: TransactionItem::class,
            subjectId: $item->id,
        );
    }

    /**
     * Populate template fields from raw data (not a TransactionItem).
     *
     * @param  array<int, string>  $imageUrls
     * @return array{fields: array<int, string>, product_info: array<string, mixed>, error?: string}
     */
    public function populateFieldsFromData(
        int $storeId,
        int $categoryId,
        string $title,
        ?string $description = null,
        array $imageUrls = [],
    ): array {
        $category = Category::with('template.fields.options')->find($categoryId);

        if (! $category) {
            return [
                'error' => 'Category not found.',
                'fields' => [],
                'product_info' => [],
            ];
        }

        $template = $category->getEffectiveTemplate();

        if (! $template) {
            return [
                'error' => 'No template found for this category.',
                'fields' => [],
                'product_info' => [],
            ];
        }

        // Check for store-specific Anthropic integration
        $integration = StoreIntegration::findActiveForStore($storeId, StoreIntegration::PROVIDER_ANTHROPIC);

        if ($integration) {
            $this->apiKey = $integration->getAnthropicApiKey();
            $this->model = $integration->getAnthropicModel();
        }

        if (empty($this->apiKey)) {
            return [
                'error' => 'Anthropic API key not configured.',
                'fields' => [],
                'product_info' => [],
            ];
        }

        $template->load('fields.options');

        return $this->callAI(
            storeId: $storeId,
            title: $title,
            description: $description,
            categoryName: $category->full_path ?? $category->name,
            template: $template,
            imageUrls: $imageUrls,
        );
    }

    /**
     * Call the AI API to identify the product and suggest field values.
     *
     * @param  array<int, string>  $imageUrls
     * @return array{fields: array<int, string>, product_info: array<string, mixed>, error?: string}
     */
    protected function callAI(
        int $storeId,
        string $title,
        ?string $description,
        ?string $categoryName,
        ProductTemplate $template,
        array $imageUrls = [],
        ?string $subjectType = null,
        ?int $subjectId = null,
    ): array {
        $prompt = $this->buildPrompt($title, $description, $categoryName, $template);
        $messages = [['role' => 'user', 'content' => $prompt]];

        // Include images if available (critical for product identification)
        if (! empty($imageUrls)) {
            $content = [];
            foreach (array_slice($imageUrls, 0, 4) as $imageUrl) {
                $content[] = [
                    'type' => 'image',
                    'source' => [
                        'type' => 'url',
                        'url' => $imageUrl,
                    ],
                ];
            }
            $content[] = [
                'type' => 'text',
                'text' => $prompt,
            ];
            $messages = [['role' => 'user', 'content' => $content]];
        }

        $systemPrompt = $this->buildSystemPrompt($categoryName);

        $response = Http::withHeaders([
            'x-api-key' => $this->apiKey,
            'anthropic-version' => '2023-06-01',
            'Content-Type' => 'application/json',
        ])->timeout(120)->post("{$this->baseUrl}/messages", [
            'model' => $this->model,
            'messages' => $messages,
            'max_tokens' => 2048,
            'system' => $systemPrompt,
        ]);

        if ($response->failed()) {
            $errorBody = $response->json();
            $errorMessage = $errorBody['error']['message'] ?? 'Failed to identify product. Please try again.';

            return [
                'error' => $errorMessage,
                'fields' => [],
                'product_info' => [],
            ];
        }

        $responseData = $response->json();
        $text = $responseData['data']['content'][0]['text'] ?? $responseData['content'][0]['text'] ?? '';

        // Parse the JSON response
        $result = $this->parseResponse($text, $template);

        // Log usage
        $inputTokens = $responseData['usage']['input_tokens'] ?? 0;
        $outputTokens = $responseData['usage']['output_tokens'] ?? 0;

        AiUsageLog::logUsage(
            storeId: $storeId,
            provider: 'anthropic',
            model: $this->model,
            feature: 'template_field_population',
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            userId: auth()->id(),
        );

        // Create suggestion record if we have a subject
        if ($subjectType && $subjectId) {
            AiSuggestion::create([
                'store_id' => $storeId,
                'suggestable_type' => $subjectType,
                'suggestable_id' => $subjectId,
                'type' => 'template_fields',
                'suggested_content' => json_encode($result),
                'status' => 'pending',
            ]);
        }

        return $result;
    }

    /**
     * Build the system prompt based on the category.
     */
    protected function buildSystemPrompt(?string $categoryName): string
    {
        $base = 'You are an expert product identifier and appraiser. ';

        // Category-specific expertise
        $categoryLower = strtolower($categoryName ?? '');

        if (str_contains($categoryLower, 'watch')) {
            $base .= 'You specialize in luxury watches including Rolex, Omega, Patek Philippe, Audemars Piguet, Cartier, and other brands. You can identify models by visual features, dial configurations, bezel types, and case designs. ';
        } elseif (str_contains($categoryLower, 'handbag') || str_contains($categoryLower, 'bag') || str_contains($categoryLower, 'purse')) {
            $base .= 'You specialize in luxury handbags including Hermès, Louis Vuitton, Chanel, Gucci, Prada, and other brands. You can identify models by shape, hardware, leather type, and design elements. ';
        } elseif (str_contains($categoryLower, 'jewelry') || str_contains($categoryLower, 'ring') || str_contains($categoryLower, 'necklace')) {
            $base .= 'You specialize in fine jewelry including engagement rings, designer pieces, and precious metal items. You can identify styles, settings, and materials. ';
        } elseif (str_contains($categoryLower, 'diamond') || str_contains($categoryLower, 'gem')) {
            $base .= 'You specialize in gemstones and diamonds. You can estimate characteristics like cut, color, clarity, and carat weight from images. ';
        }

        $base .= 'Analyze the provided images and information carefully. If you can identify the specific product, provide exact details. If uncertain, provide your best assessment with appropriate confidence levels. Always respond with valid JSON.';

        return $base;
    }

    /**
     * Build the prompt for field population.
     */
    protected function buildPrompt(
        string $title,
        ?string $description,
        ?string $categoryName,
        ProductTemplate $template,
    ): string {
        $prompt = "Identify this product and extract attribute values.\n\n";
        $prompt .= "ITEM INFORMATION:\n";
        $prompt .= "Title: {$title}\n";

        if ($description) {
            $prompt .= "Description: {$description}\n";
        }
        if ($categoryName) {
            $prompt .= "Category: {$categoryName}\n";
        }

        $prompt .= "\nTEMPLATE FIELDS TO POPULATE:\n";

        foreach ($template->fields as $field) {
            $prompt .= "- {$field->label} (field_id: {$field->id}, type: {$field->type})";

            if ($field->hasOptions()) {
                $options = $field->options->pluck('value')->toArray();
                $prompt .= ' [Options: '.implode(', ', array_slice($options, 0, 20)).(count($options) > 20 ? '...' : '').']';
            }

            $prompt .= "\n";
        }

        $prompt .= "\nRespond with a JSON object in this exact format:\n";
        $prompt .= "{\n";
        $prompt .= '  "identified": true|false,'."\n";
        $prompt .= '  "confidence": "high"|"medium"|"low",'."\n";
        $prompt .= '  "product_info": {'."\n";
        $prompt .= '    "brand": "string or null",'."\n";
        $prompt .= '    "model": "string or null",'."\n";
        $prompt .= '    "reference_number": "string or null",'."\n";
        $prompt .= '    "year": "string or null",'."\n";
        $prompt .= '    "description": "detailed description of the item"'."\n";
        $prompt .= '  },'."\n";
        $prompt .= '  "fields": {'."\n";
        $prompt .= '    "<field_id>": "suggested value",'."\n";
        $prompt .= '    ...'."\n";
        $prompt .= '  },'."\n";
        $prompt .= '  "notes": "any additional observations or uncertainty notes"'."\n";
        $prompt .= "}\n\n";

        $prompt .= "IMPORTANT:\n";
        $prompt .= "- Use field_id (number) as the key in the fields object\n";
        $prompt .= "- For select/checkbox/radio fields, use values from the provided options list\n";
        $prompt .= "- Only include fields you have reasonable confidence about\n";
        $prompt .= "- If you cannot identify the product, set identified to false and still try to extract visible attributes\n";

        return $prompt;
    }

    /**
     * Parse the AI response and extract field values.
     *
     * @return array{fields: array<int, string>, product_info: array<string, mixed>, identified: bool, confidence: string, notes: string|null}
     */
    protected function parseResponse(string $text, ProductTemplate $template): array
    {
        // Try to extract JSON from the response
        $jsonMatch = preg_match('/\{[\s\S]*\}/', $text, $matches);

        if ($jsonMatch) {
            $parsed = json_decode($matches[0], true);

            if ($parsed && isset($parsed['fields'])) {
                // Convert field IDs to integers and validate
                $fields = [];
                $validFieldIds = $template->fields->pluck('id')->toArray();

                foreach ($parsed['fields'] as $fieldId => $value) {
                    $intFieldId = (int) $fieldId;
                    if (in_array($intFieldId, $validFieldIds) && $value !== null && $value !== '') {
                        $fields[$intFieldId] = (string) $value;
                    }
                }

                return [
                    'identified' => $parsed['identified'] ?? false,
                    'confidence' => $parsed['confidence'] ?? 'low',
                    'product_info' => $parsed['product_info'] ?? [],
                    'fields' => $fields,
                    'notes' => $parsed['notes'] ?? null,
                ];
            }
        }

        // Fallback if JSON parsing fails
        return [
            'identified' => false,
            'confidence' => 'low',
            'product_info' => [],
            'fields' => [],
            'notes' => 'Failed to parse AI response.',
            'raw_response' => $text,
        ];
    }

    /**
     * Get the template for a transaction item based on its category.
     */
    protected function getTemplateForItem(TransactionItem $item): ?ProductTemplate
    {
        if (! $item->category_id || ! $item->category) {
            return null;
        }

        return $item->category->getEffectiveTemplate();
    }
}
