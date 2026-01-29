<?php

namespace App\Widget\Invoices;

use App\Models\Invoice;
use App\Widget\Table;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class InvoicesTable extends Table
{
    protected string $title = 'Invoices';

    protected bool $hasCheckBox = true;

    protected bool $isSearchable = true;

    protected string $noDataMessage = 'No invoices found.';

    /**
     * Define the table columns.
     *
     * @return array<int, array<string, mixed>|string>
     */
    public function fields(): array
    {
        return [
            [
                'key' => 'invoice_number',
                'label' => 'Invoice #',
                'sortable' => true,
            ],
            [
                'key' => 'customer_name',
                'label' => 'Customer',
                'sortable' => true,
            ],
            [
                'key' => 'invoiceable_type_name',
                'label' => 'Type',
                'sortable' => false,
            ],
            [
                'key' => 'total',
                'label' => 'Total',
                'sortable' => true,
            ],
            [
                'key' => 'total_paid',
                'label' => 'Paid',
                'sortable' => true,
            ],
            [
                'key' => 'balance_due',
                'label' => 'Balance',
                'sortable' => true,
            ],
            [
                'key' => 'status',
                'label' => 'Status',
                'sortable' => true,
                'html' => ['field' => true],
            ],
            [
                'key' => 'due_date',
                'label' => 'Due Date',
                'sortable' => true,
            ],
            [
                'key' => 'actions',
                'label' => '',
                'sortable' => false,
                'html' => true,
                'width' => '100px',
            ],
        ];
    }

    /**
     * Fetch and return the invoice data.
     *
     * @param  array<string, mixed>|null  $filter
     * @return array<string, mixed>
     */
    public function data(?array $filter): array
    {
        $storeId = data_get($filter, 'store_id');
        $query = Invoice::query()
            ->with(['customer', 'invoiceable'])
            ->where('store_id', $storeId);

        // Apply search filter
        if ($term = data_get($filter, 'term')) {
            $query->where(function ($q) use ($term) {
                $q->where('invoice_number', 'like', "%{$term}%")
                    ->orWhereHas('customer', function ($customerQuery) use ($term) {
                        $customerQuery->where('first_name', 'like', "%{$term}%")
                            ->orWhere('last_name', 'like', "%{$term}%")
                            ->orWhere('email', 'like', "%{$term}%");
                    });
            });
        }

        // Apply status filter
        if ($status = data_get($filter, 'status')) {
            $query->where('status', $status);
        }

        // Apply date range filter
        if ($from = data_get($filter, 'dates.from')) {
            $query->whereDate('created_at', '>=', $from);
        }

        if ($to = data_get($filter, 'dates.to')) {
            $query->whereDate('created_at', '<=', $to);
        }

        // Apply sorting
        $sortBy = data_get($filter, 'sortBy', 'created_at');
        $sortDesc = data_get($filter, 'sortDesc', true);
        $query->orderBy($sortBy, $sortDesc ? 'desc' : 'asc');

        // Paginate
        $perPage = $this->perPage($filter, []);
        $page = data_get($filter, 'page', 1);

        $this->paginatedData = $query->paginate($perPage, ['*'], 'page', $page);

        return [
            'items' => $this->transformItems($this->paginatedData),
        ];
    }

    /**
     * Transform the paginated items for display.
     *
     * @param  LengthAwarePaginator<Invoice>  $paginator
     * @return array<int, array<string, mixed>>
     */
    protected function transformItems(LengthAwarePaginator $paginator): array
    {
        return $paginator->getCollection()->map(function (Invoice $invoice) {
            return [
                'id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'customer_name' => $invoice->customer?->display_name ?? 'N/A',
                'customer_email' => $invoice->customer?->email,
                'invoiceable_type_name' => $invoice->invoiceable_type_name,
                'total' => $this->formatCurrency($invoice->total, $invoice->currency),
                'total_paid' => $this->formatCurrency($invoice->total_paid, $invoice->currency),
                'balance_due' => $this->formatCurrency($invoice->balance_due, $invoice->currency),
                'status' => $this->formatStatus($invoice->status),
                'due_date' => $invoice->due_date?->format('M d, Y') ?? '-',
                'created_at' => $invoice->created_at->format('M d, Y'),
                'actions' => $this->buildActionsHtml($invoice),
            ];
        })->toArray();
    }

    /**
     * Format currency for display.
     */
    protected function formatCurrency(string|float $amount, string $currency = 'USD'): string
    {
        $symbol = match ($currency) {
            'USD' => '$',
            'EUR' => "\u{20AC}",
            'GBP' => "\u{00A3}",
            default => $currency.' ',
        };

        return $symbol.number_format((float) $amount, 2);
    }

    /**
     * Format status with badge HTML.
     */
    protected function formatStatus(string $status): string
    {
        $colors = [
            Invoice::STATUS_DRAFT => 'bg-gray-100 text-gray-800',
            Invoice::STATUS_PENDING => 'bg-yellow-100 text-yellow-800',
            Invoice::STATUS_PARTIAL => 'bg-blue-100 text-blue-800',
            Invoice::STATUS_PAID => 'bg-green-100 text-green-800',
            Invoice::STATUS_OVERDUE => 'bg-red-100 text-red-800',
            Invoice::STATUS_VOID => 'bg-gray-100 text-gray-500',
            Invoice::STATUS_REFUNDED => 'bg-purple-100 text-purple-800',
        ];

        $colorClass = $colors[$status] ?? 'bg-gray-100 text-gray-800';
        $label = ucfirst($status);

        return "<span class=\"inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {$colorClass}\">{$label}</span>";
    }

    /**
     * Build actions column HTML.
     */
    protected function buildActionsHtml(Invoice $invoice): string
    {
        $viewUrl = "/invoices/{$invoice->id}";
        $pdfUrl = "/api/v1/invoices/{$invoice->id}/pdf";

        return "<div class=\"flex items-center gap-3\"><a href=\"{$viewUrl}\" class=\"text-indigo-600 hover:text-indigo-900\">View</a><a href=\"{$pdfUrl}\" class=\"text-gray-600 hover:text-gray-900\" title=\"Download PDF\">PDF</a></div>";
    }

    /**
     * @param  array<string, mixed>|null  $filter
     * @param  array<string, mixed>  $filteredData
     * @return array<string, mixed>|null
     */
    protected function tableFilter(?array $filter, array $filteredData): ?array
    {
        return [
            'status' => [
                'type' => 'select',
                'label' => 'Status',
                'value' => data_get($filter, 'status'),
                'options' => [
                    ['value' => '', 'label' => 'All Statuses'],
                    ['value' => Invoice::STATUS_PENDING, 'label' => 'Pending'],
                    ['value' => Invoice::STATUS_PARTIAL, 'label' => 'Partial'],
                    ['value' => Invoice::STATUS_PAID, 'label' => 'Paid'],
                    ['value' => Invoice::STATUS_OVERDUE, 'label' => 'Overdue'],
                    ['value' => Invoice::STATUS_VOID, 'label' => 'Void'],
                    ['value' => Invoice::STATUS_REFUNDED, 'label' => 'Refunded'],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $filter
     * @param  array<string, mixed>|null  $filteredData
     * @return array<string>
     */
    public function exportExceptions(?array $filter, ?array $filteredData = null): array
    {
        return ['actions'];
    }

    /**
     * @param  array<string, mixed>|null  $filter
     * @param  array<string, mixed>  $filteredData
     */
    public function export(?array $filter, array $filteredData): ?string
    {
        return \App\Widget\Invoices\InvoicesExport::class;
    }
}
