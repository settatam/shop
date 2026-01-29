<?php

namespace App\Services\Chat\Tools;

use App\Models\Order;

class OrderStatusTool implements ChatToolInterface
{
    public function name(): string
    {
        return 'get_order_status';
    }

    public function definition(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'Get order counts grouped by status. Use this to answer questions about pending orders, orders needing attention, or order status breakdown.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'status' => [
                        'type' => 'string',
                        'enum' => ['all', 'pending', 'confirmed', 'processing', 'shipped', 'delivered', 'completed', 'cancelled', 'needs_attention'],
                        'description' => 'Filter by specific status, "all" for summary, or "needs_attention" for orders requiring action',
                    ],
                ],
                'required' => ['status'],
            ],
        ];
    }

    public function execute(array $params, int $storeId): array
    {
        $status = $params['status'] ?? 'all';

        if ($status === 'all') {
            return $this->getAllStatusCounts($storeId);
        }

        if ($status === 'needs_attention') {
            return $this->getNeedsAttention($storeId);
        }

        return $this->getOrdersByStatus($storeId, $status);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getAllStatusCounts(int $storeId): array
    {
        $counts = Order::query()
            ->where('store_id', $storeId)
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $total = array_sum($counts);

        return [
            'total_orders' => $total,
            'by_status' => [
                'pending' => $counts['pending'] ?? 0,
                'confirmed' => $counts['confirmed'] ?? 0,
                'processing' => $counts['processing'] ?? 0,
                'shipped' => $counts['shipped'] ?? 0,
                'delivered' => $counts['delivered'] ?? 0,
                'completed' => $counts['completed'] ?? 0,
                'cancelled' => $counts['cancelled'] ?? 0,
            ],
            'summary' => [
                'needs_fulfillment' => ($counts['pending'] ?? 0) + ($counts['confirmed'] ?? 0),
                'in_transit' => $counts['shipped'] ?? 0,
                'completed_this_week' => Order::query()
                    ->where('store_id', $storeId)
                    ->where('status', Order::STATUS_COMPLETED)
                    ->where('updated_at', '>=', now()->startOfWeek())
                    ->count(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getNeedsAttention(int $storeId): array
    {
        $pendingOrders = Order::query()
            ->where('store_id', $storeId)
            ->where('status', Order::STATUS_PENDING)
            ->where('created_at', '<=', now()->subHours(24))
            ->count();

        $confirmedNotShipped = Order::query()
            ->where('store_id', $storeId)
            ->whereIn('status', [Order::STATUS_CONFIRMED, Order::STATUS_PROCESSING])
            ->where('updated_at', '<=', now()->subDays(2))
            ->count();

        $shippedNotDelivered = Order::query()
            ->where('store_id', $storeId)
            ->where('status', Order::STATUS_SHIPPED)
            ->where('updated_at', '<=', now()->subDays(7))
            ->count();

        $totalNeedingAttention = $pendingOrders + $confirmedNotShipped + $shippedNotDelivered;

        return [
            'total_needs_attention' => $totalNeedingAttention,
            'breakdown' => [
                'pending_over_24h' => $pendingOrders,
                'awaiting_shipment_over_2_days' => $confirmedNotShipped,
                'shipped_over_7_days' => $shippedNotDelivered,
            ],
            'recommendations' => $this->getRecommendations($pendingOrders, $confirmedNotShipped, $shippedNotDelivered),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getOrdersByStatus(int $storeId, string $status): array
    {
        $orders = Order::query()
            ->where('store_id', $storeId)
            ->where('status', $status)
            ->with('customer:id,first_name,last_name')
            ->latest()
            ->limit(10)
            ->get(['id', 'invoice_number', 'total', 'customer_id', 'created_at']);

        return [
            'status' => $status,
            'count' => Order::query()
                ->where('store_id', $storeId)
                ->where('status', $status)
                ->count(),
            'recent_orders' => $orders->map(fn (Order $order) => [
                'id' => $order->id,
                'invoice_number' => $order->invoice_number,
                'total' => '$'.number_format($order->total, 2),
                'customer' => $order->customer
                    ? trim($order->customer->first_name.' '.$order->customer->last_name)
                    : 'Guest',
                'created_at' => $order->created_at->diffForHumans(),
            ])->toArray(),
        ];
    }

    /**
     * @return array<int, string>
     */
    protected function getRecommendations(int $pending, int $awaiting, int $shipped): array
    {
        $recommendations = [];

        if ($pending > 0) {
            $recommendations[] = "Review and process {$pending} pending orders that are over 24 hours old.";
        }

        if ($awaiting > 0) {
            $recommendations[] = "Ship {$awaiting} orders that have been waiting over 2 days.";
        }

        if ($shipped > 0) {
            $recommendations[] = "Follow up on {$shipped} orders shipped over 7 days ago that haven't been delivered.";
        }

        if (empty($recommendations)) {
            $recommendations[] = 'All orders are on track. Great job!';
        }

        return $recommendations;
    }
}
