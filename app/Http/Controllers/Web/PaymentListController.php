<?php

namespace App\Http\Controllers\Web;

use App\Enums\Platform;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\StoreMarketplace;
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

        // Search by reference, transaction ID, or related entity numbers (memos, repairs, orders, transactions)
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('reference', 'like', "%{$search}%")
                    ->orWhere('transaction_id', 'like', "%{$search}%")
                    ->orWhere('gateway_payment_id', 'like', "%{$search}%")
                    // Search by memo number
                    ->orWhere(function ($q2) use ($search) {
                        $q2->where('payable_type', 'App\\Models\\Memo')
                            ->whereHas('payable', function ($memoQuery) use ($search) {
                                $memoQuery->where('memo_number', 'like', "%{$search}%");
                            });
                    })
                    // Search by repair number
                    ->orWhere(function ($q2) use ($search) {
                        $q2->where('payable_type', 'App\\Models\\Repair')
                            ->whereHas('payable', function ($repairQuery) use ($search) {
                                $repairQuery->where('repair_number', 'like', "%{$search}%");
                            });
                    })
                    // Search by order id or invoice number
                    ->orWhere(function ($q2) use ($search) {
                        $q2->where('payable_type', 'App\\Models\\Order')
                            ->whereHas('payable', function ($orderQuery) use ($search) {
                                $orderQuery->where('id', 'like', "%{$search}%")
                                    ->orWhere('invoice_number', 'like', "%{$search}%")
                                    ->orWhere('order_id', 'like', "%{$search}%");
                            });
                    })
                    // Search by transaction number
                    ->orWhere(function ($q2) use ($search) {
                        $q2->where('payable_type', 'App\\Models\\Transaction')
                            ->whereHas('payable', function ($transactionQuery) use ($search) {
                                $transactionQuery->where('transaction_number', 'like', "%{$search}%");
                            });
                    });
            });
        }

        // Filter by amount range
        if ($request->filled('min_amount')) {
            $query->where('amount', '>=', $request->min_amount);
        }
        if ($request->filled('max_amount')) {
            $query->where('amount', '<=', $request->max_amount);
        }

        // Filter by platform (through payable order relationship)
        if ($request->filled('platform')) {
            $platform = $request->platform;
            $query->where(function ($q) use ($platform) {
                // Filter orders by source_platform
                $q->where(function ($q2) use ($platform) {
                    $q2->where('payable_type', 'App\\Models\\Order')
                        ->whereHas('payable', function ($orderQuery) use ($platform) {
                            $orderQuery->where('source_platform', $platform);
                        });
                })
                // Also check for payments with gateway that matches platform
                    ->orWhere('gateway', $platform);
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
        if ($request->filled('min_amount')) {
            $totalsQuery->where('amount', '>=', $request->min_amount);
        }
        if ($request->filled('max_amount')) {
            $totalsQuery->where('amount', '<=', $request->max_amount);
        }
        if ($request->filled('platform')) {
            $platform = $request->platform;
            $totalsQuery->where(function ($q) use ($platform) {
                $q->where(function ($q2) use ($platform) {
                    $q2->where('payable_type', 'App\\Models\\Order')
                        ->whereHas('payable', function ($orderQuery) use ($platform) {
                            $orderQuery->where('source_platform', $platform);
                        });
                })
                    ->orWhere('gateway', $platform);
            });
        }

        $totals = [
            'count' => $totalsQuery->count(),
            'total_amount' => $totalsQuery->sum('amount'),
            'total_fees' => $totalsQuery->sum('service_fee_amount'),
        ];

        return Inertia::render('payments/Index', [
            'payments' => $payments,
            'totals' => $totals,
            'filters' => $request->only(['payment_method', 'status', 'from_date', 'to_date', 'search', 'customer_id', 'sort', 'direction', 'min_amount', 'max_amount', 'platform']),
            'paymentMethods' => $this->getPaymentMethods(),
            'statuses' => $this->getStatuses(),
            'platforms' => $this->getPlatforms(),
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

    protected function getPlatforms(): array
    {
        $store = $this->storeContext->getCurrentStore();

        // Get enabled platforms from store_marketplaces for this store
        $enabledPlatforms = StoreMarketplace::where('store_id', $store->id)
            ->whereNotNull('platform')
            ->where('status', 'active')
            ->pluck('platform')
            ->unique()
            ->toArray();

        // Map to platform options
        return collect(Platform::cases())
            ->filter(fn (Platform $platform) => in_array($platform->value, $enabledPlatforms))
            ->map(fn (Platform $platform) => [
                'value' => $platform->value,
                'label' => $platform->label(),
            ])
            ->values()
            ->toArray();
    }
}
