<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Services\StoreContext;
use App\Traits\SendsReportEmails;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TransactionsReportController extends Controller
{
    use SendsReportEmails;

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

        $totals = [
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

        // Include actionable lead totals when the data contains them
        if ($data->first() && array_key_exists('actionable_received_no_offer', $data->first())) {
            $totals['actionable_received_no_offer'] = $data->sum('actionable_received_no_offer');
            $totals['actionable_offer_no_response'] = $data->sum('actionable_offer_no_response');
            $totals['actionable_delivered_not_received'] = $data->sum('actionable_delivered_not_received');
        }

        return $totals;
    }

    /**
     * Cohort transactions report (groups by creation period, tracks milestone completion).
     */
    public function cohort(Request $request): Response
    {
        $store = $this->storeContext->getCurrentStore();
        $startDate = $request->query('start_date');
        $endDate = $request->query('end_date');
        $status = $request->query('status');
        $granularity = $request->query('granularity', 'monthly');

        $cohortData = $this->getCohortData($store->id, null, $startDate, $endDate, $status, $granularity);
        $totals = $this->calculateTotals($cohortData);

        return Inertia::render('reports/transactions/Cohort', [
            'cohortData' => $cohortData,
            'totals' => $totals,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'granularity' => $granularity,
            'statuses' => Transaction::getAvailableStatuses(),
            'filters' => [
                'status' => $status,
            ],
        ]);
    }

    /**
     * Export cohort report to CSV.
     */
    public function exportCohort(Request $request): StreamedResponse
    {
        $store = $this->storeContext->getCurrentStore();
        $startDate = $request->query('start_date');
        $endDate = $request->query('end_date');
        $status = $request->query('status');
        $granularity = $request->query('granularity', 'monthly');

        $cohortData = $this->getCohortData($store->id, null, $startDate, $endDate, $status, $granularity);

        return $this->exportToCsv($cohortData, 'transactions-cohort-'.now()->format('Y-m-d').'.csv', 'Cohort');
    }

    /**
     * Email cohort report.
     */
    public function emailCohort(Request $request): \Illuminate\Http\JsonResponse
    {
        $store = $this->storeContext->getCurrentStore();
        $startDate = $request->query('start_date');
        $endDate = $request->query('end_date');
        $status = $request->query('status');
        $granularity = $request->query('granularity', 'monthly');

        $cohortData = $this->getCohortData($store->id, null, $startDate, $endDate, $status, $granularity);
        $totals = $this->calculateTotals($cohortData);

        $headers = [
            'Cohort', 'Kits Req.', 'Kit Req Declined', 'Kit Req Declined %',
            'Kits Rec.', 'Kits Rec %', 'Kits Rec.: Rejected', 'Kits Returned',
            'Offers Given', 'Offers Declined', 'Offers Pending', 'Offers Accepted',
            'Est. Value', 'Profit', 'Profit %',
        ];

        $formatRow = fn ($row) => [
            $row['period'] ?? 'TOTALS',
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
            '$'.number_format($row['estimated_value'], 2),
            '$'.number_format($row['profit'], 2),
            $row['profit_percent'].'%',
        ];

        $granularityLabels = ['daily' => 'Daily', 'monthly' => 'Monthly', 'yearly' => 'Yearly'];
        $description = 'Cohort Analysis ('.($granularityLabels[$granularity] ?? 'Monthly').')';
        if ($startDate && $endDate) {
            $description .= " ({$startDate} to {$endDate})";
        }

        return $this->sendReportEmail(
            $request,
            'Transactions Cohort Report',
            $description,
            $headers,
            $cohortData,
            $totals,
            $formatRow,
            'transactions-cohort-'.now()->format('Y-m-d').'.csv',
            $store,
        );
    }

    /**
     * Drilldown into a specific cohort metric, returning individual transactions.
     */
    public function cohortDrilldown(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date'],
            'metric' => ['required', 'in:kits_requested,kits_declined,kits_received,kits_rejected,kits_returned,offers_given,offers_declined,offers_pending,offers_accepted,received_no_offer,offer_no_response,delivered_not_received'],
            'status' => ['nullable', 'string'],
        ]);

        $store = $this->storeContext->getCurrentStore();
        $start = Carbon::parse($validated['start_date'])->startOfDay();
        $end = Carbon::parse($validated['end_date'])->endOfDay();

        $query = Transaction::where('store_id', $store->id)
            ->whereBetween('created_at', [$start, $end]);

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        $groups = $this->getCohortStatusGroups();

        match ($validated['metric']) {
            'kits_declined' => $query->whereIn('status', $groups['kit_declined']),
            'kits_received' => $query->whereNotIn('status', $groups['pre_received']),
            'kits_rejected', 'kits_returned' => $query->whereIn('status', $groups['kit_rejected_returned']),
            'offers_given' => $query->whereIn('status', $groups['offer_given']),
            'offers_declined' => $query->whereIn('status', $groups['offer_declined']),
            'offers_pending' => $query->whereIn('status', $groups['offer_pending']),
            'offers_accepted' => $query->whereIn('status', $groups['offer_accepted']),
            'received_no_offer' => $query->whereIn('status', $groups['received_no_offer']),
            'offer_no_response' => $query->whereIn('status', $groups['offer_no_response']),
            'delivered_not_received' => $query->whereIn('status', $groups['delivered_not_received']),
            default => null, // kits_requested — no additional filter
        };

        $statuses = Transaction::getAvailableStatuses();

        $transactions = $query->with('customer')
            ->latest()
            ->limit(200)
            ->get()
            ->map(fn (Transaction $t) => [
                'id' => $t->id,
                'transaction_number' => $t->transaction_number,
                'customer_name' => $t->customer?->full_name,
                'customer_email' => $t->customer?->email,
                'status' => $t->status,
                'status_label' => $statuses[$t->status] ?? $t->status,
                'final_offer' => $t->final_offer,
                'created_at' => $t->created_at->format('M d, Y'),
                'url' => "/transactions/{$t->id}",
            ]);

        return response()->json([
            'transactions' => $transactions,
        ]);
    }

    /**
     * Get cohort data grouped by the specified granularity.
     *
     * Unlike getPeriodData which filters each metric by its own timestamp,
     * cohort data groups all transactions by created_at period and tracks
     * what percentage reached each milestone over their lifetime.
     */
    protected function getCohortData(int $storeId, ?string $year = null, ?string $startDate = null, ?string $endDate = null, ?string $status = null, string $granularity = 'monthly')
    {
        $rows = collect();

        if ($granularity === 'daily') {
            $rows = $this->getCohortDailyData($storeId, $startDate, $endDate, $status);
        } elseif ($granularity === 'yearly') {
            $rows = $this->getCohortYearlyData($storeId, $startDate, $endDate, $status);
        } else {
            $rows = $this->getCohortMonthlyData($storeId, $year, $startDate, $endDate, $status);
        }

        return $rows->reverse()->values();
    }

    /**
     * Get cohort data grouped by day.
     */
    protected function getCohortDailyData(int $storeId, ?string $startDate, ?string $endDate, ?string $status): \Illuminate\Support\Collection
    {
        $days = collect();

        if ($startDate && $endDate) {
            $rangeStart = Carbon::parse($startDate)->startOfDay();
            $rangeEnd = Carbon::parse($endDate)->endOfDay();
        } else {
            $rangeStart = now()->startOfMonth()->startOfDay();
            $rangeEnd = now()->endOfDay();
        }

        $current = $rangeStart->copy();

        while ($current->lte($rangeEnd)) {
            $dayStart = $current->copy()->startOfDay();
            $dayEnd = $current->copy()->endOfDay();
            $days->push($this->getCohortPeriodData($storeId, $dayStart, $dayEnd, $current->format('M d, Y'), $status));
            $current->addDay();
        }

        return $days;
    }

    /**
     * Get cohort data grouped by month.
     */
    protected function getCohortMonthlyData(int $storeId, ?string $year, ?string $startDate, ?string $endDate, ?string $status): \Illuminate\Support\Collection
    {
        $months = collect();

        if ($startDate && $endDate) {
            $rangeStart = Carbon::parse($startDate)->startOfMonth();
            $rangeEnd = Carbon::parse($endDate)->endOfMonth();
            $current = $rangeStart->copy();

            while ($current->lte($rangeEnd)) {
                $monthStart = $current->copy()->startOfMonth()->startOfDay();
                $monthEnd = $current->copy()->endOfMonth();
                $months->push($this->getCohortPeriodData($storeId, $monthStart, $monthEnd, $monthStart->format('M Y'), $status));
                $current->addMonth();
            }
        } elseif ($year) {
            for ($m = 1; $m <= 12; $m++) {
                $monthStart = Carbon::createFromDate((int) $year, $m, 1)->startOfDay();
                $monthEnd = $monthStart->copy()->endOfMonth();
                $months->push($this->getCohortPeriodData($storeId, $monthStart, $monthEnd, $monthStart->format('M Y'), $status));
            }
        } else {
            $current = now()->startOfMonth();

            for ($i = 12; $i >= 0; $i--) {
                $monthStart = $current->copy()->subMonths($i);
                $monthEnd = $monthStart->copy()->endOfMonth();
                $months->push($this->getCohortPeriodData($storeId, $monthStart, $monthEnd, $monthStart->format('M Y'), $status));
            }
        }

        return $months;
    }

    /**
     * Get cohort data grouped by year.
     */
    protected function getCohortYearlyData(int $storeId, ?string $startDate, ?string $endDate, ?string $status): \Illuminate\Support\Collection
    {
        $years = collect();

        if ($startDate && $endDate) {
            $startYear = (int) Carbon::parse($startDate)->format('Y');
            $endYear = (int) Carbon::parse($endDate)->format('Y');

            for ($y = $startYear; $y <= $endYear; $y++) {
                $yearStart = Carbon::createFromDate($y, 1, 1)->startOfDay();
                $yearEnd = Carbon::createFromDate($y, 12, 31)->endOfDay();
                $years->push($this->getCohortPeriodData($storeId, $yearStart, $yearEnd, (string) $y, $status));
            }
        } else {
            $currentYear = now()->year;

            for ($i = 4; $i >= 0; $i--) {
                $y = $currentYear - $i;
                $yearStart = Carbon::createFromDate($y, 1, 1)->startOfDay();
                $yearEnd = Carbon::createFromDate($y, 12, 31)->endOfDay();
                $years->push($this->getCohortPeriodData($storeId, $yearStart, $yearEnd, (string) $y, $status));
            }
        }

        return $years;
    }

    /**
     * Get cohort status groups for milestone detection.
     *
     * @return array<string, array<int, string>>
     */
    private function getCohortStatusGroups(): array
    {
        $kitDeclinedStatuses = [
            'kit_request_rejected',
            'pending_kit_requests_rejected_by_admin',
            'pending_kit_request_rejected_by_customer',
        ];

        $kitRejectedReturnedStatuses = [
            'items_returned', 'return_requested',
            'returned_by_admin', 'kit_received_rejected_by_admin',
            'kits_received_refused_by_customer_fedex', 'returned_kit',
            'offers_declined_send_back',
        ];

        $preReceivedStatuses = array_merge($kitDeclinedStatuses, [
            'pending', 'pending_kit_request', 'pending_kit_request_confirmed',
            'kit_request_on_hold', 'kit_sent', 'kit_delivered',
            'pending_kit_request_incomplete',
            'pending_kit_request_high_value', 'pending_kit_request_high_value_watches',
        ]);

        $offerGivenStatuses = [
            'offer_given', 'offer_2_given', 'offer_given_cnotes_picture',
            'offer_declined', 'offers_declined_send_back',
            'offer_accepted', 'payment_pending', 'payment_processed',
            '14_day_on_hold',
        ];

        $offerDeclinedStatuses = [
            'offer_declined', 'offers_declined_send_back',
        ];

        $offerPendingStatuses = [
            'offer_given', 'offer_2_given', 'offer_given_cnotes_picture',
            '14_day_on_hold',
        ];

        $offerAcceptedStatuses = [
            'offer_accepted', 'payment_pending', 'payment_processed',
        ];

        $receivedNoOfferStatuses = [
            'items_received', 'items_reviewed', 'kit_received',
        ];

        $offerNoResponseStatuses = [
            'offer_given', 'offer_2_given', 'offer_given_cnotes_picture',
            '14_day_on_hold',
        ];

        $deliveredNotReceivedStatuses = [
            'kit_delivered',
        ];

        return [
            'kit_declined' => $kitDeclinedStatuses,
            'kit_rejected_returned' => $kitRejectedReturnedStatuses,
            'pre_received' => $preReceivedStatuses,
            'offer_given' => $offerGivenStatuses,
            'offer_declined' => $offerDeclinedStatuses,
            'offer_pending' => $offerPendingStatuses,
            'offer_accepted' => $offerAcceptedStatuses,
            'received_no_offer' => $receivedNoOfferStatuses,
            'offer_no_response' => $offerNoResponseStatuses,
            'delivered_not_received' => $deliveredNotReceivedStatuses,
        ];
    }

    /**
     * Get cohort data for transactions created in a specific period.
     *
     * All metrics are based on transactions created in the period,
     * regardless of when each milestone was reached.
     *
     * @return array<string, mixed>
     */
    protected function getCohortPeriodData(int $storeId, Carbon $start, Carbon $end, string $periodLabel, ?string $status = null): array
    {
        $startDate = $start->format('Y-m-d');
        $endDate = $end->format('Y-m-d');

        $baseQuery = Transaction::where('store_id', $storeId)
            ->whereBetween('created_at', [$start, $end]);

        if ($status) {
            $baseQuery->where('status', $status);
        }

        $groups = $this->getCohortStatusGroups();

        // Kit Requests (all transactions created in period — the cohort baseline)
        $kitsRequested = (clone $baseQuery)->count();

        // Kit Requests Declined
        $kitsDeclined = (clone $baseQuery)
            ->whereIn('status', $groups['kit_declined'])
            ->count();

        // Kits Received (status implies items arrived)
        $kitsReceived = (clone $baseQuery)
            ->whereNotIn('status', $groups['pre_received'])
            ->count();

        // Kits Received but Rejected/Returned
        $kitsRejected = (clone $baseQuery)
            ->whereIn('status', $groups['kit_rejected_returned'])
            ->count();

        // Kits Returned (same as rejected — items shipped back)
        $kitsReturned = $kitsRejected;

        // Offers Given
        $offersGiven = (clone $baseQuery)
            ->whereIn('status', $groups['offer_given'])
            ->count();

        // Offers Declined
        $offersDeclined = (clone $baseQuery)
            ->whereIn('status', $groups['offer_declined'])
            ->count();

        // Offers Pending
        $offersPending = (clone $baseQuery)
            ->whereIn('status', $groups['offer_pending'])
            ->count();

        // Offers Accepted
        $offersAccepted = (clone $baseQuery)
            ->whereIn('status', $groups['offer_accepted'])
            ->count();

        // Actionable lead counts
        $actionableReceivedNoOffer = (clone $baseQuery)
            ->whereIn('status', $groups['received_no_offer'])
            ->count();

        $actionableOfferNoResponse = (clone $baseQuery)
            ->whereIn('status', $groups['offer_no_response'])
            ->count();

        $actionableDeliveredNotReceived = (clone $baseQuery)
            ->whereIn('status', $groups['delivered_not_received'])
            ->count();

        // Financial data for accepted transactions from this cohort
        $financialData = (clone $baseQuery)
            ->whereIn('status', $groups['offer_accepted'])
            ->selectRaw('COALESCE(SUM(final_offer), 0) as final_offer')
            ->first();

        $estimatedValueData = \App\Models\TransactionItem::whereHas('transaction', function ($query) use ($storeId, $start, $end, $status, $groups) {
            $query->where('store_id', $storeId)
                ->whereIn('status', $groups['offer_accepted'])
                ->whereBetween('created_at', [$start, $end]);
            if ($status) {
                $query->where('status', $status);
            }
        })->selectRaw('COALESCE(SUM(price * quantity), 0) as estimated_value')->first();

        $estimatedValue = (float) ($estimatedValueData->estimated_value ?? 0);
        $finalOffer = (float) ($financialData->final_offer ?? 0);
        $profit = $estimatedValue - $finalOffer;
        $profitPercent = $finalOffer > 0 ? ($profit / $finalOffer) * 100 : 0;

        // Calculate percentages relative to the cohort's total count
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
            'actionable_received_no_offer' => $actionableReceivedNoOffer,
            'actionable_offer_no_response' => $actionableOfferNoResponse,
            'actionable_delivered_not_received' => $actionableDeliveredNotReceived,
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
