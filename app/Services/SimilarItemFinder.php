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

        $query = TransactionItem::query()
            ->whereHas('transaction', function ($q) use ($storeId) {
                $q->where('store_id', $storeId)
                    ->where('status', 'payment_processed');
            })
            ->with(['category', 'images', 'transaction']);

        // Get items to score
        $items = $query->limit(200)->get();

        // Score each item
        $scored = $items->map(function (TransactionItem $item) use ($titleWords, $criteria) {
            $score = 0;
            $reasons = [];

            // Category match
            if (! empty($criteria['category_id']) && $item->category_id == $criteria['category_id']) {
                $score += 30;
                $reasons[] = 'Same category';
            }

            // Title keyword overlap
            $itemWords = $this->extractKeywords($item->title);
            $overlap = array_intersect($titleWords, $itemWords);
            if (! empty($overlap)) {
                $overlapScore = (count($overlap) / max(count($titleWords), 1)) * 50;
                $score += $overlapScore;
                $reasons[] = 'Title match: '.implode(', ', $overlap);
            }

            // Metal type match
            if (! empty($criteria['precious_metal']) && $item->precious_metal === $criteria['precious_metal']) {
                $score += 25;
                $reasons[] = 'Metal type match';
            }

            // Condition match
            if (! empty($criteria['condition']) && $item->condition === $criteria['condition']) {
                $score += 10;
                $reasons[] = 'Condition match';
            }

            return [
                'id' => $item->id,
                'title' => $item->title,
                'description' => $item->description,
                'category' => $item->category?->name,
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
