<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Services\StoreContext;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TransactionsReportController extends Controller
{
    public function __construct(
        protected StoreContext $storeContext,
    ) {}

    /**
     * Daily transactions report (current month, day by day).
     */
    public function daily(Request $request): Response
    {
        $store = $this->storeContext->getCurrentStore();
        $startDate = now()->startOfMonth();
        $endDate = now();

        $dailyData = $this->getDailyData($store->id, $startDate, $endDate);
        $totals = $this->calculateTotals($dailyData);

        return Inertia::render('reports/transactions/Daily', [
            'dailyData' => $dailyData,
            'totals' => $totals,
            'month' => now()->format('F Y'),
        ]);
    }

    /**
     * Weekly transactions report (past 13 weeks).
     */
    public function weekly(Request $request): Response
    {
        $store = $this->storeContext->getCurrentStore();
        $month = $request->query('month'); // Format: YYYY-MM

        $weeklyData = $this->getWeeklyData($store->id, $month);
        $totals = $this->calculateTotals($weeklyData);

        $filterInfo = null;
        if ($month) {
            $filterInfo = [
                'type' => 'month',
                'value' => $month,
                'label' => Carbon::parse($month.'-01')->format('F Y'),
            ];
        }

        return Inertia::render('reports/transactions/Weekly', [
            'weeklyData' => $weeklyData,
            'totals' => $totals,
            'filter' => $filterInfo,
        ]);
    }

    /**
     * Monthly transactions report (past 13 months or specific year).
     */
    public function monthly(Request $request): Response
    {
        $store = $this->storeContext->getCurrentStore();
        $year = $request->query('year'); // Format: YYYY

        $monthlyData = $this->getMonthlyData($store->id, $year);
        $totals = $this->calculateTotals($monthlyData);

        $filterInfo = null;
        if ($year) {
            $filterInfo = [
                'type' => 'year',
                'value' => $year,
                'label' => $year,
            ];
        }

        return Inertia::render('reports/transactions/Monthly', [
            'monthlyData' => $monthlyData,
            'totals' => $totals,
            'filter' => $filterInfo,
        ]);
    }

    /**
     * Yearly transactions report (past 5 years).
     */
    public function yearly(Request $request): Response
    {
        $store = $this->storeContext->getCurrentStore();

        $yearlyData = $this->getYearlyData($store->id);
        $totals = $this->calculateTotals($yearlyData);

        return Inertia::render('reports/transactions/Yearly', [
            'yearlyData' => $yearlyData,
            'totals' => $totals,
        ]);
    }

    /**
     * Get daily aggregated data.
     */
    protected function getDailyData(int $storeId, Carbon $startDate, Carbon $endDate)
    {
        $days = collect();
        $current = $startDate->copy();

        while ($current <= $endDate) {
            $dayStart = $current->copy()->startOfDay();
            $dayEnd = $current->copy()->endOfDay();

            $days->push($this->getPeriodData($storeId, $dayStart, $dayEnd, $current->format('M d, Y')));

            $current->addDay();
        }

        return $days->reverse()->values();
    }

    /**
     * Get weekly aggregated data.
     */
    protected function getWeeklyData(int $storeId, ?string $month = null)
    {
        $weeks = collect();

        if ($month) {
            $monthStart = Carbon::parse($month.'-01')->startOfMonth();
            $monthEnd = $monthStart->copy()->endOfMonth();
            $weekStart = $monthStart->copy()->startOfWeek();

            while ($weekStart->lte($monthEnd)) {
                $weekEnd = $weekStart->copy()->endOfWeek();

                if ($weekEnd->gte($monthStart) && $weekStart->lte($monthEnd)) {
                    $weeks->push($this->getPeriodData($storeId, $weekStart, $weekEnd, $weekStart->format('M d, Y')));
                }

                $weekStart->addWeek();
            }
        } else {
            $current = now()->startOfWeek();

            for ($i = 12; $i >= 0; $i--) {
                $weekStart = $current->copy()->subWeeks($i);
                $weekEnd = $weekStart->copy()->endOfWeek();
                $weeks->push($this->getPeriodData($storeId, $weekStart, $weekEnd, $weekStart->format('M d, Y')));
            }
        }

        return $weeks->reverse()->values();
    }

    /**
     * Get monthly aggregated data.
     */
    protected function getMonthlyData(int $storeId, ?string $year = null)
    {
        $months = collect();

        if ($year) {
            for ($m = 1; $m <= 12; $m++) {
                $monthStart = Carbon::createFromDate((int) $year, $m, 1)->startOfDay();
                $monthEnd = $monthStart->copy()->endOfMonth();
                $months->push($this->getPeriodData($storeId, $monthStart, $monthEnd, $monthStart->format('M Y')));
            }
        } else {
            $current = now()->startOfMonth();

            for ($i = 12; $i >= 0; $i--) {
                $monthStart = $current->copy()->subMonths($i);
                $monthEnd = $monthStart->copy()->endOfMonth();
                $months->push($this->getPeriodData($storeId, $monthStart, $monthEnd, $monthStart->format('M Y')));
            }
        }

        return $months->reverse()->values();
    }

    /**
     * Get yearly aggregated data.
     */
    protected function getYearlyData(int $storeId)
    {
        $years = collect();
        $currentYear = now()->year;

        for ($i = 4; $i >= 0; $i--) {
            $year = $currentYear - $i;
            $yearStart = Carbon::createFromDate($year, 1, 1)->startOfDay();
            $yearEnd = Carbon::createFromDate($year, 12, 31)->endOfDay();
            $years->push($this->getPeriodData($storeId, $yearStart, $yearEnd, (string) $year));
        }

        return $years->reverse()->values();
    }

    /**
     * Get aggregated data for a specific period.
     *
     * @return array<string, mixed>
     */
    protected function getPeriodData(int $storeId, Carbon $start, Carbon $end, string $periodLabel): array
    {
        $startDate = $start->format('Y-m-d');
        $endDate = $end->format('Y-m-d');
        // Kit Requests (mail-in transactions created in period)
        $kitsRequested = Transaction::where('store_id', $storeId)
            ->where('type', Transaction::TYPE_MAIL_IN)
            ->whereBetween('created_at', [$start, $end])
            ->count();

        // Kit Requests Declined
        $kitsDeclined = Transaction::where('store_id', $storeId)
            ->where('type', Transaction::TYPE_MAIL_IN)
            ->where('status', Transaction::STATUS_KIT_REQUEST_REJECTED)
            ->whereBetween('created_at', [$start, $end])
            ->count();

        // Kits Received (items_received_at set within period)
        $kitsReceived = Transaction::where('store_id', $storeId)
            ->where('type', Transaction::TYPE_MAIL_IN)
            ->whereNotNull('items_received_at')
            ->whereBetween('items_received_at', [$start, $end])
            ->count();

        // Kits Received but Rejected (received then returned)
        $kitsRejected = Transaction::where('store_id', $storeId)
            ->where('type', Transaction::TYPE_MAIL_IN)
            ->whereNotNull('items_received_at')
            ->where('status', Transaction::STATUS_ITEMS_RETURNED)
            ->whereBetween('items_received_at', [$start, $end])
            ->count();

        // Kits Returned (return shipped within period)
        $kitsReturned = Transaction::where('store_id', $storeId)
            ->where('type', Transaction::TYPE_MAIL_IN)
            ->whereNotNull('return_shipped_at')
            ->whereBetween('return_shipped_at', [$start, $end])
            ->count();

        // Offers Given (offer_given_at set within period)
        $offersGiven = Transaction::where('store_id', $storeId)
            ->whereNotNull('offer_given_at')
            ->whereBetween('offer_given_at', [$start, $end])
            ->count();

        // Offers Declined
        $offersDeclined = Transaction::where('store_id', $storeId)
            ->where('status', Transaction::STATUS_OFFER_DECLINED)
            ->whereNotNull('offer_given_at')
            ->whereBetween('offer_given_at', [$start, $end])
            ->count();

        // Offers Pending (offer given but not yet accepted/declined)
        $offersPending = Transaction::where('store_id', $storeId)
            ->where('status', Transaction::STATUS_OFFER_GIVEN)
            ->whereNotNull('offer_given_at')
            ->whereBetween('offer_given_at', [$start, $end])
            ->count();

        // Offers Accepted (payment_processed_at set within period - paid = accepted)
        $offersAccepted = Transaction::where('store_id', $storeId)
            ->whereNotNull('payment_processed_at')
            ->whereBetween('payment_processed_at', [$start, $end])
            ->count();

        // Financial data for accepted/processed transactions
        // Get final_offer from transactions and estimated_value from transaction_items.price
        $financialData = Transaction::where('store_id', $storeId)
            ->whereNotNull('payment_processed_at')
            ->whereBetween('payment_processed_at', [$start, $end])
            ->selectRaw('COALESCE(SUM(final_offer), 0) as final_offer')
            ->first();

        // Get estimated value from transaction items (sum of price field)
        $estimatedValueData = \App\Models\TransactionItem::whereHas('transaction', function ($query) use ($storeId, $start, $end) {
            $query->where('store_id', $storeId)
                ->whereNotNull('payment_processed_at')
                ->whereBetween('payment_processed_at', [$start, $end]);
        })->selectRaw('COALESCE(SUM(price * quantity), 0) as estimated_value')->first();

        $estimatedValue = (float) ($estimatedValueData->estimated_value ?? 0);
        $finalOffer = (float) ($financialData->final_offer ?? 0);
        $profit = $estimatedValue - $finalOffer;
        $profitPercent = $finalOffer > 0 ? ($profit / $finalOffer) * 100 : 0;

        // Calculate percentages
        $kitDeclinedPercent = $kitsRequested > 0 ? ($kitsDeclined / $kitsRequested) * 100 : 0;
        $kitsReceivedPercent = $kitsRequested > 0 ? ($kitsReceived / $kitsRequested) * 100 : 0;

        return [
            'period' => $periodLabel,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'kits_requested' => $kitsRequested,
            'kits_declined' => $kitsDeclined,
            'kits_declined_percent' => round($kitDeclinedPercent, 1),
            'kits_received' => $kitsReceived,
            'kits_received_percent' => round($kitsReceivedPercent, 1),
            'kits_rejected' => $kitsRejected,
            'kits_returned' => $kitsReturned,
            'offers_given' => $offersGiven,
            'offers_declined' => $offersDeclined,
            'offers_pending' => $offersPending,
            'offers_accepted' => $offersAccepted,
            'estimated_value' => $estimatedValue,
            'profit' => $profit,
            'profit_percent' => round($profitPercent, 1),
        ];
    }

    /**
     * Calculate totals from aggregated data.
     *
     * @return array<string, mixed>
     */
    protected function calculateTotals($data): array
    {
        $kitsRequested = $data->sum('kits_requested');
        $kitsDeclined = $data->sum('kits_declined');
        $kitsReceived = $data->sum('kits_received');
        $estimatedValue = $data->sum('estimated_value');
        $profit = $data->sum('profit');

        // Calculate aggregate offer total for profit percent
        $totalFinalOffer = $estimatedValue - $profit;

        return [
            'kits_requested' => $kitsRequested,
            'kits_declined' => $kitsDeclined,
            'kits_declined_percent' => $kitsRequested > 0 ? round(($kitsDeclined / $kitsRequested) * 100, 1) : 0,
            'kits_received' => $kitsReceived,
            'kits_received_percent' => $kitsRequested > 0 ? round(($kitsReceived / $kitsRequested) * 100, 1) : 0,
            'kits_rejected' => $data->sum('kits_rejected'),
            'kits_returned' => $data->sum('kits_returned'),
            'offers_given' => $data->sum('offers_given'),
            'offers_declined' => $data->sum('offers_declined'),
            'offers_pending' => $data->sum('offers_pending'),
            'offers_accepted' => $data->sum('offers_accepted'),
            'estimated_value' => $estimatedValue,
            'profit' => $profit,
            'profit_percent' => $totalFinalOffer > 0 ? round(($profit / $totalFinalOffer) * 100, 1) : 0,
        ];
    }

    /**
     * Export daily report to CSV.
     */
    public function exportDaily(Request $request): StreamedResponse
    {
        $store = $this->storeContext->getCurrentStore();
        $startDate = now()->startOfMonth();
        $endDate = now();

        $dailyData = $this->getDailyData($store->id, $startDate, $endDate);

        return $this->exportToCsv($dailyData, 'transactions-daily-'.now()->format('Y-m-d').'.csv', 'Date');
    }

    /**
     * Export weekly report to CSV.
     */
    public function exportWeekly(Request $request): StreamedResponse
    {
        $store = $this->storeContext->getCurrentStore();
        $weeklyData = $this->getWeeklyData($store->id);

        return $this->exportToCsv($weeklyData, 'transactions-weekly-'.now()->format('Y-m-d').'.csv', 'Week');
    }

    /**
     * Export monthly report to CSV.
     */
    public function exportMonthly(Request $request): StreamedResponse
    {
        $store = $this->storeContext->getCurrentStore();
        $monthlyData = $this->getMonthlyData($store->id);

        return $this->exportToCsv($monthlyData, 'transactions-monthly-'.now()->format('Y-m-d').'.csv', 'Month');
    }

    /**
     * Export yearly report to CSV.
     */
    public function exportYearly(Request $request): StreamedResponse
    {
        $store = $this->storeContext->getCurrentStore();
        $yearlyData = $this->getYearlyData($store->id);

        return $this->exportToCsv($yearlyData, 'transactions-yearly-'.now()->format('Y-m-d').'.csv', 'Year');
    }

    /**
     * Export data to CSV.
     */
    protected function exportToCsv($data, string $filename, string $periodLabel): StreamedResponse
    {
        $totals = $this->calculateTotals($data);

        return response()->streamDownload(function () use ($data, $totals, $periodLabel) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, [
                $periodLabel,
                'Kits Req.',
                'Kit Req Declined',
                'Kit Req Declined %',
                'Kits Rec.',
                'Kits Rec %',
                'Kits Rec.: Rejected',
                'Kits Returned',
                'Offers Given',
                'Offers Declined',
                'Offers Pending',
                'Offers Accepted',
                'Est. Value',
                'Profit',
                'Profit %',
            ]);

            foreach ($data as $row) {
                fputcsv($handle, [
                    $row['period'],
                    $row['kits_requested'],
                    $row['kits_declined'],
                    $row['kits_declined_percent'].'%',
                    $row['kits_received'],
                    $row['kits_received_percent'].'%',
                    $row['kits_rejected'],
                    $row['kits_returned'],
                    $row['offers_given'],
                    $row['offers_declined'],
                    $row['offers_pending'],
                    $row['offers_accepted'],
                    number_format($row['estimated_value'], 2),
                    number_format($row['profit'], 2),
                    $row['profit_percent'].'%',
                ]);
            }

            // Totals row
            fputcsv($handle, [
                'TOTALS',
                $totals['kits_requested'],
                $totals['kits_declined'],
                $totals['kits_declined_percent'].'%',
                $totals['kits_received'],
                $totals['kits_received_percent'].'%',
                $totals['kits_rejected'],
                $totals['kits_returned'],
                $totals['offers_given'],
                $totals['offers_declined'],
                $totals['offers_pending'],
                $totals['offers_accepted'],
                number_format($totals['estimated_value'], 2),
                number_format($totals['profit'], 2),
                $totals['profit_percent'].'%',
            ]);

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }
}
