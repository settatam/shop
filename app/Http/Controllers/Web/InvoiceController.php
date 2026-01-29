<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Services\StoreContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class InvoiceController extends Controller
{
    public function __construct(
        protected StoreContext $storeContext,
    ) {}

    public function index(): InertiaResponse
    {
        $store = $this->storeContext->getCurrentStore();

        $invoices = Invoice::where('store_id', $store->id)
            ->with(['invoiceable', 'customer'])
            ->orderByDesc('created_at')
            ->paginate(20);

        return Inertia::render('invoices/Index', [
            'invoices' => $invoices,
            'statuses' => Invoice::getStatuses(),
        ]);
    }

    public function show(Invoice $invoice): InertiaResponse
    {
        $invoice->load(['invoiceable', 'customer', 'payments.user']);

        return Inertia::render('invoices/Show', [
            'invoice' => $invoice,
        ]);
    }

    public function streamPdf(Invoice $invoice): Response
    {
        $this->authorizeInvoiceAccess($invoice);

        $pdf = Pdf::loadView('pdf.invoice', $this->getPdfData($invoice));

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="invoice-'.$invoice->invoice_number.'.pdf"',
        ]);
    }

    public function downloadPdf(Invoice $invoice): Response
    {
        $this->authorizeInvoiceAccess($invoice);

        $pdf = Pdf::loadView('pdf.invoice', $this->getPdfData($invoice));

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="invoice-'.$invoice->invoice_number.'.pdf"',
        ]);
    }

    protected function authorizeInvoiceAccess(Invoice $invoice): void
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store || $invoice->store_id !== $store->id) {
            abort(404);
        }
    }

    protected function getPdfData(Invoice $invoice): array
    {
        $invoice->load(['invoiceable.items.product', 'customer', 'store', 'payments']);

        $store = $invoice->store;
        $customer = $invoice->customer;
        $invoiceable = $invoice->invoiceable;

        // Build line items from the invoiceable
        $lineItems = [];
        if ($invoiceable && $invoiceable->relationLoaded('items')) {
            $items = $invoiceable->items;
            foreach ($items as $item) {
                $lineItems[] = [
                    'description' => $this->getItemDescription($item),
                    'quantity' => $item->quantity ?? 1,
                    'unit_price' => $item->price ?? $item->unit_price ?? 0,
                    'total' => ($item->quantity ?? 1) * ($item->price ?? $item->unit_price ?? 0),
                ];
            }
        }

        // Determine invoiceable type name
        $invoiceableType = $invoice->invoiceable_type_name ?? 'Invoice';

        // Extract service fee from the invoiceable (Order, Memo, or Repair)
        $serviceFee = $this->extractServiceFee($invoiceable);

        // Map store fields to what the PDF view expects
        $storeData = [
            'name' => $store?->name ?? 'Store',
            'address' => $store?->address,
            'address2' => $store?->address2,
            'city' => $store?->city,
            'state' => $store?->state,
            'zip' => $store?->zip,
            'phone' => $store?->phone,
            'email' => $store?->customer_email ?? $store?->account_email,
        ];

        return [
            'invoice' => $invoice,
            'store' => $storeData,
            'customer' => $customer ? [
                'name' => trim($customer->first_name.' '.$customer->last_name) ?: $customer->company_name,
                'address' => $customer->address,
                'address2' => $customer->address2,
                'city' => $customer->city,
                'zip' => $customer->zip,
                'email' => $customer->email,
                'phone' => $customer->phone_number,
            ] : null,
            'lineItems' => $lineItems,
            'payments' => $invoice->payments ?? collect(),
            'invoiceableType' => $invoiceableType,
            'serviceFee' => $serviceFee,
        ];
    }

    protected function extractServiceFee($invoiceable): array
    {
        if (! $invoiceable) {
            return ['amount' => 0, 'reason' => null];
        }

        // Repair model has a simple service_fee field
        if ($invoiceable instanceof \App\Models\Repair) {
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
        if ($invoiceable instanceof \App\Models\Memo && isset($invoiceable->service_fee_amount)) {
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

    protected function getItemDescription($item): string
    {
        // Check for title field (MemoItem, RepairItem)
        if (! empty($item->title)) {
            return $item->title;
        }

        // Try product relationship
        if (isset($item->product) && $item->product) {
            return $item->product->name ?? 'Product';
        }

        // Try productVariant relationship
        if (isset($item->productVariant) && $item->productVariant) {
            $variant = $item->productVariant;
            $name = $variant->product?->name ?? 'Product';
            if ($variant->sku) {
                $name .= ' ('.$variant->sku.')';
            }

            return $name;
        }

        // Try description field
        if (! empty($item->description)) {
            return $item->description;
        }

        // Try name field
        if (! empty($item->name)) {
            return $item->name;
        }

        return 'Item';
    }
}
