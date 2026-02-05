<?php

namespace App\Services;

use App\Models\Product;
use App\Models\TransactionItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class SimilarItemFinder
{
    /**
     * Find products similar to a transaction item.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function findSimilar(TransactionItem $item, int $limit = 10): Collection
    {
        $store = $item->transaction->store;
        $titleWords = $this->extractKeywords($item->title);

        $query = Product::query()
            ->where('store_id', $store->id)
            ->with(['primaryImage', 'variants']);

        // Filter by category if available
        $categoryIds = [];
        if ($item->category_id) {
            $category = $item->category;
            if ($category) {
                $categoryIds[] = $category->id;
                if ($category->parent_id) {
                    // Include siblings
                    $categoryIds = Product::query()
                        ->join('categories', 'products.category_id', '=', 'categories.id')
                        ->where('categories.parent_id', $category->parent_id)
                        ->distinct()
                        ->pluck('products.category_id')
                        ->merge([$category->id])
                        ->unique()
                        ->toArray();
                }
            }
        }

        $products = $query->limit(100)->get();

        // Score each product
        $scored = $products->map(function (Product $product) use ($titleWords, $categoryIds, $item) {
            $score = 0;
            $reasons = [];

            // Category match
            if (! empty($categoryIds) && in_array($product->category_id, $categoryIds)) {
                $score += 30;
                $reasons[] = 'Same category';
            }

            // Title keyword overlap
            $productWords = $this->extractKeywords($product->title);
            $overlap = array_intersect($titleWords, $productWords);
            if (! empty($overlap)) {
                $overlapScore = (count($overlap) / max(count($titleWords), 1)) * 50;
                $score += $overlapScore;
                $reasons[] = 'Title match: '.implode(', ', $overlap);
            }

            // Metal type match via description/title
            if ($item->precious_metal && Str::contains(strtolower($product->title.' '.($product->description ?? '')), strtolower(str_replace('_', ' ', $item->precious_metal)))) {
                $score += 20;
                $reasons[] = 'Metal type match';
            }

            return [
                'id' => $product->id,
                'title' => $product->title,
                'sku' => $product->variants->first()?->sku,
                'price' => $product->variants->first()?->price,
                'cost' => $product->variants->first()?->cost,
                'image_url' => $product->primaryImage?->thumbnail_url ?? $product->primaryImage?->url,
                'similarity_score' => round($score),
                'match_reasons' => $reasons,
            ];
        })
            ->filter(fn ($item) => $item['similarity_score'] > 0)
            ->sortByDesc('similarity_score')
            ->take($limit)
            ->values();

        return $scored;
    }

    /**
     * Find similar transaction items (past buys) based on search criteria.
     *
     * @param  array<string, mixed>  $criteria
     * @return Collection<int, array<string, mixed>>
     */
    public function findSimilarTransactionItems(array $criteria, int $storeId, int $limit = 10): Collection
    {
        $titleWords = $this->extractKeywords($criteria['title'] ?? '');
        $searchAttributes = $criteria['attributes'] ?? [];
        $hasTitle = ! empty($titleWords);
        $hasAttributes = ! empty(array_filter($searchAttributes));

        $query = TransactionItem::query()
            ->whereHas('transaction', function ($q) use ($storeId) {
                $q->where('store_id', $storeId)
                    ->where('status', 'payment_processed');
            })
            ->with(['category', 'images', 'transaction']);

        // Pre-filter by category if specified (more efficient)
        if (! empty($criteria['category_id'])) {
            $query->where('category_id', $criteria['category_id']);
        }

        // Get items to score
        $items = $query->limit(500)->get();

        // Determine scoring weights based on what search criteria we have
        $categoryWeight = $hasTitle ? 30 : 40;
        $titleWeight = 50;
        $attributeWeight = $hasTitle ? 35 : 60; // Attributes matter more when no title

        // Score each item
        $scored = $items->map(function (TransactionItem $item) use ($titleWords, $criteria, $searchAttributes, $hasTitle, $hasAttributes, $categoryWeight, $titleWeight, $attributeWeight) {
            $score = 0;
            $reasons = [];

            // Category match (already filtered, but still score it)
            if (! empty($criteria['category_id']) && $item->category_id == $criteria['category_id']) {
                $score += $categoryWeight;
                $reasons[] = 'Same category';
            }

            // Title keyword overlap (only if we have title words)
            if ($hasTitle) {
                $itemWords = $this->extractKeywords($item->title);
                $overlap = array_intersect($titleWords, $itemWords);
                if (! empty($overlap)) {
                    $overlapScore = (count($overlap) / max(count($titleWords), 1)) * $titleWeight;
                    $score += $overlapScore;
                    $reasons[] = 'Title match: '.implode(', ', $overlap);
                }
            }

            // Attribute matching
            if ($hasAttributes) {
                $itemAttributes = is_array($item->attributes) ? $item->attributes : [];
                $matchedAttributes = [];
                $mismatchedAttributes = [];
                $totalSearchAttributes = 0;

                foreach ($searchAttributes as $key => $value) {
                    if (empty($value)) {
                        continue;
                    }
                    $totalSearchAttributes++;

                    // Get the item's value for this attribute (from JSON or legacy fields)
                    $itemValue = $this->getItemAttributeValue($item, $itemAttributes, $key);

                    if ($itemValue !== null) {
                        // Item has a value for this attribute - check if it matches
                        if ($this->attributeValuesMatch($itemValue, $value)) {
                            $matchedAttributes[] = $this->formatAttributeName($key);
                        } else {
                            // Item has a DIFFERENT value - this is a mismatch
                            $mismatchedAttributes[] = $this->formatAttributeName($key);
                        }
                    }
                    // If item has no value for this attribute, it's neither a match nor mismatch
                }

                if (! empty($matchedAttributes)) {
                    // Score based on percentage of attributes matched
                    $attributeScore = (count($matchedAttributes) / max($totalSearchAttributes, 1)) * $attributeWeight;
                    $score += $attributeScore;
                    $reasons[] = 'Matches: '.implode(', ', $matchedAttributes);
                }

                // Penalize mismatches - having a different value is worse than no value
                if (! empty($mismatchedAttributes)) {
                    $penaltyPerMismatch = 30; // Significant penalty per mismatched attribute
                    $penalty = count($mismatchedAttributes) * $penaltyPerMismatch;
                    $score -= $penalty;
                    $reasons[] = 'Different: '.implode(', ', $mismatchedAttributes);
                }
            }

            return [
                'id' => $item->id,
                'title' => $item->title,
                'description' => $item->description,
                'category' => $item->category?->name,
                'attributes' => $item->attributes,
                'precious_metal' => $item->precious_metal,
                'condition' => $item->condition,
                'dwt' => $item->dwt,
                'buy_price' => $item->buy_price,
                'image_url' => $item->images->first()?->thumbnail_url ?? $item->images->first()?->url,
                'created_at' => $item->created_at->toISOString(),
                'days_ago' => $item->created_at->diffInDays(now()),
                'similarity_score' => round($score),
                'match_reasons' => $reasons,
            ];
        })
            ->filter(fn ($item) => $item['similarity_score'] > 0)
            ->sortByDesc('similarity_score')
            ->take($limit)
            ->values();

        return $scored;
    }

    /**
     * Check if two attribute values match (handles string comparison case-insensitively).
     */
    protected function attributeValuesMatch(mixed $itemValue, mixed $searchValue): bool
    {
        if (is_string($itemValue) && is_string($searchValue)) {
            return strtolower(trim($itemValue)) === strtolower(trim($searchValue));
        }

        return $itemValue == $searchValue;
    }

    /**
     * Get an item's value for a specific attribute (from JSON attributes or legacy fields).
     *
     * @param  array<string, mixed>  $itemAttributes
     */
    protected function getItemAttributeValue(TransactionItem $item, array $itemAttributes, string $attrKey): mixed
    {
        // First check if the attribute exists in the JSON attributes
        if (isset($itemAttributes[$attrKey]) && $itemAttributes[$attrKey] !== '') {
            return $itemAttributes[$attrKey];
        }

        // Then check legacy fields by common attribute names
        $key = strtolower($attrKey);

        if (in_array($key, ['precious_metal', 'precious_metals', 'metal_type', 'metal'])) {
            return $item->precious_metal;
        }

        if ($key === 'condition') {
            return $item->condition;
        }

        if (in_array($key, ['dwt', 'weight', 'weight_dwt'])) {
            return $item->dwt;
        }

        // Check for common attribute patterns (brand, material, etc.)
        // by looking for the key in different formats in the item attributes
        $keyVariants = [
            $attrKey,
            strtolower($attrKey),
            str_replace(' ', '_', strtolower($attrKey)),
            str_replace('_', ' ', strtolower($attrKey)),
        ];

        foreach ($keyVariants as $variant) {
            foreach ($itemAttributes as $itemKey => $itemValue) {
                if (strtolower($itemKey) === strtolower($variant) && $itemValue !== '') {
                    return $itemValue;
                }
            }
        }

        return null;
    }

    /**
     * Check legacy fields (precious_metal, condition, dwt) for attribute matches.
     */
    protected function checkLegacyFieldMatch(TransactionItem $item, string $attrKey, mixed $attrValue): bool
    {
        $key = strtolower($attrKey);

        // Map common attribute names to legacy fields
        if (in_array($key, ['precious_metal', 'precious_metals', 'metal_type', 'metal'])) {
            return $item->precious_metal && $this->attributeValuesMatch($item->precious_metal, $attrValue);
        }

        if ($key === 'condition') {
            return $item->condition && $this->attributeValuesMatch($item->condition, $attrValue);
        }

        if (in_array($key, ['dwt', 'weight', 'weight_dwt'])) {
            // For weight, check if within 10% tolerance
            if ($item->dwt && is_numeric($attrValue)) {
                $tolerance = (float) $attrValue * 0.1;

                return abs((float) $item->dwt - (float) $attrValue) <= $tolerance;
            }
        }

        return false;
    }

    /**
     * Format attribute name for display.
     */
    protected function formatAttributeName(string $key): string
    {
        return ucwords(str_replace(['_', '-'], ' ', $key));
    }

    /**
     * Extract meaningful keywords from a title.
     *
     * @return array<int, string>
     */
    protected function extractKeywords(?string $text): array
    {
        if (! $text) {
            return [];
        }

        $stopWords = ['the', 'a', 'an', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for', 'of', 'with', 'by', 'is', 'it', 'this', 'that'];

        return collect(preg_split('/\s+/', strtolower($text)))
            ->map(fn ($word) => preg_replace('/[^a-z0-9]/', '', $word))
            ->filter(fn ($word) => strlen($word) > 2 && ! in_array($word, $stopWords))
            ->values()
            ->toArray();
    }
}
