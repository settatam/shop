<?php

namespace App\Services\AI;

use App\Models\Category;
use App\Models\EbayCategory;
use App\Models\EbayItemSpecific;
use App\Models\Platform;
use App\Models\ProductTemplate;
use App\Models\Store;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TemplateGeneratorService
{
    /**
     * Platform field requirements for common product types.
     * This serves as context for the AI to generate comprehensive templates.
     */
    protected array $platformRequirements = [
        'jewelry' => [
            'ebay' => ['Metal', 'Metal Purity', 'Main Stone', 'Total Carat Weight', 'Ring Size', 'Chain Length', 'Brand', 'Style', 'Type'],
            'amazon' => ['metal-type', 'metal-stamp', 'gem-type', 'total-carat-weight', 'ring-size', 'chain-length', 'brand', 'item-shape'],
            'etsy' => ['primary_material', 'secondary_material', 'gemstone', 'occasion', 'style'],
        ],
        'handbags' => [
            'ebay' => ['Brand', 'Material', 'Color', 'Style', 'Size', 'Closure Type', 'Features'],
            'amazon' => ['brand', 'material-type', 'color', 'strap-type', 'closure-type', 'number-of-pockets', 'pattern'],
            'etsy' => ['primary_material', 'color', 'occasion', 'style', 'strap_style'],
        ],
        'clothing' => [
            'ebay' => ['Brand', 'Size', 'Color', 'Material', 'Style', 'Neckline', 'Sleeve Length', 'Pattern', 'Occasion'],
            'amazon' => ['brand', 'size', 'color', 'material-type', 'style', 'neckline', 'sleeve-type', 'pattern-type', 'occasion-type'],
            'etsy' => ['primary_material', 'color', 'occasion', 'style', 'size'],
        ],
        'electronics' => [
            'ebay' => ['Brand', 'Model', 'MPN', 'Type', 'Connectivity', 'Color', 'Storage Capacity', 'Screen Size'],
            'amazon' => ['brand', 'model-number', 'part-number', 'product-type', 'connectivity-type', 'color', 'memory-storage-capacity', 'display-size'],
            'etsy' => ['brand', 'color'],
        ],
        'watches' => [
            'ebay' => ['Brand', 'Model', 'Case Material', 'Band Material', 'Movement', 'Display', 'Case Size', 'Water Resistance', 'Features'],
            'amazon' => ['brand', 'model-number', 'case-material-type', 'band-material-type', 'movement-type', 'display-type', 'case-diameter', 'water-resistance-depth'],
            'etsy' => ['primary_material', 'secondary_material', 'style', 'occasion'],
        ],
        'home_decor' => [
            'ebay' => ['Brand', 'Material', 'Color', 'Style', 'Room', 'Theme', 'Features'],
            'amazon' => ['brand', 'material-type', 'color', 'style', 'room-type', 'theme', 'pattern'],
            'etsy' => ['primary_material', 'color', 'room', 'style', 'occasion'],
        ],
    ];

    /**
     * Generate a template based on user's description of what they want to sell.
     *
     * @return array{template: array, category: array, fields: array}
     */
    public function generateFromPrompt(string $prompt, Store $store): array
    {
        // Get active platforms for context
        $platforms = Platform::active()->get();

        // Build the AI prompt
        $systemPrompt = $this->buildSystemPrompt($platforms);
        $userPrompt = $this->buildUserPrompt($prompt);

        // Call the AI API
        $response = $this->callAI($systemPrompt, $userPrompt);

        // Parse and validate the response
        $parsed = $this->parseAIResponse($response);

        return $parsed;
    }

    /**
     * Create the template and category from AI response.
     */
    public function createFromAIResponse(array $aiResponse, Store $store): ProductTemplate
    {
        // Create the category first
        $category = Category::create([
            'store_id' => $store->id,
            'name' => $aiResponse['category']['name'],
            'slug' => $aiResponse['category']['slug'] ?? str($aiResponse['category']['name'])->slug(),
            'description' => $aiResponse['category']['description'] ?? null,
        ]);

        // Create the template
        $template = ProductTemplate::create([
            'store_id' => $store->id,
            'name' => $aiResponse['template']['name'],
            'description' => $aiResponse['template']['description'] ?? null,
            'is_active' => true,
            'ai_generated' => true,
            'generation_prompt' => $aiResponse['original_prompt'] ?? null,
        ]);

        // Assign template to category
        $category->update(['template_id' => $template->id]);

        // Create fields
        foreach ($aiResponse['fields'] as $index => $fieldData) {
            $field = $template->fields()->create([
                'name' => $fieldData['name'],
                'canonical_name' => $fieldData['canonical_name'] ?? $fieldData['name'],
                'label' => $fieldData['label'],
                'type' => $fieldData['type'],
                'placeholder' => $fieldData['placeholder'] ?? null,
                'help_text' => $fieldData['help_text'] ?? null,
                'is_required' => $fieldData['is_required'] ?? false,
                'is_searchable' => $fieldData['is_searchable'] ?? false,
                'is_filterable' => $fieldData['is_filterable'] ?? false,
                'show_in_listing' => $fieldData['show_in_listing'] ?? false,
                'group_name' => $fieldData['group_name'] ?? null,
                'group_position' => $fieldData['group_position'] ?? 1,
                'width_class' => $fieldData['width_class'] ?? 'full',
                'sort_order' => $index,
                'ai_generated' => true,
            ]);

            // Create options if provided
            if (! empty($fieldData['options'])) {
                foreach ($fieldData['options'] as $optIndex => $option) {
                    $field->options()->create([
                        'label' => $option['label'],
                        'value' => $option['value'],
                        'sort_order' => $optIndex,
                    ]);
                }
            }

            // Create platform mappings if provided
            if (! empty($fieldData['platform_mappings'])) {
                foreach ($fieldData['platform_mappings'] as $mapping) {
                    $platform = Platform::where('slug', $mapping['platform'])->first();
                    if ($platform) {
                        $field->platformMappings()->create([
                            'platform_id' => $platform->id,
                            'platform_field_name' => $mapping['field_name'],
                            'is_required' => $mapping['is_required'] ?? false,
                            'is_recommended' => $mapping['is_recommended'] ?? false,
                            'accepted_values' => $mapping['accepted_values'] ?? null,
                        ]);
                    }
                }
            }
        }

        return $template->load(['fields.options', 'fields.platformMappings.platform', 'categories']);
    }

    /**
     * Build the system prompt for AI.
     */
    protected function buildSystemPrompt($platforms): string
    {
        $platformNames = $platforms->pluck('name')->implode(', ');
        $requirementsJson = json_encode($this->platformRequirements, JSON_PRETTY_PRINT);

        return <<<PROMPT
You are an expert e-commerce product data specialist. Your job is to help sellers create comprehensive product templates that will work across multiple selling platforms.

SUPPORTED PLATFORMS: {$platformNames}

PLATFORM FIELD REQUIREMENTS BY PRODUCT TYPE:
{$requirementsJson}

When a user describes what they want to sell, you must:
1. Identify the product type/category
2. Create a comprehensive template with ALL fields needed to list successfully on major platforms
3. Include both required and recommended fields
4. Use appropriate field types (text, number, select, etc.)
5. Provide predefined options for fields where applicable
6. Group related fields together (e.g., "dimensions" group for length/width/height)
7. Map each field to the corresponding platform fields

RESPONSE FORMAT (JSON):
{
  "category": {
    "name": "Category Name",
    "slug": "category-slug",
    "description": "Brief category description"
  },
  "template": {
    "name": "Template Name",
    "description": "Template description"
  },
  "fields": [
    {
      "name": "field_name",
      "canonical_name": "standard_field_identifier",
      "label": "Field Label",
      "type": "text|textarea|number|select|checkbox|radio|date",
      "placeholder": "Placeholder text",
      "help_text": "Help text for the user",
      "is_required": true|false,
      "is_searchable": true|false,
      "is_filterable": true|false,
      "show_in_listing": true|false,
      "group_name": "group_name_if_grouped",
      "group_position": 1,
      "width_class": "full|half|third|quarter",
      "options": [
        {"label": "Option Label", "value": "option_value"}
      ],
      "platform_mappings": [
        {
          "platform": "ebay|amazon|etsy|shopify",
          "field_name": "Platform's Field Name",
          "is_required": true|false,
          "is_recommended": true|false,
          "accepted_values": ["value1", "value2"]
        }
      ]
    }
  ]
}

IMPORTANT GUIDELINES:
- Always include Brand as a field
- Include Condition field for used/new items
- For items with sizes, include a size field with appropriate options
- For items with colors, include a color field
- Group measurement fields together (dimensions, weight)
- Include unit fields alongside measurement fields
- Make commonly filtered attributes filterable
- Make commonly searched attributes searchable
PROMPT;
    }

    /**
     * Build the user prompt.
     */
    protected function buildUserPrompt(string $prompt): string
    {
        return "I want to sell: {$prompt}\n\nPlease generate a comprehensive product template that will allow me to list these products on all major selling platforms. Return ONLY valid JSON, no additional text.";
    }

    /**
     * Call the AI API.
     */
    protected function callAI(string $systemPrompt, string $userPrompt): string
    {
        $apiKey = config('services.openai.api_key') ?? config('services.anthropic.api_key');
        $provider = config('services.ai.provider', 'openai');

        if (! $apiKey) {
            // Return a fallback template if no API key is configured
            Log::warning('No AI API key configured, using fallback template generation');

            return $this->generateFallbackResponse($userPrompt);
        }

        if ($provider === 'anthropic') {
            return $this->callAnthropic($apiKey, $systemPrompt, $userPrompt);
        }

        return $this->callOpenAI($apiKey, $systemPrompt, $userPrompt);
    }

    /**
     * Call OpenAI API.
     */
    protected function callOpenAI(string $apiKey, string $systemPrompt, string $userPrompt): string
    {
        $response = Http::withHeaders([
            'Authorization' => "Bearer {$apiKey}",
            'Content-Type' => 'application/json',
        ])->post('https://api.openai.com/v1/chat/completions', [
            'model' => config('services.openai.model', 'gpt-4o'),
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt],
            ],
            'temperature' => 0.7,
            'max_tokens' => 4000,
        ]);

        if ($response->failed()) {
            Log::error('OpenAI API call failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \RuntimeException('Failed to generate template: '.$response->body());
        }

        return $response->json('choices.0.message.content');
    }

    /**
     * Call Anthropic API.
     */
    protected function callAnthropic(string $apiKey, string $systemPrompt, string $userPrompt): string
    {
        $response = Http::withHeaders([
            'x-api-key' => $apiKey,
            'Content-Type' => 'application/json',
            'anthropic-version' => '2023-06-01',
        ])->post('https://api.anthropic.com/v1/messages', [
            'model' => config('services.anthropic.model', 'claude-sonnet-4-20250514'),
            'max_tokens' => 4000,
            'system' => $systemPrompt,
            'messages' => [
                ['role' => 'user', 'content' => $userPrompt],
            ],
        ]);

        if ($response->failed()) {
            Log::error('Anthropic API call failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \RuntimeException('Failed to generate template: '.$response->body());
        }

        return $response->json('content.0.text');
    }

    /**
     * Parse and validate AI response.
     *
     * @return array{template: array, category: array, fields: array}
     */
    protected function parseAIResponse(string $response): array
    {
        // Extract JSON from response (handle markdown code blocks)
        $json = $response;
        if (preg_match('/```(?:json)?\s*([\s\S]*?)\s*```/', $response, $matches)) {
            $json = $matches[1];
        }

        $parsed = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::error('Failed to parse AI response', [
                'error' => json_last_error_msg(),
                'response' => $response,
            ]);
            throw new \RuntimeException('Failed to parse AI response: '.json_last_error_msg());
        }

        // Validate required structure
        if (! isset($parsed['category'], $parsed['template'], $parsed['fields'])) {
            throw new \RuntimeException('AI response missing required fields');
        }

        return $parsed;
    }

    /**
     * Generate a fallback response when no AI API is available.
     */
    protected function generateFallbackResponse(string $prompt): string
    {
        // Detect product type from prompt
        $prompt = strtolower($prompt);
        $productType = 'general';

        if (str_contains($prompt, 'jewelry') || str_contains($prompt, 'ring') || str_contains($prompt, 'necklace') || str_contains($prompt, 'bracelet')) {
            $productType = 'jewelry';
        } elseif (str_contains($prompt, 'handbag') || str_contains($prompt, 'purse') || str_contains($prompt, 'bag')) {
            $productType = 'handbags';
        } elseif (str_contains($prompt, 'clothing') || str_contains($prompt, 'shirt') || str_contains($prompt, 'dress') || str_contains($prompt, 'pants')) {
            $productType = 'clothing';
        } elseif (str_contains($prompt, 'watch')) {
            $productType = 'watches';
        } elseif (str_contains($prompt, 'electronic') || str_contains($prompt, 'phone') || str_contains($prompt, 'laptop') || str_contains($prompt, 'computer')) {
            $productType = 'electronics';
        }

        return json_encode($this->getFallbackTemplate($productType));
    }

    /**
     * Get fallback template for a product type.
     */
    protected function getFallbackTemplate(string $productType): array
    {
        $templates = [
            'jewelry' => [
                'category' => ['name' => 'Jewelry', 'slug' => 'jewelry', 'description' => 'Jewelry items including rings, necklaces, bracelets, and earrings'],
                'template' => ['name' => 'Jewelry', 'description' => 'Comprehensive template for jewelry items'],
                'fields' => [
                    ['name' => 'brand', 'canonical_name' => 'brand', 'label' => 'Brand', 'type' => 'text', 'is_required' => false, 'is_filterable' => true, 'is_searchable' => true, 'width_class' => 'half'],
                    ['name' => 'condition', 'canonical_name' => 'condition', 'label' => 'Condition', 'type' => 'select', 'is_required' => true, 'is_filterable' => true, 'width_class' => 'half', 'options' => [['label' => 'New', 'value' => 'new'], ['label' => 'Pre-owned', 'value' => 'pre_owned'], ['label' => 'Vintage', 'value' => 'vintage']]],
                    ['name' => 'metal_type', 'canonical_name' => 'metal_type', 'label' => 'Metal Type', 'type' => 'select', 'is_required' => true, 'is_filterable' => true, 'is_searchable' => true, 'show_in_listing' => true, 'width_class' => 'half', 'options' => [['label' => 'Gold', 'value' => 'gold'], ['label' => 'Silver', 'value' => 'silver'], ['label' => 'Platinum', 'value' => 'platinum'], ['label' => 'Palladium', 'value' => 'palladium'], ['label' => 'Titanium', 'value' => 'titanium'], ['label' => 'Stainless Steel', 'value' => 'stainless_steel']]],
                    ['name' => 'metal_purity', 'canonical_name' => 'metal_purity', 'label' => 'Metal Purity', 'type' => 'select', 'is_filterable' => true, 'width_class' => 'half', 'options' => [['label' => '24K (99.9%)', 'value' => '24k'], ['label' => '22K (91.7%)', 'value' => '22k'], ['label' => '18K (75%)', 'value' => '18k'], ['label' => '14K (58.3%)', 'value' => '14k'], ['label' => '10K (41.7%)', 'value' => '10k'], ['label' => '925 Sterling', 'value' => '925'], ['label' => '950 Platinum', 'value' => '950']]],
                    ['name' => 'gemstone_type', 'canonical_name' => 'gemstone_type', 'label' => 'Gemstone Type', 'type' => 'select', 'is_filterable' => true, 'is_searchable' => true, 'width_class' => 'half', 'options' => [['label' => 'Diamond', 'value' => 'diamond'], ['label' => 'Ruby', 'value' => 'ruby'], ['label' => 'Sapphire', 'value' => 'sapphire'], ['label' => 'Emerald', 'value' => 'emerald'], ['label' => 'Pearl', 'value' => 'pearl'], ['label' => 'Opal', 'value' => 'opal'], ['label' => 'Amethyst', 'value' => 'amethyst'], ['label' => 'None', 'value' => 'none']]],
                    ['name' => 'total_carat_weight', 'canonical_name' => 'total_carat_weight', 'label' => 'Total Carat Weight', 'type' => 'number', 'placeholder' => '0.00', 'help_text' => 'Combined carat weight of all stones', 'is_filterable' => true, 'width_class' => 'half'],
                    ['name' => 'ring_size', 'canonical_name' => 'ring_size', 'label' => 'Ring Size', 'type' => 'text', 'placeholder' => 'e.g. 7, 7.5', 'help_text' => 'US ring size (leave blank if not a ring)', 'is_filterable' => true, 'width_class' => 'half'],
                    ['name' => 'chain_length', 'canonical_name' => 'chain_length', 'label' => 'Chain Length', 'type' => 'number', 'placeholder' => '0', 'group_name' => 'chain_length', 'group_position' => 1, 'width_class' => 'third'],
                    ['name' => 'chain_length_unit', 'canonical_name' => 'chain_length_unit', 'label' => 'Unit', 'type' => 'select', 'group_name' => 'chain_length', 'group_position' => 2, 'width_class' => 'third', 'options' => [['label' => 'inches', 'value' => 'in'], ['label' => 'cm', 'value' => 'cm']]],
                    ['name' => 'metal_weight', 'canonical_name' => 'metal_weight', 'label' => 'Metal Weight', 'type' => 'number', 'placeholder' => '0.00', 'group_name' => 'metal_weight', 'group_position' => 1, 'width_class' => 'third'],
                    ['name' => 'metal_weight_unit', 'canonical_name' => 'metal_weight_unit', 'label' => 'Unit', 'type' => 'select', 'group_name' => 'metal_weight', 'group_position' => 2, 'width_class' => 'third', 'options' => [['label' => 'grams', 'value' => 'g'], ['label' => 'oz', 'value' => 'oz'], ['label' => 'dwt', 'value' => 'dwt']]],
                    ['name' => 'certificate_number', 'canonical_name' => 'certificate_number', 'label' => 'Certificate Number', 'type' => 'text', 'placeholder' => 'GIA, AGS, etc.', 'help_text' => 'Grading certificate number if available', 'width_class' => 'half'],
                ],
            ],
            'handbags' => [
                'category' => ['name' => 'Handbags', 'slug' => 'handbags', 'description' => 'Handbags, purses, wallets, and leather goods'],
                'template' => ['name' => 'Handbags & Accessories', 'description' => 'Comprehensive template for handbags and accessories'],
                'fields' => [
                    ['name' => 'brand', 'canonical_name' => 'brand', 'label' => 'Brand', 'type' => 'text', 'is_required' => true, 'is_filterable' => true, 'is_searchable' => true, 'show_in_listing' => true, 'width_class' => 'half'],
                    ['name' => 'condition', 'canonical_name' => 'condition', 'label' => 'Condition', 'type' => 'select', 'is_required' => true, 'is_filterable' => true, 'width_class' => 'half', 'options' => [['label' => 'New with Tags', 'value' => 'new_with_tags'], ['label' => 'New without Tags', 'value' => 'new_without_tags'], ['label' => 'Pre-owned', 'value' => 'pre_owned'], ['label' => 'Vintage', 'value' => 'vintage']]],
                    ['name' => 'material', 'canonical_name' => 'material', 'label' => 'Material', 'type' => 'select', 'is_required' => true, 'is_filterable' => true, 'is_searchable' => true, 'show_in_listing' => true, 'width_class' => 'half', 'options' => [['label' => 'Genuine Leather', 'value' => 'leather'], ['label' => 'Exotic Leather', 'value' => 'exotic_leather'], ['label' => 'Canvas', 'value' => 'canvas'], ['label' => 'Nylon', 'value' => 'nylon'], ['label' => 'Suede', 'value' => 'suede'], ['label' => 'Vegan Leather', 'value' => 'vegan_leather'], ['label' => 'Fabric', 'value' => 'fabric']]],
                    ['name' => 'color', 'canonical_name' => 'color', 'label' => 'Color', 'type' => 'text', 'placeholder' => 'e.g. Black, Navy, Cognac', 'is_filterable' => true, 'is_searchable' => true, 'width_class' => 'half'],
                    ['name' => 'bag_length', 'canonical_name' => 'length', 'label' => 'Length', 'type' => 'number', 'group_name' => 'dimensions', 'group_position' => 1, 'width_class' => 'quarter'],
                    ['name' => 'bag_width', 'canonical_name' => 'width', 'label' => 'Width', 'type' => 'number', 'group_name' => 'dimensions', 'group_position' => 2, 'width_class' => 'quarter'],
                    ['name' => 'bag_height', 'canonical_name' => 'height', 'label' => 'Height', 'type' => 'number', 'group_name' => 'dimensions', 'group_position' => 3, 'width_class' => 'quarter'],
                    ['name' => 'dimension_unit', 'canonical_name' => 'dimension_unit', 'label' => 'Unit', 'type' => 'select', 'group_name' => 'dimensions', 'group_position' => 4, 'width_class' => 'quarter', 'options' => [['label' => 'inches', 'value' => 'in'], ['label' => 'cm', 'value' => 'cm']]],
                    ['name' => 'closure_type', 'canonical_name' => 'closure_type', 'label' => 'Closure Type', 'type' => 'select', 'is_filterable' => true, 'width_class' => 'half', 'options' => [['label' => 'Zipper', 'value' => 'zipper'], ['label' => 'Magnetic Snap', 'value' => 'magnetic'], ['label' => 'Turn Lock', 'value' => 'turnlock'], ['label' => 'Flap Closure', 'value' => 'flap'], ['label' => 'Drawstring', 'value' => 'drawstring'], ['label' => 'Open Top', 'value' => 'open']]],
                    ['name' => 'hardware_color', 'canonical_name' => 'hardware_color', 'label' => 'Hardware Color', 'type' => 'select', 'is_filterable' => true, 'width_class' => 'half', 'options' => [['label' => 'Gold', 'value' => 'gold'], ['label' => 'Silver', 'value' => 'silver'], ['label' => 'Rose Gold', 'value' => 'rose_gold'], ['label' => 'Gunmetal', 'value' => 'gunmetal'], ['label' => 'Brass', 'value' => 'brass']]],
                    ['name' => 'strap_drop', 'canonical_name' => 'strap_drop', 'label' => 'Strap Drop', 'type' => 'number', 'help_text' => 'Length from top of bag to top of strap', 'group_name' => 'strap_drop', 'group_position' => 1, 'width_class' => 'third'],
                    ['name' => 'strap_drop_unit', 'canonical_name' => 'strap_drop_unit', 'label' => 'Unit', 'type' => 'select', 'group_name' => 'strap_drop', 'group_position' => 2, 'width_class' => 'third', 'options' => [['label' => 'inches', 'value' => 'in'], ['label' => 'cm', 'value' => 'cm']]],
                    ['name' => 'interior_lining', 'canonical_name' => 'interior_lining', 'label' => 'Interior Lining', 'type' => 'text', 'placeholder' => 'e.g. Microfiber, Cotton, Suede', 'width_class' => 'half'],
                    ['name' => 'compartments', 'canonical_name' => 'compartments', 'label' => 'Number of Compartments', 'type' => 'number', 'width_class' => 'half'],
                    ['name' => 'authenticity_code', 'canonical_name' => 'authenticity_code', 'label' => 'Authenticity Code', 'type' => 'text', 'placeholder' => 'Serial or date code', 'help_text' => 'For designer items', 'width_class' => 'half'],
                ],
            ],
            'general' => [
                'category' => ['name' => 'General Products', 'slug' => 'general-products', 'description' => 'General product category'],
                'template' => ['name' => 'General Product', 'description' => 'Basic template for general products'],
                'fields' => [
                    ['name' => 'brand', 'canonical_name' => 'brand', 'label' => 'Brand', 'type' => 'text', 'is_filterable' => true, 'is_searchable' => true, 'width_class' => 'half'],
                    ['name' => 'condition', 'canonical_name' => 'condition', 'label' => 'Condition', 'type' => 'select', 'is_required' => true, 'is_filterable' => true, 'width_class' => 'half', 'options' => [['label' => 'New', 'value' => 'new'], ['label' => 'Used', 'value' => 'used'], ['label' => 'Refurbished', 'value' => 'refurbished']]],
                    ['name' => 'color', 'canonical_name' => 'color', 'label' => 'Color', 'type' => 'text', 'is_filterable' => true, 'is_searchable' => true, 'width_class' => 'half'],
                    ['name' => 'material', 'canonical_name' => 'material', 'label' => 'Material', 'type' => 'text', 'is_filterable' => true, 'width_class' => 'half'],
                    ['name' => 'size', 'canonical_name' => 'size', 'label' => 'Size', 'type' => 'text', 'is_filterable' => true, 'width_class' => 'half'],
                    ['name' => 'model', 'canonical_name' => 'model', 'label' => 'Model', 'type' => 'text', 'is_searchable' => true, 'width_class' => 'half'],
                ],
            ],
        ];

        // Add more fallback templates for other types
        $templates['clothing'] = [
            'category' => ['name' => 'Clothing', 'slug' => 'clothing', 'description' => 'Clothing and apparel items'],
            'template' => ['name' => 'Clothing', 'description' => 'Comprehensive template for clothing items'],
            'fields' => [
                ['name' => 'brand', 'canonical_name' => 'brand', 'label' => 'Brand', 'type' => 'text', 'is_required' => false, 'is_filterable' => true, 'is_searchable' => true, 'show_in_listing' => true, 'width_class' => 'half'],
                ['name' => 'condition', 'canonical_name' => 'condition', 'label' => 'Condition', 'type' => 'select', 'is_required' => true, 'is_filterable' => true, 'width_class' => 'half', 'options' => [['label' => 'New with Tags', 'value' => 'new_with_tags'], ['label' => 'New without Tags', 'value' => 'new_without_tags'], ['label' => 'Pre-owned', 'value' => 'pre_owned']]],
                ['name' => 'size', 'canonical_name' => 'size', 'label' => 'Size', 'type' => 'select', 'is_required' => true, 'is_filterable' => true, 'show_in_listing' => true, 'width_class' => 'half', 'options' => [['label' => 'XS', 'value' => 'xs'], ['label' => 'S', 'value' => 's'], ['label' => 'M', 'value' => 'm'], ['label' => 'L', 'value' => 'l'], ['label' => 'XL', 'value' => 'xl'], ['label' => 'XXL', 'value' => 'xxl']]],
                ['name' => 'color', 'canonical_name' => 'color', 'label' => 'Color', 'type' => 'text', 'is_required' => true, 'is_filterable' => true, 'is_searchable' => true, 'show_in_listing' => true, 'width_class' => 'half'],
                ['name' => 'material', 'canonical_name' => 'material', 'label' => 'Material', 'type' => 'text', 'is_filterable' => true, 'width_class' => 'half'],
                ['name' => 'style', 'canonical_name' => 'style', 'label' => 'Style', 'type' => 'text', 'is_filterable' => true, 'width_class' => 'half'],
                ['name' => 'pattern', 'canonical_name' => 'pattern', 'label' => 'Pattern', 'type' => 'select', 'is_filterable' => true, 'width_class' => 'half', 'options' => [['label' => 'Solid', 'value' => 'solid'], ['label' => 'Striped', 'value' => 'striped'], ['label' => 'Plaid', 'value' => 'plaid'], ['label' => 'Floral', 'value' => 'floral'], ['label' => 'Animal Print', 'value' => 'animal_print'], ['label' => 'Abstract', 'value' => 'abstract']]],
                ['name' => 'occasion', 'canonical_name' => 'occasion', 'label' => 'Occasion', 'type' => 'select', 'is_filterable' => true, 'width_class' => 'half', 'options' => [['label' => 'Casual', 'value' => 'casual'], ['label' => 'Formal', 'value' => 'formal'], ['label' => 'Business', 'value' => 'business'], ['label' => 'Athletic', 'value' => 'athletic'], ['label' => 'Evening', 'value' => 'evening']]],
            ],
        ];

        $templates['watches'] = [
            'category' => ['name' => 'Watches', 'slug' => 'watches', 'description' => 'Watches and timepieces'],
            'template' => ['name' => 'Watches', 'description' => 'Comprehensive template for watches'],
            'fields' => [
                ['name' => 'brand', 'canonical_name' => 'brand', 'label' => 'Brand', 'type' => 'text', 'is_required' => true, 'is_filterable' => true, 'is_searchable' => true, 'show_in_listing' => true, 'width_class' => 'half'],
                ['name' => 'model', 'canonical_name' => 'model', 'label' => 'Model', 'type' => 'text', 'is_searchable' => true, 'width_class' => 'half'],
                ['name' => 'condition', 'canonical_name' => 'condition', 'label' => 'Condition', 'type' => 'select', 'is_required' => true, 'is_filterable' => true, 'width_class' => 'half', 'options' => [['label' => 'New', 'value' => 'new'], ['label' => 'Pre-owned', 'value' => 'pre_owned'], ['label' => 'Vintage', 'value' => 'vintage']]],
                ['name' => 'case_material', 'canonical_name' => 'case_material', 'label' => 'Case Material', 'type' => 'select', 'is_filterable' => true, 'width_class' => 'half', 'options' => [['label' => 'Stainless Steel', 'value' => 'stainless_steel'], ['label' => 'Gold', 'value' => 'gold'], ['label' => 'Titanium', 'value' => 'titanium'], ['label' => 'Ceramic', 'value' => 'ceramic'], ['label' => 'Plastic', 'value' => 'plastic']]],
                ['name' => 'band_material', 'canonical_name' => 'band_material', 'label' => 'Band Material', 'type' => 'select', 'is_filterable' => true, 'width_class' => 'half', 'options' => [['label' => 'Leather', 'value' => 'leather'], ['label' => 'Stainless Steel', 'value' => 'stainless_steel'], ['label' => 'Rubber', 'value' => 'rubber'], ['label' => 'Nylon', 'value' => 'nylon'], ['label' => 'Silicone', 'value' => 'silicone']]],
                ['name' => 'movement', 'canonical_name' => 'movement', 'label' => 'Movement', 'type' => 'select', 'is_filterable' => true, 'width_class' => 'half', 'options' => [['label' => 'Automatic', 'value' => 'automatic'], ['label' => 'Quartz', 'value' => 'quartz'], ['label' => 'Manual', 'value' => 'manual'], ['label' => 'Solar', 'value' => 'solar']]],
                ['name' => 'case_size', 'canonical_name' => 'case_size', 'label' => 'Case Size (mm)', 'type' => 'number', 'is_filterable' => true, 'width_class' => 'half'],
                ['name' => 'water_resistance', 'canonical_name' => 'water_resistance', 'label' => 'Water Resistance', 'type' => 'text', 'placeholder' => 'e.g. 100m, 10ATM', 'width_class' => 'half'],
                ['name' => 'display', 'canonical_name' => 'display', 'label' => 'Display', 'type' => 'select', 'is_filterable' => true, 'width_class' => 'half', 'options' => [['label' => 'Analog', 'value' => 'analog'], ['label' => 'Digital', 'value' => 'digital'], ['label' => 'Analog-Digital', 'value' => 'analog_digital']]],
            ],
        ];

        $templates['electronics'] = [
            'category' => ['name' => 'Electronics', 'slug' => 'electronics', 'description' => 'Electronic devices and gadgets'],
            'template' => ['name' => 'Electronics', 'description' => 'Comprehensive template for electronic items'],
            'fields' => [
                ['name' => 'brand', 'canonical_name' => 'brand', 'label' => 'Brand', 'type' => 'text', 'is_required' => true, 'is_filterable' => true, 'is_searchable' => true, 'show_in_listing' => true, 'width_class' => 'half'],
                ['name' => 'model', 'canonical_name' => 'model', 'label' => 'Model', 'type' => 'text', 'is_required' => true, 'is_searchable' => true, 'show_in_listing' => true, 'width_class' => 'half'],
                ['name' => 'condition', 'canonical_name' => 'condition', 'label' => 'Condition', 'type' => 'select', 'is_required' => true, 'is_filterable' => true, 'width_class' => 'half', 'options' => [['label' => 'New', 'value' => 'new'], ['label' => 'Refurbished', 'value' => 'refurbished'], ['label' => 'Used', 'value' => 'used'], ['label' => 'For Parts', 'value' => 'for_parts']]],
                ['name' => 'mpn', 'canonical_name' => 'mpn', 'label' => 'MPN (Manufacturer Part Number)', 'type' => 'text', 'is_searchable' => true, 'width_class' => 'half'],
                ['name' => 'color', 'canonical_name' => 'color', 'label' => 'Color', 'type' => 'text', 'is_filterable' => true, 'width_class' => 'half'],
                ['name' => 'storage_capacity', 'canonical_name' => 'storage_capacity', 'label' => 'Storage Capacity', 'type' => 'text', 'placeholder' => 'e.g. 256GB, 1TB', 'is_filterable' => true, 'width_class' => 'half'],
                ['name' => 'screen_size', 'canonical_name' => 'screen_size', 'label' => 'Screen Size', 'type' => 'text', 'placeholder' => 'e.g. 6.1 inches', 'is_filterable' => true, 'width_class' => 'half'],
                ['name' => 'connectivity', 'canonical_name' => 'connectivity', 'label' => 'Connectivity', 'type' => 'text', 'placeholder' => 'e.g. WiFi, Bluetooth, USB-C', 'width_class' => 'half'],
            ],
        ];

        return $templates[$productType] ?? $templates['general'];
    }

    /**
     * Generate template fields from an eBay category's item specifics.
     *
     * @return array{template: array, category: array, fields: array}
     */
    public function generateFromEbayCategory(int $ebayCategoryId, Store $store): array
    {
        $ebayCategory = EbayCategory::findOrFail($ebayCategoryId);

        // Get item specifics for this category
        $itemSpecifics = EbayItemSpecific::where('ebay_category_id', $ebayCategory->ebay_category_id)
            ->with('values')
            ->orderByDesc('is_required')
            ->orderByDesc('is_recommended')
            ->orderBy('name')
            ->get();

        // Build category path for naming
        $categoryPath = $ebayCategory->path;
        $categoryName = $ebayCategory->name;

        // Generate template structure
        $fields = $itemSpecifics->map(function ($spec, $index) {
            $hasValues = $spec->values->count() > 0;
            $values = $spec->values->pluck('value')->take(100)->toArray();

            // Determine field type
            $fieldType = $hasValues ? 'select' : $this->inferFieldTypeFromName($spec->name);

            // Build options array for select fields
            $options = $hasValues ? collect($values)->map(fn ($value) => [
                'label' => $value,
                'value' => str($value)->slug('_')->toString(),
            ])->toArray() : [];

            return [
                'name' => str($spec->name)->snake()->toString(),
                'canonical_name' => str($spec->name)->snake()->toString(),
                'label' => $spec->name,
                'type' => $fieldType,
                'placeholder' => $spec->is_required ? 'Required' : ($spec->is_recommended ? 'Recommended' : ''),
                'help_text' => null,
                'is_required' => $spec->is_required,
                'is_searchable' => in_array(strtolower($spec->name), ['brand', 'model', 'color', 'material', 'size']),
                'is_filterable' => $spec->is_recommended || $spec->is_required,
                'show_in_listing' => $spec->is_required,
                'group_name' => null,
                'group_position' => 1,
                'width_class' => $fieldType === 'textarea' ? 'full' : 'half',
                'options' => $options,
                'platform_mappings' => [
                    [
                        'platform' => 'ebay',
                        'field_name' => $spec->name,
                        'is_required' => $spec->is_required,
                        'is_recommended' => $spec->is_recommended,
                        'accepted_values' => $hasValues ? $values : null,
                    ],
                ],
                'source' => 'ebay_taxonomy',
                'ebay_item_specific_id' => $spec->id,
            ];
        })->toArray();

        return [
            'category' => [
                'name' => $categoryName,
                'slug' => str($categoryName)->slug()->toString(),
                'description' => "Category for {$categoryPath}",
                'ebay_category_id' => $ebayCategory->ebay_category_id,
                'ebay_category_path' => $categoryPath,
            ],
            'template' => [
                'name' => $categoryName.' Template',
                'description' => "Auto-generated template based on eBay's {$categoryPath} item specifics",
            ],
            'fields' => $fields,
            'source' => 'ebay_taxonomy',
            'ebay_category' => [
                'id' => $ebayCategory->id,
                'name' => $ebayCategory->name,
                'ebay_category_id' => $ebayCategory->ebay_category_id,
                'path' => $categoryPath,
            ],
        ];
    }

    /**
     * Infer field type from the item specific name.
     */
    protected function inferFieldTypeFromName(string $name): string
    {
        $name = strtolower($name);

        if (str_contains($name, 'weight') || str_contains($name, 'length') ||
            str_contains($name, 'width') || str_contains($name, 'height') ||
            str_contains($name, 'size') || str_contains($name, 'carat') ||
            str_contains($name, 'quantity') || str_contains($name, 'capacity')) {
            return 'number';
        }

        if (str_contains($name, 'description') || str_contains($name, 'notes') ||
            str_contains($name, 'features') || str_contains($name, 'details')) {
            return 'textarea';
        }

        return 'text';
    }
}
