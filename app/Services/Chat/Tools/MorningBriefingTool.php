<?php

namespace App\Services\Chat\Tools;

use App\Models\Customer;
use App\Models\Layaway;
use App\Models\MetalPrice;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductReturn;
use App\Models\Transaction;

class MorningBriefingTool implements ChatToolInterface
{
    public function name(): string
    {
        return 'get_morning_briefing';
    }

    public function definition(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'Get the morning briefing for store opening. Use when user says "morning briefing", "what do I need to know today", "open the store", or "start my day". Returns everything the owner needs to know when opening.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [],
                'required' => [],
            ],
        ];
    }

    public function execute(array $params, int $storeId): array
    {
        $today = now()->startOfDay();
        $yesterday = now()->subDay();

        // Yesterday's performance
        $yesterdayRevenue = Order::where('store_id', $storeId)
            ->whereIn('status', Order::PAID_STATUSES)
            ->whereBetween('created_at', [$yesterday->startOfDay(), $yesterday->endOfDay()])
            ->sum('total');

        $yesterdayTransactions = Order::where('store_id', $storeId)
            ->whereIn('status', Order::PAID_STATUSES)
            ->whereBetween('created_at', [$yesterday->startOfDay(), $yesterday->endOfDay()])
            ->count();

        // Items coming off hold today (assuming 30-day hold for pawn)
        $holdExpiringToday = Transaction::where('store_id', $storeId)
            ->where('status', 'payment_processed')
            ->whereDate('created_at', now()->subDays(30)->toDateString())
            ->count();

        // Layaway payments due today or overdue
        $layawaysDueToday = Layaway::where('store_id', $storeId)
            ->where('status', 'active')
            ->whereHas('schedules', function ($q) use ($today) {
                $q->where('status', 'pending')
                    ->whereDate('due_date', '<=', $today);
            })
            ->count();

        // Overdue layaways
        $overdueLayaways = Layaway::where('store_id', $storeId)
            ->where('status', 'active')
            ->whereHas('schedules', function ($q) {
                $q->where('status', 'pending')
                    ->whereDate('due_date', '<', now()->startOfDay());
            })
            ->with(['customer', 'schedules' => function ($q) {
                $q->where('status', 'pending')->orderBy('due_date');
            }])
            ->limit(5)
            ->get();

        // Slow movers to push (90+ days, price > $100)
        $slowMovers = Product::where('store_id', $storeId)
            ->where('status', 'active')
            ->where('quantity', '>', 0)
            ->where('price', '>=', 100)
            ->where('created_at', '<', now()->subDays(90))
            ->orderBy('price', 'desc')
            ->limit(5)
            ->get();

        // Metal spot prices
        $metalPrices = [];
        foreach (['gold', 'silver', 'platinum'] as $metal) {
            $price = MetalPrice::getLatest($metal);
            if ($price) {
                $metalPrices[$metal] = [
                    'per_oz' => round((float) $price->price_per_ounce, 2),
                    'per_gram' => round((float) $price->price_per_gram, 2),
                    'updated' => $price->effective_at->diffForHumans(),
                ];
            }
        }

        // Pending returns
        $pendingReturns = ProductReturn::where('store_id', $storeId)
            ->whereIn('status', ['pending', 'approved'])
            ->count();

        // New customers yesterday
        $newCustomersYesterday = Customer::where('store_id', $storeId)
            ->whereBetween('created_at', [$yesterday->startOfDay(), $yesterday->endOfDay()])
            ->count();

        // Week to date performance
        $weekStart = now()->startOfWeek();
        $weekRevenue = Order::where('store_id', $storeId)
            ->whereIn('status', Order::PAID_STATUSES)
            ->whereBetween('created_at', [$weekStart, now()])
            ->sum('total');

        $weekTransactions = Order::where('store_id', $storeId)
            ->whereIn('status', Order::PAID_STATUSES)
            ->whereBetween('created_at', [$weekStart, now()])
            ->count();

        return [
            'greeting' => $this->getGreeting(),
            'date' => now()->format('l, F j'),

            'yesterday' => [
                'revenue' => round($yesterdayRevenue, 2),
                'revenue_formatted' => '$'.number_format($yesterdayRevenue, 0),
                'transactions' => $yesterdayTransactions,
                'new_customers' => $newCustomersYesterday,
            ],

            'week_to_date' => [
                'revenue' => round($weekRevenue, 2),
                'revenue_formatted' => '$'.number_format($weekRevenue, 0),
                'transactions' => $weekTransactions,
                'days_in' => now()->dayOfWeek ?: 7,
            ],

            'action_items' => [
                'hold_expiring_today' => $holdExpiringToday,
                'layaways_due_today' => $layawaysDueToday,
                'pending_returns' => $pendingReturns,
            ],

            'overdue_layaways' => $overdueLayaways->map(function ($layaway) {
                $nextDue = $layaway->schedules->first();

                return [
                    'customer_name' => $layaway->customer?->name ?? 'Unknown',
                    'amount_due' => round($nextDue?->amount ?? 0, 2),
                    'days_overdue' => $nextDue ? now()->diffInDays($nextDue->due_date) : 0,
                    'total_balance' => round($layaway->balance_remaining ?? 0, 2),
                ];
            })->toArray(),

            'slow_movers_to_push' => $slowMovers->map(function ($product) {
                return [
                    'title' => $product->title,
                    'sku' => $product->sku,
                    'price' => round($product->price, 2),
                    'price_formatted' => '$'.number_format($product->price, 0),
                    'days_in_inventory' => $product->created_at->diffInDays(now()),
                ];
            })->toArray(),

            'metal_prices' => $metalPrices,

            'summary' => $this->buildSummary(
                $yesterdayRevenue,
                $yesterdayTransactions,
                $holdExpiringToday,
                $layawaysDueToday,
                count($overdueLayaways)
            ),
        ];
    }

    protected function getGreeting(): string
    {
        $hour = now()->hour;

        return match (true) {
            $hour < 12 => 'Good morning',
            $hour < 17 => 'Good afternoon',
            default => 'Good evening',
        };
    }

    protected function buildSummary(
        float $yesterdayRevenue,
        int $yesterdayTransactions,
        int $holdExpiring,
        int $layawaysDue,
        int $overdueLayaways
    ): string {
        $parts = [];

        if ($yesterdayRevenue > 0) {
            $parts[] = 'Yesterday you did $'.number_format($yesterdayRevenue, 0)." across {$yesterdayTransactions} transactions";
        } else {
            $parts[] = 'No sales yesterday';
        }

        $actionItems = [];
        if ($holdExpiring > 0) {
            $actionItems[] = "{$holdExpiring} items coming off hold";
        }
        if ($layawaysDue > 0) {
            $actionItems[] = "{$layawaysDue} layaway payments due";
        }
        if ($overdueLayaways > 0) {
            $actionItems[] = "{$overdueLayaways} overdue layaways need attention";
        }

        if (! empty($actionItems)) {
            $parts[] = 'Today: '.implode(', ', $actionItems);
        } else {
            $parts[] = 'No urgent items today';
        }

        return implode('. ', $parts).'.';
    }
}
