<?php

namespace App\Widget\Customers;

use App\Models\Customer;
use App\Models\LeadSource;
use App\Services\StoreContext;
use App\Widget\Table;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

class CustomersTable extends Table
{
    protected string $title = 'Customers';

    protected string $component = 'DataTable';

    protected bool $hasCheckBox = true;

    protected bool $isSearchable = true;

    protected string $noDataMessage = 'No customers found. Customers will appear here as they are created.';

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fields(): array
    {
        return [
            [
                'key' => 'name',
                'label' => 'Name',
                'sortable' => true,
            ],
            [
                'key' => 'id_photo',
                'label' => 'ID',
                'sortable' => false,
            ],
            [
                'key' => 'email',
                'label' => 'Email',
                'sortable' => true,
            ],
            [
                'key' => 'phone',
                'label' => 'Phone',
                'sortable' => false,
            ],
            [
                'key' => 'lead_source',
                'label' => 'Lead Source',
                'sortable' => false,
            ],
            [
                'key' => 'purchased',
                'label' => 'Purchased',
                'sortable' => false,
            ],
            [
                'key' => 'sold',
                'label' => 'Sold',
                'sortable' => false,
            ],
            [
                'key' => 'total',
                'label' => 'Total $',
                'sortable' => false,
            ],
            [
                'key' => 'last_activity',
                'label' => 'Last Activity',
                'sortable' => false,
            ],
            [
                'key' => 'created_at',
                'label' => 'Joined',
                'sortable' => true,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $filter
     */
    protected function query(?array $filter): Builder
    {
        $storeId = data_get($filter, 'store_id') ?: app(StoreContext::class)->getCurrentStoreId();

        $query = Customer::query()
            ->with(['leadSource', 'idFront'])
            ->withCount(['orders as items_purchased_count' => function ($q) {
                $q->whereHas('items');
            }])
            ->withCount(['transactions as items_sold_count' => function ($q) {
                $q->whereHas('items');
            }])
            ->withSum('orders', 'total')
            ->withSum('transactions', 'final_offer')
            ->where('store_id', $storeId);

        if ($term = data_get($filter, 'term')) {
            $query->where(function ($q) use ($term) {
                $q->where('first_name', 'like', "%{$term}%")
                    ->orWhere('last_name', 'like', "%{$term}%")
                    ->orWhere('email', 'like', "%{$term}%")
                    ->orWhere('phone_number', 'like', "%{$term}%")
                    ->orWhere('company_name', 'like', "%{$term}%");
            });
        }

        if ($leadSourceId = data_get($filter, 'lead_source_id')) {
            $query->where('lead_source_id', $leadSourceId);
        }

        if ($dateFrom = data_get($filter, 'date_from')) {
            $query->whereDate('created_at', '>=', $dateFrom);
        }

        if ($dateTo = data_get($filter, 'date_to')) {
            $query->whereDate('created_at', '<=', $dateTo);
        }

        return $query;
    }

    /**
     * @param  array<string, mixed>|null  $filter
     * @return array<string, mixed>
     */
    public function data(?array $filter): array
    {
        $query = $this->query($filter);

        $sortBy = data_get($filter, 'sortBy', 'id');
        $sortDirection = data_get($filter, 'sortDirection', 'desc');

        if ($sortBy === 'name') {
            $query->orderByRaw('COALESCE(first_name, "") '.$sortDirection)
                ->orderByRaw('COALESCE(last_name, "") '.$sortDirection);
        } else {
            $query->orderBy($sortBy, $sortDirection);
        }

        $page = (int) data_get($filter, 'page', 1);
        $perPage = (int) data_get($filter, 'per_page', 100);

        $this->paginatedData = $query->paginate($perPage, ['*'], 'page', $page);

        return [
            'count' => $this->paginatedData->count(),
            'total' => $this->paginatedData->total(),
            'items' => $this->paginatedData->map(fn (Customer $customer) => $this->formatCustomer($customer))->toArray(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function formatCustomer(Customer $customer): array
    {
        $lastTransaction = $customer->transactions()->latest()->first();
        $lastOrder = $customer->orders()->latest()->first();

        $lastActivityDate = null;
        if ($lastTransaction && $lastOrder) {
            $lastActivityDate = $lastTransaction->created_at->gt($lastOrder->created_at)
                ? $lastTransaction->created_at
                : $lastOrder->created_at;
        } elseif ($lastTransaction) {
            $lastActivityDate = $lastTransaction->created_at;
        } elseif ($lastOrder) {
            $lastActivityDate = $lastOrder->created_at;
        }

        $totalSales = (float) ($customer->orders_sum_total ?? 0);
        $totalBuys = (float) ($customer->transactions_sum_final_offer ?? 0);

        return [
            'id' => [
                'data' => $customer->id,
            ],
            'name' => [
                'type' => 'link',
                'href' => "/customers/{$customer->id}",
                'data' => $customer->full_name ?: 'Unnamed Customer',
                'subtitle' => $customer->company_name,
            ],
            'id_photo' => [
                'type' => 'image',
                'data' => $customer->idFront?->path,
                'alt' => 'Customer ID',
                'class' => 'size-10 rounded object-cover',
            ],
            'email' => [
                'data' => $customer->email ?? '-',
                'class' => 'text-sm text-gray-500',
            ],
            'phone' => [
                'data' => $customer->phone_number ?? '-',
                'class' => 'text-sm text-gray-500',
            ],
            'lead_source' => [
                'type' => 'badge',
                'data' => $customer->leadSource?->name ?? '-',
            ],
            'purchased' => [
                'data' => $customer->items_purchased_count ?? 0,
                'class' => 'text-center',
            ],
            'sold' => [
                'data' => $customer->items_sold_count ?? 0,
                'class' => 'text-center',
            ],
            'total' => [
                'type' => 'currency',
                'data' => $totalSales + $totalBuys,
                'currency' => 'USD',
            ],
            'last_activity' => [
                'data' => $lastActivityDate ? Carbon::parse($lastActivityDate)->format('M d, Y') : '-',
                'class' => 'text-sm text-gray-500',
            ],
            'created_at' => [
                'data' => Carbon::parse($customer->created_at)->format('M d, Y'),
                'class' => 'text-sm text-gray-500',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $filter
     */
    public function title(?array $filter): string
    {
        return 'Customers';
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
                'confirm' => 'Are you sure you want to delete the selected customers?',
            ],
            [
                'key' => 'export_csv',
                'label' => 'Export CSV',
                'icon' => 'ArrowDownTrayIcon',
                'handler' => 'download',
                'url' => '/customers/export/csv',
            ],
            [
                'key' => 'export_quickbooks',
                'label' => 'Export QuickBooks',
                'icon' => 'ArrowDownTrayIcon',
                'handler' => 'download',
                'url' => '/customers/export/quickbooks-selected',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $filter
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    public function buildFilters(?array $filter, array $data): ?array
    {
        $storeId = data_get($filter, 'store_id') ?: app(StoreContext::class)->getCurrentStoreId();

        $leadSources = LeadSource::where('store_id', $storeId)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get(['id', 'name', 'slug'])
            ->map(fn ($source) => [
                'value' => (string) $source->id,
                'label' => $source->name,
            ])
            ->toArray();

        return [
            'current' => $filter,
            'available' => [
                'lead_sources' => $leadSources,
            ],
        ];
    }
}
