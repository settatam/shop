<?php

namespace App\Services\Reports\Email;

use App\Services\Reports\AbstractReport;
use App\Services\Reports\ReportStructure;
use App\Services\Reports\SalesReportService;
use Carbon\Carbon;

/**
 * Legacy Daily Sales Report matching the existing SalesReportController format.
 *
 * Queries the Order model with 3 tables:
 * 1. Daily Individual Sales - each sale from the report day
 * 2. Month to Date - daily totals for the current month
 * 3. Month over Month - 13 months of monthly totals
 *
 * Uses SalesReportService for data - same source as the web UI.
 */
class LegacySalesReport extends AbstractReport
{
    protected string $baseUrl;

    protected SalesReportService $salesService;

    public function __construct($store, ?Carbon $reportDate = null, ?string $baseUrl = null)
    {
        parent::__construct($store, $reportDate);
        $this->baseUrl = $baseUrl ?? config('app.url');
        $this->salesService = app(SalesReportService::class);
    }

    public function getType(): string
    {
        return 'legacy_daily_sales';
    }

    public function getName(): string
    {
        return 'Legacy Daily Sales Report';
    }

    public function getSlug(): string
    {
        return 'legacy-daily-sales-report';
    }

    protected function defineStructure(): ReportStructure
    {
        return $this->structure()
            ->setTitle('{{ date }}')
            ->addTable(
                name: 'daily_sales',
                heading: 'Daily Sales',
                columns: [
                    $this->dateColumn('date', 'Date'),
                    $this->linkColumn('order_id', 'Order ID', '/orders/{id}'),
                    $this->textColumn('customer', 'Customer'),
                    $this->textColumn('lead', 'Lead'),
                    $this->badgeColumn('status', 'Status', [
                        'confirmed' => 'success',
                        'processing' => 'info',
                        'shipped' => 'info',
                        'delivered' => 'success',
                        'completed' => 'success',
                    ]),
                    $this->textColumn('marketplace', 'Marketplace'),
                    $this->numberColumn('items', '# Items'),
                    $this->textColumn('categories', 'Categories'),
                    $this->currencyColumn('cost', 'Cost'),
                    $this->currencyColumn('wholesale', 'Wholesale Value'),
                    $this->currencyColumn('subtotal', 'Sub Total'),
                    $this->currencyColumn('service_fee', 'Service Fee'),
                    $this->currencyColumn('profit', 'Profit'),
                    $this->currencyColumn('tax', 'Tax'),
                    $this->currencyColumn('shipping', 'Shipping'),
                    $this->currencyColumn('total', 'Total'),
                    $this->textColumn('payment_type', 'Payment Type'),
                ],
                dataKey: 'daily_sales'
            )
            ->addTable(
                name: 'monthly_summary',
                heading: 'Month to Date',
                columns: [
                    $this->dateColumn('date', 'Date'),
                    $this->numberColumn('sales_count', 'Sales #'),
                    $this->numberColumn('items_sold', 'Items Sold'),
                    $this->currencyColumn('total_cost', 'Total Cost'),
                    $this->currencyColumn('total_wholesale_value', 'Total Wholesale Value'),
                    $this->currencyColumn('total_sales_price', 'Total Sales Price'),
                    $this->currencyColumn('total_service_fee', 'Service Fee'),
                    $this->currencyColumn('total_paid', 'Total Paid'),
                    $this->currencyColumn('gross_profit', 'Gross Profit'),
                    $this->percentageColumn('profit_pct', 'Profit %'),
                ],
                dataKey: 'monthly_data',
                options: ['totals' => true]
            )
            ->addTable(
                name: 'month_over_month',
                heading: 'Month Over Month (Last 13 Months)',
                columns: [
                    $this->textColumn('month', 'Date'),
                    $this->numberColumn('sales_count', 'Sales #'),
                    $this->numberColumn('items_sold', 'Items Sold'),
                    $this->currencyColumn('total_cost', 'Total Cost'),
                    $this->currencyColumn('total_wholesale_value', 'Total Wholesale Value'),
                    $this->currencyColumn('total_sales_price', 'Total Sales Price'),
                    $this->currencyColumn('total_service_fee', 'Service Fee'),
                    $this->currencyColumn('total_paid', 'Total Paid'),
                    $this->currencyColumn('gross_profit', 'Gross Profit'),
                    $this->percentageColumn('profit_pct', 'Profit %'),
                ],
                dataKey: 'month_over_month',
                options: ['totals' => true]
            )
            ->setMetadata([
                'report_type' => 'legacy_daily_sales',
                'store_id' => $this->store->id,
            ]);
    }

    public function getData(): array
    {
        return [
            'date' => $this->getTitleWithDate('Daily Sales Report'),
            'store' => $this->store,
            'daily_sales' => $this->getDailySales(),
            'monthly_data' => $this->getMonthToDateData(),
            'month_over_month' => $this->getMonthOverMonthData(),
        ];
    }

    /**
     * Get individual sales for the report day from Orders.
     * Uses SalesReportService for data.
     */
    protected function getDailySales(): array
    {
        $startDate = $this->reportDate->copy()->startOfDay();
        $endDate = $this->reportDate->copy()->endOfDay();

        $orders = $this->salesService->getDailySales($this->store->id, $startDate, $endDate);

        $rows = $orders->map(function ($order) {
            return [
                'id' => $order['id'],
                'date' => $this->formatDate(Carbon::parse($order['date'])),
                'order_id' => $this->formatLink(
                    $order['order_id'],
                    "{$this->baseUrl}/orders/{$order['id']}"
                ),
                'customer' => $order['customer'],
                'lead' => $order['lead'],
                'status' => $this->formatBadge($order['status'], $this->salesService->getStatusVariant($order['status'])),
                'marketplace' => $order['marketplace'],
                'items' => $order['num_items'],
                'categories' => $order['categories'],
                'cost' => $this->formatCurrency($order['cost']),
                'wholesale' => $this->formatCurrency($order['wholesale_value']),
                'subtotal' => $this->formatCurrency($order['sub_total']),
                'service_fee' => $this->formatCurrency($order['service_fee']),
                'profit' => $this->formatCurrency($order['profit']),
                'tax' => $this->formatCurrency($order['tax']),
                'shipping' => $this->formatCurrency($order['shipping_cost']),
                'total' => $this->formatCurrency($order['total']),
                'payment_type' => $order['payment_type'],
            ];
        })->toArray();

        // Add totals row
        if (count($rows) > 0) {
            $totals = $this->salesService->calculateDailySalesTotals($orders);

            $rows[] = [
                'id' => null,
                'date' => 'Totals',
                'order_id' => ['data' => '', 'href' => ''],
                'customer' => '',
                'lead' => '',
                'status' => ['data' => '', 'variant' => 'secondary'],
                'marketplace' => '',
                'items' => $totals['num_items'],
                'categories' => '',
                'cost' => $this->formatCurrency($totals['cost']),
                'wholesale' => $this->formatCurrency($totals['wholesale_value']),
                'subtotal' => $this->formatCurrency($totals['sub_total']),
                'service_fee' => $this->formatCurrency($totals['service_fee']),
                'profit' => $this->formatCurrency($totals['profit']),
                'tax' => $this->formatCurrency($totals['tax']),
                'shipping' => $this->formatCurrency($totals['shipping_cost']),
                'total' => $this->formatCurrency($totals['total']),
                'payment_type' => '',
                '_is_total' => true,
            ];
        }

        return $rows;
    }

    /**
     * Get daily summaries for month to date.
     * Uses SalesReportService for data.
     */
    protected function getMonthToDateData(): array
    {
        $startOfMonth = $this->reportDate->copy()->startOfMonth();
        $endDate = $this->reportDate->copy()->endOfDay();

        $dailyData = $this->salesService->getDailyAggregatedData($this->store->id, $startOfMonth, $endDate);

        // Convert to reversed order (oldest first for MTD)
        $dailyData = $dailyData->reverse()->values();

        $data = $dailyData->map(function ($row) {
            return [
                'date' => $row['date'],
                'sales_count' => $row['sales_count'],
                'items_sold' => $row['items_sold'],
                'total_cost' => $this->formatCurrency($row['total_cost']),
                'total_wholesale_value' => $this->formatCurrency($row['total_wholesale_value']),
                'total_sales_price' => $this->formatCurrency($row['total_sales_price']),
                'total_service_fee' => $this->formatCurrency($row['total_service_fee']),
                'total_paid' => $this->formatCurrency($row['total_paid']),
                'gross_profit' => $this->formatCurrency($row['gross_profit']),
                'profit_pct' => $this->formatPercentage($row['profit_percent']),
            ];
        })->toArray();

        // Add totals row
        if (count($data) > 0) {
            $totals = $this->salesService->calculateAggregatedTotals($dailyData);

            // Calculate overall profit percentage from totals
            $totalProfitPct = $totals['total_sales_price'] > 0
                ? ($totals['gross_profit'] / $totals['total_sales_price']) * 100
                : 0;

            $data[] = [
                'date' => 'Total',
                'sales_count' => $totals['sales_count'],
                'items_sold' => $totals['items_sold'],
                'total_cost' => $this->formatCurrency($totals['total_cost']),
                'total_wholesale_value' => $this->formatCurrency($totals['total_wholesale_value']),
                'total_sales_price' => $this->formatCurrency($totals['total_sales_price']),
                'total_service_fee' => $this->formatCurrency($totals['total_service_fee']),
                'total_paid' => $this->formatCurrency($totals['total_paid']),
                'gross_profit' => $this->formatCurrency($totals['gross_profit']),
                'profit_pct' => $this->formatPercentage($totalProfitPct),
                '_is_total' => true,
            ];
        }

        return $data;
    }

    /**
     * Get 13 months of monthly totals.
     * Uses SalesReportService for data.
     */
    protected function getMonthOverMonthData(): array
    {
        $startDate = $this->reportDate->copy()->subMonths(12)->startOfMonth();
        $endDate = $this->reportDate->copy()->endOfMonth();

        $monthlyData = $this->salesService->getMonthlyAggregatedData($this->store->id, $startDate, $endDate);

        $data = $monthlyData->map(function ($row) {
            return [
                'month' => $row['date'],
                'sales_count' => $row['sales_count'],
                'items_sold' => $row['items_sold'],
                'total_cost' => $this->formatCurrency($row['total_cost']),
                'total_wholesale_value' => $this->formatCurrency($row['total_wholesale_value']),
                'total_sales_price' => $this->formatCurrency($row['total_sales_price']),
                'total_service_fee' => $this->formatCurrency($row['total_service_fee']),
                'total_paid' => $this->formatCurrency($row['total_paid']),
                'gross_profit' => $this->formatCurrency($row['gross_profit']),
                'profit_pct' => $this->formatPercentage($row['profit_percent']),
            ];
        })->toArray();

        // Add totals row
        if (count($data) > 0) {
            $totals = $this->salesService->calculateAggregatedTotals($monthlyData);

            // Calculate overall profit percentage from totals
            $totalProfitPct = $totals['total_sales_price'] > 0
                ? ($totals['gross_profit'] / $totals['total_sales_price']) * 100
                : 0;

            $data[] = [
                'month' => 'Total (13 Months)',
                'sales_count' => $totals['sales_count'],
                'items_sold' => $totals['items_sold'],
                'total_cost' => $this->formatCurrency($totals['total_cost']),
                'total_wholesale_value' => $this->formatCurrency($totals['total_wholesale_value']),
                'total_sales_price' => $this->formatCurrency($totals['total_sales_price']),
                'total_service_fee' => $this->formatCurrency($totals['total_service_fee']),
                'total_paid' => $this->formatCurrency($totals['total_paid']),
                'gross_profit' => $this->formatCurrency($totals['gross_profit']),
                'profit_pct' => $this->formatPercentage($totalProfitPct),
                '_is_total' => true,
            ];
        }

        return $data;
    }
}
