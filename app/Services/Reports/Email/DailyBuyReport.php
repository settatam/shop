<?php

namespace App\Services\Reports\Email;

use App\Models\TransactionWarehouse;
use App\Services\Reports\AbstractReport;
use App\Services\Reports\ReportStructure;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class DailyBuyReport extends AbstractReport
{
    public function getType(): string
    {
        return 'daily_buy';
    }

    public function getName(): string
    {
        return 'Daily Buy Report';
    }

    public function getSlug(): string
    {
        return 'daily-buy-report';
    }

    protected function defineStructure(): ReportStructure
    {
        return $this->structure()
            ->setTitle('{{ date }}')
            ->addTable(
                name: 'daily_transactions',
                heading: 'Daily Buy Transactions',
                columns: [
                    $this->dateColumn('date', 'Date'),
                    $this->textColumn('transaction_id', 'Transaction #'),
                    $this->textColumn('customer', 'Customer'),
                    $this->currencyColumn('bought', 'Bought'),
                    $this->currencyColumn('profit', 'Profit'),
                    $this->textColumn('payment_type', 'Payment Type'),
                ],
                dataKey: 'data'
            )
            ->addTable(
                name: 'monthly_summary',
                heading: 'Month to Date',
                columns: [
                    $this->dateColumn('date', 'Date'),
                    $this->numberColumn('count', 'Count'),
                    $this->currencyColumn('total_bought', 'Total Bought'),
                    $this->currencyColumn('total_profit', 'Total Profit'),
                ],
                dataKey: 'monthlyData'
            )
            ->addTable(
                name: 'month_comparison',
                heading: 'Month Over Month',
                columns: [
                    $this->textColumn('month', 'Month'),
                    $this->numberColumn('transactions', 'Transactions'),
                    $this->currencyColumn('total_bought', 'Total Bought'),
                    $this->currencyColumn('total_profit', 'Total Profit'),
                ],
                dataKey: 'monthOverMonth'
            )
            ->setMetadata([
                'report_type' => 'daily_buy',
                'store_id' => $this->store->id,
            ]);
    }

    public function getData(): array
    {
        $storeId = $this->store->id;
        $reportDate = $this->reportDate;

        // Daily transactions
        $transactions = TransactionWarehouse::where('store_id', $storeId)
            ->whereDate('payment_date_time', $reportDate)
            ->get();

        $dailyData = $transactions->map(fn ($txn) => [
            $this->formatDate($txn->payment_date_time),
            $txn->transaction_id ?? $txn->id,
            $txn->customer_name ?? 'N/A',
            $this->formatCurrency($txn->bought ?? 0),
            $this->formatCurrency($txn->profit ?? 0),
            $txn->payment_type ?? 'N/A',
        ])->toArray();

        // Totals
        $totalBought = $transactions->sum('bought');
        $totalProfit = $transactions->sum('profit');

        // Month to date
        $startOfMonth = $reportDate->copy()->startOfMonth();
        $monthlyRows = TransactionWarehouse::where('store_id', $storeId)
            ->whereBetween('payment_date_time', [$startOfMonth, $reportDate->copy()->endOfDay()])
            ->selectRaw('DATE(payment_date_time) as date, COUNT(*) as count, SUM(bought) as total_bought, SUM(profit) as total_profit')
            ->groupBy(DB::raw('DATE(payment_date_time)'))
            ->orderBy('date')
            ->get();

        $monthlyData = $monthlyRows->map(fn ($row) => [
            Carbon::parse($row->date)->format('m-d-Y'),
            $row->count,
            $this->formatCurrency($row->total_bought ?? 0),
            $this->formatCurrency($row->total_profit ?? 0),
        ])->toArray();

        $monthOverMonth = [
            [
                $reportDate->format('F Y'),
                count($dailyData),
                $this->formatCurrency($totalBought),
                $this->formatCurrency($totalProfit),
            ],
        ];

        return [
            'date' => $this->getTitleWithDate('Daily Buy Report'),
            'store' => $this->store,
            'headings' => ['Date', 'Transaction #', 'Customer', 'Bought', 'Profit', 'Payment Type'],
            'data' => $dailyData,
            'monthlyData' => $monthlyData,
            'monthly_headings' => ['Date', 'Count', 'Total Bought', 'Total Profit'],
            'monthOverMonth' => $monthOverMonth,
            'monthOverMonthHeading' => ['Month', 'Transactions', 'Total Bought', 'Total Profit'],
        ];
    }
}
