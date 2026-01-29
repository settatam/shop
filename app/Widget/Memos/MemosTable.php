<?php

namespace App\Widget\Memos;

use App\Models\Memo;
use App\Services\StoreContext;
use App\Widget\Table;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

class MemosTable extends Table
{
    protected string $title = 'Memos';

    protected string $component = 'DataTable';

    protected bool $hasCheckBox = true;

    protected bool $isSearchable = true;

    protected string $noDataMessage = 'No memos found. Create your first consignment memo to get started.';

    /**
     * Define the table fields/columns.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fields(): array
    {
        return [
            [
                'key' => 'memo_number',
                'label' => 'Memo #',
                'sortable' => true,
            ],
            [
                'key' => 'created_at',
                'label' => 'Date',
                'sortable' => true,
            ],
            [
                'key' => 'days_out',
                'label' => 'Days Out',
                'sortable' => false,
            ],
            [
                'key' => 'vendor',
                'label' => 'Consignee',
                'sortable' => false,
            ],
            [
                'key' => 'items_count',
                'label' => 'Items',
                'sortable' => false,
            ],
            [
                'key' => 'total',
                'label' => 'Amount Expected',
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
     * Build the query for fetching memos.
     *
     * @param  array<string, mixed>|null  $filter
     */
    protected function query(?array $filter): Builder
    {
        $storeId = data_get($filter, 'store_id') ?: app(StoreContext::class)->getCurrentStoreId();

        $query = Memo::query()
            ->with(['vendor', 'items'])
            ->withCount('items')
            ->where('store_id', $storeId);

        // Apply search filter
        if ($term = data_get($filter, 'term')) {
            $query->where(function ($q) use ($term) {
                $q->where('memo_number', 'like', "%{$term}%")
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
        if ($sortBy === 'vendor') {
            $query->leftJoin('vendors', 'memos.vendor_id', '=', 'vendors.id')
                ->orderByRaw('COALESCE(vendors.name, "") '.$sortDirection)
                ->select('memos.*');
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
            'items' => $this->paginatedData->map(fn (Memo $memo) => $this->formatMemo($memo))->toArray(),
        ];
    }

    /**
     * Format a memo for display.
     *
     * @return array<string, mixed>
     */
    protected function formatMemo(Memo $memo): array
    {
        $statusColors = [
            Memo::STATUS_PENDING => 'warning',
            Memo::STATUS_SENT_TO_VENDOR => 'info',
            Memo::STATUS_VENDOR_RECEIVED => 'primary',
            Memo::STATUS_VENDOR_RETURNED => 'secondary',
            Memo::STATUS_PAYMENT_RECEIVED => 'success',
            Memo::STATUS_ARCHIVED => 'secondary',
            Memo::STATUS_CANCELLED => 'danger',
        ];

        $statusLabels = [
            Memo::STATUS_PENDING => 'Pending',
            Memo::STATUS_SENT_TO_VENDOR => 'Sent to Vendor',
            Memo::STATUS_VENDOR_RECEIVED => 'With Vendor',
            Memo::STATUS_VENDOR_RETURNED => 'Returned',
            Memo::STATUS_PAYMENT_RECEIVED => 'Paid',
            Memo::STATUS_ARCHIVED => 'Archived',
            Memo::STATUS_CANCELLED => 'Cancelled',
        ];

        $daysOut = $memo->days_with_vendor;
        $isOverdue = $memo->isOverdue();

        return [
            'id' => [
                'data' => $memo->id,
            ],
            'memo_number' => [
                'type' => 'link',
                'href' => "/memos/{$memo->id}",
                'data' => $memo->memo_number,
                'class' => 'font-mono text-sm font-medium',
            ],
            'created_at' => [
                'data' => Carbon::parse($memo->created_at)->format('M d, Y'),
                'class' => 'text-sm text-gray-500',
            ],
            'days_out' => [
                'data' => $daysOut.' '.($daysOut === 1 ? 'day' : 'days'),
                'class' => $isOverdue ? 'text-red-600 font-medium' : 'text-gray-600',
            ],
            'vendor' => [
                'type' => 'link',
                'href' => $memo->vendor ? "/vendors/{$memo->vendor->id}" : null,
                'data' => $memo->vendor?->display_name ?? $memo->vendor?->name ?? 'No Vendor',
                'class' => $memo->vendor ? '' : 'text-gray-400 italic',
            ],
            'items_count' => [
                'data' => $memo->items_count ?? $memo->items->count(),
                'class' => 'text-center',
            ],
            'total' => [
                'type' => 'currency',
                'data' => $memo->total ?? 0,
                'currency' => 'USD',
                'class' => 'font-semibold',
            ],
            'status' => [
                'type' => 'badge',
                'data' => $statusLabels[$memo->status] ?? $memo->status,
                'variant' => $statusColors[$memo->status] ?? 'secondary',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $filter
     */
    public function title(?array $filter): string
    {
        return 'Memos';
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
                'confirm' => 'Are you sure you want to delete the selected memos? Only pending memos can be deleted.',
            ],
            [
                'key' => 'cancel',
                'label' => 'Cancel Selected',
                'icon' => 'XCircleIcon',
                'variant' => 'warning',
                'confirm' => 'Are you sure you want to cancel the selected memos? All items will be returned to stock.',
            ],
        ];
    }
}
