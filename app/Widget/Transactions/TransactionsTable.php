<?php

namespace App\Widget\Transactions;

use App\Models\Status;
use App\Models\Tag;
use App\Models\Transaction;
use App\Services\StoreContext;
use App\Widget\Table;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

class TransactionsTable extends Table
{
    protected string $title = 'Transactions';

    protected string $component = 'DataTable';

    protected bool $hasCheckBox = true;

    protected bool $isSearchable = true;

    protected string $noDataMessage = 'No transactions found. Create your first buy transaction to get started.';

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
                'key' => 'customer',
                'label' => 'Customer',
                'sortable' => false,
            ],
            [
                'key' => 'type',
                'label' => 'Type',
                'sortable' => true,
            ],
            [
                'key' => 'items_count',
                'label' => 'Items',
                'sortable' => false,
            ],
            [
                'key' => 'total_value',
                'label' => 'Est. Value',
                'sortable' => false,
            ],
            [
                'key' => 'final_offer',
                'label' => 'Offer',
                'sortable' => true,
            ],
            [
                'key' => 'status',
                'label' => 'Status',
                'sortable' => true,
            ],
            [
                'key' => 'tags',
                'label' => 'Tags',
                'sortable' => false,
            ],
            [
                'key' => 'created_at',
                'label' => 'Created',
                'sortable' => true,
            ],
        ];
    }

    /**
     * Build the query for fetching transactions.
     *
     * @param  array<string, mixed>|null  $filter
     */
    protected function query(?array $filter): Builder
    {
        $storeId = data_get($filter, 'store_id') ?: app(StoreContext::class)->getCurrentStoreId();

        $query = Transaction::query()
            ->with(['customer', 'items', 'statusModel', 'tags'])
            ->withCount('items')
            ->where('store_id', $storeId);

        // Apply search filter
        if ($term = data_get($filter, 'term')) {
            $query->where(function ($q) use ($term) {
                $q->where('transaction_number', 'like', "%{$term}%")
                    ->orWhere('bin_location', 'like', "%{$term}%")
                    ->orWhereHas('customer', function ($cq) use ($term) {
                        $cq->where('first_name', 'like', "%{$term}%")
                            ->orWhere('last_name', 'like', "%{$term}%")
                            ->orWhere('email', 'like', "%{$term}%");
                    });
            });
        }

        // Apply status filter (supports both status slug and status_id)
        if ($status = data_get($filter, 'status')) {
            // First try to find by slug
            $storeId = data_get($filter, 'store_id') ?: app(StoreContext::class)->getCurrentStoreId();
            $statusModel = Status::where('store_id', $storeId)
                ->where('entity_type', 'transaction')
                ->where('slug', $status)
                ->first();

            if ($statusModel) {
                $query->where('status_id', $statusModel->id);
            } else {
                // Fallback to legacy status column
                $query->where('status', $status);
            }
        }

        // Apply type filter
        //        if ($type = data_get($filter, 'type')) {
        //            $query->where('type', $type);
        //        }
        //
        //        // Apply tag filter
        //        if ($tags = data_get($filter, 'tags')) {
        //            $tagArray = is_array($tags) ? $tags : explode(',', $tags);
        //            $query->whereHas('tags', function ($q) use ($tagArray) {
        //                $q->whereIn('tags.id', $tagArray)
        //                    ->orWhereIn('tags.name', $tagArray);
        //            });
        //        }

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
        $typeLabels = [
            Transaction::TYPE_IN_STORE => 'In-House',
            Transaction::TYPE_MAIL_IN => 'Mail-In',
        ];

        // Get status from statusModel or fall back to legacy status
        $statusName = $transaction->statusModel?->name ?? ucfirst(str_replace('_', ' ', $transaction->status ?? 'Unknown'));
        $statusColor = $transaction->statusModel?->color ?? '#6b7280';

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
            'customer' => [
                'type' => 'link',
                'href' => $transaction->customer ? "/customers/{$transaction->customer->id}" : null,
                'data' => $transaction->customer?->full_name ?? 'No Customer',
                'class' => $transaction->customer ? '' : 'text-gray-400 italic',
            ],
            'type' => [
                'type' => 'badge',
                'data' => $typeLabels[$transaction->type] ?? $transaction->type,
                'variant' => $transaction->type === Transaction::TYPE_IN_STORE ? 'info' : 'secondary',
            ],
            'items_count' => [
                'data' => $transaction->items_count ?? $transaction->items->count(),
                'class' => 'text-center',
            ],
            'total_value' => [
                'type' => 'currency',
                'data' => $transaction->total_value,
                'currency' => 'USD',
            ],
            'final_offer' => [
                'type' => 'currency',
                'data' => $transaction->final_offer ?? 0,
                'currency' => 'USD',
                'class' => $transaction->final_offer ? 'font-semibold' : 'text-gray-400',
            ],
            'status' => [
                'type' => 'status-badge',
                'data' => $statusName,
                'color' => $statusColor,
            ],
            'tags' => [
                'type' => 'tags',
                'data' => $transaction->tags->map(fn ($tag) => [
                    'id' => $tag->id,
                    'name' => $tag->name,
                    'color' => $tag->color,
                ])->toArray(),
            ],
            'created_at' => [
                'data' => Carbon::parse($transaction->created_at)->format('M d, Y'),
                'class' => 'text-sm text-gray-500',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $filter
     */
    public function title(?array $filter): string
    {
        return 'Transactions';
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
     * Get bulk actions available for the table.
     *
     * @param  array<string, mixed>|null  $filter
     * @return array<mixed>
     */
    public function actions(?array $filter): array
    {
        $storeId = data_get($filter, 'store_id') ?: app(StoreContext::class)->getCurrentStoreId();

        // Get dynamic actions based on status filter
        if ($statusSlug = data_get($filter, 'status')) {
            $status = Status::where('store_id', $storeId)
                ->where('entity_type', 'transaction')
                ->where('slug', $statusSlug)
                ->first();

            if ($status) {
                $actions = $status->getEnabledBulkActions();

                return $actions->map(function ($action) {
                    $mapped = [
                        'key' => $action->action_type,
                        'label' => $action->name,
                        'icon' => $this->mapIcon($action->icon),
                        'variant' => $this->mapColor($action->color),
                        'confirm' => $action->requires_confirmation ? $action->confirmation_message : null,
                        'config' => $action->config,
                    ];

                    return array_merge($mapped, $this->getActionHandler($action->action_type));
                })->toArray();
            }
        }

        // Default actions when no specific status is selected
        return [
            [
                'key' => 'delete',
                'label' => 'Delete Selected',
                'icon' => 'TrashIcon',
                'variant' => 'danger',
                'confirm' => 'Are you sure you want to delete the selected transactions?',
            ],
            [
                'key' => 'export',
                'label' => 'Export Selected',
                'icon' => 'ArrowDownTrayIcon',
                'variant' => 'secondary',
                'handler' => 'download',
                'url' => '/transactions/export',
            ],
        ];
    }

    /**
     * Get the handler configuration for a given action type.
     *
     * @return array<string, string>
     */
    protected function getActionHandler(string $actionType): array
    {
        return match ($actionType) {
            'print_barcode' => ['handler' => 'navigate', 'url' => '/print-labels/transactions'],
            'print_shipping_label' => ['handler' => 'navigate', 'url' => '/print-labels/shipping'],
            'print_return_label' => ['handler' => 'navigate', 'url' => '/print-labels/shipping?type=return'],
            'export' => ['handler' => 'download', 'url' => '/transactions/export'],
            default => [],
        };
    }

    /**
     * Map Heroicon name to component name.
     */
    protected function mapIcon(?string $icon): string
    {
        $iconMap = [
            'truck' => 'TruckIcon',
            'qr-code' => 'QrCodeIcon',
            'check' => 'CheckIcon',
            'check-circle' => 'CheckCircleIcon',
            'x-mark' => 'XMarkIcon',
            'pause' => 'PauseIcon',
            'trash' => 'TrashIcon',
            'arrow-down-tray' => 'ArrowDownTrayIcon',
            'paper-airplane' => 'PaperAirplaneIcon',
            'inbox-arrow-down' => 'InboxArrowDownIcon',
            'clipboard-document-check' => 'ClipboardDocumentCheckIcon',
            'currency-dollar' => 'CurrencyDollarIcon',
            'banknotes' => 'BanknotesIcon',
            'clock' => 'ClockIcon',
            'arrow-uturn-left' => 'ArrowUturnLeftIcon',
            'ban' => 'NoSymbolIcon',
        ];

        return $iconMap[$icon ?? ''] ?? 'EllipsisHorizontalIcon';
    }

    /**
     * Map color to button variant.
     */
    protected function mapColor(?string $color): string
    {
        $colorMap = [
            'green' => 'success',
            'red' => 'danger',
            'yellow' => 'warning',
            'blue' => 'info',
            'purple' => 'primary',
            'indigo' => 'primary',
            'orange' => 'warning',
            'gray' => 'secondary',
        ];

        return $colorMap[$color ?? ''] ?? 'secondary';
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

        // Get available transaction statuses
        $statuses = Status::where('store_id', $storeId)
            ->where('entity_type', 'transaction')
            ->orderBy('sort_order')
            ->get(['id', 'name', 'slug', 'color', 'icon'])
            ->map(fn ($status) => [
                'value' => $status->slug,
                'label' => $status->name,
                'color' => $status->color,
                'icon' => $status->icon,
            ])
            ->toArray();

        // Get available tags
        $tags = Tag::where('store_id', $storeId)
            ->orderBy('name')
            ->get(['id', 'name', 'color'])
            ->map(fn ($tag) => [
                'value' => (string) $tag->id,
                'label' => $tag->name,
                'color' => $tag->color,
            ])
            ->toArray();

        return [
            'current' => $filter,
            'available' => [
                'statuses' => $statuses,
                'tags' => $tags,
                'types' => [
                    ['value' => Transaction::TYPE_IN_STORE, 'label' => 'In-House'],
                    ['value' => Transaction::TYPE_MAIL_IN, 'label' => 'Mail-In'],
                ],
            ],
        ];
    }
}
