<?php

namespace App\Services\Chat\Tools;

use App\Models\Order;
use App\Models\PlatformOrder;
use App\Models\StoreMarketplace;
use Illuminate\Support\Facades\DB;

class ChannelPerformanceTool implements ChatToolInterface
{
    public function name(): string
    {
        return 'channel_performance';
    }

    public function definition(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'Get performance metrics across all sales channels. Use when users ask about sales by channel, marketplace performance, cross-channel analytics, or want to compare Amazon, Walmart, Shopify, etc. performance.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'period' => [
                        'type' => 'string',
                        'enum' => ['today', 'yesterday', '7_days', '30_days', '90_days', 'this_month', 'last_month'],
                        'description' => 'The time period to analyze.',
                    ],
                    'metric' => [
                        'type' => 'string',
                        'enum' => ['overview', 'revenue', 'orders', 'aov', 'top_products'],
                        'description' => 'The type of analysis: overview (all metrics), revenue, orders (order counts), aov (average order value), top_products (best sellers by channel).',
                    ],
                ],
                'required' => [],
            ],
        ];
    }

    public function execute(array $params, int $storeId): array
    {
        $period = $params['period'] ?? '30_days';
        $metric = $params['metric'] ?? 'overview';

        $dateRange = $this->getDateRange($period);

        return match ($metric) {
            'overview' => $this->getOverview($storeId, $dateRange),
            'revenue' => $this->getRevenueBreakdown($storeId, $dateRange),
            'orders' => $this->getOrderBreakdown($storeId, $dateRange),
            'aov' => $this->getAOVBreakdown($storeId, $dateRange),
            'top_products' => $this->getTopProductsByChannel($storeId, $dateRange),
            default => ['error' => 'Unknown metric: '.$metric],
        };
    }

    protected function getDateRange(string $period): array
    {
        return match ($period) {
            'today' => [now()->startOfDay(), now()],
            'yesterday' => [now()->subDay()->startOfDay(), now()->subDay()->endOfDay()],
            '7_days' => [now()->subDays(7), now()],
            '30_days' => [now()->subDays(30), now()],
            '90_days' => [now()->subDays(90), now()],
            'this_month' => [now()->startOfMonth(), now()],
            'last_month' => [now()->subMonth()->startOfMonth(), now()->subMonth()->endOfMonth()],
            default => [now()->subDays(30), now()],
        };
    }

    protected function getOverview(int $storeId, array $dateRange): array
    {
        $channels = [];
        $totalRevenue = 0;
        $totalOrders = 0;

        // Local/POS sales
        $localData = Order::where('store_id', $storeId)
            ->whereBetween('created_at', $dateRange)
            ->whereIn('status', ['completed', 'shipped', 'delivered'])
            ->selectRaw('COUNT(*) as orders, COALESCE(SUM(total), 0) as revenue')
            ->first();

        $channels['local'] = [
            'channel' => 'Local/POS',
            'orders' => (int) $localData->orders,
            'revenue' => (float) $localData->revenue,
            'aov' => $localData->orders > 0 ? round($localData->revenue / $localData->orders, 2) : 0,
        ];
        $totalRevenue += $localData->revenue;
        $totalOrders += $localData->orders;

        // Platform sales
        $marketplaces = StoreMarketplace::where('store_id', $storeId)
            ->where('status', 'active')
            ->get();

        foreach ($marketplaces as $marketplace) {
            $platformData = PlatformOrder::where('store_marketplace_id', $marketplace->id)
                ->whereBetween('ordered_at', $dateRange)
                ->selectRaw('COUNT(*) as orders, COALESCE(SUM(total), 0) as revenue')
                ->first();

            $channels[$marketplace->platform->value] = [
                'channel' => $marketplace->platform->label(),
                'orders' => (int) $platformData->orders,
                'revenue' => (float) $platformData->revenue,
                'aov' => $platformData->orders > 0 ? round($platformData->revenue / $platformData->orders, 2) : 0,
            ];
            $totalRevenue += $platformData->revenue;
            $totalOrders += $platformData->orders;
        }

        // Calculate percentages
        foreach ($channels as $key => &$channel) {
            $channel['revenue_percent'] = $totalRevenue > 0
                ? round(($channel['revenue'] / $totalRevenue) * 100, 1)
                : 0;
            $channel['order_percent'] = $totalOrders > 0
                ? round(($channel['orders'] / $totalOrders) * 100, 1)
                : 0;
        }

        // Sort by revenue
        uasort($channels, fn ($a, $b) => $b['revenue'] <=> $a['revenue']);

        return [
            'period' => $this->formatPeriod($dateRange),
            'total_revenue' => $totalRevenue,
            'total_orders' => $totalOrders,
            'average_order_value' => $totalOrders > 0 ? round($totalRevenue / $totalOrders, 2) : 0,
            'channels' => array_values($channels),
        ];
    }

    protected function getRevenueBreakdown(int $storeId, array $dateRange): array
    {
        $overview = $this->getOverview($storeId, $dateRange);

        // Add daily trend for top channel
        $dailyTrend = Order::where('store_id', $storeId)
            ->whereBetween('created_at', $dateRange)
            ->whereIn('status', ['completed', 'shipped', 'delivered'])
            ->groupBy('date')
            ->selectRaw('DATE(created_at) as date, SUM(total) as revenue')
            ->orderBy('date')
            ->get()
            ->map(fn ($row) => [
                'date' => $row->date,
                'revenue' => (float) $row->revenue,
            ])
            ->toArray();

        return [
            'period' => $overview['period'],
            'total_revenue' => $overview['total_revenue'],
            'by_channel' => array_map(fn ($ch) => [
                'channel' => $ch['channel'],
                'revenue' => $ch['revenue'],
                'percent' => $ch['revenue_percent'],
            ], $overview['channels']),
            'daily_trend' => array_slice($dailyTrend, -7), // Last 7 days
        ];
    }

    protected function getOrderBreakdown(int $storeId, array $dateRange): array
    {
        $overview = $this->getOverview($storeId, $dateRange);

        return [
            'period' => $overview['period'],
            'total_orders' => $overview['total_orders'],
            'by_channel' => array_map(fn ($ch) => [
                'channel' => $ch['channel'],
                'orders' => $ch['orders'],
                'percent' => $ch['order_percent'],
            ], $overview['channels']),
        ];
    }

    protected function getAOVBreakdown(int $storeId, array $dateRange): array
    {
        $overview = $this->getOverview($storeId, $dateRange);

        return [
            'period' => $overview['period'],
            'overall_aov' => $overview['average_order_value'],
            'by_channel' => array_map(fn ($ch) => [
                'channel' => $ch['channel'],
                'aov' => $ch['aov'],
                'orders' => $ch['orders'],
            ], $overview['channels']),
        ];
    }

    protected function getTopProductsByChannel(int $storeId, array $dateRange): array
    {
        $results = [];

        // Local top sellers
        $localTop = DB::table('order_items')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->join('products', 'order_items.product_id', '=', 'products.id')
            ->where('orders.store_id', $storeId)
            ->whereBetween('orders.created_at', $dateRange)
            ->whereIn('orders.status', ['completed', 'shipped', 'delivered'])
            ->groupBy('products.id', 'products.title')
            ->selectRaw('products.title, SUM(order_items.quantity) as units, SUM(order_items.total) as revenue')
            ->orderByDesc('revenue')
            ->limit(5)
            ->get();

        $results['local'] = [
            'channel' => 'Local/POS',
            'top_products' => $localTop->map(fn ($p) => [
                'title' => $p->title,
                'units' => (int) $p->units,
                'revenue' => (float) $p->revenue,
            ])->toArray(),
        ];

        // Platform top sellers
        $marketplaces = StoreMarketplace::where('store_id', $storeId)
            ->where('status', 'active')
            ->get();

        foreach ($marketplaces as $marketplace) {
            $platformTop = PlatformOrder::where('store_marketplace_id', $marketplace->id)
                ->whereBetween('ordered_at', $dateRange)
                ->get()
                ->flatMap(fn ($order) => collect($order->line_items ?? []))
                ->groupBy('title')
                ->map(fn ($items, $title) => [
                    'title' => $title,
                    'units' => $items->sum('quantity'),
                    'revenue' => $items->sum('total'),
                ])
                ->sortByDesc('revenue')
                ->take(5)
                ->values()
                ->toArray();

            $results[$marketplace->platform->value] = [
                'channel' => $marketplace->platform->label(),
                'top_products' => $platformTop,
            ];
        }

        return [
            'period' => $this->formatPeriod($dateRange),
            'channels' => array_values($results),
        ];
    }

    protected function formatPeriod(array $dateRange): string
    {
        return $dateRange[0]->format('M j').' - '.$dateRange[1]->format('M j, Y');
    }
}
