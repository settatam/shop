<?php

namespace App\Services\Platforms;

use App\Models\Product;
use App\Models\StoreMarketplace;
use App\Services\AI\AIManager;
use Illuminate\Support\Facades\Log;

class ListingAIService
{
    public function __construct(protected AIManager $aiManager) {}

    /**
     * Auto-fill listing values using AI.
     *
     * @return array{success: bool, suggestions?: array<string, mixed>, error?: string}
     */
    public function autoFillListingValues(Product $product, StoreMarketplace $marketplace): array
    {
        $platformLabel = $marketplace->platform->label();
        $productData = $this->buildProductContext($product);

        $prompt = <<<PROMPT
        You are an expert {$platformLabel} product listing specialist.

        Given this product data:
        {$productData}

        Suggest optimal {$platformLabel} listing values as JSON with these fields:
        - "condition": eBay condition enum ID as string (e.g. "1000" for New with tags, "3000" for Pre-owned)
        - "category_id": suggested eBay category ID (numeric string)
        - "listing_type": "FIXED_PRICE" or "AUCTION"
        - "item_specifics": object of key-value pairs for eBay item specifics (e.g. {"Brand": "...", "Material": "...", "Color": "..."})

        IMPORTANT: All values must be human-readable, properly capitalized, and production-ready. Never return slugged, kebab-case, or snake_case values (e.g. return "White Gold" not "white-gold", "Yellow Gold" not "yellow_gold").
        Return ONLY valid JSON, no explanation.
        PROMPT;

        try {
            $response = $this->aiManager->generateJson($prompt, [
                'type' => 'object',
                'properties' => [
                    'condition' => ['type' => 'string'],
                    'category_id' => ['type' => 'string'],
                    'listing_type' => ['type' => 'string'],
                    'item_specifics' => ['type' => 'object'],
                ],
            ], ['feature' => 'listing_auto_fill']);

            $suggestions = $response->toJson();

            if (! $suggestions) {
                return ['success' => false, 'error' => 'Failed to parse AI response'];
            }

            return ['success' => true, 'suggestions' => $suggestions];
        } catch (\Throwable $e) {
            Log::warning('ListingAIService auto-fill failed', ['error' => $e->getMessage()]);

            return ['success' => false, 'error' => 'AI service unavailable'];
        }
    }

    /**
     * Generate an optimized platform-specific title.
     *
     * @return array{success: bool, title?: string, error?: string}
     */
    public function generateTitle(Product $product, StoreMarketplace $marketplace): array
    {
        $platformLabel = $marketplace->platform->label();
        $maxLength = $marketplace->platform->value === 'ebay' ? 80 : 200;
        $productData = $this->buildProductContext($product);

        $systemPrompt = "You are an expert {$platformLabel} listing title writer. Write titles that are keyword-optimized for search visibility while staying within the {$maxLength}-character limit.";

        $userPrompt = <<<PROMPT
        Product data:
        {$productData}

        Write an optimized {$platformLabel} title (max {$maxLength} characters). Include important keywords like brand, material, key features. Do not use ALL CAPS or excessive punctuation. Return ONLY the title text, nothing else.
        PROMPT;

        try {
            $response = $this->aiManager->chatWithSystem($systemPrompt, $userPrompt, [
                'feature' => 'listing_title_generation',
            ]);

            $title = trim($response->content);
            if (strlen($title) > $maxLength) {
                $title = substr($title, 0, $maxLength - 3).'...';
            }

            return ['success' => true, 'title' => $title];
        } catch (\Throwable $e) {
            Log::warning('ListingAIService title generation failed', ['error' => $e->getMessage()]);

            return ['success' => false, 'error' => 'AI service unavailable'];
        }
    }

    /**
     * Generate an optimized platform-specific description.
     *
     * @return array{success: bool, description?: string, error?: string}
     */
    public function generateDescription(Product $product, StoreMarketplace $marketplace): array
    {
        $platformLabel = $marketplace->platform->label();
        $productData = $this->buildProductContext($product);
        $isEbay = $marketplace->platform->value === 'ebay';

        $formatNote = $isEbay
            ? 'Use simple HTML formatting (bold, lists, paragraphs). eBay supports HTML in descriptions.'
            : 'Use plain text formatting.';

        $systemPrompt = "You are an expert {$platformLabel} product description writer. Write compelling, detailed descriptions that convert browsers into buyers.";

        $userPrompt = <<<PROMPT
        Product data:
        {$productData}

        Write an optimized {$platformLabel} product description. {$formatNote}
        Include: key features, specifications, condition details, and a compelling call to action.
        Return ONLY the description text, nothing else.
        PROMPT;

        try {
            $response = $this->aiManager->chatWithSystem($systemPrompt, $userPrompt, [
                'feature' => 'listing_description_generation',
            ]);

            return ['success' => true, 'description' => trim($response->content)];
        } catch (\Throwable $e) {
            Log::warning('ListingAIService description generation failed', ['error' => $e->getMessage()]);

            return ['success' => false, 'error' => 'AI service unavailable'];
        }
    }

    /**
     * Generate title, description, and item specifics for an eBay listing in a single AI call.
     *
     * @param  array<int, array{name: string, is_required: bool, is_recommended: bool, aspect_mode: string, allowed_values: string[]}>  $specifics
     * @param  array{include_title?: bool, include_description?: bool}  $options
     * @return array{success: bool, suggestions?: array{title?: string, description?: string, item_specifics?: array<string, string>}, error?: string}
     */
    public function suggestEbayListing(
        Product $product,
        StoreMarketplace $marketplace,
        array $specifics,
        array $options = [],
    ): array {
        $includeTitle = $options['include_title'] ?? true;
        $includeDescription = $options['include_description'] ?? true;
        $productData = $this->buildProductContext($product);

        $specificsDescription = $this->buildSpecificsContext($specifics);

        $fieldsToGenerate = ['item_specifics — an object of key-value pairs for every item specific you can determine a value for; omit those you cannot'];
        if ($includeTitle) {
            $fieldsToGenerate[] = 'title — an optimized eBay title, max 80 characters';
        }
        if ($includeDescription) {
            $fieldsToGenerate[] = 'description — an HTML description optimized for eBay using simple formatting (bold, lists, paragraphs)';
        }
        $fieldsList = implode("\n        - ", $fieldsToGenerate);

        $categoryName = $product->category?->name ?? 'general';

        $systemPrompt = "You are an expert eBay listing specialist for the {$categoryName} category.";

        $userPrompt = <<<PROMPT
        Product data:
        {$productData}

        Item specifics for this eBay category:
        {$specificsDescription}

        Generate optimal eBay listing values as JSON with these fields:
        - {$fieldsList}

        For SELECTION_ONLY specifics, you MUST pick a value from their allowed values list.
        IMPORTANT: All values must be human-readable, properly capitalized, and production-ready. Never return slugged, kebab-case, or snake_case values (e.g. return "White Gold" not "white-gold", "Yellow Gold" not "yellow_gold").
        Return ONLY valid JSON, no explanation.
        PROMPT;

        $schemaProperties = [
            'item_specifics' => ['type' => 'object'],
        ];
        if ($includeTitle) {
            $schemaProperties['title'] = ['type' => 'string'];
        }
        if ($includeDescription) {
            $schemaProperties['description'] = ['type' => 'string'];
        }

        try {
            $response = $this->aiManager->generateJson(
                "{$systemPrompt}\n\n{$userPrompt}",
                [
                    'type' => 'object',
                    'properties' => $schemaProperties,
                ],
                ['feature' => 'ebay_listing_suggest'],
            );

            $suggestions = $response->toJson();

            if (! $suggestions) {
                return ['success' => false, 'error' => 'Failed to parse AI response'];
            }

            return ['success' => true, 'suggestions' => $suggestions];
        } catch (\Throwable $e) {
            Log::warning('ListingAIService eBay listing suggest failed', ['error' => $e->getMessage()]);

            return ['success' => false, 'error' => 'AI service unavailable'];
        }
    }

    /**
     * Suggest values for Shopify metafield definitions using AI.
     *
     * @param  array<int, array{name: string, key: string, namespace: string, type: string, description: ?string}>  $definitions
     * @return array{success: bool, suggestions?: array<string, string>, error?: string}
     */
    public function suggestShopifyMetafields(
        Product $product,
        StoreMarketplace $marketplace,
        array $definitions,
    ): array {
        $productData = $this->buildProductContext($product);
        $definitionsContext = $this->buildMetafieldDefinitionsContext($definitions);
        $categoryName = $product->category?->name ?? 'general';

        $systemPrompt = "You are an expert Shopify product metadata specialist for the {$categoryName} category.";

        $userPrompt = <<<PROMPT
        Product data:
        {$productData}

        Shopify metafield definitions for this store:
        {$definitionsContext}

        For each metafield definition, determine the most appropriate value based on the product data.
        Return a JSON object where keys are "{namespace}.{key}" and values are the suggested metafield values.
        Match the expected type for each metafield (e.g. integers for number_integer, "true"/"false" for boolean).
        Only include metafields you can confidently determine a value for.
        IMPORTANT: All values must be human-readable, properly capitalized, and production-ready. Never return slugged, kebab-case, or snake_case values (e.g. return "White Gold" not "white-gold", "Yellow Gold" not "yellow_gold").
        Return ONLY valid JSON, no explanation.
        PROMPT;

        try {
            $response = $this->aiManager->generateJson(
                "{$systemPrompt}\n\n{$userPrompt}",
                [
                    'type' => 'object',
                ],
                ['feature' => 'shopify_metafield_suggest'],
            );

            $suggestions = $response->toJson();

            if (! $suggestions) {
                return ['success' => false, 'error' => 'Failed to parse AI response'];
            }

            return ['success' => true, 'suggestions' => $suggestions];
        } catch (\Throwable $e) {
            Log::warning('ListingAIService Shopify metafield suggest failed', ['error' => $e->getMessage()]);

            return ['success' => false, 'error' => 'AI service unavailable'];
        }
    }

    /**
     * Build a description of Shopify metafield definitions for the AI prompt.
     *
     * @param  array<int, array{name: string, key: string, namespace: string, type: string, description: ?string}>  $definitions
     */
    protected function buildMetafieldDefinitionsContext(array $definitions): string
    {
        $lines = [];
        foreach ($definitions as $def) {
            $line = "- {$def['namespace']}.{$def['key']} ({$def['name']}) [type: {$def['type']}]";
            if (! empty($def['description'])) {
                $line .= ' — '.$def['description'];
            }
            $lines[] = $line;
        }

        return implode("\n", $lines);
    }

    /**
     * Build a description of item specifics for the AI prompt.
     *
     * @param  array<int, array{name: string, is_required: bool, is_recommended: bool, aspect_mode: string, allowed_values: string[]}>  $specifics
     */
    protected function buildSpecificsContext(array $specifics): string
    {
        $lines = [];
        foreach ($specifics as $specific) {
            $flags = [];
            if ($specific['is_required']) {
                $flags[] = 'REQUIRED';
            } elseif ($specific['is_recommended']) {
                $flags[] = 'RECOMMENDED';
            }
            if ($specific['aspect_mode'] === 'SELECTION_ONLY') {
                $flags[] = 'SELECTION_ONLY';
            }

            $line = "- {$specific['name']}";
            if (! empty($flags)) {
                $line .= ' ['.implode(', ', $flags).']';
            }
            if ($specific['aspect_mode'] === 'SELECTION_ONLY' && ! empty($specific['allowed_values'])) {
                $values = array_slice($specific['allowed_values'], 0, 30);
                $line .= ': '.implode(', ', $values);
                if (count($specific['allowed_values']) > 30) {
                    $line .= '...';
                }
            }

            $lines[] = $line;
        }

        return implode("\n", $lines);
    }

    /**
     * Build a text context string from a product for AI prompts.
     */
    protected function buildProductContext(Product $product): string
    {
        $parts = [
            "Title: {$product->title}",
        ];

        if ($product->description) {
            $desc = strip_tags($product->description);
            $parts[] = 'Description: '.substr($desc, 0, 500);
        }

        if ($product->brand?->name) {
            $parts[] = "Brand: {$product->brand->name}";
        }

        if ($product->category?->name) {
            $parts[] = "Category: {$product->category->name}";
        }

        if ($product->condition) {
            $parts[] = "Condition: {$product->condition}";
        }

        $variant = $product->variants->first();
        if ($variant?->price) {
            $parts[] = "Price: \${$variant->price}";
        }

        // Include template attribute values (resolved to display labels)
        $product->loadMissing('attributeValues.field.options');
        $attributes = $product->attributeValues ?? collect();
        foreach ($attributes as $av) {
            if ($av->value && $av->field) {
                $displayValue = $av->resolveDisplayValue() ?? $av->value;
                $parts[] = "{$av->field->label}: {$displayValue}";
            }
        }

        return implode("\n", $parts);
    }
}
