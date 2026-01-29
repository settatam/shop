<?php

namespace App\Widget\Buys;

use App\Models\Category;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Services\StoreContext;
use App\Widget\Table;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

class BuyItemsTable extends Table
{
    protected string $title = 'Buy Items';

    protected string $component = 'DataTable';

    protected bool $isSearchable = true;

    protected string $noDataMessage = 'No buy items found. Complete a buy transaction with payment to see items here.';

    /**
     * Define the table fields/columns.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fields(): array
    {
        return [
            [
                'key' => 'title',
                'label' => 'Item',
                'sortable' => true,
            ],
            [
                'key' => 'sku',
                'label' => 'SKU',
                'sortable' => true,
            ],
            [
                'key' => 'category',
                'label' => 'Category',
                'sortable' => false,
            ],
            [
                'key' => 'buy_price',
                'label' => 'Buy Price',
                'sortable' => true,
            ],
            [
                'key' => 'transaction_number',
                'label' => 'Transaction #',
                'sortable' => false,
            ],
            [
                'key' => 'customer',
                'label' => 'Customer',
                'sortable' => false,
            ],
            [
                'key' => 'payment_method',
                'label' => 'Payment Method',
                'sortable' => false,
            ],
            [
                'key' => 'paid_at',
                'label' => 'Date Paid',
                'sortable' => false,
            ],
        ];
    }

    /**
     * Build the query for fetching transaction items with completed payments.
     *
     * @param  array<string, mixed>|null  $filter
     */
    protected function query(?array $filter): Builder
    {
        $storeId = data_get($filter, 'store_id') ?: app(StoreContext::class)->getCurrentStoreId();

        $query = TransactionItem::query()
            ->with(['transaction.customer', 'category'])
            ->whereHas('transaction', function ($q) use ($storeId) {
                $q->where('store_id', $storeId)
                    ->where('status', Transaction::STATUS_PAYMENT_PROCESSED);
            });

        // Apply search filter
        if ($term = data_get($filter, 'term')) {
            $query->where(function ($q) use ($term) {
                $q->where('title', 'like', "%{$term}%")
                    ->orWhere('sku', 'like', "%{$term}%")
                    ->orWhere('description', 'like', "%{$term}%")
                    ->orWhereHas('transaction', function ($tq) use ($term) {
                        $tq->where('transaction_number', 'like', "%{$term}%");
                    })
                    ->orWhereHas('transaction.customer', function ($cq) use ($term) {
                        $cq->where('first_name', 'like', "%{$term}%")
                            ->orWhere('last_name', 'like', "%{$term}%");
                    });
            });
        }

        // Apply payment method filter
        if ($paymentMethod = data_get($filter, 'payment_method')) {
            $query->whereHas('transaction', function ($q) use ($paymentMethod) {
                $q->where('payment_method', $paymentMethod);
            });
        }

        // Apply min amount filter (by item buy_price)
        if ($minAmount = data_get($filter, 'min_amount')) {
            $query->where('buy_price', '>=', $minAmount);
        }

        // Apply max amount filter (by item buy_price)
        if ($maxAmount = data_get($filter, 'max_amount')) {
            $query->where('buy_price', '<=', $maxAmount);
        }

        // Apply from date filter (by payment_processed_at)
        if ($fromDate = data_get($filter, 'from_date')) {
            $query->whereHas('transaction', function ($q) use ($fromDate) {
                $q->whereDate('payment_processed_at', '>=', $fromDate);
            });
        }

        // Apply to date filter (by payment_processed_at)
        if ($toDate = data_get($filter, 'to_date')) {
            $query->whereHas('transaction', function ($q) use ($toDate) {
                $q->whereDate('payment_processed_at', '<=', $toDate);
            });
        }

        // Apply category filter
        if ($categoryId = data_get($filter, 'category_id')) {
            $query->where('category_id', $categoryId);
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

        $query->orderBy($sortBy, $sortDirection);

        // Pagination
        $page = (int) data_get($filter, 'page', 1);
        $perPage = (int) data_get($filter, 'per_page', 15);

        $this->paginatedData = $query->paginate($perPage, ['*'], 'page', $page);

        return [
            'count' => $this->paginatedData->count(),
            'total' => $this->paginatedData->total(),
            'items' => $this->paginatedData->map(fn (TransactionItem $item) => $this->formatItem($item))->toArray(),
        ];
    }

    /**
     * Format a transaction item for display.
     *
     * @return array<string, mixed>
     */
    protected function formatItem(TransactionItem $item): array
    {
        $transaction = $item->transaction;

        $paymentMethodLabels = [
            Transaction::PAYMENT_CASH => 'Cash',
            Transaction::PAYMENT_CHECK => 'Check',
            Transaction::PAYMENT_STORE_CREDIT => 'Store Credit',
            Transaction::PAYMENT_ACH => 'ACH',
            Transaction::PAYMENT_PAYPAL => 'PayPal',
            Transaction::PAYMENT_VENMO => 'Venmo',
            Transaction::PAYMENT_WIRE_TRANSFER => 'Wire Transfer',
        ];

        return [
            'id' => [
                'data' => $item->id,
            ],
            'title' => [
                'type' => 'link',
                'href' => "/transactions/{$transaction->id}/items/{$item->id}",
                'data' => $item->title ?: 'Untitled Item',
                'class' => 'font-medium',
            ],
            'sku' => [
                'data' => $item->sku ?: '-',
                'class' => 'font-mono text-sm text-gray-500',
            ],
            'category' => [
                'data' => $item->category?->name ?? '-',
                'class' => 'text-sm',
            ],
            'buy_price' => [
                'type' => 'currency',
                'data' => $item->buy_price ?? 0,
                'currency' => 'USD',
                'class' => 'font-semibold',
            ],
            'transaction_number' => [
                'type' => 'link',
                'href' => "/transactions/{$transaction->id}",
                'data' => $transaction->transaction_number,
                'class' => 'font-mono text-sm',
            ],
            'customer' => [
                'type' => 'link',
                'href' => $transaction->customer ? "/customers/{$transaction->customer->id}" : null,
                'data' => $transaction->customer?->full_name ?? 'No Customer',
                'class' => $transaction->customer ? 'text-sm' : 'text-sm text-gray-400 italic',
            ],
            'payment_method' => [
                'type' => 'badge',
                'data' => $paymentMethodLabels[$transaction->payment_method] ?? ucfirst($transaction->payment_method ?? 'Unknown'),
                'variant' => 'secondary',
            ],
            'paid_at' => [
                'data' => $transaction->payment_processed_at
                    ? Carbon::parse($transaction->payment_processed_at)->format('M d, Y')
                    : '-',
                'class' => 'text-sm text-gray-500',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $filter
     */
    public function title(?array $filter): string
    {
        return 'Buy Items';
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
        $storeId = data_get($filter, 'store_id') ?: app(StoreContext::class)->getCurrentStoreId();

        $paymentMethods = [
            ['value' => Transaction::PAYMENT_CASH, 'label' => 'Cash'],
            ['value' => Transaction::PAYMENT_CHECK, 'label' => 'Check'],
            ['value' => Transaction::PAYMENT_STORE_CREDIT, 'label' => 'Store Credit'],
            ['value' => Transaction::PAYMENT_ACH, 'label' => 'ACH'],
            ['value' => Transaction::PAYMENT_PAYPAL, 'label' => 'PayPal'],
            ['value' => Transaction::PAYMENT_VENMO, 'label' => 'Venmo'],
            ['value' => Transaction::PAYMENT_WIRE_TRANSFER, 'label' => 'Wire Transfer'],
        ];

        // Get categories for the store
        $categories = Category::where('store_id', $storeId)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($cat) => [
                'value' => (string) $cat->id,
                'label' => $cat->name,
            ])
            ->toArray();

        return [
            'current' => $filter,
            'available' => [
                'payment_methods' => $paymentMethods,
                'categories' => $categories,
            ],
        ];
    }
}
