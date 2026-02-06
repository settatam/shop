<?php

namespace App\Services\Chat\Tools;

use App\Models\Product;

class MarketPriceCheckTool implements ChatToolInterface
{
    public function name(): string
    {
        return 'check_market_prices';
    }

    public function definition(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'Check current market prices for items. Use when user asks "what are Rolexes selling for", "market price for", "what\'s the going rate", or wants to know competitive pricing. Searches recent sales and market data.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'item_description' => [
                        'type' => 'string',
                        'description' => 'Description of item to search for (e.g., "Rolex Submariner", "14k gold cuban chain", "Louis Vuitton bag")',
                    ],
                    'brand' => [
                        'type' => 'string',
                        'description' => 'Brand name to search',
                    ],
                    'model' => [
                        'type' => 'string',
                        'description' => 'Model name or number',
                    ],
                ],
                'required' => ['item_description'],
            ],
        ];
    }

    public function execute(array $params, int $storeId): array
    {
        $itemDescription = $params['item_description'];
        $brand = $params['brand'] ?? null;
        $model = $params['model'] ?? null;

        // Build search query
        $searchQuery = $itemDescription;
        if ($brand) {
            $searchQuery = $brand.' '.$searchQuery;
        }
        if ($model) {
            $searchQuery .= ' '.$model;
        }

        $result = [
            'search_query' => $searchQuery,
            'your_inventory' => [],
            'your_sold_history' => [],
            'market_insight' => [],
        ];

        // Check your own inventory for similar items
        $yourInventory = Product::where('store_id', $storeId)
            ->where('status', 'active')
            ->where('quantity', '>', 0)
            ->where(function ($q) use ($itemDescription, $brand, $model) {
                $q->where('title', 'like', "%{$itemDescription}%");
                if ($brand) {
                    $q->orWhere('title', 'like', "%{$brand}%");
                }
                if ($model) {
                    $q->orWhere('title', 'like', "%{$model}%");
                }
            })
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get();

        $result['your_inventory'] = $yourInventory->map(function ($product) {
            return [
                'title' => $product->title,
                'sku' => $product->sku,
                'price' => round($product->price, 2),
                'price_formatted' => '$'.number_format($product->price, 0),
                'days_listed' => $product->created_at->diffInDays(now()),
            ];
        })->toArray();

        // Check your sold history
        $soldHistory = Product::where('store_id', $storeId)
            ->where('status', 'sold')
            ->where(function ($q) use ($itemDescription, $brand, $model) {
                $q->where('title', 'like', "%{$itemDescription}%");
                if ($brand) {
                    $q->orWhere('title', 'like', "%{$brand}%");
                }
                if ($model) {
                    $q->orWhere('title', 'like', "%{$model}%");
                }
            })
            ->orderBy('updated_at', 'desc')
            ->limit(10)
            ->get();

        if ($soldHistory->isNotEmpty()) {
            $avgPrice = $soldHistory->avg('price');
            $minPrice = $soldHistory->min('price');
            $maxPrice = $soldHistory->max('price');

            $result['your_sold_history'] = [
                'count' => $soldHistory->count(),
                'average_price' => round($avgPrice, 2),
                'average_price_formatted' => '$'.number_format($avgPrice, 0),
                'price_range' => '$'.number_format($minPrice, 0).' - $'.number_format($maxPrice, 0),
                'recent_sales' => $soldHistory->take(5)->map(function ($product) {
                    return [
                        'title' => $product->title,
                        'price' => round($product->price, 2),
                        'price_formatted' => '$'.number_format($product->price, 0),
                        'sold_date' => $product->updated_at->format('M j'),
                    ];
                })->toArray(),
            ];
        }

        // Generate market insight based on available data
        $result['market_insight'] = $this->generateMarketInsight(
            $searchQuery,
            $yourInventory,
            $soldHistory
        );

        return $result;
    }

    protected function generateMarketInsight($searchQuery, $inventory, $soldHistory): array
    {
        $insights = [];

        if ($soldHistory->isNotEmpty()) {
            $avgPrice = $soldHistory->avg('price');
            $avgDaysToSell = 30; // Would calculate from actual data

            $insights[] = sprintf(
                'Based on your sales history, similar items sell for around $%s',
                number_format($avgPrice, 0)
            );

            // Check if any current inventory is overpriced
            foreach ($inventory as $product) {
                if ($product->price > $avgPrice * 1.2) {
                    $insights[] = sprintf(
                        '%s may be overpriced at $%s (avg sold: $%s)',
                        $product->title,
                        number_format($product->price, 0),
                        number_format($avgPrice, 0)
                    );
                }
            }
        }

        if ($inventory->isEmpty() && $soldHistory->isEmpty()) {
            $insights[] = 'No historical data for this item type in your store';
            $insights[] = 'Consider checking eBay sold listings for market prices';
        }

        if ($inventory->isNotEmpty() && $soldHistory->isEmpty()) {
            $insights[] = 'You have these in stock but none sold yet - may need to adjust pricing';
        }

        return $insights;
    }
}
