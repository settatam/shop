<?php

namespace App\Widget\Invoices;

use App\Models\Invoice;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class InvoicesExport implements FromCollection, WithHeadings, WithMapping
{
    /**
     * @param  array<string, mixed>  $filter
     */
    public function __construct(
        protected array $filter = [],
    ) {}

    public function collection()
    {
        $storeId = data_get($this->filter, 'store_id');
        $query = Invoice::query()
            ->with(['customer'])
            ->where('store_id', $storeId);

        // Use pre-selected items if provided
        if ($items = data_get($this->filter, 'items')) {
            $query->whereIn('id', $items);
        } else {
            // Apply same filters as table
            if ($term = data_get($this->filter, 'term')) {
                $query->where(function ($q) use ($term) {
                    $q->where('invoice_number', 'like', "%{$term}%")
                        ->orWhereHas('customer', function ($customerQuery) use ($term) {
                            $customerQuery->where('first_name', 'like', "%{$term}%")
                                ->orWhere('last_name', 'like', "%{$term}%");
                        });
                });
            }

            if ($status = data_get($this->filter, 'status')) {
                $query->where('status', $status);
            }

            if ($from = data_get($this->filter, 'dates.from')) {
                $query->whereDate('created_at', '>=', $from);
            }

            if ($to = data_get($this->filter, 'dates.to')) {
                $query->whereDate('created_at', '<=', $to);
            }
        }

        return $query->orderBy('created_at', 'desc')->get();
    }

    /**
     * @return array<string>
     */
    public function headings(): array
    {
        return [
            'Invoice #',
            'Customer',
            'Email',
            'Type',
            'Subtotal',
            'Tax',
            'Shipping',
            'Discount',
            'Total',
            'Paid',
            'Balance Due',
            'Status',
            'Due Date',
            'Paid At',
            'Created At',
        ];
    }

    /**
     * @param  Invoice  $invoice
     * @return array<mixed>
     */
    public function map($invoice): array
    {
        return [
            $invoice->invoice_number,
            $invoice->customer?->display_name ?? 'N/A',
            $invoice->customer?->email ?? '',
            $invoice->invoiceable_type_name,
            $invoice->subtotal,
            $invoice->tax,
            $invoice->shipping,
            $invoice->discount,
            $invoice->total,
            $invoice->total_paid,
            $invoice->balance_due,
            ucfirst($invoice->status),
            $invoice->due_date?->format('Y-m-d') ?? '',
            $invoice->paid_at?->format('Y-m-d H:i:s') ?? '',
            $invoice->created_at->format('Y-m-d H:i:s'),
        ];
    }
}
