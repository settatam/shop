<?php

namespace App\Services\Chat\Tools;

use App\Models\Customer;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

class CustomerInsightsTool implements ChatToolInterface
{
    public function name(): string
    {
        return 'get_customer_insights';
    }

    public function definition(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'Get customer insights including top customers, new customer acquisition, and customer statistics. Use this when users ask about their best customers, customer growth, or customer data.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'insight_type' => [
                        'type' => 'string',
                        'enum' => ['top_customers', 'new_customers', 'overview'],
                        'description' => 'Type of customer insight to retrieve',
                    ],
                    'period' => [
                        'type' => 'string',
                        'enum' => ['this_week', 'this_month', 'last_30_days', 'last_90_days', 'all_time'],
                        'description' => 'Time period for analysis (default: all_time for top_customers, last_30_days for new_customers)',
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'description' => 'Number of customers to return (default 10, max 20)',
                    ],
                ],
                'required' => ['insight_type'],
            ],
        ];
    }

    public function execute(array $params, int $storeId): array
    {
        $insightType = $params['insight_type'] ?? 'overview';
        $period = $params['period'] ?? ($insightType === 'new_customers' ? 'last_30_days' : 'all_time');
        $limit = min($params['limit'] ?? 10, 20);

        return match ($insightType) {
            'top_customers' => $this->getTopCustomers($storeId, $period, $limit),
            'new_customers' => $this->getNewCustomers($storeId, $period, $limit),
            'overview' => $this->getOverview($storeId),
            default => ['error' => 'Unknown insight type'],
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected function getTopCustomers(int $storeId, string $period, int $limit): array
    {
        [$startDate, $endDate] = $this->getDateRange($period);

        $query = Customer::query()
            ->select([
                'customers.id',
                'customers.first_name',
                'customers.last_name',
                'customers.email',
                DB::raw('SUM(orders.total) as total_spent'),
                DB::raw('COUNT(orders.id) as order_count'),
            ])
            ->join('orders', 'customers.id', '=', 'orders.customer_id')
            ->where('customers.store_id', $storeId)
            ->whereIn('orders.status', Order::PAID_STATUSES)
            ->whereNull('orders.deleted_at')
            ->groupBy('customers.id', 'customers.first_name', 'customers.last_name', 'customers.email');

        if ($startDate) {
            $query->whereBetween('orders.created_at', [$startDate, $endDate]);
        }

        $customers = $query->orderByDesc('total_spent')
            ->limit($limit)
            ->get();

        $totalRevenue = $customers->sum('total_spent');

        return [
            'insight_type' => 'top_customers',
            'period' => $this->getPeriodLabel($period),
            'customers' => $customers->map(function ($customer, $index) use ($totalRevenue) {
                $percentageOfRevenue = $totalRevenue > 0
                    ? round(($customer->total_spent / $totalRevenue) * 100, 1)
                    : 0;

                return [
                    'rank' => $index + 1,
                    'name' => trim($customer->first_name.' '.$customer->last_name) ?: 'Unknown',
                    'email' => $customer->email,
                    'total_spent' => round($customer->total_spent, 2),
                    'total_spent_formatted' => '$'.number_format($customer->total_spent, 2),
                    'order_count' => (int) $customer->order_count,
                    'avg_order_value' => '$'.number_format($customer->total_spent / max($customer->order_count, 1), 2),
                    'percentage_of_revenue' => $percentageOfRevenue.'%',
                ];
            })->toArray(),
            'summary' => [
                'top_customers_revenue' => '$'.number_format($totalRevenue, 2),
                'average_per_top_customer' => '$'.number_format($totalRevenue / max($customers->count(), 1), 2),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getNewCustomers(int $storeId, string $period, int $limit): array
    {
        [$startDate, $endDate] = $this->getDateRange($period);

        $query = Customer::query()
            ->where('store_id', $storeId);

        if ($startDate) {
            $query->whereBetween('created_at', [$startDate, $endDate]);
        }

        $newCount = $query->count();

        $recentCustomers = Customer::query()
            ->where('store_id', $storeId)
            ->when($startDate, fn ($q) => $q->whereBetween('created_at', [$startDate, $endDate]))
            ->withCount(['orders' => fn ($q) => $q->whereIn('status', Order::PAID_STATUSES)])
            ->withSum(['orders' => fn ($q) => $q->whereIn('status', Order::PAID_STATUSES)], 'total')
            ->latest()
            ->limit($limit)
            ->get();

        // Get previous period for comparison
        [$prevStartDate, $prevEndDate] = $this->getPreviousPeriodRange($period, $startDate, $endDate);
        $previousCount = Customer::query()
            ->where('store_id', $storeId)
            ->when($prevStartDate, fn ($q) => $q->whereBetween('created_at', [$prevStartDate, $prevEndDate]))
            ->count();

        $growthPercent = $previousCount > 0
            ? round((($newCount - $previousCount) / $previousCount) * 100, 1)
            : ($newCount > 0 ? 100 : 0);

        return [
            'insight_type' => 'new_customers',
            'period' => $this->getPeriodLabel($period),
            'new_customer_count' => $newCount,
            'growth_from_previous' => $growthPercent.'%',
            'previous_period_count' => $previousCount,
            'recent_customers' => $recentCustomers->map(fn ($customer) => [
                'name' => trim($customer->first_name.' '.$customer->last_name) ?: 'Unknown',
                'email' => $customer->email,
                'joined' => $customer->created_at->diffForHumans(),
                'orders' => $customer->orders_count,
                'total_spent' => '$'.number_format($customer->orders_sum_total ?? 0, 2),
            ])->toArray(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getOverview(int $storeId): array
    {
        $totalCustomers = Customer::query()
            ->where('store_id', $storeId)
            ->count();

        $customersWithOrders = Customer::query()
            ->where('store_id', $storeId)
            ->whereHas('orders', fn ($q) => $q->whereIn('status', Order::PAID_STATUSES))
            ->count();

        $newThisMonth = Customer::query()
            ->where('store_id', $storeId)
            ->where('created_at', '>=', now()->startOfMonth())
            ->count();

        $avgOrderValue = Order::query()
            ->where('store_id', $storeId)
            ->whereIn('status', Order::PAID_STATUSES)
            ->avg('total');

        $repeatCustomerCount = Customer::query()
            ->where('store_id', $storeId)
            ->whereHas('orders', function ($q) {
                $q->whereIn('status', Order::PAID_STATUSES);
            }, '>=', 2)
            ->count();

        $repeatRate = $customersWithOrders > 0
            ? round(($repeatCustomerCount / $customersWithOrders) * 100, 1)
            : 0;

        return [
            'insight_type' => 'overview',
            'total_customers' => $totalCustomers,
            'customers_with_orders' => $customersWithOrders,
            'new_this_month' => $newThisMonth,
            'repeat_customers' => $repeatCustomerCount,
            'repeat_rate' => $repeatRate.'%',
            'average_order_value' => '$'.number_format($avgOrderValue ?? 0, 2),
            'conversion_rate' => $totalCustomers > 0
                ? round(($customersWithOrders / $totalCustomers) * 100, 1).'%'
                : '0%',
        ];
    }

    /**
     * @return array{0: \Carbon\Carbon|null, 1: \Carbon\Carbon}
     */
    protected function getDateRange(string $period): array
    {
        return match ($period) {
            'this_week' => [now()->startOfWeek(), now()->endOfDay()],
            'this_month' => [now()->startOfMonth(), now()->endOfDay()],
            'last_30_days' => [now()->subDays(29)->startOfDay(), now()->endOfDay()],
            'last_90_days' => [now()->subDays(89)->startOfDay(), now()->endOfDay()],
            'all_time' => [null, now()->endOfDay()],
            default => [now()->subDays(29)->startOfDay(), now()->endOfDay()],
        };
    }

    /**
     * @return array{0: \Carbon\Carbon|null, 1: \Carbon\Carbon|null}
     */
    protected function getPreviousPeriodRange(string $period, ?\Carbon\Carbon $startDate, \Carbon\Carbon $endDate): array
    {
        if (! $startDate) {
            return [null, null];
        }

        $daysDiff = $startDate->diffInDays($endDate) + 1;

        return [
            $startDate->copy()->subDays($daysDiff),
            $endDate->copy()->subDays($daysDiff),
        ];
    }

    protected function getPeriodLabel(string $period): string
    {
        return match ($period) {
            'this_week' => 'This Week',
            'this_month' => 'This Month',
            'last_30_days' => 'Last 30 Days',
            'last_90_days' => 'Last 90 Days',
            'all_time' => 'All Time',
            default => ucfirst(str_replace('_', ' ', $period)),
        };
    }
}
