<?php

namespace App\Services\Reports;

use App\Models\Status;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Service class for buys report data aggregation.
 *
 * Single source of truth for buys data calculations, used by both
 * BuysReportController (web UI) and LegacyBuyReport (email).
 */
class BuysReportService
{
    /**
     * Get the "Payment Processed" status ID for a store.
     */
    public function getPaymentProcessedStatusId(int $storeId): ?int
    {
        return Status::where('store_id', $storeId)
            ->where('slug', 'payment_processed')
            ->value('id');
    }

    /**
     * Get daily buy transactions for a date range.
     *
     * @return Collection<int, array>
     */
    public function getDailyBuys(int $storeId, Carbon $startDate, Carbon $endDate, ?array $categoryIds = null): Collection
    {
        $paymentProcessedStatusId = $this->getPaymentProcessedStatusId($storeId);

        $transactionsQuery = Transaction::query()
            ->where('store_id', $storeId)
            ->where('status_id', $paymentProcessedStatusId)
            ->whereNotNull('payment_processed_at')
            ->whereBetween('payment_processed_at', [$startDate, $endDate])
            ->with(['items.category', 'customer', 'user']);

        if ($categoryIds) {
            $transactionsQuery->whereHas('items', fn ($q) => $q->whereIn('category_id', $categoryIds));
        }

        return $transactionsQuery->orderBy('payment_processed_at', 'desc')->get()
            ->map(function ($transaction) {
                $estimatedValue = $transaction->items->sum('price');
                $purchaseAmt = $transaction->final_offer ?? 0;
                $profit = $estimatedValue - $purchaseAmt;

                $categories = $transaction->items
                    ->pluck('category.name')
                    ->filter()
                    ->unique()
                    ->values()
                    ->implode(', ');

                return [
                    'id' => $transaction->id,
                    'date' => Carbon::parse($transaction->payment_processed_at)->format('Y-m-d H:i'),
                    'transaction_number' => $transaction->transaction_number ?? "#{$transaction->id}",
                    'customer' => $transaction->customer?->full_name ?? 'Walk-in',
                    'type' => ucfirst($transaction->type ?? 'in_store'),
                    'source' => ucfirst($transaction->source ?? 'direct'),
                    'categories' => $categories ?: '-',
                    'num_items' => $transaction->items->count(),
                    'purchase_amt' => $purchaseAmt,
                    'estimated_value' => $estimatedValue,
                    'profit' => $profit,
                    'profit_percent' => $purchaseAmt > 0 ? ($profit / $purchaseAmt) * 100 : 0,
                    'user' => $transaction->user?->name ?? '-',
                ];
            });
    }

    /**
     * Calculate totals for a collection of buy rows.
     */
    public function calculateDailyBuysTotals(Collection $transactions): array
    {
        $purchaseAmt = $transactions->sum('purchase_amt');
        $estimatedValue = $transactions->sum('estimated_value');
        $profit = $estimatedValue - $purchaseAmt;

        return [
            'num_items' => $transactions->sum('num_items'),
            'purchase_amt' => $purchaseAmt,
            'estimated_value' => $estimatedValue,
            'profit' => $profit,
            'profit_percent' => $purchaseAmt > 0 ? ($profit / $purchaseAmt) * 100 : 0,
        ];
    }

    /**
     * Get daily aggregated buys data for a date range.
     *
     * @param  callable|null  $filter  Optional filter callback for Transaction query
     * @return Collection<int, array>
     */
    public function getDailyAggregatedData(int $storeId, Carbon $startDate, Carbon $endDate, ?callable $filter = null, ?array $categoryIds = null): Collection
    {
        $paymentProcessedStatusId = $this->getPaymentProcessedStatusId($storeId);

        $query = Transaction::query()
            ->where('store_id', $storeId)
            ->where('status_id', $paymentProcessedStatusId)
            ->whereNotNull('payment_processed_at')
            ->whereBetween('payment_processed_at', [$startDate, $endDate])
            ->with(['items']);

        if ($filter) {
            $filter($query);
        }

        if ($categoryIds) {
            $query->whereHas('items', fn ($q) => $q->whereIn('category_id', $categoryIds));
        }

        $transactions = $query->get();
        $grouped = $transactions->groupBy(fn ($t) => Carbon::parse($t->payment_processed_at)->format('Y-m-d'));

        $days = collect();
        $current = $startDate->copy();

        while ($current <= $endDate) {
            $key = $current->format('Y-m-d');
            $dayTransactions = $grouped->get($key, collect());

            $aggregated = $this->aggregateTransactions($dayTransactions);

            $days->push([
                'date' => $current->format('M d, Y'),
                'date_key' => $current->format('Y-m-d'),
                'buys_count' => $dayTransactions->count(),
                'purchase_amt' => $aggregated['purchase_amt'],
                'estimated_value' => $aggregated['estimated_value'],
                'profit' => $aggregated['profit'],
                'profit_percent' => $aggregated['profit_percent'],
                'avg_buy_price' => $aggregated['avg_buy_price'],
            ]);

            $current->addDay();
        }

        return $days->reverse()->values();
    }

    /**
     * Get monthly aggregated buys data for a date range.
     *
     * @param  callable|null  $filter  Optional filter callback for Transaction query
     * @return Collection<int, array>
     */
    public function getMonthlyAggregatedData(int $storeId, Carbon $startDate, Carbon $endDate, ?callable $filter = null, ?array $categoryIds = null): Collection
    {
        $paymentProcessedStatusId = $this->getPaymentProcessedStatusId($storeId);

        $query = Transaction::query()
            ->where('store_id', $storeId)
            ->where('status_id', $paymentProcessedStatusId)
            ->whereNotNull('payment_processed_at')
            ->whereBetween('payment_processed_at', [$startDate, $endDate])
            ->with(['items']);

        if ($filter) {
            $filter($query);
        }

        if ($categoryIds) {
            $query->whereHas('items', fn ($q) => $q->whereIn('category_id', $categoryIds));
        }

        $transactions = $query->get();
        $grouped = $transactions->groupBy(fn ($t) => Carbon::parse($t->payment_processed_at)->format('Y-m'));

        $months = collect();
        $current = $startDate->copy();

        while ($current <= $endDate) {
            $key = $current->format('Y-m');
            $monthTransactions = $grouped->get($key, collect());

            $aggregated = $this->aggregateTransactions($monthTransactions);

            $months->push([
                'date' => $current->format('M Y'),
                'start_date' => $current->copy()->startOfMonth()->format('Y-m-d'),
                'end_date' => $current->copy()->endOfMonth()->format('Y-m-d'),
                'buys_count' => $monthTransactions->count(),
                'purchase_amt' => $aggregated['purchase_amt'],
                'estimated_value' => $aggregated['estimated_value'],
                'profit' => $aggregated['profit'],
                'profit_percent' => $aggregated['profit_percent'],
                'avg_buy_price' => $aggregated['avg_buy_price'],
            ]);

            $current->addMonth();
        }

        return $months->reverse()->values();
    }

    /**
     * Get yearly aggregated buys data.
     *
     * @param  callable|null  $filter  Optional filter callback for Transaction query
     * @return Collection<int, array>
     */
    public function getYearlyAggregatedData(int $storeId, ?callable $filter = null, ?array $categoryIds = null): Collection
    {
        $paymentProcessedStatusId = $this->getPaymentProcessedStatusId($storeId);

        $startDate = now()->subYears(4)->startOfYear();
        $endDate = now()->endOfYear();

        $query = Transaction::query()
            ->where('store_id', $storeId)
            ->where('status_id', $paymentProcessedStatusId)
            ->whereNotNull('payment_processed_at')
            ->whereBetween('payment_processed_at', [$startDate, $endDate])
            ->with(['items']);

        if ($filter) {
            $filter($query);
        }

        if ($categoryIds) {
            $query->whereHas('items', fn ($q) => $q->whereIn('category_id', $categoryIds));
        }

        $transactions = $query->get();
        $grouped = $transactions->groupBy(fn ($t) => Carbon::parse($t->payment_processed_at)->format('Y'));

        $years = collect();
        $current = $startDate->copy();

        while ($current <= $endDate) {
            $key = $current->format('Y');
            $yearTransactions = $grouped->get($key, collect());

            $aggregated = $this->aggregateTransactions($yearTransactions);

            $years->push([
                'date' => $current->format('Y'),
                'start_date' => $current->copy()->startOfYear()->format('Y-m-d'),
                'end_date' => $current->copy()->endOfYear()->format('Y-m-d'),
                'buys_count' => $yearTransactions->count(),
                'purchase_amt' => $aggregated['purchase_amt'],
                'estimated_value' => $aggregated['estimated_value'],
                'profit' => $aggregated['profit'],
                'profit_percent' => $aggregated['profit_percent'],
                'avg_buy_price' => $aggregated['avg_buy_price'],
            ]);

            $current->addYear();
        }

        return $years->reverse()->values();
    }

    /**
     * Calculate totals for aggregated data.
     */
    public function calculateAggregatedTotals(Collection $data): array
    {
        $buysCount = $data->sum('buys_count');
        $purchaseAmt = $data->sum('purchase_amt');
        $estimatedValue = $data->sum('estimated_value');
        $profit = $estimatedValue - $purchaseAmt;

        return [
            'buys_count' => $buysCount,
            'purchase_amt' => $purchaseAmt,
            'estimated_value' => $estimatedValue,
            'profit' => $profit,
            'profit_percent' => $purchaseAmt > 0 ? ($profit / $purchaseAmt) * 100 : 0,
            'avg_buy_price' => $buysCount > 0 ? $purchaseAmt / $buysCount : 0,
        ];
    }

    /**
     * Aggregate transactions into totals.
     */
    protected function aggregateTransactions(Collection $transactions): array
    {
        $buysCount = $transactions->count();
        $purchaseAmt = $transactions->sum('final_offer');
        $estimatedValue = $transactions->sum(fn ($t) => $t->items->sum('price'));
        $profit = $estimatedValue - $purchaseAmt;
        $profitPercent = $purchaseAmt > 0 ? ($profit / $purchaseAmt) * 100 : 0;
        $avgBuyPrice = $buysCount > 0 ? $purchaseAmt / $buysCount : 0;

        return [
            'purchase_amt' => $purchaseAmt,
            'estimated_value' => $estimatedValue,
            'profit' => $profit,
            'profit_percent' => $profitPercent,
            'avg_buy_price' => $avgBuyPrice,
        ];
    }

    /**
     * Get a human-readable label for the date range.
     */
    public function getDateRangeLabel(Carbon $startDate, Carbon $endDate): string
    {
        if ($startDate->isSameMonth($endDate)) {
            return $startDate->format('F Y');
        }

        return $startDate->format('M d, Y').' - '.$endDate->format('M d, Y');
    }
}
