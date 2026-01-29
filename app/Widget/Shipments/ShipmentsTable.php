<?php

namespace App\Widget\Shipments;

use App\Models\Order;
use App\Models\ShippingLabel;
use App\Services\StoreContext;
use App\Widget\Table;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

class ShipmentsTable extends Table
{
    protected string $title = 'Shipments';

    protected string $component = 'DataTable';

    protected bool $hasCheckBox = true;

    protected bool $isSearchable = true;

    protected string $noDataMessage = 'No shipments found. Shipping labels will appear here once created.';

    /**
     * Define the table fields/columns.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fields(): array
    {
        return [
            [
                'key' => 'tracking_number',
                'label' => 'Tracking #',
                'sortable' => true,
            ],
            [
                'key' => 'created_at',
                'label' => 'Date',
                'sortable' => true,
            ],
            [
                'key' => 'carrier',
                'label' => 'Carrier',
                'sortable' => true,
            ],
            [
                'key' => 'service_type',
                'label' => 'Service',
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
                'key' => 'shipping_cost',
                'label' => 'Cost',
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
     * Build the query for fetching shipping labels.
     *
     * @param  array<string, mixed>|null  $filter
     */
    protected function query(?array $filter): Builder
    {
        $storeId = data_get($filter, 'store_id') ?: app(StoreContext::class)->getCurrentStoreId();

        $query = ShippingLabel::query()
            ->where('store_id', $storeId)
            ->where('type', ShippingLabel::TYPE_OUTBOUND)
            ->where('shippable_type', Order::class);

        // Apply search filter
        if ($term = data_get($filter, 'term')) {
            $query->where(function ($q) use ($term) {
                $q->where('tracking_number', 'like', "%{$term}%")
                    ->orWhereHas('shippable', function ($oq) use ($term) {
                        $oq->where('invoice_number', 'like', "%{$term}%")
                            ->orWhereHas('customer', function ($cq) use ($term) {
                                $cq->where('first_name', 'like', "%{$term}%")
                                    ->orWhere('last_name', 'like', "%{$term}%")
                                    ->orWhere('email', 'like', "%{$term}%");
                            });
                    });
            });
        }

        // Apply status filter
        if ($status = data_get($filter, 'status')) {
            $query->where('status', $status);
        }

        // Apply carrier filter
        if ($carrier = data_get($filter, 'carrier')) {
            $query->where('carrier', $carrier);
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
        $sortBy = data_get($filter, 'sortBy', 'created_at');
        $sortDirection = data_get($filter, 'sortDirection', 'desc');

        $query->orderBy($sortBy, $sortDirection);

        // Pagination
        $page = (int) data_get($filter, 'page', 1);
        $perPage = (int) data_get($filter, 'per_page', 15);

        $this->paginatedData = $query
            ->with(['shippable.customer'])
            ->paginate($perPage, ['*'], 'page', $page);

        return [
            'count' => $this->paginatedData->count(),
            'total' => $this->paginatedData->total(),
            'items' => $this->paginatedData->map(fn (ShippingLabel $label) => $this->formatLabel($label))->toArray(),
        ];
    }

    /**
     * Format a shipping label for display.
     *
     * @return array<string, mixed>
     */
    protected function formatLabel(ShippingLabel $label): array
    {
        $statusColors = [
            ShippingLabel::STATUS_CREATED => 'info',
            ShippingLabel::STATUS_IN_TRANSIT => 'primary',
            ShippingLabel::STATUS_DELIVERED => 'success',
            ShippingLabel::STATUS_VOIDED => 'secondary',
        ];

        $statusLabels = [
            ShippingLabel::STATUS_CREATED => 'Created',
            ShippingLabel::STATUS_IN_TRANSIT => 'In Transit',
            ShippingLabel::STATUS_DELIVERED => 'Delivered',
            ShippingLabel::STATUS_VOIDED => 'Voided',
        ];

        $carrierLabels = ShippingLabel::getCarriers();

        /** @var Order|null $order */
        $order = $label->shippable;

        return [
            'id' => [
                'data' => $label->id,
            ],
            'tracking_number' => [
                'type' => 'link',
                'href' => $label->getTrackingUrl(),
                'data' => $label->tracking_number ?? 'N/A',
                'class' => 'font-mono text-sm font-medium',
                'external' => true,
            ],
            'created_at' => [
                'data' => Carbon::parse($label->created_at)->format('M d, Y'),
                'class' => 'text-sm text-gray-500',
            ],
            'carrier' => [
                'type' => 'badge',
                'data' => $carrierLabels[$label->carrier] ?? ucfirst($label->carrier ?? 'Unknown'),
                'variant' => 'info',
            ],
            'service_type' => [
                'data' => $label->service_type ?? '-',
                'class' => 'text-sm text-gray-600',
            ],
            'order' => [
                'type' => 'link',
                'href' => $order ? "/orders/{$order->id}" : null,
                'data' => $order?->invoice_number ?? "Order #{$order?->id}" ?? 'N/A',
                'class' => 'font-mono text-sm',
            ],
            'customer' => [
                'type' => 'link',
                'href' => $order?->customer ? "/customers/{$order->customer->id}" : null,
                'data' => $order?->customer?->full_name ?? 'Walk-in Customer',
                'class' => $order?->customer ? '' : 'text-gray-400 italic',
            ],
            'shipping_cost' => [
                'type' => 'currency',
                'data' => $label->shipping_cost ?? 0,
                'currency' => 'USD',
                'class' => 'text-gray-600',
            ],
            'status' => [
                'type' => 'badge',
                'data' => $statusLabels[$label->status] ?? $label->status,
                'variant' => $statusColors[$label->status] ?? 'secondary',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $filter
     */
    public function title(?array $filter): string
    {
        return 'Shipments';
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
                'key' => 'void',
                'label' => 'Void Selected',
                'icon' => 'XCircleIcon',
                'variant' => 'danger',
                'confirm' => 'Are you sure you want to void the selected shipping labels? This action may not be reversible with the carrier.',
            ],
        ];
    }
}
