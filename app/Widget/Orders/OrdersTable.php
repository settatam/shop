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
                'label' => 'Order Id',
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
                'key' => 'status',
                'label' => 'Status',
                'sortable' => true,
            ],
            [
                'key' => 'marketplace',
                'label' => 'Marketplace',
                'sortable' => true,
            ],
            [
                'key' => 'qty',
                'label' => 'Qty',
                'sortable' => false,
            ],
            [
                'key' => 'cost',
                'label' => 'Cost',
                'sortable' => false,
            ],
            [
                'key' => 'sales_price',
                'label' => 'Sales Price',
                'sortable' => true,
            ],
            [
                'key' => 'profit',
                'label' => 'Profit',
                'sortable' => false,
            ],
            [
                'key' => 'tax',
                'label' => 'Tax',
                'sortable' => true,
            ],
            [
                'key' => 'delivery',
                'label' => 'Delivery',
                'sortable' => true,
            ],
            [
                'key' => 'service_fees',
                'label' => 'Service Fees',
                'sortable' => true,
            ],
            [
                'key' => 'total',
                'label' => 'Total',
                'sortable' => true,
            ],
            [
                'key' => 'payment_type',
                'label' => 'Payment Type',
                'sortable' => false,
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
            ->with(['customer', 'user', 'items.product', 'items.variant', 'payments'])
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

        // Apply date range filter
        if ($fromDate = data_get($filter, 'from_date')) {
            $query->whereDate('created_at', '>=', $fromDate);
        }
        if ($toDate = data_get($filter, 'to_date')) {
            $query->whereDate('created_at', '<=', $toDate);
        }

        // Apply marketplace/channel filter
        if ($marketplace = data_get($filter, 'marketplace')) {
            $query->where('source_platform', $marketplace);
        }

        // Apply price range filter
        if ($minPrice = data_get($filter, 'min_price')) {
            $query->where('total', '>=', $minPrice);
        }
        if ($maxPrice = data_get($filter, 'max_price')) {
            $query->where('total', '<=', $maxPrice);
        }

        // Apply payment type filter
        if ($paymentType = data_get($filter, 'payment_type')) {
            $query->whereHas('payments', function ($pq) use ($paymentType) {
                $pq->where('payment_method', $paymentType);
            });
        }

        // Apply charge tax filter
        if (($chargeTax = data_get($filter, 'charge_tax')) !== null && $chargeTax !== '') {
            if ($chargeTax === 'yes' || $chargeTax === '1' || $chargeTax === true) {
                $query->where('sales_tax', '>', 0);
            } else {
                $query->where(function ($q) {
                    $q->where('sales_tax', '=', 0)
                        ->orWhereNull('sales_tax');
                });
            }
        }

        // Apply vendor filter
        if ($vendorId = data_get($filter, 'vendor_id')) {
            $query->whereHas('items.product', function ($pq) use ($vendorId) {
                $pq->where('vendor_id', $vendorId);
            });
        }

        // Apply status filter
        if ($status = data_get($filter, 'status')) {
            $query->where('status', $status);
        }

        // Apply paid filter (filters to all paid statuses)
        if (data_get($filter, 'paid')) {
            $query->whereIn('status', Order::PAID_STATUSES);
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
        } elseif ($sortBy === 'marketplace') {
            $query->orderBy('source_platform', $sortDirection);
        } elseif ($sortBy === 'sales_price') {
            $query->orderBy('sub_total', $sortDirection);
        } elseif ($sortBy === 'tax') {
            $query->orderBy('sales_tax', $sortDirection);
        } elseif ($sortBy === 'delivery') {
            $query->orderBy('shipping_cost', $sortDirection);
        } elseif ($sortBy === 'service_fees') {
            $query->orderBy('service_fee_value', $sortDirection);
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

        // Calculate cost from items using effective cost priority
        $cost = $order->items->sum(function ($item) {
            if ($item->cost !== null && $item->cost > 0) {
                return $item->cost * $item->quantity;
            }
            $variant = $item->variant ?? $item->product?->variants?->first();
            $effectiveCost = $variant?->wholesale_price ?? $variant?->cost ?? 0;

            return $effectiveCost * $item->quantity;
        });

        $salesPrice = (float) ($order->sub_total ?? 0);
        $serviceFee = (float) ($order->service_fee_value ?? 0);
        $profit = $salesPrice + $serviceFee - $cost;

        // Get payment types
        $paymentTypes = $order->payments
            ->pluck('payment_method')
            ->unique()
            ->map(fn ($method) => ucfirst(str_replace('_', ' ', $method)))
            ->implode(', ');

        // Format marketplace
        $marketplaceLabels = [
            'pos' => 'In Store',
            'in_store' => 'In Store',
            'shopify' => 'Shopify',
            'reb' => 'REB',
            'memo' => 'Memo',
            'repair' => 'Repair',
            'website' => 'Website',
            'online' => 'Online',
        ];
        $marketplace = $marketplaceLabels[$order->source_platform ?? ''] ?? ucfirst($order->source_platform ?? 'In Store');

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
            'status' => [
                'type' => 'badge',
                'data' => $statusLabels[$order->status] ?? $order->status,
                'variant' => $statusColors[$order->status] ?? 'secondary',
            ],
            'marketplace' => [
                'data' => $marketplace,
                'class' => 'text-sm',
            ],
            'qty' => [
                'data' => $order->items_count ?? $order->items->sum('quantity'),
                'class' => 'text-center',
            ],
            'cost' => [
                'type' => 'currency',
                'data' => $cost,
                'currency' => 'USD',
                'class' => 'text-gray-600',
            ],
            'sales_price' => [
                'type' => 'currency',
                'data' => $salesPrice,
                'currency' => 'USD',
                'class' => 'text-gray-600',
            ],
            'profit' => [
                'type' => 'currency',
                'data' => $profit,
                'currency' => 'USD',
                'class' => $profit >= 0 ? 'text-green-600' : 'text-red-600',
            ],
            'tax' => [
                'type' => 'currency',
                'data' => $order->sales_tax ?? 0,
                'currency' => 'USD',
                'class' => 'text-gray-600',
            ],
            'delivery' => [
                'type' => 'currency',
                'data' => $order->shipping_cost ?? 0,
                'currency' => 'USD',
                'class' => 'text-gray-600',
            ],
            'service_fees' => [
                'type' => 'currency',
                'data' => $serviceFee,
                'currency' => 'USD',
                'class' => 'text-gray-600',
            ],
            'total' => [
                'type' => 'currency',
                'data' => $order->total ?? 0,
                'currency' => 'USD',
                'class' => 'font-semibold',
            ],
            'payment_type' => [
                'data' => $paymentTypes ?: '-',
                'class' => 'text-sm',
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
