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
 * Shows all TransactionItems with reviewed_at IS NULL
 * from completed transactions (payment_processed).
 * No date filter — shows ALL unreviewed items.
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
                    $this->textColumn('sku', 'SKU'),
                    $this->textColumn('title', 'Title'),
                    $this->currencyColumn('buy_price', 'Buy Price'),
                    $this->currencyColumn('estimated_value', 'Estimated Value'),
                    $this->dateColumn('date_purchased', 'Date Purchased'),
                    $this->numberColumn('days_since_purchase', 'Days Since Purchase'),
                    $this->textColumn('lead', 'Lead'),
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
        $items = TransactionItem::query()
            ->whereNull('reviewed_at')
            ->whereHas('transaction', function ($query) {
                $query->where('store_id', $this->store->id)
                    ->where('status', Transaction::STATUS_PAYMENT_PROCESSED);
            })
            ->with(['transaction.customer.leadSource'])
            ->orderBy('created_at', 'asc')
            ->get();

        $rows = $items->map(function (TransactionItem $item) {
            $transaction = $item->transaction;
            $daysSincePurchase = $transaction->payment_processed_at
                ? (int) Carbon::parse($transaction->payment_processed_at)->diffInDays($this->reportDate)
                : (int) $item->created_at->diffInDays($this->reportDate);

            return [
                'id' => $transaction->id,
                'transaction_number' => $this->formatLink(
                    $transaction->transaction_number,
                    "{$this->baseUrl}/transactions/{$transaction->id}"
                ),
                'sku' => $item->sku ?? '-',
                'title' => $item->title ?? '-',
                'buy_price' => $this->formatCurrency($item->buy_price),
                'estimated_value' => $item->price > 0
                    ? $this->formatCurrency($item->price)
                    : ['data' => 0, 'formatted' => '-'],
                'date_purchased' => $this->formatDate($transaction->payment_processed_at
                    ? Carbon::parse($transaction->payment_processed_at)
                    : $item->created_at),
                'days_since_purchase' => $daysSincePurchase,
                'lead' => $transaction->customer?->leadSource?->name ?? '-',
            ];
        })->toArray();

        if (count($rows) > 0) {
            $totalBuyPrice = $items->sum('buy_price');
            $totalEstimatedValue = $items->sum('price');

            $rows[] = [
                'id' => null,
                'transaction_number' => ['data' => 'Totals', 'href' => ''],
                'sku' => '',
                'title' => $items->count().' items',
                'buy_price' => $this->formatCurrency($totalBuyPrice),
                'estimated_value' => $totalEstimatedValue > 0
                    ? $this->formatCurrency($totalEstimatedValue)
                    : ['data' => 0, 'formatted' => '-'],
                'date_purchased' => '',
                'days_since_purchase' => '',
                'lead' => '',
                '_is_total' => true,
            ];
        }

        return $rows;
    }
}
