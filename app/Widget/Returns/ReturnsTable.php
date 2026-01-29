<?php

namespace App\Widget\Returns;

use App\Models\ProductReturn;
use App\Services\StoreContext;
use App\Widget\Table;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

class ReturnsTable extends Table
{
    protected string $title = 'Returns';

    protected string $component = 'DataTable';

    protected bool $hasCheckBox = true;

    protected bool $isSearchable = true;

    protected string $noDataMessage = 'No returns found. Returns will appear here once created.';

    /**
     * Define the table fields/columns.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fields(): array
    {
        return [
            [
                'key' => 'return_number',
                'label' => 'Return #',
                'sortable' => true,
            ],
            [
                'key' => 'requested_at',
                'label' => 'Date',
                'sortable' => true,
            ],
            [
                'key' => 'order',
                'label' => 'Order #',
                'sortable' => false,
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
                'key' => 'refund_amount',
                'label' => 'Refund',
                'sortable' => true,
            ],
            [
                'key' => 'status',
                'label' => 'Status',
                'sortable' => true,
            ],
        ];
    }

    /**
     * Build the query for fetching returns.
     *
     * @param  array<string, mixed>|null  $filter
     */
    protected function query(?array $filter): Builder
    {
        $storeId = data_get($filter, 'store_id') ?: app(StoreContext::class)->getCurrentStoreId();

        $query = ProductReturn::query()
            ->with(['order', 'customer'])
            ->withCount('items')
            ->where('store_id', $storeId);

        // Apply search filter
        if ($term = data_get($filter, 'term')) {
            $query->where(function ($q) use ($term) {
                $q->where('return_number', 'like', "%{$term}%")
                    ->orWhereHas('order', function ($oq) use ($term) {
                        $oq->where('invoice_number', 'like', "%{$term}%");
                    })
                    ->orWhereHas('customer', function ($cq) use ($term) {
                        $cq->where('first_name', 'like', "%{$term}%")
                            ->orWhere('last_name', 'like', "%{$term}%")
                            ->orWhere('email', 'like', "%{$term}%");
                    });
            });
        }

        // Apply status filter
        if ($status = data_get($filter, 'status')) {
            $query->where('status', $status);
        }

        // Apply type filter
        if ($type = data_get($filter, 'type')) {
            $query->where('type', $type);
        }

        // Apply date range filter
        if ($dateFrom = data_get($filter, 'date_from')) {
            $query->whereDate('requested_at', '>=', $dateFrom);
        }
        if ($dateTo = data_get($filter, 'date_to')) {
            $query->whereDate('requested_at', '<=', $dateTo);
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
        $sortBy = data_get($filter, 'sortBy', 'requested_at');
        $sortDirection = data_get($filter, 'sortDirection', 'desc');

        $query->orderBy($sortBy, $sortDirection);

        // Pagination
        $page = (int) data_get($filter, 'page', 1);
        $perPage = (int) data_get($filter, 'per_page', 15);

        $this->paginatedData = $query->paginate($perPage, ['*'], 'page', $page);

        return [
            'count' => $this->paginatedData->count(),
            'total' => $this->paginatedData->total(),
            'items' => $this->paginatedData->map(fn (ProductReturn $return) => $this->formatReturn($return))->toArray(),
        ];
    }

    /**
     * Format a return for display.
     *
     * @return array<string, mixed>
     */
    protected function formatReturn(ProductReturn $return): array
    {
        $statusColors = [
            ProductReturn::STATUS_PENDING => 'warning',
            ProductReturn::STATUS_APPROVED => 'info',
            ProductReturn::STATUS_PROCESSING => 'primary',
            ProductReturn::STATUS_COMPLETED => 'success',
            ProductReturn::STATUS_REJECTED => 'danger',
            ProductReturn::STATUS_CANCELLED => 'secondary',
        ];

        $statusLabels = [
            ProductReturn::STATUS_PENDING => 'Pending',
            ProductReturn::STATUS_APPROVED => 'Approved',
            ProductReturn::STATUS_PROCESSING => 'Processing',
            ProductReturn::STATUS_COMPLETED => 'Completed',
            ProductReturn::STATUS_REJECTED => 'Rejected',
            ProductReturn::STATUS_CANCELLED => 'Cancelled',
        ];

        $typeLabels = [
            ProductReturn::TYPE_RETURN => 'Return',
            ProductReturn::TYPE_EXCHANGE => 'Exchange',
        ];

        return [
            'id' => [
                'data' => $return->id,
            ],
            'return_number' => [
                'type' => 'link',
                'href' => "/returns/{$return->id}",
                'data' => $return->return_number,
                'class' => 'font-mono text-sm font-medium',
            ],
            'requested_at' => [
                'data' => $return->requested_at ? Carbon::parse($return->requested_at)->format('M d, Y') : '-',
                'class' => 'text-sm text-gray-500',
            ],
            'order' => [
                'type' => 'link',
                'href' => $return->order ? "/orders/{$return->order->id}" : null,
                'data' => $return->order?->invoice_number ?? "Order #{$return->order_id}",
                'class' => 'font-mono text-sm',
            ],
            'customer' => [
                'type' => 'link',
                'href' => $return->customer ? "/customers/{$return->customer->id}" : null,
                'data' => $return->customer?->full_name ?? 'Unknown',
                'class' => $return->customer ? '' : 'text-gray-400 italic',
            ],
            'type' => [
                'type' => 'badge',
                'data' => $typeLabels[$return->type] ?? $return->type,
                'variant' => $return->type === ProductReturn::TYPE_EXCHANGE ? 'info' : 'secondary',
            ],
            'items_count' => [
                'data' => $return->items_count ?? 0,
                'class' => 'text-center',
            ],
            'refund_amount' => [
                'type' => 'currency',
                'data' => $return->refund_amount ?? 0,
                'currency' => 'USD',
                'class' => 'font-semibold',
            ],
            'status' => [
                'type' => 'badge',
                'data' => $statusLabels[$return->status] ?? $return->status,
                'variant' => $statusColors[$return->status] ?? 'secondary',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $filter
     */
    public function title(?array $filter): string
    {
        return 'Returns';
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
        return [
            [
                'key' => 'approve',
                'label' => 'Approve Selected',
                'icon' => 'CheckCircleIcon',
                'variant' => 'success',
                'confirm' => 'Are you sure you want to approve the selected returns?',
            ],
            [
                'key' => 'reject',
                'label' => 'Reject Selected',
                'icon' => 'XCircleIcon',
                'variant' => 'danger',
                'confirm' => 'Are you sure you want to reject the selected returns?',
            ],
            [
                'key' => 'cancel',
                'label' => 'Cancel Selected',
                'icon' => 'XMarkIcon',
                'variant' => 'warning',
                'confirm' => 'Are you sure you want to cancel the selected returns?',
            ],
        ];
    }
}
