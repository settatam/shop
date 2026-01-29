<?php

namespace App\Services\AI;

use App\Models\AiSuggestion;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Collection;

class ProductCategorizer
{
    protected AIManager $aiManager;

    public function __construct(AIManager $aiManager)
    {
        $this->aiManager = $aiManager;
    }

    public function categorize(Product $product, array $options = []): AiSuggestion
    {
        $platform = $options['platform'] ?? null;
        $categories = $this->getAvailableCategories($product->store_id, $platform);

        $prompt = $this->buildCategorizationPrompt($product, $categories, $platform);

        $response = $this->aiManager->generateJson($prompt, $this->getCategorySchema(), [
            'feature' => 'categorization',
            'temperature' => 0.3,
        ]);

        $result = $response->toJson();

        return AiSuggestion::create([
            'store_id' => $product->store_id,
            'suggestable_type' => Product::class,
            'suggestable_id' => $product->id,
            'type' => 'category',
            'platform' => $platform,
            'original_content' => $product->category?->name,
            'suggested_content' => json_encode($result),
            'metadata' => [
                'suggested_category_id' => $result['category_id'] ?? null,
                'suggested_category_name' => $result['category_name'] ?? null,
                'confidence' => $result['confidence'] ?? null,
                'reasoning' => $result['reasoning'] ?? null,
                'alternative_categories' => $result['alternatives'] ?? [],
                'tokens_used' => $response->totalTokens(),
                'model' => $response->model,
            ],
        ]);
    }

    public function categorizeBulk(array $products, array $options = []): array
    {
        $suggestions = [];
        foreach ($products as $product) {
            $suggestions[] = $this->categorize($product, $options);
        }

        return $suggestions;
    }

    public function suggestPlatformCategory(Product $product, string $platform, array $platformCategories): AiSuggestion
    {
        $prompt = $this->buildPlatformCategorizationPrompt($product, $platform, $platformCategories);

        $response = $this->aiManager->generateJson($prompt, $this->getPlatformCategorySchema(), [
            'feature' => 'platform_categorization',
            'temperature' => 0.3,
        ]);

        $result = $response->toJson();

        return AiSuggestion::create([
            'store_id' => $product->store_id,
            'suggestable_type' => Product::class,
            'suggestable_id' => $product->id,
            'type' => 'platform_category',
            'platform' => $platform,
            'original_content' => null,
            'suggested_content' => json_encode($result),
            'metadata' => [
                'platform_category_id' => $result['category_id'] ?? null,
                'platform_category_path' => $result['category_path'] ?? null,
                'confidence' => $result['confidence'] ?? null,
                'reasoning' => $result['reasoning'] ?? null,
                'tokens_used' => $response->totalTokens(),
                'model' => $response->model,
            ],
        ]);
    }

    public function suggestTags(Product $product, array $options = []): AiSuggestion
    {
        $maxTags = $options['max_tags'] ?? 10;
        $platform = $options['platform'] ?? null;

        $systemPrompt = <<<PROMPT
You are an expert at generating relevant tags and keywords for e-commerce products.
Generate up to {$maxTags} highly relevant tags that will help with search discoverability.

Guidelines:
- Include both broad and specific tags
- Consider search behavior and common search terms
- Include relevant attribute-based tags (color, material, style, etc.)
- Avoid redundant or overly generic tags
- Tags should be lowercase and comma-separated
PROMPT;

        $userPrompt = $this->buildTagsPrompt($product, $platform);

        $response = $this->aiManager->chatWithSystem($systemPrompt, $userPrompt, [
            'feature' => 'tag_generation',
            'temperature' => 0.5,
        ]);

        $tags = array_map('trim', explode(',', $response->content));
        $tags = array_filter($tags);
        $tags = array_slice($tags, 0, $maxTags);

        return AiSuggestion::create([
            'store_id' => $product->store_id,
            'suggestable_type' => Product::class,
            'suggestable_id' => $product->id,
            'type' => 'tags',
            'platform' => $platform,
            'original_content' => is_array($product->tags) ? implode(', ', $product->tags) : null,
            'suggested_content' => implode(', ', $tags),
            'metadata' => [
                'tags' => $tags,
                'count' => count($tags),
                'tokens_used' => $response->totalTokens(),
                'model' => $response->model,
            ],
        ]);
    }

    protected function getAvailableCategories(int $storeId, ?string $platform): Collection
    {
        return Category::where('store_id', $storeId)
            ->orderBy('name')
            ->get(['id', 'name', 'parent_id']);
    }

    protected function buildCategorizationPrompt(Product $product, Collection $categories, ?string $platform): string
    {
        $categoryList = $categories->map(fn ($c) => "- ID: {$c->id}, Name: {$c->name}")->join("\n");

        return <<<PROMPT
Analyze the following product and suggest the most appropriate category from the available options.

Product Information:
- Title: {$product->title}
- Description: {$product->description}
- Brand: {$product->brand?->name}
- Current Category: {$product->category?->name}

Available Categories:
{$categoryList}

Respond with a JSON object containing:
- category_id: The ID of the best matching category
- category_name: The name of the suggested category
- confidence: A score from 0-100 indicating confidence in the suggestion
- reasoning: A brief explanation of why this category was chosen
- alternatives: An array of up to 3 alternative category IDs that could also fit
PROMPT;
    }

    protected function buildPlatformCategorizationPrompt(Product $product, string $platform, array $platformCategories): string
    {
        $categoryList = collect($platformCategories)
            ->take(100)
            ->map(fn ($c) => "- ID: {$c['id']}, Path: {$c['name']}")
            ->join("\n");

        return <<<PROMPT
Analyze the following product and suggest the most appropriate {$platform} category.

Product Information:
- Title: {$product->title}
- Description: {$product->description}
- Brand: {$product->brand?->name}
- Internal Category: {$product->category?->name}

Available {$platform} Categories (partial list):
{$categoryList}

Respond with a JSON object containing:
- category_id: The ID of the best matching platform category
- category_path: The full category path/breadcrumb
- confidence: A score from 0-100 indicating confidence
- reasoning: A brief explanation of the choice
PROMPT;
    }

    protected function buildTagsPrompt(Product $product, ?string $platform): string
    {
        $prompt = "Generate relevant tags for:\n\n";
        $prompt .= "Title: {$product->title}\n";

        if ($product->description) {
            $prompt .= 'Description: '.substr($product->description, 0, 500)."\n";
        }

        if ($product->brand) {
            $prompt .= "Brand: {$product->brand->name}\n";
        }

        if ($product->category) {
            $prompt .= "Category: {$product->category->name}\n";
        }

        if ($platform) {
            $prompt .= "\nTarget Platform: {$platform}";
        }

        return $prompt;
    }

    protected function getCategorySchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'category_id' => ['type' => 'integer'],
                'category_name' => ['type' => 'string'],
                'confidence' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
                'reasoning' => ['type' => 'string'],
                'alternatives' => ['type' => 'array', 'items' => ['type' => 'integer']],
            ],
            'required' => ['category_id', 'category_name', 'confidence'],
        ];
    }

    protected function getPlatformCategorySchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'category_id' => ['type' => 'string'],
                'category_path' => ['type' => 'string'],
                'confidence' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
                'reasoning' => ['type' => 'string'],
            ],
            'required' => ['category_id', 'category_path', 'confidence'],
        ];
    }
}
