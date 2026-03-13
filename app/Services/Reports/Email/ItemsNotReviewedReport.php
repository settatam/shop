<?php

namespace App\Services\Reports\Email;

use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Services\Reports\AbstractReport;
use App\Services\Reports\ReportStructure;
use Carbon\Carbon;

/**
 * Items Not Reviewed Report.
 *
 * Shows TransactionItems with reviewed_at IS NULL from completed
 * transactions (payment_processed) within the last month.
 * Sorted by transaction_id descending.
 */
class ItemsNotReviewedReport extends AbstractReport
{
    protected string $baseUrl;

    public function __construct($store, ?Carbon $reportDate = null, ?string $baseUrl = null)
    {
        parent::__construct($store, $reportDate);
        $this->baseUrl = $baseUrl ?? config('app.url');
    }

    public function getType(): string
    {
        return 'items_not_reviewed';
    }

    public function getName(): string
    {
        return 'Items Not Reviewed Report';
    }

    public function getSlug(): string
    {
        return 'items-not-reviewed-report';
    }

    protected function defineStructure(): ReportStructure
    {
        return $this->structure()
            ->setTitle('{{ date }}')
            ->addTable(
                name: 'items_not_reviewed',
                heading: 'Items Not Reviewed',
                columns: [
                    $this->linkColumn('transaction_number', 'Transaction #', '/transactions/{id}'),
                    $this->dateColumn('date_purchased', 'Date of Purchase'),
                    $this->textColumn('title', 'Title'),
                    $this->numberColumn('quantity', 'Qty'),
                    $this->currencyColumn('amount', 'Amount'),
                    $this->currencyColumn('line_total', 'Line Total'),
                ],
                dataKey: 'items_not_reviewed'
            )
            ->setMetadata([
                'report_type' => 'items_not_reviewed',
                'store_id' => $this->store->id,
            ]);
    }

    public function getData(): array
    {
        return [
            'date' => $this->getTitleWithDate('Items Not Reviewed Report'),
            'store' => $this->store,
            'items_not_reviewed' => $this->getUnreviewedItems(),
        ];
    }

    protected function getUnreviewedItems(): array
    {
        $oneMonthAgo = $this->reportDate->copy()->subMonth()->startOfDay();

        $items = TransactionItem::query()
            ->whereNull('reviewed_at')
            ->whereHas('transaction', function ($query) use ($oneMonthAgo) {
                $query->where('store_id', $this->store->id)
                    ->where('status', Transaction::STATUS_PAYMENT_PROCESSED)
                    ->where('payment_processed_at', '>=', $oneMonthAgo);
            })
            ->with(['transaction'])
            ->orderBy('transaction_id', 'desc')
            ->get();

        $rows = $items->map(function (TransactionItem $item) {
            $transaction = $item->transaction;
            $lineTotal = ($item->buy_price ?? 0) * ($item->quantity ?? 1);

            return [
                'id' => $transaction->id,
                'transaction_number' => $this->formatLink(
                    $transaction->transaction_number,
                    "{$this->baseUrl}/transactions/{$transaction->id}"
                ),
                'date_purchased' => $this->formatDate($transaction->payment_processed_at
                    ? Carbon::parse($transaction->payment_processed_at)
                    : $item->created_at),
                'title' => $item->title ?? '-',
                'quantity' => $item->quantity ?? 1,
                'amount' => $this->formatCurrency($item->buy_price),
                'line_total' => $this->formatCurrency($lineTotal),
            ];
        })->toArray();

        if (count($rows) > 0) {
            $totalAmount = $items->sum('buy_price');
            $totalLineTotal = $items->sum(fn ($item) => ($item->buy_price ?? 0) * ($item->quantity ?? 1));

            $rows[] = [
                'id' => null,
                'transaction_number' => ['data' => 'Totals', 'href' => ''],
                'date_purchased' => $items->count().' items',
                'title' => '',
                'quantity' => $items->sum('quantity'),
                'amount' => $this->formatCurrency($totalAmount),
                'line_total' => $this->formatCurrency($totalLineTotal),
                '_is_total' => true,
            ];
        }

        return $rows;
    }
}
