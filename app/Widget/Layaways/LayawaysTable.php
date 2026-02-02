<?php

namespace App\Widget\Layaways;

use App\Models\Layaway;
use App\Services\StoreContext;
use App\Widget\Table;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

class LayawaysTable extends Table
{
    protected string $title = 'Layaways';

    protected string $component = 'DataTable';

    protected bool $hasCheckBox = true;

    protected bool $isSearchable = true;

    protected string $noDataMessage = 'No layaways found. Create your first layaway to get started.';

    /**
     * Define the table fields/columns.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fields(): array
    {
        return [
            [
                'key' => 'layaway_number',
                'label' => 'Layaway #',
                'sortable' => true,
            ],
            [
                'key' => 'created_at',
                'label' => 'Date',
                'sortable' => true,
            ],
            [
                'key' => 'customer',
                'label' => 'Customer',
                'sortable' => false,
            ],
            [
                'key' => 'items_count',
                'label' => 'Items',
                'sortable' => false,
            ],
            [
                'key' => 'total',
                'label' => 'Total',
                'sortable' => true,
            ],
            [
                'key' => 'balance_due',
                'label' => 'Balance',
                'sortable' => true,
            ],
            [
                'key' => 'progress',
                'label' => 'Progress',
                'sortable' => false,
            ],
            [
                'key' => 'due_date',
                'label' => 'Due Date',
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
     * Build the query for fetching layaways.
     *
     * @param  array<string, mixed>|null  $filter
     */
    protected function query(?array $filter): Builder
    {
        $storeId = data_get($filter, 'store_id') ?: app(StoreContext::class)->getCurrentStoreId();

        $query = Layaway::query()
            ->with(['customer', 'items'])
            ->withCount('items')
            ->where('store_id', $storeId);

        // Apply search filter
        if ($term = data_get($filter, 'term')) {
            $query->where(function ($q) use ($term) {
                $q->where('layaway_number', 'like', "%{$term}%")
                    ->orWhereHas('customer', function ($cq) use ($term) {
                        $cq->where('first_name', 'like', "%{$term}%")
                            ->orWhere('last_name', 'like', "%{$term}%")
                            ->orWhere('email', 'like', "%{$term}%")
                            ->orWhere('phone', 'like', "%{$term}%");
                    });
            });
        }

        // Apply status filter
        if ($status = data_get($filter, 'status')) {
            $query->where('status', $status);
        }

        // Apply payment type filter
        if ($paymentType = data_get($filter, 'payment_type')) {
            $query->where('payment_type', $paymentType);
        }

        // Apply date range filter
        if ($dateFrom = data_get($filter, 'date_from')) {
            $query->whereDate('created_at', '>=', $dateFrom);
        }
        if ($dateTo = data_get($filter, 'date_to')) {
            $query->whereDate('created_at', '<=', $dateTo);
        }

        // Apply overdue filter
        if (data_get($filter, 'overdue')) {
            $query->where('status', Layaway::STATUS_ACTIVE)
                ->where('due_date', '<', now());
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
            $query->leftJoin('customers', 'layaways.customer_id', '=', 'customers.id')
                ->orderByRaw('COALESCE(customers.first_name, "") '.$sortDirection)
                ->select('layaways.*');
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
            'items' => $this->paginatedData->map(fn (Layaway $layaway) => $this->formatLayaway($layaway))->toArray(),
        ];
    }

    /**
     * Format a layaway for display.
     *
     * @return array<string, mixed>
     */
    protected function formatLayaway(Layaway $layaway): array
    {
        $statusColors = [
            Layaway::STATUS_PENDING => 'warning',
            Layaway::STATUS_ACTIVE => 'info',
            Layaway::STATUS_COMPLETED => 'success',
            Layaway::STATUS_CANCELLED => 'secondary',
            Layaway::STATUS_DEFAULTED => 'danger',
        ];

        $statusLabels = [
            Layaway::STATUS_PENDING => 'Pending',
            Layaway::STATUS_ACTIVE => 'Active',
            Layaway::STATUS_COMPLETED => 'Completed',
            Layaway::STATUS_CANCELLED => 'Cancelled',
            Layaway::STATUS_DEFAULTED => 'Defaulted',
        ];

        $isOverdue = $layaway->isOverdue();
        $progressPercent = $layaway->getProgressPercentage();

        return [
            'id' => [
                'data' => $layaway->id,
            ],
            'layaway_number' => [
                'type' => 'link',
                'href' => "/layaways/{$layaway->id}",
                'data' => $layaway->layaway_number,
                'class' => 'font-mono text-sm font-medium',
            ],
            'created_at' => [
                'data' => Carbon::parse($layaway->created_at)->format('M d, Y'),
                'class' => 'text-sm text-gray-500',
            ],
            'customer' => [
                'type' => 'link',
                'href' => $layaway->customer ? "/customers/{$layaway->customer->id}" : null,
                'data' => $layaway->customer?->full_name ?? 'No Customer',
                'class' => $layaway->customer ? '' : 'text-gray-400 italic',
            ],
            'items_count' => [
                'data' => $layaway->items_count ?? $layaway->items->count(),
                'class' => 'text-center',
            ],
            'total' => [
                'type' => 'currency',
                'data' => $layaway->total ?? 0,
                'currency' => 'USD',
                'class' => 'font-semibold',
            ],
            'balance_due' => [
                'type' => 'currency',
                'data' => $layaway->balance_due ?? 0,
                'currency' => 'USD',
                'class' => $layaway->balance_due > 0 ? 'font-semibold text-orange-600' : 'text-green-600',
            ],
            'progress' => [
                'type' => 'progress',
                'data' => round($progressPercent),
                'class' => $progressPercent >= 100 ? 'text-green-600' : 'text-gray-600',
            ],
            'due_date' => [
                'data' => $layaway->due_date ? Carbon::parse($layaway->due_date)->format('M d, Y') : '-',
                'class' => $isOverdue ? 'text-red-600 font-medium' : 'text-gray-600',
            ],
            'status' => [
                'type' => 'badge',
                'data' => $statusLabels[$layaway->status] ?? $layaway->status,
                'variant' => $statusColors[$layaway->status] ?? 'secondary',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $filter
     */
    public function title(?array $filter): string
    {
        return 'Layaways';
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
                'key' => 'delete',
                'label' => 'Delete Selected',
                'icon' => 'TrashIcon',
                'variant' => 'danger',
                'confirm' => 'Are you sure you want to delete the selected layaways? Only pending layaways can be deleted.',
            ],
            [
                'key' => 'cancel',
                'label' => 'Cancel Selected',
                'icon' => 'XCircleIcon',
                'variant' => 'warning',
                'confirm' => 'Are you sure you want to cancel the selected layaways? Items will be released back to inventory.',
            ],
        ];
    }
}
