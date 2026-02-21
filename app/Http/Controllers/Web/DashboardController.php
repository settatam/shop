<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Inventory;
use App\Models\Invoice;
use App\Models\Memo;
use App\Models\Order;
use App\Models\Product;
use App\Models\Repair;
use App\Models\SalesChannel;
use App\Models\StoreMarketplace;
use App\Models\Transaction;
use App\Services\StoreContext;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __construct(protected StoreContext $storeContext) {}

    public function index(): Response
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return Inertia::render(edition_view('Dashboard'), [
                'stats' => [],
                'recentActivity' => [],
                'lowStockProducts' => [],
                'recentOrders' => [],
                'salesChart' => [],
                'ordersByStatus' => [],
                'recentBuys' => [],
                'buysByStatus' => [],
                'recentRepairs' => [],
                'repairsByStatus' => [],
                'recentMemos' => [],
                'memosByStatus' => [],
                'todaySummary' => [
                    'date' => now()->format('Y-m-d'),
                    'dateFormatted' => now()->format('F j, Y'),
                    'sales' => ['count' => 0, 'total' => 0],
                    'buys' => ['count' => 0, 'total' => 0],
                    'repairs' => ['count' => 0],
                    'memos' => ['count' => 0],
                ],
            ]);
        }

        $storeId = $store->id;

        // Get date range (default last 30 days)
        $endDate = now();
        $startDate = now()->subDays(29)->startOfDay();
        $previousStartDate = now()->subDays(59)->startOfDay();
        $previousEndDate = now()->subDays(30)->endOfDay();

        return Inertia::render(edition_view('Dashboard'), [
            'stats' => $this->getStats($storeId, $startDate, $endDate, $previousStartDate, $previousEndDate),
            'recentActivity' => $this->getRecentActivity($storeId),
            'lowStockProducts' => $this->getLowStockProducts($storeId),
            'recentOrders' => $this->getRecentOrders($storeId),
            'salesChart' => $this->getSalesChartData($storeId, $startDate, $endDate),
            'ordersByStatus' => $this->getOrdersByStatus($storeId),
            'recentBuys' => $this->getRecentBuys($storeId),
            'buysByStatus' => $this->getBuysByStatus($storeId),
            'recentRepairs' => $this->getRecentRepairs($storeId),
            'repairsByStatus' => $this->getRepairsByStatus($storeId),
            'recentMemos' => $this->getRecentMemos($storeId),
            'memosByStatus' => $this->getMemosByStatus($storeId),
            'todaySummary' => $this->getTodaySummary($storeId),
            'marketplaces' => $this->getActiveMarketplaces($storeId),
            'salesByChannel' => $this->getSalesByChannel($storeId, $startDate, $endDate),
        ]);
    }

    /**
     * Get dashboard stats with percentage changes.
     *
     * @return array<string, mixed>
     */
    protected function getStats(int $storeId, Carbon $startDate, Carbon $endDate, Carbon $previousStartDate, Carbon $previousEndDate): array
    {
        // Current period totals
        $currentRevenue = Order::where('store_id', $storeId)
            ->whereIn('status', Order::PAID_STATUSES)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->sum('total');

        $currentOrders = Order::where('store_id', $storeId)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->count();

        $currentCustomers = Customer::where('store_id', $storeId)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->count();

        // Previous period totals for comparison
        $previousRevenue = Order::where('store_id', $storeId)
            ->whereIn('status', Order::PAID_STATUSES)
            ->whereBetween('created_at', [$previousStartDate, $previousEndDate])
            ->sum('total');

        $previousOrders = Order::where('store_id', $storeId)
            ->whereBetween('created_at', [$previousStartDate, $previousEndDate])
            ->count();

        $previousCustomers = Customer::where('store_id', $storeId)
            ->whereBetween('created_at', [$previousStartDate, $previousEndDate])
            ->count();

        // Outstanding invoices
        $outstandingInvoices = Invoice::where('store_id', $storeId)
            ->unpaid()
            ->sum('balance_due');

        // Overdue invoices
        $overdueInvoices = Invoice::where('store_id', $storeId)
            ->overdue()
            ->sum('balance_due');

        // Products and inventory
        $totalProducts = Product::where('store_id', $storeId)->count();
        $lowStockCount = $this->getLowStockCount($storeId);

        return [
            [
                'name' => 'Revenue',
                'value' => $currentRevenue,
                'change' => $this->calculatePercentageChange($previousRevenue, $currentRevenue),
                'changeType' => $currentRevenue >= $previousRevenue ? 'positive' : 'negative',
                'format' => 'currency',
            ],
            [
                'name' => 'Orders',
                'value' => $currentOrders,
                'change' => $this->calculatePercentageChange($previousOrders, $currentOrders),
                'changeType' => $currentOrders >= $previousOrders ? 'positive' : 'negative',
                'format' => 'number',
            ],
            [
                'name' => 'New Customers',
                'value' => $currentCustomers,
                'change' => $this->calculatePercentageChange($previousCustomers, $currentCustomers),
                'changeType' => $currentCustomers >= $previousCustomers ? 'positive' : 'negative',
                'format' => 'number',
            ],
            [
                'name' => 'Outstanding',
                'value' => $outstandingInvoices,
                'change' => null,
                'changeType' => 'neutral',
                'format' => 'currency',
            ],
        ];
    }

    /**
     * Calculate percentage change between two values.
     */
    protected function calculatePercentageChange(float $previous, float $current): string
    {
        if ($previous == 0) {
            return $current > 0 ? '+100%' : '0%';
        }

        $change = (($current - $previous) / $previous) * 100;

        return ($change >= 0 ? '+' : '').number_format($change, 1).'%';
    }

    /**
     * Get recent activity logs.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function getRecentActivity(int $storeId): array
    {
        $logs = ActivityLog::where('store_id', $storeId)
            ->with(['user', 'subject'])
            ->orderByDesc('created_at')
            ->limit(15)
            ->get();

        // Group by date
        $grouped = $logs->groupBy(function ($log) {
            $date = $log->created_at;
            if ($date->isToday()) {
                return 'Today';
            }
            if ($date->isYesterday()) {
                return 'Yesterday';
            }

            return $date->format('F j, Y');
        });

        $result = [];
        foreach ($grouped as $date => $items) {
            $result[] = [
                'date' => $date,
                'dateTime' => $items->first()->created_at->toDateString(),
                'items' => $items->map(fn ($log) => [
                    'id' => $log->id,
                    'description' => $log->description,
                    'activity' => $log->activity_slug,
                    'user' => $log->user ? [
                        'name' => $log->user->name,
                        'avatar' => null,
                    ] : null,
                    'subject' => $log->subject ? [
                        'type' => class_basename($log->subject_type),
                        'id' => $log->subject_id,
                    ] : null,
                    'time' => $log->created_at->format('g:i A'),
                    'created_at' => $log->created_at->toIso8601String(),
                ])->toArray(),
            ];
        }

        return $result;
    }

    /**
     * Get low stock products.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function getLowStockProducts(int $storeId, int $threshold = 10): array
    {
        return Product::where('store_id', $storeId)
            ->where('track_quantity', true)
            ->with(['variants', 'images'])
            ->get()
            ->filter(function ($product) use ($threshold) {
                return $product->total_quantity <= $threshold;
            })
            ->take(5)
            ->map(fn ($product) => [
                'id' => $product->id,
                'title' => $product->title,
                'handle' => $product->handle,
                'quantity' => $product->total_quantity,
                'image' => $product->images->first()?->url,
            ])
            ->values()
            ->toArray();
    }

    /**
     * Get low stock count.
     */
    protected function getLowStockCount(int $storeId, int $threshold = 10): int
    {
        return Product::where('store_id', $storeId)
            ->where('track_quantity', true)
            ->with('variants')
            ->get()
            ->filter(fn ($product) => $product->total_quantity <= $threshold)
            ->count();
    }

    /**
     * Get recent orders.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function getRecentOrders(int $storeId): array
    {
        return Order::where('store_id', $storeId)
            ->with('customer')
            ->orderByDesc('created_at')
            ->limit(5)
            ->get()
            ->map(fn ($order) => [
                'id' => $order->id,
                'invoice_number' => $order->invoice_number,
                'customer' => $order->customer ? [
                    'name' => $order->customer->name,
                    'email' => $order->customer->email,
                ] : null,
                'total' => $order->total,
                'status' => $order->status,
                'created_at' => $order->created_at->toIso8601String(),
            ])
            ->toArray();
    }

    /**
     * Get sales chart data for the last 30 days.
     *
     * @return array<string, mixed>
     */
    protected function getSalesChartData(int $storeId, Carbon $startDate, Carbon $endDate): array
    {
        $sales = Order::where('store_id', $storeId)
            ->whereIn('status', Order::PAID_STATUSES)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->selectRaw('DATE(created_at) as date, SUM(total) as total, COUNT(*) as count')
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->keyBy('date');

        $labels = [];
        $revenue = [];
        $orders = [];

        $current = $startDate->copy();
        while ($current <= $endDate) {
            $dateKey = $current->format('Y-m-d');
            $labels[] = $current->format('M j');
            $revenue[] = (float) ($sales[$dateKey]->total ?? 0);
            $orders[] = (int) ($sales[$dateKey]->count ?? 0);
            $current->addDay();
        }

        return [
            'labels' => $labels,
            'datasets' => [
                [
                    'label' => 'Revenue',
                    'data' => $revenue,
                    'borderColor' => 'rgb(99, 102, 241)',
                    'backgroundColor' => 'rgba(99, 102, 241, 0.1)',
                    'fill' => true,
                ],
            ],
            'ordersData' => $orders,
        ];
    }

    /**
     * Get orders grouped by status.
     *
     * @return array<string, int>
     */
    protected function getOrdersByStatus(int $storeId): array
    {
        $statuses = [
            Order::STATUS_PENDING => 0,
            Order::STATUS_CONFIRMED => 0,
            Order::STATUS_PROCESSING => 0,
            Order::STATUS_SHIPPED => 0,
            Order::STATUS_DELIVERED => 0,
            Order::STATUS_COMPLETED => 0,
            Order::STATUS_CANCELLED => 0,
        ];

        $counts = Order::where('store_id', $storeId)
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        return array_merge($statuses, $counts);
    }

    /**
     * Get recent buy transactions.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function getRecentBuys(int $storeId): array
    {
        return Transaction::where('store_id', $storeId)
            ->with('customer')
            ->orderByDesc('created_at')
            ->limit(5)
            ->get()
            ->map(fn ($transaction) => [
                'id' => $transaction->id,
                'transaction_number' => $transaction->transaction_number,
                'customer' => $transaction->customer ? [
                    'name' => $transaction->customer->name,
                    'email' => $transaction->customer->email,
                ] : null,
                'final_offer' => $transaction->final_offer,
                'preliminary_offer' => $transaction->preliminary_offer,
                'status' => $transaction->status,
                'type' => $transaction->type,
                'created_at' => $transaction->created_at->toIso8601String(),
            ])
            ->toArray();
    }

    /**
     * Get buy transactions grouped by status.
     *
     * @return array<string, int>
     */
    protected function getBuysByStatus(int $storeId): array
    {
        // Initialize all possible statuses with 0 (matching StatusService definitions)
        $statuses = [
            // Online workflow - Kit Request Phase
            Transaction::STATUS_PENDING_KIT_REQUEST => 0,
            Transaction::STATUS_KIT_REQUEST_CONFIRMED => 0,
            Transaction::STATUS_KIT_REQUEST_REJECTED => 0,
            Transaction::STATUS_KIT_REQUEST_ON_HOLD => 0,
            // Online workflow - Kit Shipping Phase
            Transaction::STATUS_KIT_SENT => 0,
            Transaction::STATUS_KIT_DELIVERED => 0,
            // In-house workflow - Items Phase
            Transaction::STATUS_PENDING => 0,
            Transaction::STATUS_ITEMS_RECEIVED => 0,
            Transaction::STATUS_ITEMS_REVIEWED => 0,
            // Offer Phase
            Transaction::STATUS_OFFER_GIVEN => 0,
            Transaction::STATUS_OFFER_ACCEPTED => 0,
            Transaction::STATUS_OFFER_DECLINED => 0,
            // Payment Phase
            Transaction::STATUS_PAYMENT_PENDING => 0,
            Transaction::STATUS_PAYMENT_PROCESSED => 0,
            // Return/Cancellation
            Transaction::STATUS_RETURN_REQUESTED => 0,
            Transaction::STATUS_ITEMS_RETURNED => 0,
            Transaction::STATUS_CANCELLED => 0,
        ];

        $counts = Transaction::where('store_id', $storeId)
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        return array_merge($statuses, $counts);
    }

    /**
     * Get recent repairs.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function getRecentRepairs(int $storeId): array
    {
        return Repair::where('store_id', $storeId)
            ->with(['customer', 'vendor'])
            ->orderByDesc('created_at')
            ->limit(5)
            ->get()
            ->map(fn ($repair) => [
                'id' => $repair->id,
                'repair_number' => $repair->repair_number,
                'customer' => $repair->customer ? [
                    'name' => $repair->customer->name,
                    'email' => $repair->customer->email,
                ] : null,
                'vendor' => $repair->vendor ? [
                    'name' => $repair->vendor->name,
                ] : null,
                'total' => $repair->total,
                'status' => $repair->status,
                'is_appraisal' => $repair->is_appraisal,
                'created_at' => $repair->created_at->toIso8601String(),
            ])
            ->toArray();
    }

    /**
     * Get repairs grouped by status.
     *
     * @return array<string, int>
     */
    protected function getRepairsByStatus(int $storeId): array
    {
        $statuses = [
            Repair::STATUS_PENDING => 0,
            Repair::STATUS_SENT_TO_VENDOR => 0,
            Repair::STATUS_RECEIVED_BY_VENDOR => 0,
            Repair::STATUS_COMPLETED => 0,
            Repair::STATUS_PAYMENT_RECEIVED => 0,
            Repair::STATUS_CANCELLED => 0,
        ];

        $counts = Repair::where('store_id', $storeId)
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        return array_merge($statuses, $counts);
    }

    /**
     * Get recent memos.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function getRecentMemos(int $storeId): array
    {
        return Memo::where('store_id', $storeId)
            ->with('vendor')
            ->orderByDesc('created_at')
            ->limit(5)
            ->get()
            ->map(fn ($memo) => [
                'id' => $memo->id,
                'memo_number' => $memo->memo_number,
                'vendor' => $memo->vendor ? [
                    'name' => $memo->vendor->name,
                ] : null,
                'total' => $memo->total,
                'tenure' => $memo->tenure,
                'status' => $memo->status,
                'created_at' => $memo->created_at->toIso8601String(),
            ])
            ->toArray();
    }

    /**
     * Get memos grouped by status.
     *
     * @return array<string, int>
     */
    protected function getMemosByStatus(int $storeId): array
    {
        $statuses = [
            Memo::STATUS_PENDING => 0,
            Memo::STATUS_SENT_TO_VENDOR => 0,
            Memo::STATUS_VENDOR_RECEIVED => 0,
            Memo::STATUS_VENDOR_RETURNED => 0,
            Memo::STATUS_PAYMENT_RECEIVED => 0,
            Memo::STATUS_ARCHIVED => 0,
        ];

        $counts = Memo::where('store_id', $storeId)
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        return array_merge($statuses, $counts);
    }

    /**
     * Get today's summary for sales and buys.
     *
     * @return array<string, mixed>
     */
    protected function getTodaySummary(int $storeId): array
    {
        $today = now()->startOfDay();
        $tomorrow = now()->endOfDay();

        // Today's sales (orders)
        $todaySalesCount = Order::where('store_id', $storeId)
            ->whereBetween('created_at', [$today, $tomorrow])
            ->count();

        $todaySalesTotal = Order::where('store_id', $storeId)
            ->whereIn('status', Order::PAID_STATUSES)
            ->whereBetween('created_at', [$today, $tomorrow])
            ->sum('total');

        // Today's buys (transactions)
        $todayBuysCount = Transaction::where('store_id', $storeId)
            ->whereBetween('created_at', [$today, $tomorrow])
            ->count();

        $todayBuysTotal = Transaction::where('store_id', $storeId)
            ->whereIn('status', [Transaction::STATUS_PAYMENT_PROCESSED, Transaction::STATUS_OFFER_ACCEPTED])
            ->whereBetween('created_at', [$today, $tomorrow])
            ->sum('final_offer');

        // Today's repairs
        $todayRepairsCount = Repair::where('store_id', $storeId)
            ->whereBetween('created_at', [$today, $tomorrow])
            ->count();

        // Today's memos
        $todayMemosCount = Memo::where('store_id', $storeId)
            ->whereBetween('created_at', [$today, $tomorrow])
            ->count();

        return [
            'date' => now()->format('Y-m-d'),
            'dateFormatted' => now()->format('F j, Y'),
            'sales' => [
                'count' => $todaySalesCount,
                'total' => $todaySalesTotal,
            ],
            'buys' => [
                'count' => $todayBuysCount,
                'total' => $todayBuysTotal,
            ],
            'repairs' => [
                'count' => $todayRepairsCount,
            ],
            'memos' => [
                'count' => $todayMemosCount,
            ],
        ];
    }

    /**
     * Get active marketplaces for the store.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function getActiveMarketplaces(int $storeId): array
    {
        return StoreMarketplace::where('store_id', $storeId)
            ->sellingPlatforms()
            ->connected()
            ->where('status', 'active')
            ->get()
            ->map(fn ($marketplace) => [
                'id' => $marketplace->id,
                'platform' => $marketplace->platform->value,
                'platform_label' => $marketplace->platform->label(),
                'name' => $marketplace->name,
                'status' => $marketplace->status,
                'last_sync_at' => $marketplace->last_sync_at?->toIso8601String(),
                'last_sync_ago' => $marketplace->last_sync_at?->diffForHumans(),
            ])
            ->toArray();
    }

    /**
     * Get sales grouped by sales channel.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function getSalesByChannel(int $storeId, Carbon $startDate, Carbon $endDate): array
    {
        $channels = SalesChannel::where('store_id', $storeId)
            ->where('is_active', true)
            ->get();

        $result = [];

        foreach ($channels as $channel) {
            $orders = Order::where('store_id', $storeId)
                ->where('sales_channel_id', $channel->id)
                ->whereBetween('created_at', [$startDate, $endDate])
                ->get();

            $paidOrders = $orders->filter(fn ($o) => in_array($o->status, Order::PAID_STATUSES));

            $result[] = [
                'id' => $channel->id,
                'name' => $channel->name,
                'code' => $channel->code,
                'color' => $channel->color,
                'is_local' => $channel->is_local,
                'orders_count' => $orders->count(),
                'revenue' => round($paidOrders->sum('total'), 2),
                'avg_order_value' => $paidOrders->count() > 0
                    ? round($paidOrders->sum('total') / $paidOrders->count(), 2)
                    : 0,
            ];
        }

        // Sort by revenue descending
        usort($result, fn ($a, $b) => $b['revenue'] <=> $a['revenue']);

        return $result;
    }

    /**
     * API endpoint for refreshing dashboard data.
     */
    public function getData(Request $request): JsonResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return response()->json(['error' => 'No store selected'], 400);
        }

        $storeId = $store->id;
        $days = $request->input('days', 30);

        $endDate = now();
        $startDate = now()->subDays($days - 1)->startOfDay();
        $previousStartDate = now()->subDays(($days * 2) - 1)->startOfDay();
        $previousEndDate = now()->subDays($days)->endOfDay();

        return response()->json([
            'stats' => $this->getStats($storeId, $startDate, $endDate, $previousStartDate, $previousEndDate),
            'recentActivity' => $this->getRecentActivity($storeId),
            'lowStockProducts' => $this->getLowStockProducts($storeId),
            'recentOrders' => $this->getRecentOrders($storeId),
            'salesChart' => $this->getSalesChartData($storeId, $startDate, $endDate),
            'ordersByStatus' => $this->getOrdersByStatus($storeId),
            'recentBuys' => $this->getRecentBuys($storeId),
            'buysByStatus' => $this->getBuysByStatus($storeId),
            'recentRepairs' => $this->getRecentRepairs($storeId),
            'repairsByStatus' => $this->getRepairsByStatus($storeId),
            'recentMemos' => $this->getRecentMemos($storeId),
            'memosByStatus' => $this->getMemosByStatus($storeId),
            'todaySummary' => $this->getTodaySummary($storeId),
            'marketplaces' => $this->getActiveMarketplaces($storeId),
            'salesByChannel' => $this->getSalesByChannel($storeId, $startDate, $endDate),
        ]);
    }
}
