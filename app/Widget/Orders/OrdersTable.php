<?php

namespace App\Widget\Orders;

use App\Models\Order;
use App\Services\StoreContext;
use App\Widget\Table;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

class OrdersTable extends Table
{
    protected string $title = 'Orders';

    protected string $component = 'DataTable';

    protected bool $hasCheckBox = true;

    protected bool $isSearchable = true;

    protected string $noDataMessage = 'No orders found. Create your first order to get started.';

    /**
     * Define the table fields/columns.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fields(): array
    {
        return [
            [
                'key' => 'invoice_number',
                'label' => 'Order #',
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
                'key' => 'sub_total',
                'label' => 'Subtotal',
                'sortable' => true,
            ],
            [
                'key' => 'total',
                'label' => 'Total',
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
     * Build the query for fetching orders.
     *
     * @param  array<string, mixed>|null  $filter
     */
    protected function query(?array $filter): Builder
    {
        $storeId = data_get($filter, 'store_id') ?: app(StoreContext::class)->getCurrentStoreId();

        $query = Order::query()
            ->with(['customer', 'user'])
            ->withCount('items')
            ->where('store_id', $storeId);

        // Apply search filter
        if ($term = data_get($filter, 'term')) {
            $query->where(function ($q) use ($term) {
                $q->where('invoice_number', 'like', "%{$term}%")
                    ->orWhere('order_id', 'like', "%{$term}%")
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
            $query->leftJoin('customers', 'orders.customer_id', '=', 'customers.id')
                ->orderByRaw('COALESCE(CONCAT(customers.first_name, " ", customers.last_name), "") '.$sortDirection)
                ->select('orders.*');
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
            'items' => $this->paginatedData->map(fn (Order $order) => $this->formatOrder($order))->toArray(),
        ];
    }

    /**
     * Format an order for display.
     *
     * @return array<string, mixed>
     */
    protected function formatOrder(Order $order): array
    {
        $statusColors = [
            Order::STATUS_DRAFT => 'secondary',
            Order::STATUS_PENDING => 'warning',
            Order::STATUS_CONFIRMED => 'info',
            Order::STATUS_PROCESSING => 'primary',
            Order::STATUS_SHIPPED => 'info',
            Order::STATUS_DELIVERED => 'success',
            Order::STATUS_COMPLETED => 'success',
            Order::STATUS_CANCELLED => 'danger',
            Order::STATUS_REFUNDED => 'secondary',
            Order::STATUS_PARTIAL_PAYMENT => 'warning',
        ];

        $statusLabels = [
            Order::STATUS_DRAFT => 'Draft',
            Order::STATUS_PENDING => 'Pending',
            Order::STATUS_CONFIRMED => 'Confirmed',
            Order::STATUS_PROCESSING => 'Processing',
            Order::STATUS_SHIPPED => 'Shipped',
            Order::STATUS_DELIVERED => 'Delivered',
            Order::STATUS_COMPLETED => 'Completed',
            Order::STATUS_CANCELLED => 'Cancelled',
            Order::STATUS_REFUNDED => 'Refunded',
            Order::STATUS_PARTIAL_PAYMENT => 'Partial Payment',
        ];

        return [
            'id' => [
                'data' => $order->id,
            ],
            'invoice_number' => [
                'type' => 'link',
                'href' => "/orders/{$order->id}",
                'data' => $order->invoice_number ?? "Order #{$order->id}",
                'class' => 'font-mono text-sm font-medium',
            ],
            'created_at' => [
                'data' => Carbon::parse($order->created_at)->format('M d, Y'),
                'class' => 'text-sm text-gray-500',
            ],
            'customer' => [
                'type' => 'link',
                'href' => $order->customer ? "/customers/{$order->customer->id}" : null,
                'data' => $order->customer?->full_name ?? 'Walk-in Customer',
                'class' => $order->customer ? '' : 'text-gray-400 italic',
            ],
            'items_count' => [
                'data' => $order->items_count ?? $order->items->count(),
                'class' => 'text-center',
            ],
            'sub_total' => [
                'type' => 'currency',
                'data' => $order->sub_total ?? 0,
                'currency' => 'USD',
                'class' => 'text-gray-600',
            ],
            'total' => [
                'type' => 'currency',
                'data' => $order->total ?? 0,
                'currency' => 'USD',
                'class' => 'font-semibold',
            ],
            'status' => [
                'type' => 'badge',
                'data' => $statusLabels[$order->status] ?? $order->status,
                'variant' => $statusColors[$order->status] ?? 'secondary',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $filter
     */
    public function title(?array $filter): string
    {
        return 'Orders';
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
                'confirm' => 'Are you sure you want to delete the selected orders? Only pending or draft orders can be deleted.',
            ],
            [
                'key' => 'cancel',
                'label' => 'Cancel Selected',
                'icon' => 'XCircleIcon',
                'variant' => 'warning',
                'confirm' => 'Are you sure you want to cancel the selected orders?',
            ],
        ];
    }
}
