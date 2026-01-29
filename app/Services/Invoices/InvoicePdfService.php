<?php

namespace App\Services\Invoices;

use App\Models\Invoice;
use App\Models\Memo;
use App\Models\Repair;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;

class InvoicePdfService
{
    public function generate(Invoice $invoice): \Barryvdh\DomPDF\PDF
    {
        $invoice->load(['store', 'customer', 'invoiceable', 'payments', 'user']);

        $data = $this->prepareInvoiceData($invoice);

        return Pdf::loadView('pdf.invoice', $data)
            ->setPaper('letter', 'portrait');
    }

    public function download(Invoice $invoice): Response
    {
        $pdf = $this->generate($invoice);
        $filename = $this->getFilename($invoice);

        return $pdf->download($filename);
    }

    public function stream(Invoice $invoice): Response
    {
        $pdf = $this->generate($invoice);
        $filename = $this->getFilename($invoice);

        return $pdf->stream($filename);
    }

    /**
     * @return array<string, mixed>
     */
    protected function prepareInvoiceData(Invoice $invoice): array
    {
        $store = $invoice->store;
        $customer = $invoice->customer;
        $invoiceable = $invoice->invoiceable;

        return [
            'invoice' => $invoice,
            'store' => [
                'name' => $store->business_name ?? $store->name,
                'address' => $store->address,
                'address2' => $store->address2,
                'city' => $store->city,
                'state' => $store->state,
                'zip' => $store->zip,
                'phone' => $store->phone,
                'email' => $store->customer_email ?? $store->account_email,
            ],
            'customer' => $customer ? [
                'name' => $customer->full_name,
                'email' => $customer->email,
                'phone' => $customer->phone_number,
                'address' => $customer->address,
                'address2' => $customer->address2,
                'city' => $customer->city,
                'zip' => $customer->zip,
            ] : null,
            'invoiceable' => $invoiceable,
            'invoiceableType' => $invoice->invoiceable_type_name,
            'payments' => $invoice->payments,
            'lineItems' => $this->getLineItems($invoice),
            'serviceFee' => $this->extractServiceFee($invoiceable),
        ];
    }

    /**
     * Extract service fee from the invoiceable (Order, Memo, or Repair).
     *
     * @return array{amount: float, reason: string|null}
     */
    protected function extractServiceFee($invoiceable): array
    {
        if (! $invoiceable) {
            return ['amount' => 0, 'reason' => null];
        }

        // Repair model has a simple service_fee field
        if ($invoiceable instanceof Repair) {
            return [
                'amount' => (float) ($invoiceable->service_fee ?? 0),
                'reason' => null,
            ];
        }

        // Order and Memo have service_fee_value, service_fee_unit, service_fee_reason
        $value = (float) ($invoiceable->service_fee_value ?? 0);
        $unit = $invoiceable->service_fee_unit ?? 'fixed';
        $reason = $invoiceable->service_fee_reason ?? null;

        if ($value <= 0) {
            return ['amount' => 0, 'reason' => null];
        }

        // For Memo, use pre-calculated service_fee_amount if available
        if ($invoiceable instanceof Memo && isset($invoiceable->service_fee_amount)) {
            return [
                'amount' => (float) $invoiceable->service_fee_amount,
                'reason' => $reason,
            ];
        }

        // Calculate amount based on unit type
        $amount = $value;
        if ($unit === 'percent') {
            $subtotal = (float) ($invoiceable->sub_total ?? 0);
            $amount = $subtotal * $value / 100;
        }

        return [
            'amount' => round($amount, 2),
            'reason' => $reason,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function getLineItems(Invoice $invoice): array
    {
        $invoiceable = $invoice->invoiceable;

        if (! $invoiceable) {
            return [];
        }

        $items = [];

        if (method_exists($invoiceable, 'items') || method_exists($invoiceable, 'orderItems')) {
            $relationName = method_exists($invoiceable, 'orderItems') ? 'orderItems' : 'items';
            $invoiceableItems = $invoiceable->$relationName;

            foreach ($invoiceableItems as $item) {
                $items[] = [
                    'description' => $item->title ?? $item->name ?? 'Item',
                    'quantity' => $item->quantity ?? 1,
                    'unit_price' => $item->price ?? $item->unit_price ?? 0,
                    'total' => ($item->quantity ?? 1) * ($item->price ?? $item->unit_price ?? 0),
                ];
            }
        }

        if (method_exists($invoiceable, 'services')) {
            foreach ($invoiceable->services as $service) {
                $items[] = [
                    'description' => $service->name ?? $service->description ?? 'Service',
                    'quantity' => $service->quantity ?? 1,
                    'unit_price' => $service->price ?? $service->cost ?? 0,
                    'total' => ($service->quantity ?? 1) * ($service->price ?? $service->cost ?? 0),
                ];
            }
        }

        return $items;
    }

    protected function getFilename(Invoice $invoice): string
    {
        return "invoice-{$invoice->invoice_number}.pdf";
    }
}
