<?php

namespace App\Services\Chat\Tools;

use App\Models\Customer;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

class CustomerIntelligenceTool implements ChatToolInterface
{
    public function name(): string
    {
        return 'get_customer_intelligence';
    }

    public function definition(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'Get detailed intelligence about a specific customer. Use when user mentions a customer name, says "customer check in", "who is this customer", or "tell me about [name]". Returns purchase history, preferences, and insights.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'customer_name' => [
                        'type' => 'string',
                        'description' => 'Customer name to search for',
                    ],
                    'customer_id' => [
                        'type' => 'integer',
                        'description' => 'Customer ID if known',
                    ],
                    'phone' => [
                        'type' => 'string',
                        'description' => 'Customer phone number',
                    ],
                    'email' => [
                        'type' => 'string',
                        'description' => 'Customer email',
                    ],
                ],
                'required' => [],
            ],
        ];
    }

    public function execute(array $params, int $storeId): array
    {
        $customer = $this->findCustomer($params, $storeId);

        if (! $customer) {
            return [
                'found' => false,
                'message' => 'Customer not found',
                'searched' => array_filter($params),
            ];
        }

        // Lifetime stats
        $lifetimeStats = Order::where('store_id', $storeId)
            ->where('customer_id', $customer->id)
            ->whereIn('status', Order::PAID_STATUSES)
            ->selectRaw('COUNT(*) as order_count, COALESCE(SUM(total), 0) as lifetime_spend, AVG(total) as avg_order')
            ->first();

        // Recent orders
        $recentOrders = Order::where('store_id', $storeId)
            ->where('customer_id', $customer->id)
            ->whereIn('status', Order::PAID_STATUSES)
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->with('items.product.category')
            ->get();

        // Last purchase
        $lastOrder = $recentOrders->first();

        // Favorite categories
        $favoriteCategories = DB::table('order_items')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->join('products', 'order_items.product_id', '=', 'products.id')
            ->leftJoin('categories', 'products.category_id', '=', 'categories.id')
            ->where('orders.store_id', $storeId)
            ->where('orders.customer_id', $customer->id)
            ->whereIn('orders.status', Order::PAID_STATUSES)
            ->select('categories.name', DB::raw('COUNT(*) as purchase_count'), DB::raw('SUM(order_items.price * order_items.quantity) as total_spent'))
            ->groupBy('categories.id', 'categories.name')
            ->orderByDesc('purchase_count')
            ->limit(3)
            ->get();

        // Days since last visit
        $daysSinceLastVisit = $lastOrder
            ? $lastOrder->created_at->diffInDays(now())
            : null;

        // Customer tier based on lifetime spend
        $tier = $this->calculateTier($lifetimeStats->lifetime_spend ?? 0);

        // Purchase frequency
        $firstOrder = Order::where('store_id', $storeId)
            ->where('customer_id', $customer->id)
            ->whereIn('status', Order::PAID_STATUSES)
            ->orderBy('created_at', 'asc')
            ->first();

        $avgDaysBetweenPurchases = null;
        if ($firstOrder && $lifetimeStats->order_count > 1) {
            $daysSinceFirst = $firstOrder->created_at->diffInDays(now());
            $avgDaysBetweenPurchases = round($daysSinceFirst / ($lifetimeStats->order_count - 1));
        }

        // Notes from customer record
        $customerNotes = $customer->notes()->latest()->limit(3)->get();

        return [
            'found' => true,
            'customer' => [
                'id' => $customer->id,
                'name' => $customer->name,
                'email' => $customer->email,
                'phone' => $customer->phone,
                'member_since' => $customer->created_at->format('M Y'),
                'tier' => $tier,
            ],

            'lifetime_stats' => [
                'total_orders' => $lifetimeStats->order_count ?? 0,
                'lifetime_spend' => round($lifetimeStats->lifetime_spend ?? 0, 2),
                'lifetime_spend_formatted' => '$'.number_format($lifetimeStats->lifetime_spend ?? 0, 0),
                'average_order' => round($lifetimeStats->avg_order ?? 0, 2),
                'average_order_formatted' => '$'.number_format($lifetimeStats->avg_order ?? 0, 0),
            ],

            'last_visit' => $lastOrder ? [
                'date' => $lastOrder->created_at->format('M j, Y'),
                'days_ago' => $daysSinceLastVisit,
                'amount' => round($lastOrder->total, 2),
                'amount_formatted' => '$'.number_format($lastOrder->total, 0),
                'items' => $lastOrder->items->map(fn ($item) => $item->title)->toArray(),
            ] : null,

            'preferences' => [
                'favorite_categories' => $favoriteCategories->map(function ($cat) {
                    return [
                        'name' => $cat->name ?? 'Uncategorized',
                        'purchase_count' => $cat->purchase_count,
                        'total_spent' => round($cat->total_spent, 2),
                    ];
                })->toArray(),
                'avg_days_between_purchases' => $avgDaysBetweenPurchases,
            ],

            'recent_purchases' => $recentOrders->map(function ($order) {
                return [
                    'date' => $order->created_at->format('M j'),
                    'total' => round($order->total, 2),
                    'items' => $order->items->map(fn ($item) => $item->title)->implode(', '),
                ];
            })->toArray(),

            'notes' => $customerNotes->map(function ($note) {
                return [
                    'content' => $note->content,
                    'date' => $note->created_at->format('M j'),
                ];
            })->toArray(),

            'insights' => $this->generateInsights(
                $customer,
                $lifetimeStats,
                $daysSinceLastVisit,
                $avgDaysBetweenPurchases,
                $favoriteCategories
            ),
        ];
    }

    protected function findCustomer(array $params, int $storeId): ?Customer
    {
        if (! empty($params['customer_id'])) {
            return Customer::where('store_id', $storeId)
                ->where('id', $params['customer_id'])
                ->first();
        }

        if (! empty($params['phone'])) {
            return Customer::where('store_id', $storeId)
                ->where('phone', 'like', '%'.preg_replace('/\D/', '', $params['phone']).'%')
                ->first();
        }

        if (! empty($params['email'])) {
            return Customer::where('store_id', $storeId)
                ->where('email', $params['email'])
                ->first();
        }

        if (! empty($params['customer_name'])) {
            return Customer::where('store_id', $storeId)
                ->where('name', 'like', '%'.$params['customer_name'].'%')
                ->first();
        }

        return null;
    }

    protected function calculateTier(float $lifetimeSpend): string
    {
        return match (true) {
            $lifetimeSpend >= 10000 => 'VIP',
            $lifetimeSpend >= 5000 => 'Gold',
            $lifetimeSpend >= 1000 => 'Silver',
            $lifetimeSpend > 0 => 'Bronze',
            default => 'New',
        };
    }

    protected function generateInsights(
        Customer $customer,
        $lifetimeStats,
        ?int $daysSinceLastVisit,
        ?int $avgDaysBetweenPurchases,
        $favoriteCategories
    ): array {
        $insights = [];

        // VIP customer
        if (($lifetimeStats->lifetime_spend ?? 0) >= 5000) {
            $insights[] = 'High-value customer - consider VIP treatment';
        }

        // Overdue for visit
        if ($avgDaysBetweenPurchases && $daysSinceLastVisit && $daysSinceLastVisit > $avgDaysBetweenPurchases * 1.5) {
            $insights[] = "Usually visits every {$avgDaysBetweenPurchases} days - overdue by ".($daysSinceLastVisit - $avgDaysBetweenPurchases).' days';
        }

        // Category preference
        if ($favoriteCategories->isNotEmpty()) {
            $topCategory = $favoriteCategories->first();
            $insights[] = "Loves {$topCategory->name} - show them new arrivals in this category";
        }

        // High average order
        if (($lifetimeStats->avg_order ?? 0) >= 500) {
            $insights[] = 'High-ticket buyer - comfortable with premium items';
        }

        // Frequent buyer
        if (($lifetimeStats->order_count ?? 0) >= 10) {
            $insights[] = 'Frequent buyer - loyal customer';
        }

        return $insights;
    }
}
