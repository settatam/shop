<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Status;
use App\Models\Transaction;
use App\Services\StoreContext;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BuysReportController extends Controller
{
    public function __construct(
        protected StoreContext $storeContext,
    ) {}

    /**
     * Get the "Payment Processed" status ID for a store.
     */
    protected function getPaymentProcessedStatusId(int $storeId): ?int
    {
        return Status::where('store_id', $storeId)
            ->where('slug', 'payment_processed')
            ->value('id');
    }

    /**
     * Unified Buys Report - Month to Date (daily breakdown).
     * Shows ALL completed buys regardless of source (online, in-store, trade-in).
     */
    public function index(Request $request): Response
    {
        $store = $this->storeContext->getCurrentStore();
        $startDate = now()->startOfMonth();
        $endDate = now();

        $dailyData = $this->getAllBuysDailyData($store->id, $startDate, $endDate);

        $totals = $this->calculateTotals($dailyData);

        return Inertia::render('reports/buys/Index', [
            'dailyData' => $dailyData,
            'totals' => $totals,
            'month' => now()->format('F Y'),
        ]);
    }

    /**
     * Unified Buys Report - Month over Month.
     */
    public function monthly(Request $request): Response
    {
        $store = $this->storeContext->getCurrentStore();
        $startDate = now()->subMonths(12)->startOfMonth();
        $endDate = now()->endOfMonth();

        $monthlyData = $this->getAllBuysMonthlyData($store->id, $startDate, $endDate);

        $totals = $this->calculateTotals($monthlyData);

        return Inertia::render('reports/buys/Monthly', [
            'monthlyData' => $monthlyData,
            'totals' => $totals,
        ]);
    }

    /**
     * Unified Buys Report - Year over Year.
     */
    public function yearly(Request $request): Response
    {
        $store = $this->storeContext->getCurrentStore();
        $yearlyData = $this->getAllBuysYearlyData($store->id);
        $totals = $this->calculateTotals($yearlyData);

        return Inertia::render('reports/buys/Yearly', [
            'yearlyData' => $yearlyData,
            'totals' => $totals,
        ]);
    }

    /**
     * Export Unified MTD to CSV.
     */
    public function exportIndex(Request $request): StreamedResponse
    {
        $store = $this->storeContext->getCurrentStore();
        $startDate = now()->startOfMonth();
        $endDate = now();

        $dailyData = $this->getAllBuysDailyData($store->id, $startDate, $endDate);

        return $this->exportToCsv($dailyData, 'buys-mtd-'.now()->format('Y-m-d').'.csv');
    }

    /**
     * Export Unified Monthly to CSV.
     */
    public function exportMonthly(Request $request): StreamedResponse
    {
        $store = $this->storeContext->getCurrentStore();
        $startDate = now()->subMonths(12)->startOfMonth();
        $endDate = now()->endOfMonth();

        $monthlyData = $this->getAllBuysMonthlyData($store->id, $startDate, $endDate);

        return $this->exportToCsv($monthlyData, 'buys-monthly-'.now()->format('Y-m-d').'.csv');
    }

    /**
     * Export Unified Yearly to CSV.
     */
    public function exportYearly(Request $request): StreamedResponse
    {
        $store = $this->storeContext->getCurrentStore();
        $yearlyData = $this->getAllBuysYearlyData($store->id);

        return $this->exportToCsv($yearlyData, 'buys-yearly-'.now()->format('Y-m-d').'.csv');
    }

    /**
     * Get all buys daily aggregated data (no type/source filter).
     */
    protected function getAllBuysDailyData(int $storeId, Carbon $startDate, Carbon $endDate)
    {
        return $this->getDailyBuysData($storeId, $startDate, $endDate, fn ($query) => $query);
    }

    /**
     * Get all buys monthly aggregated data (no type/source filter).
     */
    protected function getAllBuysMonthlyData(int $storeId, Carbon $startDate, Carbon $endDate)
    {
        return $this->getMonthlyBuysData($storeId, $startDate, $endDate, fn ($query) => $query);
    }

    /**
     * Get all buys yearly aggregated data (no type/source filter).
     */
    protected function getAllBuysYearlyData(int $storeId)
    {
        return $this->getYearlyBuysData($storeId, fn ($query) => $query);
    }

    /**
     * In-Store Buys Report - Month to Date (daily breakdown).
     */
    public function inStore(Request $request): Response
    {
        $store = $this->storeContext->getCurrentStore();
        $startDate = now()->startOfMonth();
        $endDate = now();

        $dailyData = $this->getInStoreDailyData($store->id, $startDate, $endDate);

        $totals = $this->calculateTotals($dailyData);

        return Inertia::render('reports/buys/InStore', [
            'dailyData' => $dailyData,
            'totals' => $totals,
            'month' => now()->format('F Y'),
        ]);
    }

    /**
     * In-Store Buys Report - Month over Month.
     */
    public function inStoreMonthly(Request $request): Response
    {
        $store = $this->storeContext->getCurrentStore();
        $startDate = now()->subMonths(12)->startOfMonth();
        $endDate = now()->endOfMonth();

        $monthlyData = $this->getInStoreMonthlyData($store->id, $startDate, $endDate);

        $totals = $this->calculateTotals($monthlyData);

        return Inertia::render('reports/buys/InStoreMonthly', [
            'monthlyData' => $monthlyData,
            'totals' => $totals,
        ]);
    }

    /**
     * Online Buys Report - Month to Date (daily breakdown).
     */
    public function online(Request $request): Response
    {
        $store = $this->storeContext->getCurrentStore();
        $startDate = now()->startOfMonth();
        $endDate = now();

        $dailyData = $this->getOnlineDailyData($store->id, $startDate, $endDate);

        $totals = $this->calculateTotals($dailyData);

        return Inertia::render('reports/buys/Online', [
            'dailyData' => $dailyData,
            'totals' => $totals,
            'month' => now()->format('F Y'),
        ]);
    }

    /**
     * Online Buys Report - Month over Month.
     */
    public function onlineMonthly(Request $request): Response
    {
        $store = $this->storeContext->getCurrentStore();
        $startDate = now()->subMonths(12)->startOfMonth();
        $endDate = now()->endOfMonth();

        $monthlyData = $this->getOnlineMonthlyData($store->id, $startDate, $endDate);

        $totals = $this->calculateTotals($monthlyData);

        return Inertia::render('reports/buys/OnlineMonthly', [
            'monthlyData' => $monthlyData,
            'totals' => $totals,
        ]);
    }

    /**
     * Trade-In Buys Report - Month to Date (daily breakdown).
     */
    public function tradeIn(Request $request): Response
    {
        $store = $this->storeContext->getCurrentStore();
        $startDate = now()->startOfMonth();
        $endDate = now();

        $dailyData = $this->getTradeInDailyData($store->id, $startDate, $endDate);

        $totals = $this->calculateTotals($dailyData);

        return Inertia::render('reports/buys/TradeIn', [
            'dailyData' => $dailyData,
            'totals' => $totals,
            'month' => now()->format('F Y'),
        ]);
    }

    /**
     * Trade-In Buys Report - Month over Month.
     */
    public function tradeInMonthly(Request $request): Response
    {
        $store = $this->storeContext->getCurrentStore();
        $startDate = now()->subMonths(12)->startOfMonth();
        $endDate = now()->endOfMonth();

        $monthlyData = $this->getTradeInMonthlyData($store->id, $startDate, $endDate);

        $totals = $this->calculateTotals($monthlyData);

        return Inertia::render('reports/buys/TradeInMonthly', [
            'monthlyData' => $monthlyData,
            'totals' => $totals,
        ]);
    }

    /**
     * Export In-Store MTD to CSV.
     */
    public function exportInStore(Request $request): StreamedResponse
    {
        $store = $this->storeContext->getCurrentStore();
        $startDate = now()->startOfMonth();
        $endDate = now();

        $dailyData = $this->getInStoreDailyData($store->id, $startDate, $endDate);

        return $this->exportToCsv($dailyData, 'buys-in-store-mtd-'.now()->format('Y-m-d').'.csv');
    }

    /**
     * Export In-Store Monthly to CSV.
     */
    public function exportInStoreMonthly(Request $request): StreamedResponse
    {
        $store = $this->storeContext->getCurrentStore();
        $startDate = now()->subMonths(12)->startOfMonth();
        $endDate = now()->endOfMonth();

        $monthlyData = $this->getInStoreMonthlyData($store->id, $startDate, $endDate);

        return $this->exportToCsv($monthlyData, 'buys-in-store-monthly-'.now()->format('Y-m-d').'.csv');
    }

    /**
     * Export Online MTD to CSV.
     */
    public function exportOnline(Request $request): StreamedResponse
    {
        $store = $this->storeContext->getCurrentStore();
        $startDate = now()->startOfMonth();
        $endDate = now();

        $dailyData = $this->getOnlineDailyData($store->id, $startDate, $endDate);

        return $this->exportToCsv($dailyData, 'buys-online-mtd-'.now()->format('Y-m-d').'.csv');
    }

    /**
     * Export Online Monthly to CSV.
     */
    public function exportOnlineMonthly(Request $request): StreamedResponse
    {
        $store = $this->storeContext->getCurrentStore();
        $startDate = now()->subMonths(12)->startOfMonth();
        $endDate = now()->endOfMonth();

        $monthlyData = $this->getOnlineMonthlyData($store->id, $startDate, $endDate);

        return $this->exportToCsv($monthlyData, 'buys-online-monthly-'.now()->format('Y-m-d').'.csv');
    }

    /**
     * Export Trade-In MTD to CSV.
     */
    public function exportTradeIn(Request $request): StreamedResponse
    {
        $store = $this->storeContext->getCurrentStore();
        $startDate = now()->startOfMonth();
        $endDate = now();

        $dailyData = $this->getTradeInDailyData($store->id, $startDate, $endDate);

        return $this->exportToCsv($dailyData, 'buys-trade-in-mtd-'.now()->format('Y-m-d').'.csv');
    }

    /**
     * Export Trade-In Monthly to CSV.
     */
    public function exportTradeInMonthly(Request $request): StreamedResponse
    {
        $store = $this->storeContext->getCurrentStore();
        $startDate = now()->subMonths(12)->startOfMonth();
        $endDate = now()->endOfMonth();

        $monthlyData = $this->getTradeInMonthlyData($store->id, $startDate, $endDate);

        return $this->exportToCsv($monthlyData, 'buys-trade-in-monthly-'.now()->format('Y-m-d').'.csv');
    }

    /**
     * In-Store Buys Report - Year over Year.
     */
    public function inStoreYearly(Request $request): Response
    {
        $store = $this->storeContext->getCurrentStore();
        $yearlyData = $this->getInStoreYearlyData($store->id);
        $totals = $this->calculateTotals($yearlyData);

        return Inertia::render('reports/buys/InStoreYearly', [
            'yearlyData' => $yearlyData,
            'totals' => $totals,
        ]);
    }

    /**
     * Online Buys Report - Year over Year.
     */
    public function onlineYearly(Request $request): Response
    {
        $store = $this->storeContext->getCurrentStore();
        $yearlyData = $this->getOnlineYearlyData($store->id);
        $totals = $this->calculateTotals($yearlyData);

        return Inertia::render('reports/buys/OnlineYearly', [
            'yearlyData' => $yearlyData,
            'totals' => $totals,
        ]);
    }

    /**
     * Trade-In Buys Report - Year over Year.
     */
    public function tradeInYearly(Request $request): Response
    {
        $store = $this->storeContext->getCurrentStore();
        $yearlyData = $this->getTradeInYearlyData($store->id);
        $totals = $this->calculateTotals($yearlyData);

        return Inertia::render('reports/buys/TradeInYearly', [
            'yearlyData' => $yearlyData,
            'totals' => $totals,
        ]);
    }

    /**
     * Export In-Store Yearly to CSV.
     */
    public function exportInStoreYearly(Request $request): StreamedResponse
    {
        $store = $this->storeContext->getCurrentStore();
        $yearlyData = $this->getInStoreYearlyData($store->id);

        return $this->exportToCsv($yearlyData, 'buys-in-store-yearly-'.now()->format('Y-m-d').'.csv');
    }

    /**
     * Export Online Yearly to CSV.
     */
    public function exportOnlineYearly(Request $request): StreamedResponse
    {
        $store = $this->storeContext->getCurrentStore();
        $yearlyData = $this->getOnlineYearlyData($store->id);

        return $this->exportToCsv($yearlyData, 'buys-online-yearly-'.now()->format('Y-m-d').'.csv');
    }

    /**
     * Export Trade-In Yearly to CSV.
     */
    public function exportTradeInYearly(Request $request): StreamedResponse
    {
        $store = $this->storeContext->getCurrentStore();
        $yearlyData = $this->getTradeInYearlyData($store->id);

        return $this->exportToCsv($yearlyData, 'buys-trade-in-yearly-'.now()->format('Y-m-d').'.csv');
    }

    /**
     * Get in-store daily aggregated data.
     * In-store means in_house type with source NOT online.
     */
    protected function getInStoreDailyData(int $storeId, Carbon $startDate, Carbon $endDate)
    {
        return $this->getDailyBuysData($storeId, $startDate, $endDate, function ($query) {
            $query->where('type', Transaction::TYPE_IN_STORE)
                ->where(function ($q) {
                    $q->whereNull('source')
                        ->orWhere('source', '!=', Transaction::SOURCE_ONLINE);
                })
                ->where(function ($q) {
                    $q->whereNull('source')
                        ->orWhere('source', '!=', Transaction::SOURCE_TRADE_IN);
                });
        });
    }

    /**
     * Get in-store monthly aggregated data.
     */
    protected function getInStoreMonthlyData(int $storeId, Carbon $startDate, Carbon $endDate)
    {
        return $this->getMonthlyBuysData($storeId, $startDate, $endDate, function ($query) {
            $query->where('type', Transaction::TYPE_IN_STORE)
                ->where(function ($q) {
                    $q->whereNull('source')
                        ->orWhere('source', '!=', Transaction::SOURCE_ONLINE);
                })
                ->where(function ($q) {
                    $q->whereNull('source')
                        ->orWhere('source', '!=', Transaction::SOURCE_TRADE_IN);
                });
        });
    }

    /**
     * Get online daily aggregated data.
     * Online means mail_in type OR source = online.
     */
    protected function getOnlineDailyData(int $storeId, Carbon $startDate, Carbon $endDate)
    {
        return $this->getDailyBuysData($storeId, $startDate, $endDate, function ($query) {
            $query->where(function ($q) {
                $q->where('type', Transaction::TYPE_MAIL_IN)
                    ->orWhere('source', Transaction::SOURCE_ONLINE);
            });
        });
    }

    /**
     * Get online monthly aggregated data.
     */
    protected function getOnlineMonthlyData(int $storeId, Carbon $startDate, Carbon $endDate)
    {
        return $this->getMonthlyBuysData($storeId, $startDate, $endDate, function ($query) {
            $query->where(function ($q) {
                $q->where('type', Transaction::TYPE_MAIL_IN)
                    ->orWhere('source', Transaction::SOURCE_ONLINE);
            });
        });
    }

    /**
     * Get trade-in daily aggregated data.
     * Trade-in means source = trade_in (trade-in transactions).
     */
    protected function getTradeInDailyData(int $storeId, Carbon $startDate, Carbon $endDate)
    {
        return $this->getDailyBuysData($storeId, $startDate, $endDate, function ($query) {
            $query->where('source', Transaction::SOURCE_TRADE_IN);
        });
    }

    /**
     * Get trade-in monthly aggregated data.
     */
    protected function getTradeInMonthlyData(int $storeId, Carbon $startDate, Carbon $endDate)
    {
        return $this->getMonthlyBuysData($storeId, $startDate, $endDate, function ($query) {
            $query->where('source', Transaction::SOURCE_TRADE_IN);
        });
    }

    /**
     * Get in-store yearly aggregated data.
     */
    protected function getInStoreYearlyData(int $storeId)
    {
        return $this->getYearlyBuysData($storeId, function ($query) {
            $query->where('type', Transaction::TYPE_IN_STORE)
                ->where(function ($q) {
                    $q->whereNull('source')
                        ->orWhere('source', '!=', Transaction::SOURCE_ONLINE);
                })
                ->where(function ($q) {
                    $q->whereNull('source')
                        ->orWhere('source', '!=', Transaction::SOURCE_TRADE_IN);
                });
        });
    }

    /**
     * Get online yearly aggregated data.
     */
    protected function getOnlineYearlyData(int $storeId)
    {
        return $this->getYearlyBuysData($storeId, function ($query) {
            $query->where(function ($q) {
                $q->where('type', Transaction::TYPE_MAIL_IN)
                    ->orWhere('source', Transaction::SOURCE_ONLINE);
            });
        });
    }

    /**
     * Get trade-in yearly aggregated data.
     */
    protected function getTradeInYearlyData(int $storeId)
    {
        return $this->getYearlyBuysData($storeId, function ($query) {
            $query->where('source', Transaction::SOURCE_TRADE_IN);
        });
    }

    /**
     * Get daily buys data with custom filter.
     * Includes all transactions that have reached payment_processed status.
     */
    protected function getDailyBuysData(int $storeId, Carbon $startDate, Carbon $endDate, callable $filter)
    {
        $paymentProcessedStatusId = $this->getPaymentProcessedStatusId($storeId);

        $query = Transaction::query()
            ->where('store_id', $storeId)
            ->where('status_id', $paymentProcessedStatusId)
            ->whereNotNull('payment_processed_at')
            ->whereBetween('payment_processed_at', [$startDate, $endDate])
            ->with(['items']);

        $filter($query);

        $transactions = $query->get();

        // Group by day using payment_processed_at
        $grouped = $transactions->groupBy(fn ($t) => Carbon::parse($t->payment_processed_at)->format('Y-m-d'));

        // Generate all days in range
        $days = collect();
        $current = $startDate->copy();
        while ($current <= $endDate) {
            $key = $current->format('Y-m-d');
            $dayTransactions = $grouped->get($key, collect());

            $buysCount = $dayTransactions->count();
            $purchaseAmt = $dayTransactions->sum('final_offer');
            // Estimated value comes from sum of item prices (resale value)
            $estimatedValue = $dayTransactions->sum(fn ($t) => $t->items->sum('price'));
            $profit = $estimatedValue - $purchaseAmt;
            $profitPercent = $purchaseAmt > 0 ? ($profit / $purchaseAmt) * 100 : 0;
            $avgBuyPrice = $buysCount > 0 ? $purchaseAmt / $buysCount : 0;

            $days->push([
                'date' => $current->format('M d, Y'),
                'date_key' => $current->format('Y-m-d'),
                'buys_count' => $buysCount,
                'purchase_amt' => $purchaseAmt,
                'estimated_value' => $estimatedValue,
                'profit' => $profit,
                'profit_percent' => $profitPercent,
                'avg_buy_price' => $avgBuyPrice,
            ]);

            $current->addDay();
        }

        return $days;
    }

    /**
     * Get yearly buys data with custom filter.
     * Includes all transactions that have reached payment_processed status.
     */
    protected function getYearlyBuysData(int $storeId, callable $filter)
    {
        $paymentProcessedStatusId = $this->getPaymentProcessedStatusId($storeId);

        // Get last 5 years of data
        $startDate = now()->subYears(4)->startOfYear();
        $endDate = now()->endOfYear();

        $query = Transaction::query()
            ->where('store_id', $storeId)
            ->where('status_id', $paymentProcessedStatusId)
            ->whereNotNull('payment_processed_at')
            ->whereBetween('payment_processed_at', [$startDate, $endDate])
            ->with(['items']);

        $filter($query);

        $transactions = $query->get();

        // Group by year using payment_processed_at
        $grouped = $transactions->groupBy(fn ($t) => Carbon::parse($t->payment_processed_at)->format('Y'));

        // Generate all years in range
        $years = collect();
        $current = $startDate->copy();
        while ($current <= $endDate) {
            $key = $current->format('Y');
            $yearTransactions = $grouped->get($key, collect());

            $buysCount = $yearTransactions->count();
            $purchaseAmt = $yearTransactions->sum('final_offer');
            // Estimated value comes from sum of item prices (resale value)
            $estimatedValue = $yearTransactions->sum(fn ($t) => $t->items->sum('price'));
            $profit = $estimatedValue - $purchaseAmt;
            $profitPercent = $purchaseAmt > 0 ? ($profit / $purchaseAmt) * 100 : 0;
            $avgBuyPrice = $buysCount > 0 ? $purchaseAmt / $buysCount : 0;

            $years->push([
                'date' => $current->format('Y'),
                'start_date' => $current->copy()->startOfYear()->format('Y-m-d'),
                'end_date' => $current->copy()->endOfYear()->format('Y-m-d'),
                'buys_count' => $buysCount,
                'purchase_amt' => $purchaseAmt,
                'estimated_value' => $estimatedValue,
                'profit' => $profit,
                'profit_percent' => $profitPercent,
                'avg_buy_price' => $avgBuyPrice,
            ]);

            $current->addYear();
        }

        return $years;
    }

    /**
     * Get monthly buys data with custom filter.
     * Includes all transactions that have reached payment_processed status.
     */
    protected function getMonthlyBuysData(int $storeId, Carbon $startDate, Carbon $endDate, callable $filter)
    {
        $paymentProcessedStatusId = $this->getPaymentProcessedStatusId($storeId);

        $query = Transaction::query()
            ->where('store_id', $storeId)
            ->where('status_id', $paymentProcessedStatusId)
            ->whereNotNull('payment_processed_at')
            ->whereBetween('payment_processed_at', [$startDate, $endDate])
            ->with(['items']);

        $filter($query);

        $transactions = $query->get();

        // Group by month using payment_processed_at
        $grouped = $transactions->groupBy(fn ($t) => Carbon::parse($t->payment_processed_at)->format('Y-m'));

        // Generate all months in range
        $months = collect();
        $current = $startDate->copy();
        while ($current <= $endDate) {
            $key = $current->format('Y-m');
            $monthTransactions = $grouped->get($key, collect());

            $buysCount = $monthTransactions->count();
            $purchaseAmt = $monthTransactions->sum('final_offer');
            // Estimated value comes from sum of item prices (resale value)
            $estimatedValue = $monthTransactions->sum(fn ($t) => $t->items->sum('price'));
            $profit = $estimatedValue - $purchaseAmt;
            $profitPercent = $purchaseAmt > 0 ? ($profit / $purchaseAmt) * 100 : 0;
            $avgBuyPrice = $buysCount > 0 ? $purchaseAmt / $buysCount : 0;

            $months->push([
                'date' => $current->format('M Y'),
                'start_date' => $current->copy()->startOfMonth()->format('Y-m-d'),
                'end_date' => $current->copy()->endOfMonth()->format('Y-m-d'),
                'buys_count' => $buysCount,
                'purchase_amt' => $purchaseAmt,
                'estimated_value' => $estimatedValue,
                'profit' => $profit,
                'profit_percent' => $profitPercent,
                'avg_buy_price' => $avgBuyPrice,
            ]);

            $current->addMonth();
        }

        return $months;
    }

    /**
     * Calculate totals from aggregated data.
     */
    protected function calculateTotals($data): array
    {
        $buysCount = $data->sum('buys_count');
        $purchaseAmt = $data->sum('purchase_amt');
        $estimatedValue = $data->sum('estimated_value');
        $profit = $estimatedValue - $purchaseAmt;
        $profitPercent = $purchaseAmt > 0 ? ($profit / $purchaseAmt) * 100 : 0;
        $avgBuyPrice = $buysCount > 0 ? $purchaseAmt / $buysCount : 0;

        return [
            'buys_count' => $buysCount,
            'purchase_amt' => $purchaseAmt,
            'estimated_value' => $estimatedValue,
            'profit' => $profit,
            'profit_percent' => $profitPercent,
            'avg_buy_price' => $avgBuyPrice,
        ];
    }

    /**
     * Export data to CSV.
     */
    protected function exportToCsv($data, string $filename): StreamedResponse
    {
        $totals = $this->calculateTotals($data);

        return response()->streamDownload(function () use ($data, $totals) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, [
                'Date',
                '# of Buys',
                'Purchase Amt',
                'Estimated Value',
                'Profit',
                'Profit %',
                'Avg Buy Price',
            ]);

            foreach ($data as $row) {
                fputcsv($handle, [
                    $row['date'],
                    $row['buys_count'],
                    number_format($row['purchase_amt'], 2),
                    number_format($row['estimated_value'], 2),
                    number_format($row['profit'], 2),
                    number_format($row['profit_percent'], 2).'%',
                    number_format($row['avg_buy_price'], 2),
                ]);
            }

            // Totals row
            fputcsv($handle, [
                'TOTALS',
                $totals['buys_count'],
                number_format($totals['purchase_amt'], 2),
                number_format($totals['estimated_value'], 2),
                number_format($totals['profit'], 2),
                number_format($totals['profit_percent'], 2).'%',
                number_format($totals['avg_buy_price'], 2),
            ]);

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }
}
