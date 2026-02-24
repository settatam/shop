<?php

namespace App\Services\Reports\Email;

use App\Models\Order;
use App\Services\Reports\AbstractReport;
use App\Services\Reports\ReportStructure;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Daily Sales Report with 3 tables:
 * 1. Daily Individual Sales - each order from the report day
 * 2. Month to Date - daily totals for the current month
 * 3. Month over Month - 13 months of monthly totals
 */
class DailySalesReport extends AbstractReport
{
    protected string $baseUrl;

    public function __construct($store, ?Carbon $reportDate = null, ?string $baseUrl = null)
    {
        parent::__construct($store, $reportDate);
        $this->baseUrl = $baseUrl ?? config('app.url');
    }

    public function getType(): string
    {
        return 'daily_sales';
    }

    public function getName(): string
    {
        return 'Daily Sales Report';
    }

    public function getSlug(): string
    {
        return 'daily-sales-report';
    }

    protected function defineStructure(): ReportStructure
    {
        return $this->structure()
            ->setTitle('{{ date }}')
            ->addTable(
                name: 'daily_orders',
                heading: 'Daily Sales',
                columns: [
                    $this->dateColumn('date', 'Date'),
                    $this->linkColumn('order_id', 'Order #', '/orders/{id}'),
                    $this->textColumn('customer', 'Customer'),
                    $this->badgeColumn('status', 'Status', [
                        'Paid' => 'success',
                        'Completed' => 'success',
                        'Pending' => 'warning',
                        'Processing' => 'info',
                        'Cancelled' => 'danger',
                    ]),
                    $this->textColumn('marketplace', 'Marketplace'),
                    $this->numberColumn('items', '# Items'),
                    $this->currencyColumn('cost', 'Cost'),
                    $this->currencyColumn('wholesale', 'Wholesale'),
                    $this->currencyColumn('subtotal', 'Subtotal'),
                    $this->currencyColumn('profit', 'Profit'),
                    $this->currencyColumn('tax', 'Tax'),
                    $this->currencyColumn('shipping', 'Shipping'),
                    $this->currencyColumn('total', 'Total'),
                    $this->textColumn('payment_type', 'Payment'),
                    $this->textColumn('vendor', 'Vendor'),
                ],
                dataKey: 'daily_orders'
            )
            ->addTable(
                name: 'monthly_summary',
                heading: 'Month to Date',
                columns: [
                    $this->dateColumn('date', 'Date'),
                    $this->numberColumn('sales_count', 'Sales #'),
                    $this->numberColumn('items_sold', 'Items'),
                    $this->currencyColumn('total_cost', 'Cost'),
                    $this->currencyColumn('total_wholesale', 'Wholesale'),
                    $this->currencyColumn('total_sales', 'Sales'),
                    $this->currencyColumn('total_paid', 'Paid'),
                    $this->currencyColumn('gross_profit', 'Profit'),
                    $this->percentageColumn('profit_pct', 'Profit %'),
                ],
                dataKey: 'monthly_data',
                options: ['totals' => true]
            )
            ->addTable(
                name: 'month_over_month',
                heading: 'Month Over Month (Last 13 Months)',
                columns: [
                    $this->textColumn('month', 'Month'),
                    $this->numberColumn('sales_count', 'Sales #'),
                    $this->numberColumn('items_sold', 'Items'),
                    $this->currencyColumn('total_cost', 'Cost'),
                    $this->currencyColumn('total_wholesale', 'Wholesale'),
                    $this->currencyColumn('total_sales', 'Sales'),
                    $this->currencyColumn('total_paid', 'Paid'),
                    $this->currencyColumn('gross_profit', 'Profit'),
                    $this->percentageColumn('profit_pct', 'Profit %'),
                ],
                dataKey: 'month_over_month',
                options: ['totals' => true]
            )
            ->setMetadata([
                'report_type' => 'daily_sales',
                'store_id' => $this->store->id,
            ]);
    }

    public function getData(): array
    {
        return [
            'date' => $this->getTitleWithDate('Daily Sales Report'),
            'store' => $this->store,
            'daily_orders' => $this->getDailyOrders(),
            'monthly_data' => $this->getMonthToDateData(),
            'month_over_month' => $this->getMonthOverMonthData(),
        ];
    }

    /**
     * Get individual orders for the report day.
     */
    protected function getDailyOrders(): array
    {
        $orders = Order::with(['customer', 'items.product.vendor', 'items.variant', 'payments', 'salesChannel'])
            ->where('store_id', $this->store->id)
            ->whereDate('created_at', $this->reportDate)
            ->whereNull('deleted_at')
            ->orderBy('created_at', 'desc')
            ->get();

        return $orders->map(function (Order $order) {
            // Calculate costs
            $cost = $order->items->sum(function ($item) {
                return ($item->cost ?? $item->product?->cost ?? 0) * ($item->quantity ?? 1);
            });

            $wholesale = $order->items->sum(function ($item) {
                return ($item->variant?->wholesale_price ?? $item->product?->wholesale_price ?? 0) * ($item->quantity ?? 1);
            });

            $subtotal = (float) ($order->sub_total ?? $order->subtotal ?? 0);
            $profit = $subtotal - $cost;

            // Get payment types
            $paymentTypes = $order->payments
                ->pluck('payment_method')
                ->unique()
                ->map(fn ($m) => ucfirst(str_replace('_', ' ', $m)))
                ->implode(', ');

            // Get vendors
            $vendors = $order->items
                ->map(fn ($item) => $item->product?->vendor?->name ?? $item->product?->vendor?->company_name)
                ->filter()
                ->unique()
                ->implode(', ');

            // Format status
            $status = match ($order->status) {
                'confirmed', 'completed' => 'Paid',
                default => ucfirst($order->status ?? 'Unknown'),
            };
            $statusVariant = match ($order->status) {
                'confirmed', 'completed' => 'success',
                'pending' => 'warning',
                'processing' => 'info',
                'cancelled', 'refunded' => 'danger',
                default => 'secondary',
            };

            return [
                'id' => $order->id,
                'date' => $this->formatDate(Carbon::parse($order->created_at)),
                'order_id' => $this->formatLink(
                    $order->invoice_number ?? $order->order_number ?? "#{$order->id}",
                    "{$this->baseUrl}/orders/{$order->id}"
                ),
                'customer' => $order->customer?->full_name ?? 'Walk-in',
                'status' => $this->formatBadge($status, $statusVariant),
                'marketplace' => $order->salesChannel?->name ?? $order->source_platform ?? 'Direct',
                'items' => $order->items->sum('quantity'),
                'cost' => $this->formatCurrency($cost),
                'wholesale' => $this->formatCurrency($wholesale),
                'subtotal' => $this->formatCurrency($subtotal),
                'profit' => $this->formatCurrency($profit),
                'tax' => $this->formatCurrency($order->sales_tax ?? $order->tax ?? 0),
                'shipping' => $this->formatCurrency($order->shipping_cost ?? 0),
                'total' => $this->formatCurrency($order->total ?? 0),
                'payment_type' => $paymentTypes ?: '-',
                'vendor' => $vendors ?: '-',
            ];
        })->toArray();
    }

    /**
     * Get daily summaries for month to date.
     */
    protected function getMonthToDateData(): array
    {
        $startOfMonth = $this->reportDate->copy()->startOfMonth();
        $endDate = $this->reportDate->copy()->endOfDay();

        $dailyStats = DB::table('orders')
            ->where('store_id', $this->store->id)
            ->whereBetween('created_at', [$startOfMonth, $endDate])
            ->whereNull('deleted_at')
            ->select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('COUNT(*) as sales_count'),
                DB::raw('SUM(total) as total_sales'),
                DB::raw('SUM(sub_total) as subtotal'),
                DB::raw('SUM(sales_tax) as total_tax'),
            )
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy('date')
            ->get();

        $data = $dailyStats->map(function ($day) {
            $profitPct = $day->total_sales > 0
                ? (($day->total_sales - ($day->subtotal ?? 0)) / $day->total_sales) * 100
                : 0;

            return [
                'date' => Carbon::parse($day->date)->format('m-d-Y'),
                'sales_count' => (int) $day->sales_count,
                'items_sold' => 0, // Would need items join
                'total_cost' => $this->formatCurrency(0),
                'total_wholesale' => $this->formatCurrency(0),
                'total_sales' => $this->formatCurrency($day->total_sales ?? 0),
                'total_paid' => $this->formatCurrency($day->total_sales ?? 0),
                'gross_profit' => $this->formatCurrency(0),
                'profit_pct' => $this->formatPercentage($profitPct),
            ];
        })->toArray();

        // Add totals row
        if (count($data) > 0) {
            $totals = [
                'date' => 'Total',
                'sales_count' => collect($data)->sum('sales_count'),
                'items_sold' => collect($data)->sum('items_sold'),
                'total_cost' => $this->formatCurrency(collect($data)->sum(fn ($d) => $d['total_cost']['data'] ?? 0)),
                'total_wholesale' => $this->formatCurrency(collect($data)->sum(fn ($d) => $d['total_wholesale']['data'] ?? 0)),
                'total_sales' => $this->formatCurrency(collect($data)->sum(fn ($d) => $d['total_sales']['data'] ?? 0)),
                'total_paid' => $this->formatCurrency(collect($data)->sum(fn ($d) => $d['total_paid']['data'] ?? 0)),
                'gross_profit' => $this->formatCurrency(collect($data)->sum(fn ($d) => $d['gross_profit']['data'] ?? 0)),
                'profit_pct' => '-',
                '_is_total' => true,
            ];
            $data[] = $totals;
        }

        return $data;
    }

    /**
     * Get 13 months of monthly totals.
     */
    protected function getMonthOverMonthData(): array
    {
        $data = [];

        for ($i = 0; $i < 13; $i++) {
            $monthStart = $this->reportDate->copy()->subMonths($i)->startOfMonth();
            $monthEnd = $this->reportDate->copy()->subMonths($i)->endOfMonth();

            // Don't include future dates
            if ($monthStart->isFuture()) {
                continue;
            }

            $stats = DB::table('orders')
                ->where('store_id', $this->store->id)
                ->whereBetween('created_at', [$monthStart, $monthEnd])
                ->whereNull('deleted_at')
                ->select(
                    DB::raw('COUNT(*) as sales_count'),
                    DB::raw('SUM(total) as total_sales'),
                    DB::raw('SUM(sub_total) as subtotal'),
                )
                ->first();

            $profitPct = ($stats->total_sales ?? 0) > 0
                ? ((($stats->total_sales ?? 0) - ($stats->subtotal ?? 0)) / ($stats->total_sales ?? 0)) * 100
                : 0;

            $data[] = [
                'month' => $monthStart->format('F Y'),
                'sales_count' => (int) ($stats->sales_count ?? 0),
                'items_sold' => 0,
                'total_cost' => $this->formatCurrency(0),
                'total_wholesale' => $this->formatCurrency(0),
                'total_sales' => $this->formatCurrency($stats->total_sales ?? 0),
                'total_paid' => $this->formatCurrency($stats->total_sales ?? 0),
                'gross_profit' => $this->formatCurrency(0),
                'profit_pct' => $this->formatPercentage($profitPct),
            ];
        }

        // Add totals row
        if (count($data) > 0) {
            $totals = [
                'month' => 'Total (13 Months)',
                'sales_count' => collect($data)->sum('sales_count'),
                'items_sold' => collect($data)->sum('items_sold'),
                'total_cost' => $this->formatCurrency(collect($data)->sum(fn ($d) => $d['total_cost']['data'] ?? 0)),
                'total_wholesale' => $this->formatCurrency(collect($data)->sum(fn ($d) => $d['total_wholesale']['data'] ?? 0)),
                'total_sales' => $this->formatCurrency(collect($data)->sum(fn ($d) => $d['total_sales']['data'] ?? 0)),
                'total_paid' => $this->formatCurrency(collect($data)->sum(fn ($d) => $d['total_paid']['data'] ?? 0)),
                'gross_profit' => $this->formatCurrency(collect($data)->sum(fn ($d) => $d['gross_profit']['data'] ?? 0)),
                'profit_pct' => '-',
                '_is_total' => true,
            ];
            $data[] = $totals;
        }

        return $data;
    }
}
