<?php

namespace App\Services\Invoices;

use App\Models\Invoice;
use App\Models\Memo;
use App\Models\Order;
use App\Models\Repair;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Picqer\Barcode\BarcodeGeneratorPNG;

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

        // Generate barcode for invoice number
        $barcodeBase64 = $this->generateBarcode($invoice->invoice_number);

        // Get trade-ins if this is an order with a trade-in transaction
        $tradeIns = $this->getTradeIns($invoiceable);

        // Get date of purchase from invoiceable
        $dateOfPurchase = $this->getDateOfPurchase($invoiceable);

        // Get primary payment method
        $primaryPaymentMethod = $this->getPrimaryPaymentMethod($invoice);

        // Get payment modes summary
        $paymentModes = $this->getPaymentModes($invoice);

        // Get salesperson
        $salesperson = $this->getSalesperson($invoiceable);

        return [
            'invoice' => $invoice,
            'store' => [
                'name' => $store->business_name ?? $store->name,
                'logo' => $store->logo ?? null,
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
                'company_name' => $customer->company_name,
                'first_name' => $customer->first_name,
                'last_name' => $customer->last_name,
                'email' => $customer->email,
                'phone' => $customer->phone_number,
                'address' => $customer->address,
                'address2' => $customer->address2,
                'city' => $customer->city,
                'state' => $customer->state?->code ?? $customer->state,
                'zip' => $customer->zip,
            ] : null,
            'invoiceable' => $invoiceable,
            'invoiceableType' => $invoice->invoiceable_type_name,
            'payments' => $invoice->payments,
            'lineItems' => $this->getLineItems($invoice),
            'serviceFee' => $this->extractServiceFee($invoiceable),
            'barcodeBase64' => $barcodeBase64,
            'tradeIns' => $tradeIns,
            'dateOfPurchase' => $dateOfPurchase,
            'primaryPaymentMethod' => $primaryPaymentMethod,
            'paymentModes' => $paymentModes,
            'salesperson' => $salesperson,
        ];
    }

    /**
     * Generate barcode as base64 PNG.
     */
    protected function generateBarcode(string $text): string
    {
        try {
            $generator = new BarcodeGeneratorPNG;

            return base64_encode($generator->getBarcode($text, $generator::TYPE_CODE_128));
        } catch (\Exception $e) {
            return '';
        }
    }

    /**
     * Get trade-in items if this is an order with a trade-in.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function getTradeIns($invoiceable): array
    {
        if (! $invoiceable instanceof Order || ! $invoiceable->hasTradeIn()) {
            return [];
        }

        $invoiceable->load('tradeInTransaction.items');
        $transaction = $invoiceable->tradeInTransaction;

        if (! $transaction) {
            return [];
        }

        $items = [];
        foreach ($transaction->items as $item) {
            $items[] = [
                'title' => $item->title ?? 'Trade-In Item',
                'quantity' => $item->quantity ?? 1,
                'unit_cost' => $item->buy_price ?? 0,
                'price' => $item->buy_price ?? 0,
            ];
        }

        return $items;
    }

    /**
     * Get date of purchase from the invoiceable.
     */
    protected function getDateOfPurchase($invoiceable): ?\Carbon\Carbon
    {
        if (! $invoiceable) {
            return null;
        }

        if ($invoiceable instanceof Order) {
            return $invoiceable->date_of_purchase ?? $invoiceable->created_at;
        }

        return $invoiceable->created_at;
    }

    /**
     * Get primary payment method label.
     */
    protected function getPrimaryPaymentMethod(Invoice $invoice): string
    {
        $payment = $invoice->payments->first();

        if (! $payment) {
            return 'Not Paid';
        }

        return ucfirst(str_replace('_', ' ', $payment->payment_method));
    }

    /**
     * Get payment modes summary for multiple payments.
     *
     * @return array<int, array{mode: string, total_paid: float}>
     */
    protected function getPaymentModes(Invoice $invoice): array
    {
        $modes = [];

        foreach ($invoice->payments->groupBy('payment_method') as $method => $payments) {
            $modes[] = [
                'mode' => ucfirst(str_replace('_', ' ', $method)),
                'total_paid' => $payments->sum('amount'),
            ];
        }

        return $modes;
    }

    /**
     * Get salesperson name from the invoiceable.
     */
    protected function getSalesperson($invoiceable): ?string
    {
        if (! $invoiceable) {
            return null;
        }

        $invoiceable->load('user');

        return $invoiceable->user?->name;
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
            $invoiceable->load(["{$relationName}.product.images", "{$relationName}.product.category"]);
            $invoiceableItems = $invoiceable->$relationName;

            foreach ($invoiceableItems as $item) {
                $product = $item->product;
                $items[] = [
                    'description' => $item->title ?? $item->name ?? 'Item',
                    'sku' => $item->sku ?? $product?->sku ?? null,
                    'quantity' => $item->quantity ?? 1,
                    'unit_price' => $item->price ?? $item->unit_price ?? 0,
                    'total' => ($item->quantity ?? 1) * ($item->price ?? $item->unit_price ?? 0),
                    'image' => $product?->primaryImage?->url ?? null,
                    'category' => $product?->category?->name ?? null,
                ];
            }
        }

        if (method_exists($invoiceable, 'services')) {
            foreach ($invoiceable->services as $service) {
                $items[] = [
                    'description' => $service->name ?? $service->description ?? 'Service',
                    'sku' => null,
                    'quantity' => $service->quantity ?? 1,
                    'unit_price' => $service->price ?? $service->cost ?? 0,
                    'total' => ($service->quantity ?? 1) * ($service->price ?? $service->cost ?? 0),
                    'image' => null,
                    'category' => 'Service',
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
