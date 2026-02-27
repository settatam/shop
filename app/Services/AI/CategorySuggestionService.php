<?php

namespace App\Services\AI;

use App\Models\EbayCategory;
use Illuminate\Support\Collection;

class CategorySuggestionService
{
    public function __construct(protected AIManager $aiManager) {}

    /**
     * Suggest the best eBay categories for a given local category.
     *
     * @return array<int, array{ebay_category_id: int, name: string, path: string, confidence: int, reasoning: string}>
     */
    public function suggestEbayCategories(string $categoryName, ?string $templateName = null, ?string $categoryPath = null): array
    {
        $candidates = $this->findCandidates($categoryName, $templateName);

        if ($candidates->isEmpty()) {
            return [];
        }

        $prompt = $this->buildPrompt($categoryName, $templateName, $categoryPath, $candidates);

        $response = $this->aiManager->generateJson($prompt, $this->getSuggestionsSchema(), [
            'feature' => 'category_suggestion',
            'temperature' => 0.2,
        ]);

        $result = $response->toJson();
        $suggestions = $result['suggestions'] ?? [];

        // Validate that suggested IDs exist in our candidates
        $candidateIds = $candidates->pluck('ebay_category_id')->toArray();

        return collect($suggestions)
            ->filter(fn (array $s) => in_array($s['ebay_category_id'] ?? null, $candidateIds))
            ->take(5)
            ->values()
            ->toArray();
    }

    /**
     * Find candidate eBay leaf categories matching keywords from the category name and template.
     */
    protected function findCandidates(string $categoryName, ?string $templateName): Collection
    {
        $keywords = $this->extractKeywords($categoryName, $templateName);

        if (empty($keywords)) {
            return collect();
        }

        $query = EbayCategory::query()
            ->whereDoesntHave('children')
            ->where(function ($q) use ($keywords) {
                foreach ($keywords as $keyword) {
                    $q->orWhere('name', 'like', '%'.$keyword.'%');
                }
            })
            ->limit(50);

        return $query->get()->map(fn (EbayCategory $cat) => [
            'id' => $cat->id,
            'ebay_category_id' => $cat->ebay_category_id,
            'name' => $cat->name,
            'path' => $cat->path,
        ]);
    }

    /**
     * Extract meaningful search keywords from category name and template name.
     *
     * @return string[]
     */
    protected function extractKeywords(string $categoryName, ?string $templateName): array
    {
        $text = $categoryName;
        if ($templateName) {
            $text .= ' '.$templateName;
        }

        $stopWords = ['and', 'or', 'the', 'a', 'an', 'in', 'on', 'at', 'to', 'for', 'of', 'with', 'by', '&'];

        $words = preg_split('/[\s>\/,\-]+/', strtolower($text));
        $words = array_filter($words, fn (string $w) => strlen($w) >= 2 && ! in_array($w, $stopWords));

        return array_values(array_unique($words));
    }

    protected function buildPrompt(string $categoryName, ?string $templateName, ?string $categoryPath, Collection $candidates): string
    {
        $candidateList = $candidates
            ->map(fn (array $c) => "- ID: {$c['ebay_category_id']}, Path: {$c['path']}")
            ->join("\n");

        $context = "Category Name: {$categoryName}";
        if ($templateName) {
            $context .= "\nTemplate: {$templateName}";
        }
        if ($categoryPath) {
            $context .= "\nCategory Path: {$categoryPath}";
        }

        return <<<PROMPT
You are an expert at matching product categories to eBay marketplace categories.

Given the following local store category, suggest the top 5 best matching eBay categories from the candidate list below.

{$context}

Candidate eBay Categories:
{$candidateList}

For each suggestion, provide:
- ebay_category_id: The eBay category ID from the list above
- name: The leaf category name
- path: The full breadcrumb path
- confidence: A score from 0-100 indicating how well this eBay category matches
- reasoning: A brief explanation of why this category is a good match

Rank suggestions from best match to worst. Only include categories that are genuinely relevant.
PROMPT;
    }

    protected function getSuggestionsSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'suggestions' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'ebay_category_id' => ['type' => 'integer'],
                            'name' => ['type' => 'string'],
                            'path' => ['type' => 'string'],
                            'confidence' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
                            'reasoning' => ['type' => 'string'],
                        ],
                        'required' => ['ebay_category_id', 'name', 'path', 'confidence', 'reasoning'],
                    ],
                ],
            ],
            'required' => ['suggestions'],
        ];
    }
}
