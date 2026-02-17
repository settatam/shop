<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Services\StoreContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PaymentListController extends Controller
{
    public function __construct(
        protected StoreContext $storeContext,
    ) {}

    public function index(Request $request): Response
    {
        $store = $this->storeContext->getCurrentStore();

        $query = Payment::where('store_id', $store->id)
            ->with(['customer', 'user', 'payable.customer', 'invoice']);

        // Sorting
        $sortField = $request->get('sort', 'created_at');
        $sortDirection = $request->get('direction', 'desc');
        $allowedSorts = ['id', 'amount', 'payment_method', 'status', 'paid_at', 'created_at'];

        if (in_array($sortField, $allowedSorts)) {
            $query->orderBy($sortField, $sortDirection === 'asc' ? 'asc' : 'desc');
        } else {
            $query->orderByDesc('created_at');
        }

        // Filter by payment method
        if ($request->filled('payment_method')) {
            $query->where('payment_method', $request->payment_method);
        }

        // Filter by status
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Filter by date range
        if ($request->filled('from_date')) {
            $query->whereDate('paid_at', '>=', $request->from_date);
        }
        if ($request->filled('to_date')) {
            $query->whereDate('paid_at', '<=', $request->to_date);
        }

        // Filter by customer
        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }

        // Search by reference or transaction ID
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('reference', 'like', "%{$search}%")
                    ->orWhere('transaction_id', 'like', "%{$search}%")
                    ->orWhere('gateway_payment_id', 'like', "%{$search}%");
            });
        }

        $payments = $query->paginate(20)->withQueryString();

        // Transform payments to include customer from payable if not directly set
        $payments->getCollection()->transform(function ($payment) {
            if (! $payment->customer && $payment->payable && $payment->payable->customer) {
                $payment->setRelation('customer', $payment->payable->customer);
            }

            return $payment;
        });

        // Calculate totals for filtered results
        $totalsQuery = Payment::where('store_id', $store->id);
        if ($request->filled('payment_method')) {
            $totalsQuery->where('payment_method', $request->payment_method);
        }
        if ($request->filled('status')) {
            $totalsQuery->where('status', $request->status);
        }
        if ($request->filled('from_date')) {
            $totalsQuery->whereDate('paid_at', '>=', $request->from_date);
        }
        if ($request->filled('to_date')) {
            $totalsQuery->whereDate('paid_at', '<=', $request->to_date);
        }

        $totals = [
            'count' => $totalsQuery->count(),
            'total_amount' => $totalsQuery->sum('amount'),
            'total_fees' => $totalsQuery->sum('service_fee_amount'),
        ];

        return Inertia::render('payments/Index', [
            'payments' => $payments,
            'totals' => $totals,
            'filters' => $request->only(['payment_method', 'status', 'from_date', 'to_date', 'search', 'customer_id', 'sort', 'direction']),
            'paymentMethods' => $this->getPaymentMethods(),
            'statuses' => $this->getStatuses(),
        ]);
    }

    public function show(Payment $payment): Response
    {
        $payment->load(['customer', 'user', 'payable.customer', 'payable.items', 'invoice', 'terminalCheckout', 'notes.user']);

        // Use payable's customer if payment doesn't have a direct customer
        if (! $payment->customer && $payment->payable && $payment->payable->customer) {
            $payment->setRelation('customer', $payment->payable->customer);
        }

        $noteEntries = ($payment->notes ?? collect())->map(fn ($note) => [
            'id' => $note->id,
            'content' => $note->content,
            'user' => $note->user ? [
                'id' => $note->user->id,
                'name' => $note->user->name,
            ] : null,
            'created_at' => $note->created_at->toISOString(),
            'updated_at' => $note->updated_at->toISOString(),
        ]);

        return Inertia::render('payments/Show', [
            'payment' => $payment,
            'noteEntries' => $noteEntries,
        ]);
    }

    protected function getPaymentMethods(): array
    {
        return [
            ['value' => Payment::METHOD_CASH, 'label' => 'Cash'],
            ['value' => Payment::METHOD_CARD, 'label' => 'Credit/Debit Card'],
            ['value' => Payment::METHOD_CHECK, 'label' => 'Check'],
            ['value' => Payment::METHOD_BANK_TRANSFER, 'label' => 'Bank Transfer / ACH / Wire'],
            ['value' => Payment::METHOD_STORE_CREDIT, 'label' => 'Store Credit'],
            ['value' => Payment::METHOD_LAYAWAY, 'label' => 'Layaway'],
            ['value' => Payment::METHOD_EXTERNAL, 'label' => 'External Payment'],
        ];
    }

    protected function getStatuses(): array
    {
        return [
            ['value' => Payment::STATUS_PENDING, 'label' => 'Pending'],
            ['value' => Payment::STATUS_COMPLETED, 'label' => 'Completed'],
            ['value' => Payment::STATUS_FAILED, 'label' => 'Failed'],
            ['value' => Payment::STATUS_REFUNDED, 'label' => 'Refunded'],
            ['value' => Payment::STATUS_PARTIALLY_REFUNDED, 'label' => 'Partially Refunded'],
        ];
    }
}
