<?php

namespace App\Services;

use App\Contracts\Payable;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;

class PaymentService
{
    /**
     * Calculate the payment summary for a payable with given adjustments.
     *
     * @param  array{
     *     discount_value?: float,
     *     discount_unit?: string,
     *     service_fee_value?: float,
     *     service_fee_unit?: string,
     *     charge_taxes?: bool,
     *     tax_rate?: float,
     *     tax_type?: string,
     *     shipping_cost?: float
     * }  $adjustments
     * @return array{
     *     subtotal: float,
     *     discount_amount: float,
     *     service_fee_amount: float,
     *     tax_amount: float,
     *     shipping_cost: float,
     *     grand_total: float,
     *     total_paid: float,
     *     balance_due: float
     * }
     */
    public function calculateSummary(Payable $payable, array $adjustments = []): array
    {
        $currentAdjustments = $payable->getPaymentAdjustments();
        $subtotal = $payable->getSubtotal();

        // Discount calculation
        $discountValue = $adjustments['discount_value'] ?? $currentAdjustments['discount_value'];
        $discountUnit = $adjustments['discount_unit'] ?? $currentAdjustments['discount_unit'];
        $discountAmount = $discountUnit === 'percent'
            ? ($subtotal * $discountValue / 100)
            : $discountValue;

        $afterDiscount = $subtotal - $discountAmount;

        // Service fee calculation (calculated on subtotal after discount)
        $serviceFeeValue = $adjustments['service_fee_value'] ?? $currentAdjustments['service_fee_value'];
        $serviceFeeUnit = $adjustments['service_fee_unit'] ?? $currentAdjustments['service_fee_unit'];
        $serviceFeeAmount = $serviceFeeUnit === 'percent'
            ? ($afterDiscount * $serviceFeeValue / 100)
            : $serviceFeeValue;

        // Tax calculation (only on subtotal after discount, not on service fee)
        $taxableAmount = $afterDiscount;
        $chargeTaxes = $adjustments['charge_taxes'] ?? $currentAdjustments['charge_taxes'];
        $taxRate = $adjustments['tax_rate'] ?? $currentAdjustments['tax_rate'];
        $taxType = $adjustments['tax_type'] ?? $currentAdjustments['tax_type'];
        $taxAmount = 0;
        if ($chargeTaxes && $taxRate > 0) {
            $taxAmount = $taxType === 'percent'
                ? ($taxableAmount * $taxRate / 100)
                : $taxRate;
        }

        // Shipping
        $shippingCost = $adjustments['shipping_cost'] ?? $currentAdjustments['shipping_cost'];

        // Grand total
        $grandTotal = $afterDiscount + $serviceFeeAmount + $taxAmount + $shippingCost;

        // Payment tracking
        $totalPaid = $payable->getTotalPaid();
        $balanceDue = max(0, $grandTotal - $totalPaid);

        return [
            'subtotal' => round($subtotal, 2),
            'discount_amount' => round($discountAmount, 2),
            'service_fee_amount' => round($serviceFeeAmount, 2),
            'tax_amount' => round($taxAmount, 2),
            'shipping_cost' => round($shippingCost, 2),
            'grand_total' => round($grandTotal, 2),
            'total_paid' => round($totalPaid, 2),
            'balance_due' => round($balanceDue, 2),
        ];
    }

    /**
     * Update payable with payment adjustments.
     */
    public function updateAdjustments(Payable $payable, array $adjustments): Payable
    {
        $payable->updatePaymentAdjustments($adjustments);

        $summary = $this->calculateSummary($payable);

        $payable->updateCalculatedTotals($summary);

        return $payable;
    }

    /**
     * Process payments for a payable (supports single or multiple payments).
     *
     * @return array{
     *     payments: array<Payment>,
     *     payment: Payment|null,
     *     payable: Payable,
     *     is_fully_paid: bool
     * }
     */
    public function processPayments(Payable $payable, array $paymentsData, int $userId): array
    {
        // Normalize to array of payments
        if (isset($paymentsData['payment_method'])) {
            // Single payment format
            $paymentsData = [$paymentsData];
        }

        return DB::transaction(function () use ($payable, $paymentsData, $userId) {
            $payments = [];
            $totalAmount = 0;
            $totalServiceFee = 0;

            // First pass: create all payments and calculate their individual service fees
            foreach ($paymentsData as $paymentData) {
                if (($paymentData['amount'] ?? 0) <= 0) {
                    continue;
                }

                $payment = $this->createPayment($payable, $paymentData, $userId);
                $payments[] = $payment;
                // Include both the payment amount and service fee in total paid
                $totalAmount += $paymentData['amount'] + ($payment->service_fee_amount ?? 0);
                $totalServiceFee += ($payment->service_fee_amount ?? 0);
            }

            // Update payable's total to include the actual service fee from payments
            // (not recalculated, use the actual amounts from individual payments)
            if ($totalServiceFee > 0) {
                // Find the service fee details from the first payment that has one (for display purposes)
                $serviceFeePayment = collect($paymentsData)->first(fn ($p) => ($p['service_fee_value'] ?? 0) > 0);

                // Store the service fee as a FIXED amount (the actual calculated total)
                $payable->updatePaymentAdjustments([
                    'service_fee_value' => $totalServiceFee,
                    'service_fee_unit' => 'fixed',
                    'service_fee_reason' => $serviceFeePayment['service_fee_reason'] ?? null,
                ]);

                // Recalculate summary with the updated adjustments and update totals
                $summary = $this->calculateSummary($payable);
                $payable->updateCalculatedTotals($summary);
            }

            // Update payable totals
            if ($totalAmount > 0) {
                $payable->recordPayment($totalAmount);
            }

            $isFullyPaid = $payable->isFullyPaid();

            // Trigger completion callback if fully paid
            if ($isFullyPaid) {
                $payable->onPaymentComplete();
            }

            return [
                'payments' => $payments,
                'payment' => $payments[0] ?? null,
                'payable' => $payable,
                'is_fully_paid' => $isFullyPaid,
            ];
        });
    }

    /**
     * Create a single payment record.
     */
    protected function createPayment(Payable $payable, array $paymentData, int $userId): Payment
    {
        // Calculate service fee amount
        // Service fee is only collected on subtotal (price of items), not on shipping, taxes, etc.
        // For partial payments, service fee is on the payment amount but capped at the subtotal
        $serviceFeeValue = $paymentData['service_fee_value'] ?? null;
        $serviceFeeUnit = $paymentData['service_fee_unit'] ?? null;
        $serviceFeeAmount = null;

        if ($serviceFeeValue && $serviceFeeValue > 0) {
            // Get subtotal (price of items) after any discount
            $adjustments = $payable->getPaymentAdjustments();
            $subtotal = $payable->getSubtotal();
            $discountValue = $adjustments['discount_value'] ?? 0;
            $discountUnit = $adjustments['discount_unit'] ?? 'fixed';
            $discountAmount = $discountUnit === 'percent'
                ? ($subtotal * $discountValue / 100)
                : $discountValue;
            $subtotalAfterDiscount = $subtotal - $discountAmount;

            // The base for service fee calculation is the payment amount, capped at subtotal
            $serviceFeeBase = min($paymentData['amount'], $subtotalAfterDiscount);

            if ($serviceFeeUnit === 'percent') {
                $serviceFeeAmount = round($serviceFeeBase * $serviceFeeValue / 100, 2);
            } else {
                // For fixed fee, cap at the proportional amount if partial payment
                $serviceFeeAmount = $paymentData['amount'] >= $subtotalAfterDiscount
                    ? $serviceFeeValue
                    : round($serviceFeeValue * ($paymentData['amount'] / $subtotalAfterDiscount), 2);
            }
        }

        return Payment::create([
            'store_id' => $payable->getStoreId(),
            'payable_type' => get_class($payable),
            'payable_id' => $payable->id,
            'user_id' => $userId,
            'payment_method' => $paymentData['payment_method'],
            'amount' => $paymentData['amount'],
            'service_fee_value' => $serviceFeeValue,
            'service_fee_unit' => $serviceFeeUnit,
            'service_fee_amount' => $serviceFeeAmount,
            'currency' => 'USD',
            'status' => Payment::STATUS_COMPLETED,
            'reference' => $paymentData['reference'] ?? null,
            'notes' => $paymentData['notes'] ?? null,
            'gateway' => $paymentData['gateway'] ?? null,
            'gateway_payment_id' => $paymentData['gateway_payment_id'] ?? null,
            'gateway_response' => $paymentData['gateway_response'] ?? null,
            'transaction_id' => $paymentData['transaction_id'] ?? null,
            'paid_at' => now(),
        ]);
    }

    /**
     * Get payment history for a payable.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Payment>
     */
    public function getPaymentHistory(Payable $payable)
    {
        return $payable->payments()
            ->with('user')
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Void/refund a payment.
     */
    public function voidPayment(Payment $payment): Payment
    {
        return DB::transaction(function () use ($payment) {
            $payable = $payment->payable;

            // Update payment status
            $payment->update(['status' => Payment::STATUS_REFUNDED]);

            // Recalculate totals
            $totalPaid = $payable->payments()
                ->where('status', Payment::STATUS_COMPLETED)
                ->sum('amount');

            $payable->update([
                'total_paid' => $totalPaid,
                'balance_due' => max(0, $payable->getGrandTotal() - $totalPaid),
            ]);

            return $payment->fresh();
        });
    }
}
