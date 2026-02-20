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
     * Unified Buys Report - Daily breakdown with date range.
     * Shows ALL completed buys regardless of source (online, in-store, trade-in).
     */
    public function index(Request $request): Response
    {
        $store = $this->storeContext->getCurrentStore();

        // Parse date range from request, default to current month
        $startDate = $request->filled('start_date')
            ? Carbon::parse($request->input('start_date'))->startOfDay()
            : now()->startOfMonth();

        $endDate = $request->filled('end_date')
            ? Carbon::parse($request->input('end_date'))->endOfDay()
            : now()->endOfDay();

        // Ensure start is before end
        if ($startDate > $endDate) {
            [$startDate, $endDate] = [$endDate, $startDate];
        }

        $dailyData = $this->getAllBuysDailyData($store->id, $startDate, $endDate);

        $totals = $this->calculateTotals($dailyData);

        return Inertia::render('reports/buys/Index', [
            'dailyData' => $dailyData,
            'totals' => $totals,
            'startDate' => $startDate->format('Y-m-d'),
            'endDate' => $endDate->format('Y-m-d'),
            'dateRangeLabel' => $this->getDateRangeLabel($startDate, $endDate),
        ]);
    }

    /**
     * Get a human-readable label for the date range.
     */
    protected function getDateRangeLabel(Carbon $startDate, Carbon $endDate): string
    {
        if ($startDate->isSameMonth($endDate)) {
            return $startDate->format('F Y');
        }

        return $startDate->format('M d, Y').' - '.$endDate->format('M d, Y');
    }

    /**
     * Unified Buys Report - Daily view showing individual transactions.
     */
    public function daily(Request $request): Response
    {
        $store = $this->storeContext->getCurrentStore();

        // Parse date range from request, default to today
        $startDate = $request->filled('start_date')
            ? Carbon::parse($request->input('start_date'))->startOfDay()
            : now()->startOfDay();

        $endDate = $request->filled('end_date')
            ? Carbon::parse($request->input('end_date'))->endOfDay()
            : now()->endOfDay();

        if ($startDate > $endDate) {
            [$startDate, $endDate] = [$endDate, $startDate];
        }

        $paymentProcessedStatusId = $this->getPaymentProcessedStatusId($store->id);

        $transactions = Transaction::query()
            ->where('store_id', $store->id)
            ->where('status_id', $paymentProcessedStatusId)
            ->whereNotNull('payment_processed_at')
            ->whereBetween('payment_processed_at', [$startDate, $endDate])
            ->with(['items', 'customer', 'user'])
            ->orderBy('payment_processed_at', 'desc')
            ->get()
            ->map(function ($transaction) {
                $estimatedValue = $transaction->items->sum('price');
                $profit = $estimatedValue - ($transaction->final_offer ?? 0);

                return [
                    'id' => $transaction->id,
                    'date' => Carbon::parse($transaction->payment_processed_at)->format('Y-m-d H:i'),
                    'transaction_number' => $transaction->transaction_number ?? "#{$transaction->id}",
                    'customer' => $transaction->customer?->full_name ?? 'Walk-in',
                    'type' => ucfirst($transaction->type ?? 'in_store'),
                    'source' => ucfirst($transaction->source ?? 'direct'),
                    'num_items' => $transaction->items->count(),
                    'purchase_amt' => $transaction->final_offer ?? 0,
                    'estimated_value' => $estimatedValue,
                    'profit' => $profit,
                    'profit_percent' => ($transaction->final_offer ?? 0) > 0
                        ? ($profit / ($transaction->final_offer ?? 1)) * 100
                        : 0,
                    'user' => $transaction->user?->name ?? '-',
                ];
            });

        // Calculate totals
        $totals = [
            'num_items' => $transactions->sum('num_items'),
            'purchase_amt' => $transactions->sum('purchase_amt'),
            'estimated_value' => $transactions->sum('estimated_value'),
            'profit' => $transactions->sum('profit'),
            'profit_percent' => $transactions->sum('purchase_amt') > 0
                ? ($transactions->sum('profit') / $transactions->sum('purchase_amt')) * 100
                : 0,
        ];

        return Inertia::render('reports/buys/Daily', [
            'transactions' => $transactions,
            'totals' => $totals,
            'startDate' => $startDate->format('Y-m-d'),
            'endDate' => $endDate->format('Y-m-d'),
            'dateRangeLabel' => $this->getDateRangeLabel($startDate, $endDate),
        ]);
    }

    /**
     * Export daily transactions to CSV.
     */
    public function exportDaily(Request $request): StreamedResponse
    {
        $store = $this->storeContext->getCurrentStore();

        $startDate = $request->filled('start_date')
            ? Carbon::parse($request->input('start_date'))->startOfDay()
            : now()->startOfDay();

        $endDate = $request->filled('end_date')
            ? Carbon::parse($request->input('end_date'))->endOfDay()
            : now()->endOfDay();

        if ($startDate > $endDate) {
            [$startDate, $endDate] = [$endDate, $startDate];
        }

        $paymentProcessedStatusId = $this->getPaymentProcessedStatusId($store->id);

        $transactions = Transaction::query()
            ->where('store_id', $store->id)
            ->where('status_id', $paymentProcessedStatusId)
            ->whereNotNull('payment_processed_at')
            ->whereBetween('payment_processed_at', [$startDate, $endDate])
            ->with(['items', 'customer', 'user'])
            ->orderBy('payment_processed_at', 'desc')
            ->get();

        $filename = 'buys-daily-'.$startDate->format('Y-m-d').'-to-'.$endDate->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($transactions) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, [
                'Date',
                'Transaction #',
                'Customer',
                'Type',
                'Source',
                '# Items',
                'Purchase Amt',
                'Estimated Value',
                'Profit',
                'Profit %',
                'User',
            ]);

            $totalItems = 0;
            $totalPurchase = 0;
            $totalEstimated = 0;
            $totalProfit = 0;

            foreach ($transactions as $transaction) {
                $estimatedValue = $transaction->items->sum('price');
                $purchaseAmt = $transaction->final_offer ?? 0;
                $profit = $estimatedValue - $purchaseAmt;
                $profitPercent = $purchaseAmt > 0 ? ($profit / $purchaseAmt) * 100 : 0;
                $numItems = $transaction->items->count();

                fputcsv($handle, [
                    Carbon::parse($transaction->payment_processed_at)->format('Y-m-d H:i'),
                    $transaction->transaction_number ?? "#{$transaction->id}",
                    $transaction->customer?->full_name ?? 'Walk-in',
                    ucfirst($transaction->type ?? 'in_store'),
                    ucfirst($transaction->source ?? 'direct'),
                    $numItems,
                    number_format($purchaseAmt, 2),
                    number_format($estimatedValue, 2),
                    number_format($profit, 2),
                    number_format($profitPercent, 2).'%',
                    $transaction->user?->name ?? '-',
                ]);

                $totalItems += $numItems;
                $totalPurchase += $purchaseAmt;
                $totalEstimated += $estimatedValue;
                $totalProfit += $profit;
            }

            $totalProfitPercent = $totalPurchase > 0 ? ($totalProfit / $totalPurchase) * 100 : 0;

            fputcsv($handle, [
                'TOTALS',
                '',
                '',
                '',
                '',
                $totalItems,
                number_format($totalPurchase, 2),
                number_format($totalEstimated, 2),
                number_format($totalProfit, 2),
                number_format($totalProfitPercent, 2).'%',
                '',
            ]);

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }

    /**
     * Unified Buys Report - Month over Month.
     */
    public function monthly(Request $request): Response
    {
        $store = $this->storeContext->getCurrentStore();

        // Parse month/year range from request, default to last 12 months
        $startMonth = $request->input('start_month', now()->subMonths(12)->month);
        $startYear = $request->input('start_year', now()->subMonths(12)->year);
        $endMonth = $request->input('end_month', now()->month);
        $endYear = $request->input('end_year', now()->year);

        $startDate = Carbon::createFromDate($startYear, $startMonth, 1)->startOfMonth();
        $endDate = Carbon::createFromDate($endYear, $endMonth, 1)->endOfMonth();

        // Ensure start is before end
        if ($startDate > $endDate) {
            [$startDate, $endDate] = [$endDate, $startDate];
        }

        $monthlyData = $this->getAllBuysMonthlyData($store->id, $startDate, $endDate);

        $totals = $this->calculateTotals($monthlyData);

        return Inertia::render('reports/buys/Monthly', [
            'monthlyData' => $monthlyData,
            'totals' => $totals,
            'startMonth' => $startDate->month,
            'startYear' => $startDate->year,
            'endMonth' => $endDate->month,
            'endYear' => $endDate->year,
            'dateRangeLabel' => $startDate->format('M Y').' - '.$endDate->format('M Y'),
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
     * Export Unified daily report to CSV.
     */
    public function exportIndex(Request $request): StreamedResponse
    {
        $store = $this->storeContext->getCurrentStore();

        $startDate = $request->filled('start_date')
            ? Carbon::parse($request->input('start_date'))->startOfDay()
            : now()->startOfMonth();

        $endDate = $request->filled('end_date')
            ? Carbon::parse($request->input('end_date'))->endOfDay()
            : now()->endOfDay();

        if ($startDate > $endDate) {
            [$startDate, $endDate] = [$endDate, $startDate];
        }

        $dailyData = $this->getAllBuysDailyData($store->id, $startDate, $endDate);

        $filename = 'buys-'.$startDate->format('Y-m-d').'-to-'.$endDate->format('Y-m-d').'.csv';

        return $this->exportToCsv($dailyData, $filename);
    }

    /**
     * Export Unified Monthly to CSV.
     */
    public function exportMonthly(Request $request): StreamedResponse
    {
        $store = $this->storeContext->getCurrentStore();

        $startMonth = $request->input('start_month', now()->subMonths(12)->month);
        $startYear = $request->input('start_year', now()->subMonths(12)->year);
        $endMonth = $request->input('end_month', now()->month);
        $endYear = $request->input('end_year', now()->year);

        $startDate = Carbon::createFromDate($startYear, $startMonth, 1)->startOfMonth();
        $endDate = Carbon::createFromDate($endYear, $endMonth, 1)->endOfMonth();

        if ($startDate > $endDate) {
            [$startDate, $endDate] = [$endDate, $startDate];
        }

        $monthlyData = $this->getAllBuysMonthlyData($store->id, $startDate, $endDate);

        $filename = 'buys-monthly-'.$startDate->format('Y-m').'-to-'.$endDate->format('Y-m').'.csv';

        return $this->exportToCsv($monthlyData, $filename);
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

        return $days->reverse()->values();
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

        return $years->reverse()->values();
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

        return $months->reverse()->values();
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
