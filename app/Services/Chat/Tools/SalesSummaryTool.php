<?php

namespace App\Services\Chat\Tools;

use App\Models\Customer;
use App\Models\Order;
use Carbon\Carbon;

class SalesSummaryTool implements ChatToolInterface
{
    public function name(): string
    {
        return 'get_sales_summary';
    }

    public function definition(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'Get sales revenue, order counts, and customer metrics for a time period. Use this to answer questions about sales performance, revenue, or how the business is doing.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'period' => [
                        'type' => 'string',
                        'enum' => ['today', 'yesterday', 'this_week', 'last_week', 'this_month', 'last_month', 'last_30_days', 'last_7_days'],
                        'description' => 'The time period to analyze',
                    ],
                    'compare_to_previous' => [
                        'type' => 'boolean',
                        'description' => 'Whether to include comparison with the previous equivalent period',
                    ],
                ],
                'required' => ['period'],
            ],
        ];
    }

    public function execute(array $params, int $storeId): array
    {
        $period = $params['period'] ?? 'today';
        $compare = $params['compare_to_previous'] ?? true;

        [$startDate, $endDate] = $this->getDateRange($period);
        [$prevStartDate, $prevEndDate] = $this->getPreviousDateRange($period, $startDate, $endDate);

        // Current period metrics
        $revenue = Order::where('store_id', $storeId)
            ->whereIn('status', Order::PAID_STATUSES)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->sum('total');

        $orderCount = Order::where('store_id', $storeId)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->count();

        $paidOrderCount = Order::where('store_id', $storeId)
            ->whereIn('status', Order::PAID_STATUSES)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->count();

        $newCustomers = Customer::where('store_id', $storeId)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->count();

        $averageOrderValue = $paidOrderCount > 0 ? $revenue / $paidOrderCount : 0;

        $result = [
            'period' => $period,
            'period_label' => $this->getPeriodLabel($period),
            'start_date' => $startDate->format('Y-m-d'),
            'end_date' => $endDate->format('Y-m-d'),
            'revenue' => round($revenue, 2),
            'revenue_formatted' => '$'.number_format($revenue, 2),
            'total_orders' => $orderCount,
            'paid_orders' => $paidOrderCount,
            'new_customers' => $newCustomers,
            'average_order_value' => round($averageOrderValue, 2),
            'average_order_value_formatted' => '$'.number_format($averageOrderValue, 2),
        ];

        // Add comparison data
        if ($compare) {
            $prevRevenue = Order::where('store_id', $storeId)
                ->whereIn('status', Order::PAID_STATUSES)
                ->whereBetween('created_at', [$prevStartDate, $prevEndDate])
                ->sum('total');

            $prevOrderCount = Order::where('store_id', $storeId)
                ->whereBetween('created_at', [$prevStartDate, $prevEndDate])
                ->count();

            $result['comparison'] = [
                'previous_revenue' => round($prevRevenue, 2),
                'previous_revenue_formatted' => '$'.number_format($prevRevenue, 2),
                'previous_orders' => $prevOrderCount,
                'revenue_change_percent' => $this->calculatePercentChange($prevRevenue, $revenue),
                'orders_change_percent' => $this->calculatePercentChange($prevOrderCount, $orderCount),
            ];
        }

        return $result;
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
            'last_7_days' => [now()->subDays(6)->startOfDay(), now()->endOfDay()],
            'last_30_days' => [now()->subDays(29)->startOfDay(), now()->endOfDay()],
            default => [now()->startOfDay(), now()->endOfDay()],
        };
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    protected function getPreviousDateRange(string $period, Carbon $startDate, Carbon $endDate): array
    {
        $daysDiff = $startDate->diffInDays($endDate) + 1;

        return [
            $startDate->copy()->subDays($daysDiff),
            $endDate->copy()->subDays($daysDiff),
        ];
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
            'last_7_days' => 'Last 7 Days',
            'last_30_days' => 'Last 30 Days',
            default => ucfirst(str_replace('_', ' ', $period)),
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
