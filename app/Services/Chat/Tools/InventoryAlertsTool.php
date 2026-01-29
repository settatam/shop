<?php

namespace App\Services\Chat\Tools;

use App\Models\Inventory;
use Illuminate\Support\Facades\DB;

class InventoryAlertsTool implements ChatToolInterface
{
    public function name(): string
    {
        return 'get_inventory_alerts';
    }

    public function definition(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'Get inventory alerts including low stock items, out of stock items, and items needing reorder. Use this when users ask about stock levels or inventory issues.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'alert_type' => [
                        'type' => 'string',
                        'enum' => ['all', 'low_stock', 'out_of_stock', 'needs_reorder'],
                        'description' => 'Type of inventory alert to retrieve',
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'description' => 'Maximum number of items to return (default 10)',
                    ],
                ],
                'required' => ['alert_type'],
            ],
        ];
    }

    public function execute(array $params, int $storeId): array
    {
        $alertType = $params['alert_type'] ?? 'all';
        $limit = min($params['limit'] ?? 10, 25);

        if ($alertType === 'all') {
            return $this->getAllAlerts($storeId, $limit);
        }

        return match ($alertType) {
            'low_stock' => $this->getLowStock($storeId, $limit),
            'out_of_stock' => $this->getOutOfStock($storeId, $limit),
            'needs_reorder' => $this->getNeedsReorder($storeId, $limit),
            default => ['error' => 'Unknown alert type'],
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected function getAllAlerts(int $storeId, int $limit): array
    {
        $outOfStockCount = Inventory::query()
            ->where('store_id', $storeId)
            ->where('quantity', '<=', 0)
            ->count();

        $lowStockCount = Inventory::query()
            ->where('store_id', $storeId)
            ->where('quantity', '>', 0)
            ->whereColumn('quantity', '<=', DB::raw('COALESCE(reorder_point, 5)'))
            ->count();

        $needsReorderCount = Inventory::query()
            ->where('store_id', $storeId)
            ->whereColumn('quantity', '<=', 'reorder_point')
            ->where('reorder_point', '>', 0)
            ->count();

        $totalAlerts = $outOfStockCount + $lowStockCount;

        return [
            'summary' => [
                'total_alerts' => $totalAlerts,
                'out_of_stock' => $outOfStockCount,
                'low_stock' => $lowStockCount,
                'needs_reorder' => $needsReorderCount,
            ],
            'top_items' => $this->getTopAlertItems($storeId, $limit),
            'health_status' => $this->getHealthStatus($outOfStockCount, $lowStockCount),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getLowStock(int $storeId, int $limit): array
    {
        $items = $this->getInventoryAlertItems($storeId, $limit, 'low_stock');

        return [
            'alert_type' => 'low_stock',
            'count' => count($items),
            'items' => $items,
            'message' => count($items) > 0
                ? 'These items are running low and may need restocking soon.'
                : 'No low stock items found.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getOutOfStock(int $storeId, int $limit): array
    {
        $items = $this->getInventoryAlertItems($storeId, $limit, 'out_of_stock');

        return [
            'alert_type' => 'out_of_stock',
            'count' => count($items),
            'items' => $items,
            'message' => count($items) > 0
                ? 'These items are completely out of stock and need immediate attention.'
                : 'No out of stock items. Great job keeping inventory stocked!',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getNeedsReorder(int $storeId, int $limit): array
    {
        $items = $this->getInventoryAlertItems($storeId, $limit, 'needs_reorder');

        return [
            'alert_type' => 'needs_reorder',
            'count' => count($items),
            'items' => $items,
            'message' => count($items) > 0
                ? 'These items have reached their reorder point.'
                : 'No items need reordering at this time.',
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function getInventoryAlertItems(int $storeId, int $limit, string $type): array
    {
        $query = Inventory::query()
            ->where('store_id', $storeId)
            ->with(['variant.product:id,title', 'warehouse:id,name']);

        switch ($type) {
            case 'out_of_stock':
                $query->where('quantity', '<=', 0);
                break;
            case 'low_stock':
                $query->where('quantity', '>', 0)
                    ->whereColumn('quantity', '<=', DB::raw('COALESCE(reorder_point, 5)'));
                break;
            case 'needs_reorder':
                $query->whereColumn('quantity', '<=', 'reorder_point')
                    ->where('reorder_point', '>', 0);
                break;
        }

        return $query->orderBy('quantity')
            ->limit($limit)
            ->get()
            ->map(fn (Inventory $inv) => [
                'product' => $inv->variant?->product?->title ?? 'Unknown Product',
                'sku' => $inv->variant?->sku ?? 'N/A',
                'variant' => $this->formatVariantName($inv->variant),
                'warehouse' => $inv->warehouse?->name ?? 'Default',
                'quantity' => $inv->quantity,
                'reorder_point' => $inv->reorder_point ?? 0,
            ])
            ->toArray();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function getTopAlertItems(int $storeId, int $limit): array
    {
        return Inventory::query()
            ->where('store_id', $storeId)
            ->where(function ($q) {
                $q->where('quantity', '<=', 0)
                    ->orWhereColumn('quantity', '<=', DB::raw('COALESCE(reorder_point, 5)'));
            })
            ->with(['variant.product:id,title', 'warehouse:id,name'])
            ->orderBy('quantity')
            ->limit($limit)
            ->get()
            ->map(fn (Inventory $inv) => [
                'product' => $inv->variant?->product?->title ?? 'Unknown Product',
                'sku' => $inv->variant?->sku ?? 'N/A',
                'quantity' => $inv->quantity,
                'status' => $inv->quantity <= 0 ? 'out_of_stock' : 'low_stock',
            ])
            ->toArray();
    }

    protected function formatVariantName(?object $variant): string
    {
        if (! $variant) {
            return 'Default';
        }

        $parts = [];
        if ($variant->option1_value) {
            $parts[] = $variant->option1_value;
        }
        if ($variant->option2_value) {
            $parts[] = $variant->option2_value;
        }
        if ($variant->option3_value) {
            $parts[] = $variant->option3_value;
        }

        return ! empty($parts) ? implode(' / ', $parts) : 'Default';
    }

    protected function getHealthStatus(int $outOfStock, int $lowStock): string
    {
        $total = $outOfStock + $lowStock;

        if ($total === 0) {
            return 'excellent';
        }
        if ($outOfStock === 0 && $lowStock < 5) {
            return 'good';
        }
        if ($outOfStock < 5) {
            return 'needs_attention';
        }

        return 'critical';
    }
}
