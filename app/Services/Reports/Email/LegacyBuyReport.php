<?php

namespace App\Services\Reports\Email;

use App\Services\Reports\AbstractReport;
use App\Services\Reports\BuysReportService;
use App\Services\Reports\ReportStructure;
use Carbon\Carbon;

/**
 * Legacy Daily Buy Report matching the existing BuysReportController format.
 *
 * Queries Transaction/TransactionItem models with 3 tables:
 * 1. Daily Individual Buys - each buy transaction from the report day
 * 2. Month to Date - daily totals for the current month
 * 3. Month over Month - 13 months of monthly totals
 *
 * Uses BuysReportService for data - same source as the web UI.
 */
class LegacyBuyReport extends AbstractReport
{
    protected string $baseUrl;

    protected BuysReportService $buysService;

    public function __construct($store, ?Carbon $reportDate = null, ?string $baseUrl = null)
    {
        parent::__construct($store, $reportDate);
        $this->baseUrl = $baseUrl ?? config('app.url');
        $this->buysService = app(BuysReportService::class);
    }

    public function getType(): string
    {
        return 'legacy_daily_buy';
    }

    public function getName(): string
    {
        return 'Legacy Daily Buy Report';
    }

    public function getSlug(): string
    {
        return 'legacy-daily-buy-report';
    }

    protected function defineStructure(): ReportStructure
    {
        return $this->structure()
            ->setTitle('{{ date }}')
            ->addTable(
                name: 'daily_buys',
                heading: 'Daily Buys',
                columns: [
                    $this->dateColumn('date', 'Date'),
                    $this->linkColumn('txn_id', 'Txn ID', '/transactions/{id}'),
                    $this->textColumn('customer', 'Customer'),
                    $this->textColumn('type', 'Type'),
                    $this->textColumn('source', 'Source'),
                    $this->textColumn('categories', 'Categories'),
                    $this->numberColumn('num_items', '# Items'),
                    $this->currencyColumn('purchase_amt', 'Purchase Amt'),
                    $this->currencyColumn('estimated_value', 'Estimated Value'),
                    $this->currencyColumn('profit', 'Profit'),
                    $this->percentageColumn('profit_pct', 'Profit %'),
                    $this->textColumn('user', 'User'),
                ],
                dataKey: 'daily_buys'
            )
            ->addTable(
                name: 'monthly_summary',
                heading: 'Month to Date',
                columns: [
                    $this->dateColumn('date', 'Date'),
                    $this->numberColumn('buys_count', '# of Buys'),
                    $this->currencyColumn('purchase_amt', 'Purchase Amt'),
                    $this->currencyColumn('estimated_value', 'Estimated Value'),
                    $this->currencyColumn('profit', 'Profit'),
                    $this->percentageColumn('profit_pct', 'Profit %'),
                    $this->currencyColumn('avg_buy_price', 'Avg Buy Price'),
                ],
                dataKey: 'monthly_data',
                options: ['totals' => true]
            )
            ->addTable(
                name: 'month_over_month',
                heading: 'Month Over Month (Last 13 Months)',
                columns: [
                    $this->textColumn('month', 'Date'),
                    $this->numberColumn('buys_count', '# of Buys'),
                    $this->currencyColumn('purchase_amt', 'Purchase Amt'),
                    $this->currencyColumn('estimated_value', 'Estimated Value'),
                    $this->currencyColumn('profit', 'Profit'),
                    $this->percentageColumn('profit_pct', 'Profit %'),
                    $this->currencyColumn('avg_buy_price', 'Avg Buy Price'),
                ],
                dataKey: 'month_over_month',
                options: ['totals' => true]
            )
            ->setMetadata([
                'report_type' => 'legacy_daily_buy',
                'store_id' => $this->store->id,
            ]);
    }

    public function getData(): array
    {
        return [
            'date' => $this->getTitleWithDate('Daily Buy Report'),
            'store' => $this->store,
            'daily_buys' => $this->getDailyBuys(),
            'monthly_data' => $this->getMonthToDateData(),
            'month_over_month' => $this->getMonthOverMonthData(),
        ];
    }

    /**
     * Get individual buy transactions for the report day.
     * Uses BuysReportService for data.
     */
    protected function getDailyBuys(): array
    {
        $startDate = $this->reportDate->copy()->startOfDay();
        $endDate = $this->reportDate->copy()->endOfDay();

        $transactions = $this->buysService->getDailyBuys($this->store->id, $startDate, $endDate);

        $rows = $transactions->map(function ($txn) {
            return [
                'id' => $txn['id'],
                'date' => $this->formatDate(Carbon::parse($txn['date'])),
                'txn_id' => $this->formatLink(
                    $txn['transaction_number'],
                    "{$this->baseUrl}/transactions/{$txn['id']}"
                ),
                'customer' => $txn['customer'],
                'type' => $txn['type'],
                'source' => $txn['source'],
                'categories' => $txn['categories'],
                'num_items' => $txn['num_items'],
                'purchase_amt' => $this->formatCurrency($txn['purchase_amt']),
                'estimated_value' => $txn['estimated_value'] > 0
                    ? $this->formatCurrency($txn['estimated_value'])
                    : ['data' => 0, 'formatted' => '--'],
                'profit' => $txn['profit'] > 0
                    ? $this->formatCurrency($txn['profit'])
                    : ['data' => 0, 'formatted' => 'N/A'],
                'profit_pct' => $txn['profit_percent'] > 0
                    ? $this->formatPercentage($txn['profit_percent'])
                    : ['data' => 0, 'formatted' => 'N/A'],
                'user' => $txn['user'],
            ];
        })->toArray();

        // Add totals row
        if (count($rows) > 0) {
            $totals = $this->buysService->calculateDailyBuysTotals($transactions);

            // Calculate overall profit percentage from totals
            $totalProfitPct = $totals['estimated_value'] > 0
                ? ($totals['profit'] / $totals['estimated_value']) * 100
                : 0;

            $rows[] = [
                'id' => null,
                'date' => 'Totals',
                'txn_id' => ['data' => '', 'href' => ''],
                'customer' => '',
                'type' => '',
                'source' => '',
                'categories' => '',
                'num_items' => $totals['num_items'],
                'purchase_amt' => $this->formatCurrency($totals['purchase_amt']),
                'estimated_value' => $totals['estimated_value'] > 0
                    ? $this->formatCurrency($totals['estimated_value'])
                    : ['data' => 0, 'formatted' => '-'],
                'profit' => $totals['profit'] > 0
                    ? $this->formatCurrency($totals['profit'])
                    : ['data' => 0, 'formatted' => '-'],
                'profit_pct' => $totalProfitPct > 0
                    ? $this->formatPercentage($totalProfitPct)
                    : ['data' => 0, 'formatted' => '-'],
                'user' => '',
                '_is_total' => true,
            ];
        }

        return $rows;
    }

    /**
     * Get daily summaries for month to date.
     * Uses BuysReportService for data.
     */
    protected function getMonthToDateData(): array
    {
        $startOfMonth = $this->reportDate->copy()->startOfMonth();
        $endDate = $this->reportDate->copy()->endOfDay();

        $dailyData = $this->buysService->getDailyAggregatedData($this->store->id, $startOfMonth, $endDate);

        // Convert to reversed order (oldest first for MTD)
        $dailyData = $dailyData->reverse()->values();

        $data = $dailyData->map(function ($row) {
            return [
                'date' => $row['date'],
                'buys_count' => $row['buys_count'],
                'purchase_amt' => $this->formatCurrency($row['purchase_amt']),
                'estimated_value' => $row['estimated_value'] > 0
                    ? $this->formatCurrency($row['estimated_value'])
                    : ['data' => 0, 'formatted' => '-'],
                'profit' => $row['profit'] > 0
                    ? $this->formatCurrency($row['profit'])
                    : ['data' => 0, 'formatted' => '-'],
                'profit_pct' => $row['profit_percent'] > 0
                    ? $this->formatPercentage($row['profit_percent'])
                    : ['data' => 0, 'formatted' => '-'],
                'avg_buy_price' => $this->formatCurrency($row['avg_buy_price']),
            ];
        })->toArray();

        // Add totals row
        if (count($data) > 0) {
            $totals = $this->buysService->calculateAggregatedTotals($dailyData);

            // Calculate overall profit percentage from totals
            $totalProfitPct = $totals['estimated_value'] > 0
                ? ($totals['profit'] / $totals['estimated_value']) * 100
                : 0;

            // Calculate overall average buy price
            $avgBuyPrice = $totals['buys_count'] > 0
                ? $totals['purchase_amt'] / $totals['buys_count']
                : 0;

            $data[] = [
                'date' => 'Totals',
                'buys_count' => $totals['buys_count'],
                'purchase_amt' => $this->formatCurrency($totals['purchase_amt']),
                'estimated_value' => $totals['estimated_value'] > 0
                    ? $this->formatCurrency($totals['estimated_value'])
                    : ['data' => 0, 'formatted' => '-'],
                'profit' => $totals['profit'] > 0
                    ? $this->formatCurrency($totals['profit'])
                    : ['data' => 0, 'formatted' => '-'],
                'profit_pct' => $totalProfitPct > 0
                    ? $this->formatPercentage($totalProfitPct)
                    : ['data' => 0, 'formatted' => '-'],
                'avg_buy_price' => $this->formatCurrency($avgBuyPrice),
                '_is_total' => true,
            ];
        }

        return $data;
    }

    /**
     * Get 13 months of monthly totals.
     * Uses BuysReportService for data.
     */
    protected function getMonthOverMonthData(): array
    {
        $startDate = $this->reportDate->copy()->subMonths(12)->startOfMonth();
        $endDate = $this->reportDate->copy()->endOfMonth();

        $monthlyData = $this->buysService->getMonthlyAggregatedData($this->store->id, $startDate, $endDate);

        $data = $monthlyData->map(function ($row) {
            return [
                'month' => $row['date'],
                'buys_count' => $row['buys_count'],
                'purchase_amt' => $this->formatCurrency($row['purchase_amt']),
                'estimated_value' => $row['estimated_value'] > 0
                    ? $this->formatCurrency($row['estimated_value'])
                    : ['data' => 0, 'formatted' => '-'],
                'profit' => $row['profit'] > 0
                    ? $this->formatCurrency($row['profit'])
                    : ['data' => 0, 'formatted' => '-'],
                'profit_pct' => $row['profit_percent'] > 0
                    ? $this->formatPercentage($row['profit_percent'])
                    : ['data' => 0, 'formatted' => '-'],
                'avg_buy_price' => $this->formatCurrency($row['avg_buy_price']),
            ];
        })->toArray();

        // Add totals row
        if (count($data) > 0) {
            $totals = $this->buysService->calculateAggregatedTotals($monthlyData);

            // Calculate overall profit percentage from totals
            $totalProfitPct = $totals['estimated_value'] > 0
                ? ($totals['profit'] / $totals['estimated_value']) * 100
                : 0;

            // Calculate overall average buy price
            $avgBuyPrice = $totals['buys_count'] > 0
                ? $totals['purchase_amt'] / $totals['buys_count']
                : 0;

            $data[] = [
                'month' => 'Total (13 Months)',
                'buys_count' => $totals['buys_count'],
                'purchase_amt' => $this->formatCurrency($totals['purchase_amt']),
                'estimated_value' => $totals['estimated_value'] > 0
                    ? $this->formatCurrency($totals['estimated_value'])
                    : ['data' => 0, 'formatted' => '-'],
                'profit' => $totals['profit'] > 0
                    ? $this->formatCurrency($totals['profit'])
                    : ['data' => 0, 'formatted' => '-'],
                'profit_pct' => $totalProfitPct > 0
                    ? $this->formatPercentage($totalProfitPct)
                    : ['data' => 0, 'formatted' => '-'],
                'avg_buy_price' => $this->formatCurrency($avgBuyPrice),
                '_is_total' => true,
            ];
        }

        return $data;
    }
}
