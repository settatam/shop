<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\InvoiceResource;
use App\Http\Resources\TerminalCheckoutResource;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentTerminal;
use App\Services\Invoices\InvoicePdfService;
use App\Services\Invoices\InvoiceService;
use App\Services\Terminals\TerminalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class InvoiceController extends Controller
{
    public function __construct(
        protected InvoiceService $invoiceService,
        protected TerminalService $terminalService,
        protected InvoicePdfService $invoicePdfService,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Invoice::query()
            ->with(['customer', 'user', 'invoiceable', 'payments'])
            ->latest();

        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->has('customer_id')) {
            $query->where('customer_id', $request->input('customer_id'));
        }

        if ($request->has('invoiceable_type')) {
            $query->where('invoiceable_type', $request->input('invoiceable_type'));
        }

        if ($request->has('date_from')) {
            $query->whereDate('created_at', '>=', $request->input('date_from'));
        }

        if ($request->has('date_to')) {
            $query->whereDate('created_at', '<=', $request->input('date_to'));
        }

        if ($request->has('overdue')) {
            $query->overdue();
        }

        if ($request->has('unpaid')) {
            $query->unpaid();
        }

        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('invoice_number', 'like', "%{$search}%")
                    ->orWhereHas('customer', function ($customerQuery) use ($search) {
                        $customerQuery->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
            });
        }

        $invoices = $query->paginate($request->input('per_page', 15));

        return InvoiceResource::collection($invoices);
    }

    public function show(Invoice $invoice): InvoiceResource
    {
        $invoice->load(['customer', 'user', 'invoiceable', 'payments', 'terminalCheckouts.terminal']);

        return new InvoiceResource($invoice);
    }

    public function addPayment(Request $request, Invoice $invoice): JsonResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'payment_method' => ['required', 'string', 'in:cash,card,check,store_credit,bank_transfer,external,layaway'],
            'reference' => ['nullable', 'string', 'max:255'],
            'transaction_id' => ['nullable', 'string', 'max:255'],
            'gateway' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $payment = $this->invoiceService->addPayment($invoice, $validated);

        return response()->json([
            'message' => 'Payment added successfully.',
            'payment' => $payment,
            'invoice' => new InvoiceResource($invoice->fresh(['customer', 'user', 'payments'])),
        ]);
    }

    public function voidInvoice(Invoice $invoice): InvoiceResource
    {
        $invoice = $this->invoiceService->voidInvoice($invoice);

        return new InvoiceResource($invoice->load(['customer', 'user', 'invoiceable', 'payments']));
    }

    public function refundPayment(Request $request, Invoice $invoice, Payment $payment): JsonResponse
    {
        $validated = $request->validate([
            'amount' => ['nullable', 'numeric', 'min:0.01'],
        ]);

        $payment = $this->invoiceService->refundPayment($payment, $validated['amount'] ?? null);

        return response()->json([
            'message' => 'Payment refunded successfully.',
            'payment' => $payment,
            'invoice' => new InvoiceResource($invoice->fresh(['customer', 'user', 'payments'])),
        ]);
    }

    public function syncTotals(Invoice $invoice): InvoiceResource
    {
        $invoice = $this->invoiceService->syncInvoiceTotals($invoice);

        return new InvoiceResource($invoice->load(['customer', 'user', 'invoiceable', 'payments']));
    }

    public function initiateTerminalPayment(Request $request, Invoice $invoice): JsonResponse
    {
        $validated = $request->validate([
            'terminal_id' => ['required', 'exists:payment_terminals,id'],
            'amount' => ['required', 'numeric', 'min:0.01'],
        ]);

        $terminal = PaymentTerminal::findOrFail($validated['terminal_id']);

        $checkout = $this->terminalService->createCheckout(
            $invoice,
            $terminal,
            (float) $validated['amount']
        );

        return response()->json([
            'message' => 'Terminal checkout initiated. Waiting for customer payment.',
            'data' => new TerminalCheckoutResource($checkout->load('terminal')),
        ]);
    }

    public function downloadPdf(Invoice $invoice): Response
    {
        return $this->invoicePdfService->download($invoice);
    }

    public function streamPdf(Invoice $invoice): Response
    {
        return $this->invoicePdfService->stream($invoice);
    }
}
