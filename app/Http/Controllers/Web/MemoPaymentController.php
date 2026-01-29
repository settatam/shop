<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProcessMemoPaymentRequest;
use App\Http\Requests\UpdateMemoAdjustmentsRequest;
use App\Models\Memo;
use App\Services\MemoPaymentService;
use App\Services\StoreContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MemoPaymentController extends Controller
{
    public function __construct(
        protected MemoPaymentService $paymentService,
        protected StoreContext $storeContext
    ) {}

    /**
     * Get payment summary for a memo.
     */
    public function summary(Memo $memo, Request $request): JsonResponse
    {
        $this->authorizeStoreMemo($memo);

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

        $summary = $this->paymentService->calculateSummary($memo, $adjustments);

        return response()->json([
            'summary' => $summary,
            'memo' => [
                'id' => $memo->id,
                'memo_number' => $memo->memo_number,
                'status' => $memo->status,
                'can_receive_payment' => $memo->canReceivePayment(),
            ],
        ]);
    }

    /**
     * Update memo payment adjustments.
     */
    public function updateAdjustments(UpdateMemoAdjustmentsRequest $request, Memo $memo): JsonResponse
    {
        $this->authorizeStoreMemo($memo);

        if (! $memo->canReceivePayment()) {
            return response()->json([
                'message' => 'This memo cannot receive payment in its current state.',
            ], 422);
        }

        $memo = $this->paymentService->updateAdjustments($memo, $request->validated());

        return response()->json([
            'message' => 'Payment adjustments updated successfully.',
            'memo' => $memo,
            'summary' => $this->paymentService->calculateSummary($memo),
        ]);
    }

    /**
     * Process a payment for a memo.
     */
    public function processPayment(ProcessMemoPaymentRequest $request, Memo $memo): JsonResponse
    {
        $this->authorizeStoreMemo($memo);

        if (! $memo->canReceivePayment() && ! $memo->hasPayments()) {
            return response()->json([
                'message' => 'This memo cannot receive payment in its current state.',
            ], 422);
        }

        // Get normalized payment data (always an array of payments)
        $payments = $request->getPayments();

        $result = $this->paymentService->processPayment(
            $memo,
            $payments,
            auth()->id()
        );

        $paymentsCount = count($result['payments'] ?? [$result['payment']]);
        $message = $result['is_fully_paid']
            ? 'Payment completed. Memo has been fully paid.'
            : ($paymentsCount > 1
                ? "{$paymentsCount} payments recorded successfully."
                : 'Payment recorded successfully.');

        return response()->json([
            'message' => $message,
            'payment' => $result['payment'],
            'payments' => $result['payments'] ?? [$result['payment']],
            'memo' => $result['memo'],
            'is_fully_paid' => $result['is_fully_paid'],
        ]);
    }

    /**
     * Get payment history for a memo.
     */
    public function paymentHistory(Memo $memo): JsonResponse
    {
        $this->authorizeStoreMemo($memo);

        $payments = $this->paymentService->getPaymentHistory($memo);

        return response()->json([
            'payments' => $payments,
            'summary' => $this->paymentService->calculateSummary($memo),
        ]);
    }

    /**
     * Void/refund a specific payment.
     */
    public function voidPayment(Memo $memo, int $paymentId): JsonResponse
    {
        $this->authorizeStoreMemo($memo);

        $payment = $memo->payments()->findOrFail($paymentId);

        if ($payment->status !== \App\Models\Payment::STATUS_COMPLETED) {
            return response()->json([
                'message' => 'This payment cannot be voided.',
            ], 422);
        }

        $payment = $this->paymentService->voidPayment($payment);

        return response()->json([
            'message' => 'Payment voided successfully.',
            'payment' => $payment,
            'memo' => $memo->fresh(),
            'summary' => $this->paymentService->calculateSummary($memo->fresh()),
        ]);
    }

    /**
     * Verify the memo belongs to the current store.
     */
    protected function authorizeStoreMemo(Memo $memo): void
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store || $memo->store_id !== $store->id) {
            abort(403, 'Unauthorized access to this memo.');
        }
    }
}
