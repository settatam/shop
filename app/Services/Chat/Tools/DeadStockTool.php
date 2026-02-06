<?php

namespace App\Services\Chat\Tools;

use App\Models\Product;

class DeadStockTool implements ChatToolInterface
{
    public function name(): string
    {
        return 'get_dead_stock';
    }

    public function definition(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'Get information about slow-moving or dead stock inventory. Use this when users ask about items not selling, slow movers, stale inventory, or dead stock.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'category' => [
                        'type' => 'string',
                        'description' => 'Filter by category name (e.g., "jewelry", "watches", "electronics")',
                    ],
                    'threshold_days' => [
                        'type' => 'integer',
                        'description' => 'Consider items older than this many days as slow-moving (default 90)',
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'description' => 'Maximum number of items to return (default 10)',
                    ],
                ],
                'required' => [],
            ],
        ];
    }

    public function execute(array $params, int $storeId): array
    {
        $category = $params['category'] ?? null;
        $thresholdDays = $params['threshold_days'] ?? 90;
        $limit = min($params['limit'] ?? 10, 50);

        $cutoffDate = now()->subDays($thresholdDays);

        $query = Product::where('store_id', $storeId)
            ->where('status', 'active')
            ->where('quantity', '>', 0)
            ->where('created_at', '<', $cutoffDate)
            ->with('category')
            ->orderBy('created_at', 'asc');

        if ($category) {
            $query->whereHas('category', function ($q) use ($category) {
                $q->where('name', 'like', "%{$category}%");
            });
        }

        $slowMovers = $query->limit($limit)->get();

        // Get aggregate stats
        $totalSlowMovers = Product::where('store_id', $storeId)
            ->where('status', 'active')
            ->where('quantity', '>', 0)
            ->where('created_at', '<', $cutoffDate)
            ->count();

        $totalValue = Product::where('store_id', $storeId)
            ->where('status', 'active')
            ->where('quantity', '>', 0)
            ->where('created_at', '<', $cutoffDate)
            ->sum('price');

        // Dead stock (180+ days)
        $deadStockCutoff = now()->subDays(180);
        $deadStockCount = Product::where('store_id', $storeId)
            ->where('status', 'active')
            ->where('quantity', '>', 0)
            ->where('created_at', '<', $deadStockCutoff)
            ->count();

        if ($slowMovers->isEmpty()) {
            return [
                'message' => "No slow-moving inventory found (items older than {$thresholdDays} days).",
                'total_slow_movers' => 0,
                'items' => [],
            ];
        }

        return [
            'threshold_days' => $thresholdDays,
            'total_slow_movers' => $totalSlowMovers,
            'total_value' => round($totalValue, 2),
            'total_value_formatted' => '$'.number_format($totalValue, 2),
            'dead_stock_count' => $deadStockCount,
            'dead_stock_message' => $deadStockCount > 0
                ? "{$deadStockCount} items have been in inventory for over 180 days"
                : 'No items over 180 days old',
            'showing' => $slowMovers->count(),
            'items' => $slowMovers->map(function ($product) {
                $daysInInventory = $product->created_at->diffInDays(now());

                return [
                    'id' => $product->id,
                    'sku' => $product->sku,
                    'title' => $product->title,
                    'category' => $product->category?->name,
                    'price' => round($product->price, 2),
                    'price_formatted' => '$'.number_format($product->price, 2),
                    'days_in_inventory' => $daysInInventory,
                    'age_category' => $this->getAgeCategory($daysInInventory),
                ];
            })->toArray(),
        ];
    }

    protected function getAgeCategory(int $days): string
    {
        return match (true) {
            $days >= 180 => 'dead_stock',
            $days >= 120 => 'very_slow',
            $days >= 90 => 'slow_mover',
            default => 'aging',
        };
    }
}
