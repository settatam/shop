<?php

namespace App\Services\Chat\Tools;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductReturn;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class SalesReportTool implements ChatToolInterface
{
    public function name(): string
    {
        return 'get_sales_report';
    }

    public function definition(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'Get a comprehensive sales report for verbal delivery. Use this for questions like "how did we do today", "give me the weekly report", "what happened this month", or any sales performance questions. Returns all metrics needed for a verbal sales briefing.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'period' => [
                        'type' => 'string',
                        'enum' => ['today', 'yesterday', 'this_week', 'last_week', 'this_month', 'last_month', 'this_year'],
                        'description' => 'The time period for the report',
                    ],
                ],
                'required' => ['period'],
            ],
        ];
    }

    public function execute(array $params, int $storeId): array
    {
        $period = $params['period'] ?? 'today';

        [$startDate, $endDate] = $this->getDateRange($period);
        [$prevStartDate, $prevEndDate] = $this->getPreviousDateRange($period, $startDate, $endDate);

        // Current period metrics
        $currentMetrics = $this->getMetrics($storeId, $startDate, $endDate);
        $previousMetrics = $this->getMetrics($storeId, $prevStartDate, $prevEndDate);

        // Top sale of the period
        $topSale = Order::where('store_id', $storeId)
            ->whereIn('status', Order::PAID_STATUSES)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->orderByDesc('total')
            ->with('customer')
            ->first();

        // Top categories
        $topCategories = OrderItem::query()
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->join('products', 'order_items.product_id', '=', 'products.id')
            ->leftJoin('categories', 'products.category_id', '=', 'categories.id')
            ->where('orders.store_id', $storeId)
            ->whereIn('orders.status', Order::PAID_STATUSES)
            ->whereBetween('orders.created_at', [$startDate, $endDate])
            ->select('categories.name as category_name', DB::raw('SUM(order_items.price * order_items.quantity) as total_sales'))
            ->groupBy('categories.id', 'categories.name')
            ->orderByDesc('total_sales')
            ->limit(3)
            ->get();

        // Best day (for week/month reports)
        $bestDay = null;
        if (in_array($period, ['this_week', 'last_week', 'this_month', 'last_month', 'this_year'])) {
            $bestDay = Order::where('store_id', $storeId)
                ->whereIn('status', Order::PAID_STATUSES)
                ->whereBetween('created_at', [$startDate, $endDate])
                ->select(DB::raw('DATE(created_at) as date'), DB::raw('SUM(total) as daily_total'))
                ->groupBy(DB::raw('DATE(created_at)'))
                ->orderByDesc('daily_total')
                ->first();
        }

        // Returns
        $returns = ProductReturn::where('store_id', $storeId)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->selectRaw('COUNT(*) as count, COALESCE(SUM(refund_amount), 0) as total_refunded')
            ->first();

        // Calculate changes
        $revenueChange = $this->calculatePercentChange($previousMetrics['revenue'], $currentMetrics['revenue']);
        $ordersChange = $this->calculatePercentChange($previousMetrics['order_count'], $currentMetrics['order_count']);

        return [
            'period' => $period,
            'period_label' => $this->getPeriodLabel($period),
            'date_range' => [
                'start' => $startDate->format('M j'),
                'end' => $endDate->format('M j, Y'),
            ],

            // Revenue
            'revenue' => round($currentMetrics['revenue'], 2),
            'revenue_formatted' => '$'.number_format($currentMetrics['revenue'], 0),
            'previous_revenue' => round($previousMetrics['revenue'], 2),
            'previous_revenue_formatted' => '$'.number_format($previousMetrics['revenue'], 0),
            'revenue_change_percent' => $revenueChange,
            'revenue_trend' => $revenueChange >= 0 ? 'up' : 'down',

            // Transactions
            'transaction_count' => $currentMetrics['order_count'],
            'previous_transaction_count' => $previousMetrics['order_count'],
            'transactions_change_percent' => $ordersChange,

            // Averages
            'average_ticket' => round($currentMetrics['average_order'], 2),
            'average_ticket_formatted' => '$'.number_format($currentMetrics['average_order'], 0),

            // Customers
            'new_customers' => $currentMetrics['new_customers'],
            'returning_customers' => $currentMetrics['returning_customers'],

            // Top sale
            'top_sale' => $topSale ? [
                'amount' => round($topSale->total, 2),
                'amount_formatted' => '$'.number_format($topSale->total, 0),
                'customer_name' => $topSale->customer?->name ?? 'Walk-in',
                'item_count' => $topSale->items_count ?? $topSale->items()->count(),
            ] : null,

            // Categories
            'top_categories' => $topCategories->map(function ($cat) use ($currentMetrics) {
                $percentage = $currentMetrics['revenue'] > 0
                    ? round(($cat->total_sales / $currentMetrics['revenue']) * 100)
                    : 0;

                return [
                    'name' => $cat->category_name ?? 'Uncategorized',
                    'total' => round($cat->total_sales, 2),
                    'total_formatted' => '$'.number_format($cat->total_sales, 0),
                    'percentage' => $percentage,
                ];
            })->toArray(),

            // Best day (for longer periods)
            'best_day' => $bestDay ? [
                'date' => Carbon::parse($bestDay->date)->format('l'),
                'date_full' => Carbon::parse($bestDay->date)->format('M j'),
                'total' => round($bestDay->daily_total, 2),
                'total_formatted' => '$'.number_format($bestDay->daily_total, 0),
            ] : null,

            // Returns
            'returns' => [
                'count' => $returns->count ?? 0,
                'total_refunded' => round($returns->total_refunded ?? 0, 2),
                'total_refunded_formatted' => '$'.number_format($returns->total_refunded ?? 0, 0),
            ],

            // Comparison period label
            'comparison_period' => $this->getComparisonLabel($period),
        ];
    }

    /**
     * @return array{revenue: float, order_count: int, average_order: float, new_customers: int, returning_customers: int}
     */
    protected function getMetrics(int $storeId, Carbon $startDate, Carbon $endDate): array
    {
        $revenue = Order::where('store_id', $storeId)
            ->whereIn('status', Order::PAID_STATUSES)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->sum('total');

        $orderCount = Order::where('store_id', $storeId)
            ->whereIn('status', Order::PAID_STATUSES)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->count();

        $newCustomers = Customer::where('store_id', $storeId)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->count();

        // Returning customers = customers with orders in this period who existed before
        $returningCustomers = Order::where('store_id', $storeId)
            ->whereIn('status', Order::PAID_STATUSES)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->whereHas('customer', function ($q) use ($startDate) {
                $q->where('created_at', '<', $startDate);
            })
            ->distinct('customer_id')
            ->count('customer_id');

        return [
            'revenue' => (float) $revenue,
            'order_count' => $orderCount,
            'average_order' => $orderCount > 0 ? $revenue / $orderCount : 0,
            'new_customers' => $newCustomers,
            'returning_customers' => $returningCustomers,
        ];
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    protected function getDateRange(string $period): array
    {
        return match ($period) {
            'today' => [now()->startOfDay(), now()->endOfDay()],
            'yesterday' => [now()->subDay()->startOfDay(), now()->subDay()->endOfDay()],
            'this_week' => [now()->startOfWeek(), now()->endOfDay()],
            'last_week' => [now()->subWeek()->startOfWeek(), now()->subWeek()->endOfWeek()],
            'this_month' => [now()->startOfMonth(), now()->endOfDay()],
            'last_month' => [now()->subMonth()->startOfMonth(), now()->subMonth()->endOfMonth()],
            'this_year' => [now()->startOfYear(), now()->endOfDay()],
            default => [now()->startOfDay(), now()->endOfDay()],
        };
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    protected function getPreviousDateRange(string $period, Carbon $startDate, Carbon $endDate): array
    {
        $daysDiff = $startDate->diffInDays($endDate) + 1;

        return match ($period) {
            'this_week' => [now()->subWeek()->startOfWeek(), now()->subWeek()->endOfWeek()],
            'this_month' => [now()->subMonth()->startOfMonth(), now()->subMonth()->endOfMonth()],
            'this_year' => [now()->subYear()->startOfYear(), now()->subYear()->endOfYear()],
            default => [
                $startDate->copy()->subDays($daysDiff),
                $endDate->copy()->subDays($daysDiff),
            ],
        };
    }

    protected function getPeriodLabel(string $period): string
    {
        return match ($period) {
            'today' => 'Today',
            'yesterday' => 'Yesterday',
            'this_week' => 'This Week',
            'last_week' => 'Last Week',
            'this_month' => 'This Month',
            'last_month' => 'Last Month',
            'this_year' => 'This Year',
            default => ucfirst(str_replace('_', ' ', $period)),
        };
    }

    protected function getComparisonLabel(string $period): string
    {
        return match ($period) {
            'today' => 'yesterday',
            'yesterday' => 'the day before',
            'this_week' => 'last week',
            'last_week' => 'the week before',
            'this_month' => 'last month',
            'last_month' => 'the month before',
            'this_year' => 'last year',
            default => 'the previous period',
        };
    }

    protected function calculatePercentChange(float $previous, float $current): float
    {
        if ($previous == 0) {
            return $current > 0 ? 100 : 0;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }
}
