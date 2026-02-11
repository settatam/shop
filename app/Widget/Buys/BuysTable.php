<?php

namespace App\Widget\Buys;

use App\Models\Transaction;
use App\Services\StoreContext;
use App\Widget\Table;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

class BuysTable extends Table
{
    protected string $title = 'Buys by Transaction';

    protected string $component = 'DataTable';

    protected bool $isSearchable = true;

    protected string $noDataMessage = 'No completed buys found. Complete a buy transaction with payment to see it here.';

    /**
     * Define the table fields/columns.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fields(): array
    {
        return [
            [
                'key' => 'transaction_number',
                'label' => 'Transaction #',
                'sortable' => true,
            ],
            [
                'key' => 'date',
                'label' => 'Date',
                'sortable' => true,
            ],
            [
                'key' => 'customer',
                'label' => 'Customer',
                'sortable' => false,
            ],
            [
                'key' => 'image',
                'label' => 'Image',
                'sortable' => false,
            ],
            [
                'key' => 'purchase_price',
                'label' => 'We Paid',
                'sortable' => true,
            ],
            [
                'key' => 'est_value',
                'label' => 'Est. Value',
                'sortable' => true,
            ],
            [
                'key' => 'est_profit',
                'label' => 'Est Profit $',
                'sortable' => false,
            ],
            [
                'key' => 'est_profit_pct',
                'label' => 'Est Profit %',
                'sortable' => false,
            ],
            [
                'key' => 'payment_method',
                'label' => 'Payment Method',
                'sortable' => true,
            ],
            [
                'key' => 'status',
                'label' => 'Status',
                'sortable' => true,
            ],
            [
                'key' => 'lead_source',
                'label' => 'Lead Source',
                'sortable' => false,
            ],
        ];
    }

    /**
     * Build the query for fetching transactions with completed payments.
     *
     * @param  array<string, mixed>|null  $filter
     */
    protected function query(?array $filter): Builder
    {
        $storeId = data_get($filter, 'store_id') ?: app(StoreContext::class)->getCurrentStoreId();

        $query = Transaction::query()
            ->with(['customer.leadSource', 'images', 'items.images', 'items.product.images'])
            ->where('store_id', $storeId)
            ->where('status', Transaction::STATUS_PAYMENT_PROCESSED);

        // Apply search filter
        if ($term = data_get($filter, 'term')) {
            $query->where(function ($q) use ($term) {
                $q->where('transaction_number', 'like', "%{$term}%")
                    ->orWhereHas('customer', function ($cq) use ($term) {
                        $cq->where('first_name', 'like', "%{$term}%")
                            ->orWhere('last_name', 'like', "%{$term}%")
                            ->orWhere('email', 'like', "%{$term}%");
                    });
            });
        }

        // Apply payment method filter
        if ($paymentMethod = data_get($filter, 'payment_method')) {
            $query->where('payment_method', $paymentMethod);
        }

        // Apply min amount filter
        if ($minAmount = data_get($filter, 'min_amount')) {
            $query->where('final_offer', '>=', $minAmount);
        }

        // Apply max amount filter
        if ($maxAmount = data_get($filter, 'max_amount')) {
            $query->where('final_offer', '<=', $maxAmount);
        }

        // Apply from date filter (use payment_processed_at if set, otherwise created_at)
        if ($fromDate = data_get($filter, 'from_date')) {
            $query->where(function ($q) use ($fromDate) {
                $q->whereDate('payment_processed_at', '>=', $fromDate)
                    ->orWhere(function ($q2) use ($fromDate) {
                        $q2->whereNull('payment_processed_at')
                            ->whereDate('created_at', '>=', $fromDate);
                    });
            });
        }

        // Apply to date filter (use payment_processed_at if set, otherwise created_at)
        if ($toDate = data_get($filter, 'to_date')) {
            $query->where(function ($q) use ($toDate) {
                $q->whereDate('payment_processed_at', '<=', $toDate)
                    ->orWhere(function ($q2) use ($toDate) {
                        $q2->whereNull('payment_processed_at')
                            ->whereDate('created_at', '<=', $toDate);
                    });
            });
        }

        return $query;
    }

    /**
     * Get the data for the table.
     *
     * @param  array<string, mixed>|null  $filter
     * @return array<string, mixed>
     */
    public function data(?array $filter): array
    {
        $query = $this->query($filter);

        // Sorting
        $sortBy = data_get($filter, 'sortBy', 'id');
        $sortDirection = data_get($filter, 'sortDirection', 'desc');

        // Handle special sort columns
        if ($sortBy === 'customer') {
            $query->leftJoin('customers', 'transactions.customer_id', '=', 'customers.id')
                ->orderByRaw('COALESCE(customers.first_name, "") '.$sortDirection)
                ->select('transactions.*');
        } elseif ($sortBy === 'purchase_price') {
            $query->orderBy('final_offer', $sortDirection);
        } elseif ($sortBy === 'est_value') {
            $query->orderBy('estimated_value', $sortDirection);
        } elseif ($sortBy === 'date') {
            // Sort by payment_processed_at if set, otherwise by created_at
            $query->orderByRaw("COALESCE(payment_processed_at, created_at) {$sortDirection}");
        } else {
            $query->orderBy($sortBy, $sortDirection);
        }

        // Pagination
        $page = (int) data_get($filter, 'page', 1);
        $perPage = (int) data_get($filter, 'per_page', 15);

        $this->paginatedData = $query->paginate($perPage, ['*'], 'page', $page);

        return [
            'count' => $this->paginatedData->count(),
            'total' => $this->paginatedData->total(),
            'items' => $this->paginatedData->map(fn (Transaction $transaction) => $this->formatTransaction($transaction))->toArray(),
        ];
    }

    /**
     * Format a transaction for display.
     *
     * @return array<string, mixed>
     */
    protected function formatTransaction(Transaction $transaction): array
    {
        $paymentMethodLabels = [
            Transaction::PAYMENT_CASH => 'Cash',
            Transaction::PAYMENT_CHECK => 'Check',
            Transaction::PAYMENT_STORE_CREDIT => 'Store Credit',
            Transaction::PAYMENT_ACH => 'ACH',
            Transaction::PAYMENT_PAYPAL => 'PayPal',
            Transaction::PAYMENT_VENMO => 'Venmo',
            Transaction::PAYMENT_WIRE_TRANSFER => 'Wire Transfer',
        ];

        $statusLabels = Transaction::getAvailableStatuses();

        // Calculate values from items
        $purchasePrice = (float) ($transaction->final_offer ?? 0);
        // Estimated value is the sum of items' price (what they're worth)
        $estValue = (float) $transaction->items->sum('price');
        $estProfit = $estValue - $purchasePrice;
        $estProfitPct = $purchasePrice > 0 ? ($estProfit / $purchasePrice) * 100 : 0;

        // Date: use payment_processed_at if set, otherwise fall back to created_at
        $transactionDate = $transaction->payment_processed_at ?? $transaction->created_at;

        // Get first image - check transaction images first (online), then item images, then product images
        $firstImage = null;
        if ($transaction->images->isNotEmpty()) {
            // Online transactions store images on the transaction
            $firstImage = $transaction->images->first();
        } else {
            // Check item images first, then product images
            foreach ($transaction->items as $item) {
                if ($item->images->isNotEmpty()) {
                    $firstImage = $item->images->first();
                    break;
                }
                // Check if item has a linked product with images
                if ($item->product && $item->product->images->isNotEmpty()) {
                    $firstImage = $item->product->images->first();
                    break;
                }
            }
        }

        return [
            'id' => [
                'data' => $transaction->id,
            ],
            'transaction_number' => [
                'type' => 'link',
                'href' => "/transactions/{$transaction->id}",
                'data' => $transaction->transaction_number,
                'class' => 'font-mono text-sm font-medium',
            ],
            'date' => [
                'data' => $transactionDate
                    ? Carbon::parse($transactionDate)->format('M d, Y')
                    : '-',
                'class' => 'text-sm text-gray-500',
            ],
            'customer' => [
                'type' => 'link',
                'href' => $transaction->customer ? "/customers/{$transaction->customer->id}" : null,
                'data' => $transaction->customer?->full_name ?? 'No Customer',
                'class' => $transaction->customer ? '' : 'text-gray-400 italic',
            ],
            'image' => [
                'type' => 'image',
                'data' => $firstImage?->url ?? $firstImage?->path,
                'alt' => 'Item image',
                'class' => 'size-10 rounded object-cover',
            ],
            'purchase_price' => [
                'type' => 'currency',
                'data' => $purchasePrice,
                'currency' => 'USD',
                'class' => 'font-semibold',
            ],
            'est_value' => [
                'type' => 'currency',
                'data' => $estValue,
                'currency' => 'USD',
            ],
            'est_profit' => [
                'type' => 'currency',
                'data' => $estProfit,
                'currency' => 'USD',
                'class' => $estProfit >= 0 ? 'text-green-600' : 'text-red-600',
            ],
            'est_profit_pct' => [
                'data' => number_format($estProfitPct, 1).'%',
                'class' => $estProfitPct >= 0 ? 'text-green-600' : 'text-red-600',
            ],
            'payment_method' => [
                'type' => 'badge',
                'data' => $paymentMethodLabels[$transaction->payment_method] ?? ucfirst($transaction->payment_method ?? 'Unknown'),
                'variant' => 'secondary',
            ],
            'status' => [
                'type' => 'badge',
                'data' => $statusLabels[$transaction->status] ?? ucfirst(str_replace('_', ' ', $transaction->status)),
                'variant' => $this->getStatusVariant($transaction->status),
            ],
            'lead_source' => [
                'data' => $transaction->customer?->leadSource?->name ?? '-',
                'class' => 'text-sm text-gray-500',
            ],
        ];
    }

    /**
     * Get badge variant for status.
     */
    protected function getStatusVariant(string $status): string
    {
        return match ($status) {
            Transaction::STATUS_PAYMENT_PROCESSED => 'success',
            Transaction::STATUS_OFFER_ACCEPTED => 'success',
            Transaction::STATUS_OFFER_GIVEN => 'warning',
            Transaction::STATUS_PENDING => 'secondary',
            Transaction::STATUS_CANCELLED => 'destructive',
            Transaction::STATUS_OFFER_DECLINED => 'destructive',
            default => 'secondary',
        };
    }

    /**
     * @param  array<string, mixed>|null  $filter
     */
    public function title(?array $filter): string
    {
        return 'Buys by Transaction';
    }

    /**
     * @param  array<string, mixed>|null  $filter
     * @param  array<string, mixed>  $filteredData
     */
    public function exportable(?array $filter, array $filteredData): bool
    {
        return true;
    }

    /**
     * Build available filters for the table.
     *
     * @param  array<string, mixed>|null  $filter
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    public function buildFilters(?array $filter, array $data): ?array
    {
        $paymentMethods = [
            ['value' => Transaction::PAYMENT_CASH, 'label' => 'Cash'],
            ['value' => Transaction::PAYMENT_CHECK, 'label' => 'Check'],
            ['value' => Transaction::PAYMENT_STORE_CREDIT, 'label' => 'Store Credit'],
            ['value' => Transaction::PAYMENT_ACH, 'label' => 'ACH'],
            ['value' => Transaction::PAYMENT_PAYPAL, 'label' => 'PayPal'],
            ['value' => Transaction::PAYMENT_VENMO, 'label' => 'Venmo'],
            ['value' => Transaction::PAYMENT_WIRE_TRANSFER, 'label' => 'Wire Transfer'],
        ];

        return [
            'current' => $filter,
            'available' => [
                'payment_methods' => $paymentMethods,
            ],
        ];
    }
}
