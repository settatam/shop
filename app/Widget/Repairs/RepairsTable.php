<?php

namespace App\Widget\Repairs;

use App\Models\Repair;
use App\Services\StoreContext;
use App\Widget\Table;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

class RepairsTable extends Table
{
    protected string $title = 'Repairs';

    protected string $component = 'DataTable';

    protected bool $hasCheckBox = true;

    protected bool $isSearchable = true;

    protected string $noDataMessage = 'No repairs found. Create your first repair order to get started.';

    /**
     * Define the table fields/columns.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fields(): array
    {
        return [
            [
                'key' => 'repair_number',
                'label' => 'Repair #',
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
                'key' => 'vendor',
                'label' => 'Repair Vendor',
                'sortable' => false,
            ],
            [
                'key' => 'items_count',
                'label' => 'Items',
                'sortable' => false,
            ],
            [
                'key' => 'customer_total',
                'label' => 'Customer Cost',
                'sortable' => false,
            ],
            [
                'key' => 'vendor_total',
                'label' => 'Vendor Cost',
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
     * Build the query for fetching repairs.
     *
     * @param  array<string, mixed>|null  $filter
     */
    protected function query(?array $filter): Builder
    {
        $storeId = data_get($filter, 'store_id') ?: app(StoreContext::class)->getCurrentStoreId();

        $query = Repair::query()
            ->with(['customer', 'vendor', 'items'])
            ->withCount('items')
            ->where('store_id', $storeId);

        // Apply search filter
        if ($term = data_get($filter, 'term')) {
            $query->where(function ($q) use ($term) {
                $q->where('repair_number', 'like', "%{$term}%")
                    ->orWhereHas('customer', function ($cq) use ($term) {
                        $cq->where('first_name', 'like', "%{$term}%")
                            ->orWhere('last_name', 'like', "%{$term}%")
                            ->orWhere('email', 'like', "%{$term}%");
                    })
                    ->orWhereHas('vendor', function ($vq) use ($term) {
                        $vq->where('name', 'like', "%{$term}%")
                            ->orWhere('company_name', 'like', "%{$term}%")
                            ->orWhere('email', 'like', "%{$term}%");
                    });
            });
        }

        // Apply status filter
        if ($status = data_get($filter, 'status')) {
            $query->where('status', $status);
        }

        // Apply vendor filter
        if ($vendorId = data_get($filter, 'vendor_id')) {
            $query->where('vendor_id', $vendorId);
        }

        // Apply date range filter
        if ($dateFrom = data_get($filter, 'date_from')) {
            $query->whereDate('created_at', '>=', $dateFrom);
        }
        if ($dateTo = data_get($filter, 'date_to')) {
            $query->whereDate('created_at', '<=', $dateTo);
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
            $query->leftJoin('customers', 'repairs.customer_id', '=', 'customers.id')
                ->orderByRaw('COALESCE(CONCAT(customers.first_name, " ", customers.last_name), "") '.$sortDirection)
                ->select('repairs.*');
        } elseif ($sortBy === 'vendor') {
            $query->leftJoin('vendors', 'repairs.vendor_id', '=', 'vendors.id')
                ->orderByRaw('COALESCE(vendors.name, "") '.$sortDirection)
                ->select('repairs.*');
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
            'items' => $this->paginatedData->map(fn (Repair $repair) => $this->formatRepair($repair))->toArray(),
        ];
    }

    /**
     * Format a repair for display.
     *
     * @return array<string, mixed>
     */
    protected function formatRepair(Repair $repair): array
    {
        $statusColors = [
            Repair::STATUS_PENDING => 'warning',
            Repair::STATUS_SENT_TO_VENDOR => 'info',
            Repair::STATUS_RECEIVED_BY_VENDOR => 'primary',
            Repair::STATUS_COMPLETED => 'success',
            Repair::STATUS_PAYMENT_RECEIVED => 'success',
            Repair::STATUS_REFUNDED => 'secondary',
            Repair::STATUS_CANCELLED => 'danger',
            Repair::STATUS_ARCHIVED => 'secondary',
        ];

        $statusLabels = [
            Repair::STATUS_PENDING => 'Pending',
            Repair::STATUS_SENT_TO_VENDOR => 'Sent to Vendor',
            Repair::STATUS_RECEIVED_BY_VENDOR => 'With Vendor',
            Repair::STATUS_COMPLETED => 'Completed',
            Repair::STATUS_PAYMENT_RECEIVED => 'Paid',
            Repair::STATUS_REFUNDED => 'Refunded',
            Repair::STATUS_CANCELLED => 'Cancelled',
            Repair::STATUS_ARCHIVED => 'Archived',
        ];

        return [
            'id' => [
                'data' => $repair->id,
            ],
            'repair_number' => [
                'type' => 'link',
                'href' => "/repairs/{$repair->id}",
                'data' => $repair->repair_number,
                'class' => 'font-mono text-sm font-medium',
            ],
            'created_at' => [
                'data' => Carbon::parse($repair->created_at)->format('M d, Y'),
                'class' => 'text-sm text-gray-500',
            ],
            'customer' => [
                'type' => 'link',
                'href' => $repair->customer ? "/customers/{$repair->customer->id}" : null,
                'data' => $repair->customer?->full_name ?? 'No Customer',
                'class' => $repair->customer ? '' : 'text-gray-400 italic',
            ],
            'vendor' => [
                'type' => 'link',
                'href' => $repair->vendor ? "/vendors/{$repair->vendor->id}" : null,
                'data' => $repair->vendor?->display_name ?? $repair->vendor?->name ?? 'No Vendor',
                'class' => $repair->vendor ? '' : 'text-gray-400 italic',
            ],
            'items_count' => [
                'data' => $repair->items_count ?? $repair->items->count(),
                'class' => 'text-center',
            ],
            'customer_total' => [
                'type' => 'currency',
                'data' => $repair->customer_total ?? 0,
                'currency' => 'USD',
                'class' => 'font-semibold',
            ],
            'vendor_total' => [
                'type' => 'currency',
                'data' => $repair->vendor_total ?? 0,
                'currency' => 'USD',
                'class' => 'text-gray-600',
            ],
            'status' => [
                'type' => 'badge',
                'data' => $statusLabels[$repair->status] ?? $repair->status,
                'variant' => $statusColors[$repair->status] ?? 'secondary',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $filter
     */
    public function title(?array $filter): string
    {
        return 'Repairs';
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
                'confirm' => 'Are you sure you want to delete the selected repairs? Only pending repairs can be deleted.',
            ],
            [
                'key' => 'cancel',
                'label' => 'Cancel Selected',
                'icon' => 'XCircleIcon',
                'variant' => 'warning',
                'confirm' => 'Are you sure you want to cancel the selected repairs?',
            ],
        ];
    }
}
