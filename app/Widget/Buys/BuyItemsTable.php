<?php

namespace App\Widget\Buys;

use App\Models\Category;
use App\Models\Status;
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

    protected string $noDataMessage = 'No buy items found. Items appear here after a transaction reaches "Payment Processed" status.';

    /**
     * Define the table fields/columns.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fields(): array
    {
        return [
            [
                'key' => 'purchase_date',
                'label' => 'Purchase Date',
                'sortable' => true,
            ],
            [
                'key' => 'image',
                'label' => 'Picture',
                'sortable' => false,
            ],
            [
                'key' => 'transaction_number',
                'label' => 'Transaction ID',
                'sortable' => false,
            ],
            [
                'key' => 'title',
                'label' => 'Title',
                'sortable' => true,
            ],
            [
                'key' => 'est_value',
                'label' => 'Est Value',
                'sortable' => true,
            ],
            [
                'key' => 'amount_paid',
                'label' => 'Amount Paid',
                'sortable' => true,
            ],
            [
                'key' => 'profit',
                'label' => 'Profit',
                'sortable' => false,
            ],
            [
                'key' => 'customer',
                'label' => 'Customer',
                'sortable' => false,
            ],
            [
                'key' => 'payment_type',
                'label' => 'Payment Type',
                'sortable' => false,
            ],
            [
                'key' => 'type',
                'label' => 'Source',
                'sortable' => false,
            ],
            [
                'key' => 'status',
                'label' => 'Status',
                'sortable' => true,
            ],
        ];
    }

    /**
     * Build the query for fetching transaction items from "bought" transactions.
     * A transaction is considered "bought" if it has passed through "Payment Processed" status.
     *
     * @param  array<string, mixed>|null  $filter
     */
    protected function query(?array $filter): Builder
    {
        $storeId = data_get($filter, 'store_id') ?: app(StoreContext::class)->getCurrentStoreId();

        // Get the "Payment Processed" status for this store
        $paymentProcessedStatus = Status::where('store_id', $storeId)
            ->where('entity_type', 'transaction')
            ->where('slug', 'payment_processed')
            ->first();

        $query = TransactionItem::query()
            ->with(['transaction.customer', 'transaction.statusModel', 'category', 'images', 'product.images'])
            ->whereHas('transaction', function ($q) use ($storeId, $paymentProcessedStatus) {
                $q->where('store_id', $storeId);

                // Filter by payment processed status (either current status_id or legacy status field)
                if ($paymentProcessedStatus) {
                    $q->where(function ($sq) use ($paymentProcessedStatus) {
                        $sq->where('status_id', $paymentProcessedStatus->id)
                            ->orWhere('status', 'payment_processed');
                    });
                } else {
                    // Fallback to legacy status field
                    $q->where('status', 'payment_processed');
                }
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

        // Apply status filter
        if ($statusSlug = data_get($filter, 'status')) {
            $storeId = data_get($filter, 'store_id') ?: app(StoreContext::class)->getCurrentStoreId();
            $status = Status::where('store_id', $storeId)
                ->where('entity_type', 'transaction')
                ->where('slug', $statusSlug)
                ->first();

            if ($status) {
                $query->whereHas('transaction', function ($q) use ($status) {
                    $q->where('status_id', $status->id);
                });
            }
        }

        // Apply payment method filter
        if ($paymentMethod = data_get($filter, 'payment_method')) {
            $query->whereHas('transaction', function ($q) use ($paymentMethod) {
                $q->where('payment_method', $paymentMethod);
            });
        }

        // Apply transaction type filter (in_store / mail_in)
        if ($transactionType = data_get($filter, 'transaction_type')) {
            $query->whereHas('transaction', function ($q) use ($transactionType) {
                $q->where('type', $transactionType);
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

        // Apply from date filter
        if ($fromDate = data_get($filter, 'from_date')) {
            $query->whereHas('transaction', function ($q) use ($fromDate) {
                $q->whereDate('created_at', '>=', $fromDate);
            });
        }

        // Apply to date filter
        if ($toDate = data_get($filter, 'to_date')) {
            $query->whereHas('transaction', function ($q) use ($toDate) {
                $q->whereDate('created_at', '<=', $toDate);
            });
        }

        // Apply category filter (supports parent + subcategory cascading)
        $subcategoryId = data_get($filter, 'subcategory_id');
        $parentCategoryId = data_get($filter, 'parent_category_id');

        if ($subcategoryId) {
            // If subcategory is selected, filter by that specific category
            $query->where('category_id', $subcategoryId);
        } elseif ($parentCategoryId === '0' || $parentCategoryId === 0) {
            // Uncategorized items
            $query->where(function ($q) {
                $q->whereNull('category_id')->orWhere('category_id', 0);
            });
        } elseif ($parentCategoryId) {
            // If only parent category is selected, include parent and all its children
            $childCategoryIds = Category::where('parent_id', $parentCategoryId)
                ->pluck('id')
                ->toArray();
            $allCategoryIds = array_merge([$parentCategoryId], $childCategoryIds);
            $query->whereIn('category_id', $allCategoryIds);
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
        if ($sortBy === 'purchase_date') {
            $query->join('transactions', 'transactions.id', '=', 'transaction_items.transaction_id')
                ->orderBy('transactions.created_at', $sortDirection)
                ->select('transaction_items.*');
        } elseif ($sortBy === 'status') {
            $query->join('transactions as t', 't.id', '=', 'transaction_items.transaction_id')
                ->orderBy('t.status_id', $sortDirection)
                ->select('transaction_items.*');
        } elseif ($sortBy === 'amount_paid') {
            $query->orderBy('buy_price', $sortDirection);
        } elseif ($sortBy === 'est_value') {
            $query->orderBy('price', $sortDirection);
        } else {
            $query->orderBy($sortBy, $sortDirection);
        }

        // Pagination
        $page = (int) data_get($filter, 'page', 1);
        $perPage = (int) data_get($filter, 'per_page', 100);

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

        $typeLabels = [
            Transaction::TYPE_IN_STORE => 'In-Store',
            Transaction::TYPE_MAIL_IN => 'Mail-In',
        ];

        // Get status from statusModel
        $statusName = $transaction->statusModel?->name ?? ucfirst(str_replace('_', ' ', $transaction->status ?? 'Unknown'));
        $statusColor = $transaction->statusModel?->color ?? '#6b7280';

        // Calculate profit
        $estValue = (float) ($item->price ?? 0);
        $amountPaid = (float) ($item->buy_price ?? 0);
        $profit = $estValue - $amountPaid;

        // Get first image - check item images first, then product images
        $firstImage = null;
        if ($item->images->isNotEmpty()) {
            $firstImage = $item->images->first();
        } elseif ($item->product && $item->product->images->isNotEmpty()) {
            $firstImage = $item->product->images->first();
        }

        return [
            'id' => [
                'data' => $item->id,
            ],
            'purchase_date' => [
                'data' => Carbon::parse($transaction->created_at)->format('M d, Y'),
                'class' => 'text-sm text-gray-500',
            ],
            'image' => [
                'type' => 'image',
                'data' => $firstImage?->url ?? $firstImage?->path,
                'alt' => $item->title ?? 'Item image',
                'class' => 'size-16 rounded object-cover',
            ],
            'transaction_number' => [
                'type' => 'link',
                'href' => "/transactions/{$transaction->id}",
                'data' => $transaction->transaction_number,
                'class' => 'font-mono text-sm',
            ],
            'title' => [
                'type' => 'link',
                'href' => "/transactions/{$transaction->id}/items/{$item->id}",
                'data' => $item->title ?: 'Untitled Item',
                'class' => 'font-medium',
            ],
            'est_value' => [
                'type' => 'currency',
                'data' => $estValue,
                'currency' => 'USD',
            ],
            'amount_paid' => [
                'type' => 'currency',
                'data' => $amountPaid,
                'currency' => 'USD',
                'class' => 'font-semibold',
            ],
            'profit' => [
                'type' => 'currency',
                'data' => $profit,
                'currency' => 'USD',
                'class' => $profit >= 0 ? 'text-green-600' : 'text-red-600',
            ],
            'customer' => [
                'type' => 'link',
                'href' => $transaction->customer ? "/customers/{$transaction->customer->id}" : null,
                'data' => $transaction->customer?->full_name ?? 'No Customer',
                'class' => $transaction->customer ? 'text-sm' : 'text-sm text-gray-400 italic',
            ],
            'payment_type' => [
                'data' => $this->formatPaymentMethods($transaction->payment_method, $paymentMethodLabels),
                'class' => 'text-sm',
            ],
            'type' => [
                'type' => 'badge',
                'data' => $typeLabels[$transaction->type] ?? ucfirst($transaction->type ?? 'Unknown'),
                'variant' => $transaction->type === Transaction::TYPE_MAIL_IN ? 'info' : 'secondary',
            ],
            'status' => [
                'type' => 'status-badge',
                'data' => $statusName,
                'color' => $statusColor,
            ],
        ];
    }

    /**
     * Format payment methods for display (handles comma-separated values).
     *
     * @param  array<string, string>  $labels
     */
    protected function formatPaymentMethods(?string $paymentMethod, array $labels): string
    {
        if (! $paymentMethod) {
            return '-';
        }

        $methods = explode(',', $paymentMethod);

        return collect($methods)
            ->map(fn ($method) => $labels[trim($method)] ?? ucfirst(trim($method)))
            ->implode(', ');
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

        // Get parent categories (no parent_id) with their children
        $parentCategories = Category::where('store_id', $storeId)
            ->whereNull('parent_id')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($cat) => [
                'value' => (string) $cat->id,
                'label' => $cat->name,
            ])
            ->toArray();

        // Get all categories grouped by parent_id for subcategory lookup
        $childCategories = Category::where('store_id', $storeId)
            ->whereNotNull('parent_id')
            ->orderBy('name')
            ->get(['id', 'name', 'parent_id']);

        $subcategoriesByParent = $childCategories
            ->groupBy('parent_id')
            ->map(fn ($cats) => $cats->map(fn ($cat) => [
                'value' => (string) $cat->id,
                'label' => $cat->name,
            ])->toArray())
            ->toArray();

        // Map of category_id => parent_id for reconstructing category chains
        $categoryParentMap = $childCategories
            ->pluck('parent_id', 'id')
            ->map(fn ($parentId) => (string) $parentId)
            ->toArray();

        // Get transaction statuses
        $statuses = Status::where('store_id', $storeId)
            ->where('entity_type', 'transaction')
            ->orderBy('sort_order')
            ->get(['id', 'name', 'slug', 'color'])
            ->map(fn ($status) => [
                'value' => $status->slug,
                'label' => $status->name,
                'color' => $status->color,
            ])
            ->toArray();

        $types = [
            ['value' => Transaction::TYPE_IN_STORE, 'label' => 'In-Store'],
            ['value' => Transaction::TYPE_MAIL_IN, 'label' => 'Mail-In'],
        ];

        return [
            'current' => $filter,
            'available' => [
                'payment_methods' => $paymentMethods,
                'parent_categories' => $parentCategories,
                'subcategories_by_parent' => $subcategoriesByParent,
                'category_parent_map' => $categoryParentMap,
                'statuses' => $statuses,
                'types' => $types,
            ],
        ];
    }
}
