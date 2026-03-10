<?php

namespace App\Http\Controllers;

use App\Contracts\Payable;
use App\Http\Requests\ProcessPaymentRequest;
use App\Http\Requests\UpdatePaymentAdjustmentsRequest;
use App\Models\Activity;
use App\Models\ActivityLog;
use App\Models\Layaway;
use App\Models\Memo;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentTerminal;
use App\Models\Repair;
use App\Models\TerminalCheckout;
use App\Services\Gateways\PaymentGatewayFactory;
use App\Services\PaymentService;
use App\Services\StoreContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    /**
     * Map of model type strings to model classes.
     *
     * @var array<string, class-string<Payable>>
     */
    protected array $modelTypes = [
        'layaway' => Layaway::class,
        'layaways' => Layaway::class,
        'memo' => Memo::class,
        'memos' => Memo::class,
        'order' => Order::class,
        'orders' => Order::class,
        'repair' => Repair::class,
        'repairs' => Repair::class,
        'appraisal' => Repair::class,
        'appraisals' => Repair::class,
    ];

    public function __construct(
        protected PaymentService $paymentService,
        protected StoreContext $storeContext,
        protected PaymentGatewayFactory $gatewayFactory,
    ) {}

    /**
     * Get payment summary for a payable.
     */
    public function summary(Request $request, int $id, string $type): JsonResponse
    {
        $payable = $this->resolvePayable($type, $id);

        $adjustments = $request->only([
            'discount_value',
            'discount_unit',
            'service_fee_value',
            'service_fee_unit',
            'charge_taxes',
            'tax_rate',
            'tax_type',
            'shipping_cost',
        ]);

        $summary = $this->paymentService->calculateSummary($payable, $adjustments);

        return response()->json([
            'summary' => $summary,
            $type => [
                'id' => $payable->id,
                'identifier' => $payable->getDisplayIdentifier(),
                'can_receive_payment' => $payable->canReceivePayment(),
            ],
        ]);
    }

    /**
     * Update payment adjustments.
     */
    public function updateAdjustments(UpdatePaymentAdjustmentsRequest $request, int $id, string $type): JsonResponse
    {
        $payable = $this->resolvePayable($type, $id);

        if (! $payable->canReceivePayment()) {
            return response()->json([
                'message' => 'This '.$type.' cannot receive payment in its current state.',
            ], 422);
        }

        $payable = $this->paymentService->updateAdjustments($payable, $request->validated());

        return response()->json([
            'message' => 'Payment adjustments updated successfully.',
            $type => $payable,
            'summary' => $this->paymentService->calculateSummary($payable),
        ]);
    }

    /**
     * Process payment(s) for a payable.
     */
    public function processPayment(ProcessPaymentRequest $request, int $id, string $type): JsonResponse
    {
        $payable = $this->resolvePayable($type, $id);

        if (! $payable->canReceivePayment() && ! $payable->hasPayments()) {
            return response()->json([
                'message' => 'This '.$type.' cannot receive payment in its current state.',
            ], 422);
        }

        $payments = $request->getPayments();

        $result = $this->paymentService->processPayments(
            $payable,
            $payments,
            auth()->id()
        );

        $paymentsCount = count($result['payments']);
        $message = $result['is_fully_paid']
            ? 'Payment completed. Balance has been fully paid.'
            : ($paymentsCount > 1
                ? "{$paymentsCount} payments recorded successfully."
                : 'Payment recorded successfully.');

        return response()->json([
            'message' => $message,
            'payment' => $result['payment'],
            'payments' => $result['payments'],
            $type => $result['payable'],
            'is_fully_paid' => $result['is_fully_paid'],
        ]);
    }

    /**
     * Get payment history for a payable.
     */
    public function paymentHistory(int $id, string $type): JsonResponse
    {
        $payable = $this->resolvePayable($type, $id);

        $payments = $this->paymentService->getPaymentHistory($payable);

        return response()->json([
            'payments' => $payments,
            'summary' => $this->paymentService->calculateSummary($payable),
        ]);
    }

    /**
     * Initiate a terminal checkout for a payable.
     * For Dejavoo terminals, this blocks until the customer completes the transaction.
     */
    public function terminalCheckout(Request $request, int $id, string $type): JsonResponse
    {
        $payable = $this->resolvePayable($type, $id);

        if (! $payable->canReceivePayment()) {
            return response()->json([
                'message' => 'This '.$type.' cannot receive payment in its current state.',
            ], 422);
        }

        $validated = $request->validate([
            'terminal_id' => ['required', 'exists:payment_terminals,id'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'service_fee_value' => ['nullable', 'numeric', 'min:0'],
            'service_fee_unit' => ['nullable', 'string', 'in:fixed,percent'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $terminal = PaymentTerminal::findOrFail($validated['terminal_id']);
        $store = $this->storeContext->getCurrentStore();

        if ($terminal->store_id !== $store->id) {
            return response()->json([
                'message' => 'Terminal does not belong to this store.',
            ], 403);
        }

        if (! $terminal->isActive()) {
            return response()->json([
                'message' => 'Terminal is not active.',
            ], 422);
        }

        $amount = (float) $validated['amount'];

        $gateway = $this->gatewayFactory->makeTerminal($terminal->gateway);
        $timeout = config('payment-gateways.terminal.default_timeout', 300);

        // Allow the request to run long enough for the terminal to complete
        set_time_limit($timeout + 60);

        $result = $gateway->createCheckout($terminal, $amount, [
            'timeout' => $timeout,
            'reference' => $payable->getDisplayIdentifier(),
            'customer_id' => $payable->customer_id ?? null,
        ]);

        if (! $result->success) {
            // Record the failed attempt
            TerminalCheckout::create([
                'store_id' => $store->id,
                'payable_type' => get_class($payable),
                'payable_id' => $payable->id,
                'terminal_id' => $terminal->id,
                'user_id' => auth()->id(),
                'checkout_id' => 'failed_'.uniqid(),
                'amount' => $amount,
                'currency' => 'USD',
                'status' => TerminalCheckout::STATUS_FAILED,
                'error_message' => $result->errorMessage,
                'timeout_seconds' => $timeout,
                'expires_at' => now(),
                'gateway_response' => $result->gatewayResponse,
                'metadata' => [
                    'service_fee_value' => $validated['service_fee_value'] ?? null,
                    'service_fee_unit' => $validated['service_fee_unit'] ?? null,
                    'notes' => $validated['notes'] ?? null,
                ],
            ]);

            ActivityLog::log(
                Activity::ORDERS_TERMINAL_PAYMENT_FAILED,
                $payable,
                null,
                ['error' => $result->errorMessage, 'amount' => $amount, 'terminal' => $terminal->name],
                "Terminal payment of \${$amount} failed: {$result->errorMessage}",
            );

            return response()->json([
                'message' => $result->errorMessage ?? 'Failed to create terminal checkout',
            ], 422);
        }

        $isCompleted = $result->status === 'completed';

        // Create checkout record with the appropriate status
        $checkout = TerminalCheckout::create([
            'store_id' => $store->id,
            'payable_type' => get_class($payable),
            'payable_id' => $payable->id,
            'terminal_id' => $terminal->id,
            'user_id' => auth()->id(),
            'checkout_id' => $result->checkoutId,
            'amount' => $amount,
            'currency' => 'USD',
            'status' => $isCompleted ? TerminalCheckout::STATUS_COMPLETED : TerminalCheckout::STATUS_PENDING,
            'external_payment_id' => $isCompleted ? $result->checkoutId : null,
            'timeout_seconds' => $timeout,
            'expires_at' => $result->expiresAt ?? now()->addSeconds($timeout),
            'completed_at' => $isCompleted ? now() : null,
            'gateway_response' => $result->gatewayResponse,
            'metadata' => [
                'service_fee_value' => $validated['service_fee_value'] ?? null,
                'service_fee_unit' => $validated['service_fee_unit'] ?? null,
                'notes' => $validated['notes'] ?? null,
            ],
        ]);

        // For blocking gateways (Dejavoo): payment was already approved, create payment record now
        if ($isCompleted) {
            $cardBrand = data_get($result->gatewayResponse, 'card_type');
            $cardLastFour = data_get($result->gatewayResponse, 'card_last4');

            $payment = Payment::create([
                'store_id' => $store->id,
                'payable_type' => get_class($payable),
                'payable_id' => $payable->id,
                'terminal_checkout_id' => $checkout->id,
                'customer_id' => $payable->customer_id ?? null,
                'user_id' => auth()->id(),
                'payment_method' => Payment::METHOD_CARD,
                'status' => Payment::STATUS_COMPLETED,
                'amount' => $amount,
                'currency' => 'USD',
                'gateway' => $terminal->gateway,
                'gateway_payment_id' => $result->checkoutId,
                'gateway_response' => $result->gatewayResponse,
                'metadata' => [
                    'card_brand' => $cardBrand,
                    'card_last_four' => $cardLastFour,
                    'service_fee_value' => $validated['service_fee_value'] ?? null,
                    'service_fee_unit' => $validated['service_fee_unit'] ?? null,
                    'notes' => $validated['notes'] ?? null,
                ],
                'paid_at' => now(),
            ]);

            $checkout->update(['payment_id' => $payment->id]);

            // Recalculate payable totals
            if (method_exists($payable, 'recalculateTotals')) {
                $payable->recalculateTotals();
            }

            $cardDesc = $cardBrand ? "{$cardBrand} ending {$cardLastFour}" : 'card';
            ActivityLog::log(
                Activity::ORDERS_TERMINAL_PAYMENT,
                $payable,
                null,
                ['amount' => $amount, 'card_brand' => $cardBrand, 'card_last_four' => $cardLastFour, 'terminal' => $terminal->name],
                "Terminal payment of \${$amount} received via {$cardDesc}",
            );

            return response()->json([
                'message' => 'Payment approved.',
                'status' => 'completed',
                'checkout_id' => $checkout->id,
                'payment_id' => $payment->id,
                'card_brand' => $cardBrand,
                'card_last_four' => $cardLastFour,
            ]);
        }

        // For async gateways (Square): return checkout_id for polling
        return response()->json([
            'message' => 'Terminal checkout initiated.',
            'status' => 'pending',
            'checkout_id' => $checkout->id,
            'gateway_checkout_id' => $result->checkoutId,
            'expires_at' => $checkout->expires_at,
        ]);
    }

    /**
     * Void/refund a specific payment.
     */
    public function voidPayment(int $id, int $paymentId, string $type): JsonResponse
    {
        $payable = $this->resolvePayable($type, $id);

        $payment = $payable->payments()->findOrFail($paymentId);

        if ($payment->status !== Payment::STATUS_COMPLETED) {
            return response()->json([
                'message' => 'This payment cannot be voided.',
            ], 422);
        }

        $payment = $this->paymentService->voidPayment($payment);

        return response()->json([
            'message' => 'Payment voided successfully.',
            'payment' => $payment,
            $type => $payable->fresh(),
            'summary' => $this->paymentService->calculateSummary($payable->fresh()),
        ]);
    }

    /**
     * Resolve the payable model from type and ID.
     */
    protected function resolvePayable(string $type, int $id): Payable
    {
        $type = strtolower($type);

        if (! isset($this->modelTypes[$type])) {
            abort(404, "Unknown payable type: {$type}");
        }

        $modelClass = $this->modelTypes[$type];
        $payable = $modelClass::findOrFail($id);

        $this->authorizePayable($payable);

        return $payable;
    }

    /**
     * Verify the payable belongs to the current store.
     */
    protected function authorizePayable(Payable $payable): void
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store || $payable->getStoreId() !== $store->id) {
            abort(403, 'Unauthorized access to this resource.');
        }
    }
}
