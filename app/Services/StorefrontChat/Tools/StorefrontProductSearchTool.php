<?php

namespace App\Services\StorefrontChat\Tools;

use App\Models\PlatformListing;
use App\Models\Product;
use App\Services\Chat\Tools\ChatToolInterface;

class StorefrontProductSearchTool implements ChatToolInterface
{
    public function name(): string
    {
        return 'search_products';
    }

    public function definition(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'Search for products in the store. Use this when a customer asks about products, wants to browse, or is looking for something specific like gold rings, diamond earrings, etc.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'query' => [
                        'type' => 'string',
                        'description' => 'Search query (e.g. "gold ring", "diamond earrings", "silver necklace")',
                    ],
                    'category' => [
                        'type' => 'string',
                        'description' => 'Filter by category name (e.g. "Rings", "Earrings", "Necklaces")',
                    ],
                    'min_price' => [
                        'type' => 'number',
                        'description' => 'Minimum price filter',
                    ],
                    'max_price' => [
                        'type' => 'number',
                        'description' => 'Maximum price filter',
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'description' => 'Maximum number of results (default 5, max 10)',
                    ],
                ],
                'required' => [],
            ],
        ];
    }

    public function execute(array $params, int $storeId): array
    {
        $query = $params['query'] ?? null;
        $category = $params['category'] ?? null;
        $minPrice = $params['min_price'] ?? null;
        $maxPrice = $params['max_price'] ?? null;
        $limit = min($params['limit'] ?? 5, 10);

        $productQuery = Product::where('store_id', $storeId)
            ->where('status', Product::STATUS_ACTIVE)
            ->with(['brand', 'category', 'variants']);

        if ($query) {
            $scoutIds = Product::search($query)
                ->where('store_id', $storeId)
                ->take(50)
                ->keys()
                ->toArray();

            if (empty($scoutIds)) {
                return [
                    'found' => false,
                    'message' => 'No products found matching your search.',
                    'products' => [],
                ];
            }

            $productQuery->whereIn('id', $scoutIds);
        }

        if ($category) {
            $productQuery->whereHas('category', function ($q) use ($category) {
                $q->where('name', 'like', "%{$category}%");
            });
        }

        if ($minPrice !== null || $maxPrice !== null) {
            $productQuery->whereHas('variants', function ($q) use ($minPrice, $maxPrice) {
                if ($minPrice !== null) {
                    $q->where('price', '>=', $minPrice);
                }
                if ($maxPrice !== null) {
                    $q->where('price', '<=', $maxPrice);
                }
            });
        }

        $products = $productQuery->limit($limit)->get();

        if ($products->isEmpty()) {
            return [
                'found' => false,
                'message' => 'No products found matching your criteria.',
                'products' => [],
            ];
        }

        $results = $products->map(function (Product $product) {
            $defaultVariant = $product->variants->first();
            $listing = $product->platformListings
                ->where('status', PlatformListing::STATUS_LISTED)
                ->first();

            return [
                'id' => $product->id,
                'title' => $product->title,
                'price' => $defaultVariant?->price ? round($defaultVariant->price, 2) : null,
                'price_formatted' => $defaultVariant?->price ? '$'.number_format($defaultVariant->price, 2) : null,
                'brand' => $product->brand?->name,
                'category' => $product->category?->name,
                'condition' => $product->condition,
                'available' => ($product->total_quantity ?? 0) > 0,
                'listing_url' => $listing?->listing_url,
            ];
        })->toArray();

        return [
            'found' => true,
            'count' => count($results),
            'products' => $results,
        ];
    }
}
