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

class LeadsReportController extends Controller
{
    public function __construct(
        protected StoreContext $storeContext,
    ) {}

    /**
     * Leads Report - Month to Date (daily breakdown).
     * Shows online transaction funnel metrics.
     */
    public function index(Request $request): Response
    {
        $store = $this->storeContext->getCurrentStore();
        $startDate = now()->startOfMonth();
        $endDate = now();

        $dailyData = $this->getDailyLeadsData($store->id, $startDate, $endDate);
        $totals = $this->calculateTotals($dailyData);
        $incomingKits = $this->getIncomingKits($store->id);

        return Inertia::render('reports/leads/Index', [
            'dailyData' => $dailyData,
            'totals' => $totals,
            'month' => now()->format('F Y'),
            'incomingKits' => $incomingKits,
        ]);
    }

    /**
     * Leads Report - Month over Month.
     */
    public function monthly(Request $request): Response
    {
        $store = $this->storeContext->getCurrentStore();
        $startDate = now()->subMonths(12)->startOfMonth();
        $endDate = now()->endOfMonth();

        $monthlyData = $this->getMonthlyLeadsData($store->id, $startDate, $endDate);
        $totals = $this->calculateTotals($monthlyData);

        return Inertia::render('reports/leads/Monthly', [
            'monthlyData' => $monthlyData,
            'totals' => $totals,
        ]);
    }

    /**
     * Leads Report - Year over Year.
     */
    public function yearly(Request $request): Response
    {
        $store = $this->storeContext->getCurrentStore();
        $yearlyData = $this->getYearlyLeadsData($store->id);
        $totals = $this->calculateTotals($yearlyData);

        return Inertia::render('reports/leads/Yearly', [
            'yearlyData' => $yearlyData,
            'totals' => $totals,
        ]);
    }

    /**
     * Daily Kits Report - Kits delivered to customers but not sent back.
     * Shows kits that have been delivered (kit_delivered_at set) but items not yet received.
     */
    public function dailyKits(Request $request): Response
    {
        $store = $this->storeContext->getCurrentStore();
        $daysBack = $request->input('days', 7);
        $startDate = now()->subDays($daysBack)->startOfDay();

        $kits = $this->getPendingReturnKits($store->id, $startDate);

        return Inertia::render('reports/leads/DailyKits', [
            'kits' => $kits,
            'daysBack' => $daysBack,
        ]);
    }

    /**
     * Export Leads MTD to CSV.
     */
    public function exportIndex(Request $request): StreamedResponse
    {
        $store = $this->storeContext->getCurrentStore();
        $startDate = now()->startOfMonth();
        $endDate = now();

        $dailyData = $this->getDailyLeadsData($store->id, $startDate, $endDate);

        return $this->exportLeadsToCsv($dailyData, 'leads-mtd-'.now()->format('Y-m-d').'.csv');
    }

    /**
     * Export Leads Monthly to CSV.
     */
    public function exportMonthly(Request $request): StreamedResponse
    {
        $store = $this->storeContext->getCurrentStore();
        $startDate = now()->subMonths(12)->startOfMonth();
        $endDate = now()->endOfMonth();

        $monthlyData = $this->getMonthlyLeadsData($store->id, $startDate, $endDate);

        return $this->exportLeadsToCsv($monthlyData, 'leads-monthly-'.now()->format('Y-m-d').'.csv');
    }

    /**
     * Export Leads Yearly to CSV.
     */
    public function exportYearly(Request $request): StreamedResponse
    {
        $store = $this->storeContext->getCurrentStore();
        $yearlyData = $this->getYearlyLeadsData($store->id);

        return $this->exportLeadsToCsv($yearlyData, 'leads-yearly-'.now()->format('Y-m-d').'.csv');
    }

    /**
     * Export Daily Kits to CSV.
     */
    public function exportDailyKits(Request $request): StreamedResponse
    {
        $store = $this->storeContext->getCurrentStore();
        $daysBack = $request->input('days', 7);
        $startDate = now()->subDays($daysBack)->startOfDay();

        $kits = $this->getPendingReturnKits($store->id, $startDate);

        return response()->streamDownload(function () use ($kits) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, [
                'Transaction ID',
                'Date Kit Delivered',
                'Customer Name',
                'Status',
                'Outbound Tracking',
                'Days Since Delivered',
            ]);

            foreach ($kits as $kit) {
                fputcsv($handle, [
                    $kit['transaction_number'],
                    $kit['kit_delivered_at'],
                    $kit['customer_name'],
                    $kit['status'],
                    $kit['outbound_tracking'],
                    $kit['days_since_delivered'],
                ]);
            }

            fclose($handle);
        }, 'daily-kits-'.now()->format('Y-m-d').'.csv', [
            'Content-Type' => 'text/csv',
        ]);
    }

    /**
     * Get daily leads data for a date range.
     */
    protected function getDailyLeadsData(int $storeId, Carbon $startDate, Carbon $endDate): array
    {
        $days = collect();
        $current = $startDate->copy();

        while ($current <= $endDate) {
            $dayStart = $current->copy()->startOfDay();
            $dayEnd = $current->copy()->endOfDay();

            $metrics = $this->getLeadsMetricsForPeriod($storeId, $dayStart, $dayEnd);

            $days->push([
                'date' => $current->format('M d, Y'),
                'date_key' => $current->format('Y-m-d'),
                ...$metrics,
            ]);

            $current->addDay();
        }

        return $days->reverse()->values()->toArray();
    }

    /**
     * Get monthly leads data for a date range.
     */
    protected function getMonthlyLeadsData(int $storeId, Carbon $startDate, Carbon $endDate): array
    {
        $months = collect();
        $current = $startDate->copy()->startOfMonth();

        while ($current <= $endDate) {
            $monthStart = $current->copy()->startOfMonth();
            $monthEnd = $current->copy()->endOfMonth();

            $metrics = $this->getLeadsMetricsForPeriod($storeId, $monthStart, $monthEnd);

            $months->push([
                'date' => $current->format('M Y'),
                'start_date' => $monthStart->format('Y-m-d'),
                'end_date' => $monthEnd->format('Y-m-d'),
                ...$metrics,
            ]);

            $current->addMonth();
        }

        return $months->reverse()->values()->toArray();
    }

    /**
     * Get yearly leads data.
     */
    protected function getYearlyLeadsData(int $storeId): array
    {
        $years = collect();
        $startDate = now()->subYears(4)->startOfYear();
        $endDate = now()->endOfYear();
        $current = $startDate->copy();

        while ($current <= $endDate) {
            $yearStart = $current->copy()->startOfYear();
            $yearEnd = $current->copy()->endOfYear();

            $metrics = $this->getLeadsMetricsForPeriod($storeId, $yearStart, $yearEnd);

            $years->push([
                'date' => $current->format('Y'),
                'start_date' => $yearStart->format('Y-m-d'),
                'end_date' => $yearEnd->format('Y-m-d'),
                ...$metrics,
            ]);

            $current->addYear();
        }

        return $years->reverse()->values()->toArray();
    }

    /**
     * Get leads metrics for a specific period.
     * These metrics are based on when transactions were CREATED.
     */
    protected function getLeadsMetricsForPeriod(int $storeId, Carbon $startDate, Carbon $endDate): array
    {
        // Base query for online/mail-in transactions created in this period
        $baseQuery = fn () => Transaction::query()
            ->where('store_id', $storeId)
            ->where(function ($q) {
                $q->where('type', Transaction::TYPE_MAIL_IN)
                    ->orWhere('source', Transaction::SOURCE_ONLINE);
            })
            ->whereBetween('created_at', [$startDate, $endDate]);

        // Kits Requested (all online transactions created)
        $kitsRequested = $baseQuery()->count();

        // Kit Request Declined (rejected at kit request stage)
        $kitReqDeclined = $baseQuery()
            ->where('status', Transaction::STATUS_KIT_REQUEST_REJECTED)
            ->count();

        // Kit Request Declined %
        $kitReqDeclinedPct = $kitsRequested > 0 ? ($kitReqDeclined / $kitsRequested) * 100 : 0;

        // Kits Received (items_received_at is set)
        $kitsReceived = $baseQuery()
            ->whereNotNull('items_received_at')
            ->count();

        // Kits Received %
        $kitsReceivedPct = $kitsRequested > 0 ? ($kitsReceived / $kitsRequested) * 100 : 0;

        // Kits Received: Rejected (received but then rejected/returned)
        $kitsRecRejected = $baseQuery()
            ->whereNotNull('items_received_at')
            ->whereIn('status', [
                Transaction::STATUS_RETURN_REQUESTED,
                Transaction::STATUS_ITEMS_RETURNED,
            ])
            ->count();

        // Kits Returned (items shipped back to customer)
        $kitsReturned = $baseQuery()
            ->where('status', Transaction::STATUS_ITEMS_RETURNED)
            ->count();

        // Offers Declined
        $offersDeclined = $baseQuery()
            ->where('status', Transaction::STATUS_OFFER_DECLINED)
            ->count();

        // Offers Given (offer_given_at is set)
        $offersGiven = $baseQuery()
            ->whereNotNull('offer_given_at')
            ->count();

        // Offers Pending (offer given but not yet accepted/declined)
        $offersPending = $baseQuery()
            ->where('status', Transaction::STATUS_OFFER_GIVEN)
            ->count();

        // Offers Accepted (offer_accepted_at is set)
        $offersAccepted = $baseQuery()
            ->whereNotNull('offer_accepted_at')
            ->count();

        // Estimated Value (sum of estimated_value for accepted offers)
        $estimatedValue = $baseQuery()
            ->whereNotNull('offer_accepted_at')
            ->sum('estimated_value');

        // Final Offer (total paid out)
        $finalOffer = $baseQuery()
            ->whereNotNull('payment_processed_at')
            ->sum('final_offer');

        // Profit = Estimated Value - Final Offer
        $profit = $estimatedValue - $finalOffer;

        // Profit %
        $profitPct = $estimatedValue > 0 ? ($profit / $estimatedValue) * 100 : 0;

        return [
            'kits_requested' => $kitsRequested,
            'kit_req_declined' => $kitReqDeclined,
            'kit_req_declined_pct' => round($kitReqDeclinedPct, 1),
            'kits_received' => $kitsReceived,
            'kits_received_pct' => round($kitsReceivedPct, 1),
            'kits_rec_rejected' => $kitsRecRejected,
            'kits_returned' => $kitsReturned,
            'offers_declined' => $offersDeclined,
            'offers_given' => $offersGiven,
            'offers_pending' => $offersPending,
            'offers_accepted' => $offersAccepted,
            'estimated_value' => $estimatedValue,
            'final_offer' => $finalOffer,
            'profit' => $profit,
            'profit_pct' => round($profitPct, 1),
        ];
    }

    /**
     * Get kits that have been delivered to customers but not sent back.
     */
    protected function getPendingReturnKits(int $storeId, Carbon $startDate): array
    {
        $kits = Transaction::query()
            ->where('store_id', $storeId)
            ->where(function ($q) {
                $q->where('type', Transaction::TYPE_MAIL_IN)
                    ->orWhere('source', Transaction::SOURCE_ONLINE);
            })
            ->whereNotNull('kit_delivered_at')
            ->where('kit_delivered_at', '>=', $startDate)
            ->whereNull('items_received_at') // Not yet received back
            ->whereNotIn('status', [
                Transaction::STATUS_CANCELLED,
                Transaction::STATUS_ITEMS_RETURNED,
            ])
            ->with(['customer'])
            ->orderBy('kit_delivered_at', 'desc')
            ->get();

        return $kits->map(function ($transaction) {
            $deliveredAt = Carbon::parse($transaction->kit_delivered_at);

            return [
                'id' => $transaction->id,
                'transaction_number' => $transaction->transaction_number,
                'kit_delivered_at' => $deliveredAt->format('M d, Y'),
                'kit_delivered_at_raw' => $deliveredAt->format('Y-m-d'),
                'customer_name' => $transaction->customer?->full_name ?? 'Unknown',
                'customer_id' => $transaction->customer_id,
                'status' => $this->formatStatus($transaction->status),
                'status_raw' => $transaction->status,
                'outbound_tracking' => $transaction->outbound_tracking_number,
                'outbound_carrier' => $transaction->outbound_carrier,
                'return_tracking' => $transaction->return_tracking_number,
                'return_carrier' => $transaction->return_carrier,
                'days_since_delivered' => $deliveredAt->diffInDays(now()),
            ];
        })->toArray();
    }

    /**
     * Get kits that are on their way back to the store (in transit).
     */
    protected function getIncomingKits(int $storeId): array
    {
        $kits = Transaction::query()
            ->where('store_id', $storeId)
            ->where(function ($q) {
                $q->where('type', Transaction::TYPE_MAIL_IN)
                    ->orWhere('source', Transaction::SOURCE_ONLINE);
            })
            ->whereNotNull('return_shipped_at')
            ->whereNull('items_received_at')
            ->whereNotIn('status', [
                Transaction::STATUS_CANCELLED,
                Transaction::STATUS_ITEMS_RETURNED,
            ])
            ->with(['customer'])
            ->orderBy('return_shipped_at', 'desc')
            ->limit(10)
            ->get();

        return $kits->map(function ($transaction) {
            $shippedAt = Carbon::parse($transaction->return_shipped_at);

            // Get tracking status from metadata if available
            $trackingStatus = $transaction->metadata['return_tracking_status'] ?? null;

            return [
                'id' => $transaction->id,
                'transaction_number' => $transaction->transaction_number,
                'return_shipped_at' => $shippedAt->format('M d, Y'),
                'days_in_transit' => $shippedAt->diffInDays(now()),
                'customer_name' => $transaction->customer?->full_name ?? 'Unknown',
                'customer_id' => $transaction->customer_id,
                'status' => $this->formatStatus($transaction->status),
                'return_tracking' => $transaction->return_tracking_number,
                'return_carrier' => $transaction->return_carrier ?? 'fedex',
                'estimated_value' => $transaction->estimated_value ?? 0,
                'tracking_status' => $trackingStatus ? [
                    'status' => $trackingStatus['status'] ?? null,
                    'status_label' => $trackingStatus['status_label'] ?? null,
                    'description' => $trackingStatus['description'] ?? null,
                    'location' => $trackingStatus['location'] ?? null,
                    'estimated_delivery' => $trackingStatus['estimated_delivery'] ?? null,
                    'updated_at' => $trackingStatus['updated_at'] ?? null,
                ] : null,
            ];
        })->toArray();
    }

    /**
     * Format status for display.
     */
    protected function formatStatus(string $status): string
    {
        $labels = [
            Transaction::STATUS_PENDING_KIT_REQUEST => 'Pending Kit Request',
            Transaction::STATUS_KIT_REQUEST_CONFIRMED => 'Kit Confirmed',
            Transaction::STATUS_KIT_REQUEST_REJECTED => 'Kit Rejected',
            Transaction::STATUS_KIT_REQUEST_ON_HOLD => 'Kit On Hold',
            Transaction::STATUS_KIT_SENT => 'Kit Sent',
            Transaction::STATUS_KIT_DELIVERED => 'Kit Delivered',
            Transaction::STATUS_PENDING => 'Pending',
            Transaction::STATUS_ITEMS_RECEIVED => 'Items Received',
            Transaction::STATUS_ITEMS_REVIEWED => 'Items Reviewed',
            Transaction::STATUS_OFFER_GIVEN => 'Offer Given',
            Transaction::STATUS_OFFER_ACCEPTED => 'Offer Accepted',
            Transaction::STATUS_OFFER_DECLINED => 'Offer Declined',
            Transaction::STATUS_PAYMENT_PENDING => 'Payment Pending',
            Transaction::STATUS_PAYMENT_PROCESSED => 'Payment Processed',
            Transaction::STATUS_RETURN_REQUESTED => 'Return Requested',
            Transaction::STATUS_ITEMS_RETURNED => 'Items Returned',
            Transaction::STATUS_CANCELLED => 'Cancelled',
        ];

        return $labels[$status] ?? ucfirst(str_replace('_', ' ', $status));
    }

    /**
     * Calculate totals from data array.
     */
    protected function calculateTotals(array $data): array
    {
        $collection = collect($data);

        $kitsRequested = $collection->sum('kits_requested');
        $kitReqDeclined = $collection->sum('kit_req_declined');
        $kitsReceived = $collection->sum('kits_received');
        $kitsRecRejected = $collection->sum('kits_rec_rejected');
        $kitsReturned = $collection->sum('kits_returned');
        $offersDeclined = $collection->sum('offers_declined');
        $offersGiven = $collection->sum('offers_given');
        $offersPending = $collection->sum('offers_pending');
        $offersAccepted = $collection->sum('offers_accepted');
        $estimatedValue = $collection->sum('estimated_value');
        $finalOffer = $collection->sum('final_offer');
        $profit = $estimatedValue - $finalOffer;

        return [
            'kits_requested' => $kitsRequested,
            'kit_req_declined' => $kitReqDeclined,
            'kit_req_declined_pct' => $kitsRequested > 0 ? round(($kitReqDeclined / $kitsRequested) * 100, 1) : 0,
            'kits_received' => $kitsReceived,
            'kits_received_pct' => $kitsRequested > 0 ? round(($kitsReceived / $kitsRequested) * 100, 1) : 0,
            'kits_rec_rejected' => $kitsRecRejected,
            'kits_returned' => $kitsReturned,
            'offers_declined' => $offersDeclined,
            'offers_given' => $offersGiven,
            'offers_pending' => $offersPending,
            'offers_accepted' => $offersAccepted,
            'estimated_value' => $estimatedValue,
            'final_offer' => $finalOffer,
            'profit' => $profit,
            'profit_pct' => $estimatedValue > 0 ? round(($profit / $estimatedValue) * 100, 1) : 0,
        ];
    }

    /**
     * Export leads data to CSV.
     */
    protected function exportLeadsToCsv(array $data, string $filename): StreamedResponse
    {
        $totals = $this->calculateTotals($data);

        return response()->streamDownload(function () use ($data, $totals) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, [
                'Date',
                'Kits Req.',
                'Kit Req Declined',
                'Kit Req Declined %',
                'Kits Rec.',
                'Kits Rec %',
                'Kits Rec.: Rejected',
                'Kits Returned',
                'Offers Declined',
                'Offers Given',
                'Offers Pending',
                'Offers Accepted',
                'Est. Value',
                'Final Offer',
                'Profit',
                'Profit %',
            ]);

            foreach ($data as $row) {
                fputcsv($handle, [
                    $row['date'],
                    $row['kits_requested'],
                    $row['kit_req_declined'],
                    $row['kit_req_declined_pct'].'%',
                    $row['kits_received'],
                    $row['kits_received_pct'].'%',
                    $row['kits_rec_rejected'],
                    $row['kits_returned'],
                    $row['offers_declined'],
                    $row['offers_given'],
                    $row['offers_pending'],
                    $row['offers_accepted'],
                    number_format($row['estimated_value'], 2),
                    number_format($row['final_offer'], 2),
                    number_format($row['profit'], 2),
                    $row['profit_pct'].'%',
                ]);
            }

            // Totals row
            fputcsv($handle, [
                'TOTALS',
                $totals['kits_requested'],
                $totals['kit_req_declined'],
                $totals['kit_req_declined_pct'].'%',
                $totals['kits_received'],
                $totals['kits_received_pct'].'%',
                $totals['kits_rec_rejected'],
                $totals['kits_returned'],
                $totals['offers_declined'],
                $totals['offers_given'],
                $totals['offers_pending'],
                $totals['offers_accepted'],
                number_format($totals['estimated_value'], 2),
                number_format($totals['final_offer'], 2),
                number_format($totals['profit'], 2),
                $totals['profit_pct'].'%',
            ]);

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }
}
