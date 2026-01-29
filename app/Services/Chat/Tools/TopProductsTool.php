<?php

namespace App\Services\Chat\Tools;

use App\Models\Order;
use App\Models\OrderItem;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class TopProductsTool implements ChatToolInterface
{
    public function name(): string
    {
        return 'get_top_products';
    }

    public function definition(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'Get top performing products by revenue or quantity sold. Use this when users ask about best sellers, top products, or product performance.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'metric' => [
                        'type' => 'string',
                        'enum' => ['revenue', 'quantity'],
                        'description' => 'Rank products by revenue or quantity sold',
                    ],
                    'period' => [
                        'type' => 'string',
                        'enum' => ['today', 'this_week', 'this_month', 'last_30_days', 'all_time'],
                        'description' => 'Time period to analyze',
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'description' => 'Number of products to return (default 10, max 20)',
                    ],
                ],
                'required' => ['metric', 'period'],
            ],
        ];
    }

    public function execute(array $params, int $storeId): array
    {
        $metric = $params['metric'] ?? 'revenue';
        $period = $params['period'] ?? 'this_month';
        $limit = min($params['limit'] ?? 10, 20);

        [$startDate, $endDate] = $this->getDateRange($period);

        $topProducts = $this->getTopProducts($storeId, $metric, $startDate, $endDate, $limit);
        $totals = $this->getTotals($storeId, $startDate, $endDate);

        return [
            'metric' => $metric,
            'period' => $this->getPeriodLabel($period),
            'start_date' => $startDate?->format('Y-m-d'),
            'end_date' => $endDate->format('Y-m-d'),
            'products' => $topProducts,
            'totals' => $totals,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function getTopProducts(int $storeId, string $metric, ?Carbon $startDate, Carbon $endDate, int $limit): array
    {
        $orderColumn = $metric === 'revenue' ? 'total_revenue' : 'total_quantity';

        $query = OrderItem::query()
            ->select([
                'order_items.product_id',
                'products.title as product_name',
                DB::raw('SUM(order_items.quantity) as total_quantity'),
                DB::raw('SUM(order_items.price * order_items.quantity) as total_revenue'),
                DB::raw('COUNT(DISTINCT orders.id) as order_count'),
            ])
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->join('products', 'order_items.product_id', '=', 'products.id')
            ->where('orders.store_id', $storeId)
            ->whereIn('orders.status', Order::PAID_STATUSES)
            ->whereNull('orders.deleted_at')
            ->groupBy('order_items.product_id', 'products.title');

        if ($startDate) {
            $query->whereBetween('orders.created_at', [$startDate, $endDate]);
        }

        $products = $query->orderByDesc($orderColumn)
            ->limit($limit)
            ->get();

        $totalRevenue = $products->sum('total_revenue');

        return $products->map(function ($product, $index) use ($totalRevenue) {
            $percentageOfTotal = $totalRevenue > 0
                ? round(($product->total_revenue / $totalRevenue) * 100, 1)
                : 0;

            return [
                'rank' => $index + 1,
                'product_name' => $product->product_name ?? 'Unknown Product',
                'quantity_sold' => (int) $product->total_quantity,
                'revenue' => round($product->total_revenue, 2),
                'revenue_formatted' => '$'.number_format($product->total_revenue, 2),
                'order_count' => (int) $product->order_count,
                'percentage_of_total' => $percentageOfTotal.'%',
                'avg_order_quantity' => round($product->total_quantity / max($product->order_count, 1), 1),
            ];
        })->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    protected function getTotals(int $storeId, ?Carbon $startDate, Carbon $endDate): array
    {
        $query = Order::query()
            ->where('store_id', $storeId)
            ->whereIn('status', Order::PAID_STATUSES);

        if ($startDate) {
            $query->whereBetween('created_at', [$startDate, $endDate]);
        }

        $orderData = $query->selectRaw('SUM(total) as revenue, COUNT(*) as orders')
            ->first();

        $uniqueProducts = OrderItem::query()
            ->whereHas('order', function ($q) use ($storeId, $startDate, $endDate) {
                $q->where('store_id', $storeId)
                    ->whereIn('status', Order::PAID_STATUSES);

                if ($startDate) {
                    $q->whereBetween('created_at', [$startDate, $endDate]);
                }
            })
            ->distinct('product_id')
            ->count('product_id');

        return [
            'total_revenue' => round($orderData->revenue ?? 0, 2),
            'total_revenue_formatted' => '$'.number_format($orderData->revenue ?? 0, 2),
            'total_orders' => (int) ($orderData->orders ?? 0),
            'unique_products_sold' => $uniqueProducts,
        ];
    }

    /**
     * @return array{0: Carbon|null, 1: Carbon}
     */
    protected function getDateRange(string $period): array
    {
        return match ($period) {
            'today' => [now()->startOfDay(), now()->endOfDay()],
            'this_week' => [now()->startOfWeek(), now()->endOfDay()],
            'this_month' => [now()->startOfMonth(), now()->endOfDay()],
            'last_30_days' => [now()->subDays(29)->startOfDay(), now()->endOfDay()],
            'all_time' => [null, now()->endOfDay()],
            default => [now()->startOfMonth(), now()->endOfDay()],
        };
    }

    protected function getPeriodLabel(string $period): string
    {
        return match ($period) {
            'today' => 'Today',
            'this_week' => 'This Week',
            'this_month' => 'This Month',
            'last_30_days' => 'Last 30 Days',
            'all_time' => 'All Time',
            default => ucfirst(str_replace('_', ' ', $period)),
        };
    }
}
