<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Services\StoreContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
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
        $invoice->load([
            'invoiceable.items.product.images',
            'invoiceable.items.product.category',
            'invoiceable.user',
            'invoiceable.customer.defaultAddress.state',
            'invoiceable.customer.addresses.state',
            'customer.defaultAddress.state',
            'customer.addresses.state',
            'store',
            'payments',
        ]);

        $store = $invoice->store;
        $invoiceable = $invoice->invoiceable;

        // Get customer - try invoice first, then fall back to invoiceable
        $customer = $invoice->customer ?? $invoiceable?->customer;

        // Generate barcode
        $barcodeBase64 = '';
        if ($invoice->invoice_number) {
            $generator = new \Picqer\Barcode\BarcodeGeneratorPNG;
            $barcodeBase64 = base64_encode($generator->getBarcode($invoice->invoice_number, $generator::TYPE_CODE_128));
        }

        // Build line items from the invoiceable
        $lineItems = [];
        if ($invoiceable && $invoiceable->relationLoaded('items')) {
            $items = $invoiceable->items;
            foreach ($items as $item) {
                $lineItems[] = [
                    'description' => $this->getItemDescription($item),
                    'sku' => $item->sku ?? $item->product?->sku ?? null,
                    'category' => $item->product?->category?->name ?? $item->category ?? null,
                    'image' => $this->getItemImageUrl($item),
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

        // Get salesperson name
        $salesperson = $invoiceable?->user?->name ?? null;

        // Get date of purchase
        $dateOfPurchase = $invoiceable?->date_of_purchase ?? $invoiceable?->created_at ?? $invoice->created_at;

        // Get payments - try invoice first, then fall back to invoiceable
        $payments = $invoice->payments;
        if ($payments->isEmpty() && $invoiceable && method_exists($invoiceable, 'payments')) {
            $invoiceable->load('payments');
            $payments = $invoiceable->payments ?? collect();
        }

        // Get primary payment method
        $primaryPaymentMethod = $payments->first()?->payment_method ?? null;
        $paymentMethodLabels = [
            'cash' => 'Cash',
            'credit_card' => 'Credit Card',
            'debit_card' => 'Debit Card',
            'check' => 'Check',
            'store_credit' => 'Store Credit',
            'gift_card' => 'Gift Card',
            'trade_in' => 'Trade-In',
            'wire' => 'Wire Transfer',
            'ach' => 'ACH Transfer',
            'paypal' => 'PayPal',
            'venmo' => 'Venmo',
            'external' => 'External',
        ];
        $primaryPaymentMethodLabel = $paymentMethodLabels[$primaryPaymentMethod]
            ?? ($primaryPaymentMethod ? ucfirst(str_replace('_', ' ', $primaryPaymentMethod)) : 'N/A');

        // Build payment modes for display
        $paymentModes = $payments->map(fn ($payment) => [
            'mode' => $paymentMethodLabels[$payment->payment_method]
                ?? ucfirst(str_replace('_', ' ', $payment->payment_method)),
            'total_paid' => $payment->amount,
        ])->toArray();

        // Get logo URL - convert to base64 for DomPDF compatibility
        $logoBase64 = null;
        if ($store?->logo) {
            try {
                $logoContents = Storage::disk('do_spaces')->get($store->logo);
                if ($logoContents) {
                    $mimeType = $this->getMimeType($store->logo);
                    $logoBase64 = 'data:'.$mimeType.';base64,'.base64_encode($logoContents);
                }
            } catch (\Exception $e) {
                // If we can't get the logo, just skip it
                $logoBase64 = null;
            }
        }

        // Map store fields to what the PDF view expects
        $storeData = [
            'name' => $store?->business_name ?? $store?->name ?? 'Store',
            'logo' => $logoBase64,
            'address' => $store?->address,
            'address2' => $store?->address2,
            'city' => $store?->city,
            'state' => $store?->state,
            'zip' => $store?->zip,
            'phone' => $store?->phone,
            'email' => $store?->customer_email ?? $store?->account_email,
        ];

        // Build customer data using defaultAddress (like OrderController)
        $customerData = null;
        if ($customer) {
            $customerAddress = $customer->defaultAddress ?? $customer->addresses->first();
            $customerData = [
                'name' => trim($customer->first_name.' '.$customer->last_name) ?: $customer->company_name,
                'first_name' => $customer->first_name,
                'last_name' => $customer->last_name,
                'company_name' => $customerAddress?->company ?? $customer->company_name,
                'address' => $customerAddress?->address ?? $customer->address,
                'address2' => $customerAddress?->address2 ?? $customer->address2,
                'city' => $customerAddress?->city ?? $customer->city,
                'state' => $customerAddress?->state_abbreviation ?? $customerAddress?->state?->abbreviation ?? $customer->state,
                'zip' => $customerAddress?->zip ?? $customer->zip,
                'email' => $customer->email,
                'phone' => $customerAddress?->phone ?? $customer->phone_number,
            ];
        }

        return [
            'invoice' => $invoice,
            'store' => $storeData,
            'customer' => $customerData,
            'lineItems' => $lineItems,
            'payments' => $invoice->payments ?? collect(),
            'invoiceableType' => $invoiceableType,
            'serviceFee' => $serviceFee,
            'barcodeBase64' => $barcodeBase64,
            'salesperson' => $salesperson,
            'dateOfPurchase' => $dateOfPurchase,
            'primaryPaymentMethod' => $primaryPaymentMethodLabel,
            'paymentModes' => $paymentModes,
        ];
    }

    protected function getItemImageUrl($item): ?string
    {
        // Try to get image from product
        if (isset($item->product) && $item->product && $item->product->relationLoaded('images')) {
            $firstImage = $item->product->images->first();
            if ($firstImage && $firstImage->url) {
                // Convert to base64 for DomPDF compatibility
                try {
                    $imageContents = @file_get_contents($firstImage->url);
                    if ($imageContents) {
                        $mimeType = $this->getMimeTypeFromUrl($firstImage->url);

                        return 'data:'.$mimeType.';base64,'.base64_encode($imageContents);
                    }
                } catch (\Exception $e) {
                    // If we can't get the image, return null
                    return null;
                }
            }
        }

        return null;
    }

    protected function getMimeType(string $path): string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match ($extension) {
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            default => 'image/jpeg',
        };
    }

    protected function getMimeTypeFromUrl(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?? '';

        return $this->getMimeType($path);
    }

    protected function extractServiceFee($invoiceable): array
    {
        if (! $invoiceable) {
            return ['amount' => 0, 'reason' => null, 'value' => 0, 'unit' => 'fixed'];
        }

        // Repair model has a simple service_fee field
        if ($invoiceable instanceof \App\Models\Repair) {
            return [
                'amount' => (float) ($invoiceable->service_fee ?? 0),
                'reason' => null,
                'value' => (float) ($invoiceable->service_fee_value ?? 0),
                'unit' => $invoiceable->service_fee_unit ?? 'fixed',
            ];
        }

        // Order and Memo have service_fee_value, service_fee_unit, service_fee_reason
        $value = (float) ($invoiceable->service_fee_value ?? 0);
        $unit = $invoiceable->service_fee_unit ?? 'fixed';
        $reason = $invoiceable->service_fee_reason ?? null;

        if ($value <= 0) {
            return ['amount' => 0, 'reason' => null, 'value' => 0, 'unit' => 'fixed'];
        }

        // For Memo, use pre-calculated service_fee_amount if available
        if ($invoiceable instanceof \App\Models\Memo && isset($invoiceable->service_fee_amount)) {
            return [
                'amount' => (float) $invoiceable->service_fee_amount,
                'reason' => $reason,
                'value' => $value,
                'unit' => $unit,
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
            'value' => $value,
            'unit' => $unit,
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
