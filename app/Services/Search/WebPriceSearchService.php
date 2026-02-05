<?php

namespace App\Services\Search;

use App\Models\StoreIntegration;
use App\Services\Search\Providers\SerpApiProvider;

class WebPriceSearchService
{
    public function __construct(
        protected SerpApiProvider $serpApi
    ) {}

    /**
     * Search for prices across the web.
     *
     * @param  array<string, mixed>  $criteria  Search criteria (title, category, precious_metal, etc.)
     * @return array<string, mixed>
     */
    public function searchPrices(int $storeId, array $criteria): array
    {
        $integration = StoreIntegration::findActiveForStore($storeId, StoreIntegration::PROVIDER_SERPAPI);

        if (! $integration) {
            return [
                'error' => 'SerpAPI integration not configured. Please configure it in Settings > Integrations.',
                'listings' => [],
                'summary' => $this->getEmptySummary(),
            ];
        }

        $this->serpApi->setIntegration($integration);
        $query = $this->buildSearchQuery($criteria);

        if (empty(trim($query))) {
            return [
                'error' => 'No search criteria provided',
                'listings' => [],
                'summary' => $this->getEmptySummary(),
            ];
        }

        $results = [
            'google_shopping' => $this->serpApi->searchGoogleShopping($query),
            'ebay_sold' => $this->serpApi->searchEbaySold($query),
            'searched_at' => now()->toIso8601String(),
            'query' => $query,
        ];

        return $this->normalizeResults($results);
    }

    /**
     * Build a search query from item criteria.
     */
    protected function buildSearchQuery(array $criteria): string
    {
        $parts = [];

        if (! empty($criteria['title'])) {
            $parts[] = $criteria['title'];
        }

        if (! empty($criteria['precious_metal'])) {
            $metalLabel = $this->getMetalLabel($criteria['precious_metal']);
            if ($metalLabel) {
                $parts[] = $metalLabel;
            }
        }

        if (! empty($criteria['category'])) {
            $parts[] = $criteria['category'];
        }

        // Add relevant attributes if provided
        if (! empty($criteria['attributes']) && is_array($criteria['attributes'])) {
            foreach ($criteria['attributes'] as $key => $value) {
                if (! empty($value) && is_string($value) && strlen($value) < 50) {
                    // Skip certain technical fields
                    if (! in_array($key, ['dwt', 'weight', 'weight_dwt'])) {
                        $parts[] = $value;
                    }
                }
            }
        }

        return implode(' ', array_slice($parts, 0, 5)); // Limit to 5 terms
    }

    /**
     * Convert metal code to human-readable label.
     */
    protected function getMetalLabel(string $metal): ?string
    {
        $labels = [
            'gold_10k' => '10K Gold',
            'gold_14k' => '14K Gold',
            'gold_18k' => '18K Gold',
            'gold_22k' => '22K Gold',
            'gold_24k' => '24K Gold',
            'silver' => 'Sterling Silver',
            'platinum' => 'Platinum',
            'palladium' => 'Palladium',
        ];

        return $labels[$metal] ?? null;
    }

    /**
     * Normalize results from different sources into a consistent format.
     *
     * @return array<string, mixed>
     */
    protected function normalizeResults(array $results): array
    {
        $listings = [];

        // Check for errors
        if (isset($results['google_shopping']['error']) && isset($results['ebay_sold']['error'])) {
            return [
                'error' => $results['google_shopping']['error'],
                'listings' => [],
                'summary' => $this->getEmptySummary(),
                'searched_at' => $results['searched_at'],
                'query' => $results['query'],
            ];
        }

        // Process Google Shopping results
        $shoppingResults = $results['google_shopping']['shopping_results'] ?? [];
        foreach ($shoppingResults as $item) {
            $price = $this->extractPrice($item['price'] ?? $item['extracted_price'] ?? null);
            if ($price !== null) {
                $listings[] = [
                    'source' => 'Google Shopping',
                    'title' => $item['title'] ?? 'Unknown',
                    'price' => $price,
                    'link' => $item['link'] ?? null,
                    'image' => $item['thumbnail'] ?? null,
                    'seller' => $item['source'] ?? null,
                    'condition' => $item['second_hand_condition'] ?? null,
                ];
            }
        }

        // Process eBay sold listings
        $ebayResults = $results['ebay_sold']['organic_results'] ?? [];
        foreach ($ebayResults as $item) {
            $priceData = $item['price'] ?? null;
            $priceValue = null;

            if (is_array($priceData)) {
                $priceValue = $this->extractPrice($priceData['raw'] ?? $priceData['extracted'] ?? null);
            } else {
                $priceValue = $this->extractPrice($priceData);
            }

            if ($priceValue !== null) {
                $listings[] = [
                    'source' => 'eBay (Sold)',
                    'title' => $item['title'] ?? 'Unknown',
                    'price' => $priceValue,
                    'link' => $item['link'] ?? null,
                    'image' => $item['thumbnail'] ?? null,
                    'sold_date' => $item['date'] ?? null,
                    'condition' => $item['condition'] ?? null,
                ];
            }
        }

        // Sort by price (ascending)
        usort($listings, fn ($a, $b) => ($a['price'] ?? 0) <=> ($b['price'] ?? 0));

        return [
            'listings' => $listings,
            'summary' => $this->calculatePriceSummary($listings),
            'searched_at' => $results['searched_at'],
            'query' => $results['query'],
        ];
    }

    /**
     * Extract numeric price from various formats.
     */
    protected function extractPrice(mixed $price): ?float
    {
        if ($price === null) {
            return null;
        }

        if (is_numeric($price)) {
            return (float) $price;
        }

        if (is_string($price)) {
            // Remove currency symbols and commas, extract number
            $cleaned = preg_replace('/[^0-9.]/', '', $price);
            if (is_numeric($cleaned) && $cleaned > 0) {
                return (float) $cleaned;
            }
        }

        return null;
    }

    /**
     * Calculate price statistics from listings.
     *
     * @return array<string, mixed>
     */
    protected function calculatePriceSummary(array $listings): array
    {
        $prices = array_filter(array_column($listings, 'price'));

        if (empty($prices)) {
            return $this->getEmptySummary();
        }

        return [
            'min' => min($prices),
            'max' => max($prices),
            'avg' => round(array_sum($prices) / count($prices), 2),
            'median' => $this->calculateMedian($prices),
            'count' => count($prices),
        ];
    }

    /**
     * Calculate median value from an array of numbers.
     */
    protected function calculateMedian(array $numbers): float
    {
        sort($numbers);
        $count = count($numbers);

        if ($count === 0) {
            return 0;
        }

        $middle = (int) floor($count / 2);

        if ($count % 2 === 0) {
            return round(($numbers[$middle - 1] + $numbers[$middle]) / 2, 2);
        }

        return round($numbers[$middle], 2);
    }

    /**
     * Get empty summary structure.
     *
     * @return array<string, mixed>
     */
    protected function getEmptySummary(): array
    {
        return [
            'min' => null,
            'max' => null,
            'avg' => null,
            'median' => null,
            'count' => 0,
        ];
    }
}
