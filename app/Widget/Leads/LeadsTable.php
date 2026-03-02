<?php

namespace App\Widget\Leads;

use App\Models\Lead;
use App\Models\Status;
use App\Models\Tag;
use App\Services\StoreContext;
use App\Widget\Table;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

class LeadsTable extends Table
{
    protected string $title = 'Leads';

    protected string $component = 'DataTable';

    protected bool $hasCheckBox = true;

    protected bool $isSearchable = true;

    protected string $noDataMessage = 'No leads found. Leads will appear here as kit requests come in.';

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fields(): array
    {
        return [
            [
                'key' => 'lead_number',
                'label' => 'ID',
                'sortable' => true,
            ],
            [
                'key' => 'created_at',
                'label' => 'Date',
                'sortable' => true,
            ],
            [
                'key' => 'image',
                'label' => 'Pictures',
                'sortable' => false,
            ],
            [
                'key' => 'customer',
                'label' => 'Customer',
                'sortable' => false,
            ],
            [
                'key' => 'final_offer',
                'label' => 'Offer',
                'sortable' => true,
            ],
            [
                'key' => 'payment_method',
                'label' => 'Payment Mode',
                'sortable' => true,
            ],
            [
                'key' => 'status',
                'label' => 'Status',
                'sortable' => true,
            ],
            [
                'key' => 'total_value',
                'label' => 'Estimated Value',
                'sortable' => false,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $filter
     */
    protected function query(?array $filter): Builder
    {
        $storeId = data_get($filter, 'store_id') ?: app(StoreContext::class)->getCurrentStoreId();

        $query = Lead::query()
            ->with(['customer', 'items.images', 'items.product.images', 'images', 'statusModel', 'tags'])
            ->withCount('items')
            ->where('store_id', $storeId);

        // Apply search filter
        if ($term = data_get($filter, 'term')) {
            $query->where(function ($q) use ($term) {
                $q->where('lead_number', 'like', "%{$term}%")
                    ->orWhere('bin_location', 'like', "%{$term}%")
                    ->orWhereHas('customer', function ($cq) use ($term) {
                        $cq->where('first_name', 'like', "%{$term}%")
                            ->orWhere('last_name', 'like', "%{$term}%")
                            ->orWhere('email', 'like', "%{$term}%");
                    });
            });
        }

        // Check status for date column selection
        $status = data_get($filter, 'status');
        $isPaymentProcessed = $status === 'payment_processed';

        // Apply date range filter
        $dateColumn = $isPaymentProcessed ? 'payment_processed_at' : 'created_at';
        if ($dateFrom = data_get($filter, 'date_from')) {
            $query->whereDate($dateColumn, '>=', $dateFrom);
        }
        if ($dateTo = data_get($filter, 'date_to')) {
            $query->whereDate($dateColumn, '<=', $dateTo);
        }

        // Apply status filter
        if ($status) {
            $storeId = data_get($filter, 'store_id') ?: app(StoreContext::class)->getCurrentStoreId();
            $statusModel = Status::where('store_id', $storeId)
                ->where('entity_type', 'lead')
                ->where('slug', $status)
                ->first();

            if ($statusModel) {
                $query->where('status_id', $statusModel->id);
            } else {
                $query->where('status', $status);
            }
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

        // Sorting
        $sortBy = data_get($filter, 'sortBy', 'id');
        $sortDirection = data_get($filter, 'sortDirection', 'desc');

        if ($sortBy === 'customer') {
            $query->leftJoin('customers', 'leads.customer_id', '=', 'customers.id')
                ->orderByRaw('COALESCE(customers.first_name, "") '.$sortDirection)
                ->select('leads.*');
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
            'items' => $this->paginatedData->map(fn (Lead $lead) => $this->formatLead($lead))->toArray(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function formatLead(Lead $lead): array
    {
        $paymentMethodLabels = [
            Lead::PAYMENT_CASH => 'Cash',
            Lead::PAYMENT_CHECK => 'Check',
            Lead::PAYMENT_STORE_CREDIT => 'Store Credit',
            Lead::PAYMENT_ACH => 'ACH',
            Lead::PAYMENT_PAYPAL => 'PayPal',
            Lead::PAYMENT_VENMO => 'Venmo',
            Lead::PAYMENT_WIRE_TRANSFER => 'Wire Transfer',
        ];

        $statusName = $lead->statusModel?->name ?? ucfirst(str_replace('_', ' ', $lead->status ?? 'Unknown'));
        $statusColor = $lead->statusModel?->color ?? '#6b7280';

        // Get first image
        $firstImage = null;
        if ($lead->images->isNotEmpty()) {
            $firstImage = $lead->images->first();
        } else {
            foreach ($lead->items as $item) {
                if ($item->images->isNotEmpty()) {
                    $firstImage = $item->images->first();
                    break;
                }
                if ($item->product && $item->product->images->isNotEmpty()) {
                    $firstImage = $item->product->images->first();
                    break;
                }
            }
        }

        return [
            'id' => [
                'data' => $lead->id,
            ],
            'lead_number' => [
                'type' => 'link',
                'href' => "/leads/{$lead->id}",
                'data' => $lead->lead_number,
                'class' => 'font-mono text-sm font-medium',
            ],
            'created_at' => [
                'data' => Carbon::parse($lead->created_at)->format('M d, Y'),
                'class' => 'text-sm text-gray-500',
            ],
            'image' => [
                'type' => 'image',
                'data' => $firstImage?->url ?? $firstImage?->path,
                'alt' => 'Lead image',
                'class' => 'size-16 rounded object-cover',
            ],
            'customer' => [
                'type' => 'link',
                'href' => $lead->customer ? "/customers/{$lead->customer->id}" : null,
                'data' => $lead->customer?->full_name ?? 'No Customer',
                'class' => $lead->customer ? '' : 'text-gray-400 italic',
            ],
            'final_offer' => [
                'type' => 'currency',
                'data' => $lead->final_offer ?? 0,
                'currency' => 'USD',
                'class' => $lead->final_offer ? 'font-semibold' : 'text-gray-400',
            ],
            'payment_method' => [
                'data' => $this->formatPaymentMethods($lead->payment_method, $paymentMethodLabels),
                'class' => 'text-sm',
            ],
            'status' => [
                'type' => 'status-badge',
                'data' => $statusName,
                'color' => $statusColor,
            ],
            'total_value' => [
                'type' => 'currency',
                'data' => $lead->total_value,
                'currency' => 'USD',
            ],
        ];
    }

    /**
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
        return 'Leads';
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
                'confirm' => 'Are you sure you want to delete the selected leads?',
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

        $statuses = Status::where('store_id', $storeId)
            ->where('entity_type', 'lead')
            ->orderBy('sort_order')
            ->get(['id', 'name', 'slug', 'color', 'icon'])
            ->map(fn ($status) => [
                'value' => $status->slug,
                'label' => $status->name,
                'color' => $status->color,
                'icon' => $status->icon,
            ])
            ->toArray();

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
            ],
        ];
    }
}
