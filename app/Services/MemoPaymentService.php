<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Memo;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;

class MemoPaymentService
{
    /**
     * Calculate the payment summary for a memo with given adjustments.
     *
     * @param  array{
     *     discount_value?: float,
     *     discount_unit?: string,
     *     discount_reason?: string|null,
     *     service_fee_value?: float,
     *     service_fee_unit?: string,
     *     service_fee_reason?: string|null,
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
    public function calculateSummary(Memo $memo, array $adjustments = []): array
    {
        $subtotal = (float) $memo->total;

        // Discount calculation
        $discountValue = $adjustments['discount_value'] ?? (float) $memo->discount_value;
        $discountUnit = $adjustments['discount_unit'] ?? $memo->discount_unit ?? 'fixed';
        $discountAmount = $discountUnit === 'percent'
            ? ($subtotal * $discountValue / 100)
            : $discountValue;

        $afterDiscount = $subtotal - $discountAmount;

        // Service fee calculation
        $serviceFeeValue = $adjustments['service_fee_value'] ?? (float) $memo->service_fee_value;
        $serviceFeeUnit = $adjustments['service_fee_unit'] ?? $memo->service_fee_unit ?? 'fixed';
        $serviceFeeAmount = $serviceFeeUnit === 'percent'
            ? ($afterDiscount * $serviceFeeValue / 100)
            : $serviceFeeValue;

        $taxableAmount = $afterDiscount + $serviceFeeAmount;

        // Tax calculation
        $chargeTaxes = $adjustments['charge_taxes'] ?? $memo->charge_taxes;
        $taxRate = $adjustments['tax_rate'] ?? (float) $memo->tax_rate;
        $taxType = $adjustments['tax_type'] ?? $memo->tax_type ?? 'percent';
        $taxAmount = 0;
        if ($chargeTaxes && $taxRate > 0) {
            $taxAmount = $taxType === 'percent'
                ? ($taxableAmount * $taxRate / 100)
                : $taxRate;
        }

        // Shipping
        $shippingCost = $adjustments['shipping_cost'] ?? (float) ($memo->shipping_cost ?? 0);

        // Grand total
        $grandTotal = $taxableAmount + $taxAmount + $shippingCost;

        // Payment tracking
        $totalPaid = (float) ($memo->total_paid ?? 0);
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
     * Update memo with payment adjustments.
     *
     * @param  array{
     *     discount_value?: float,
     *     discount_unit?: string,
     *     discount_reason?: string|null,
     *     service_fee_value?: float,
     *     service_fee_unit?: string,
     *     service_fee_reason?: string|null,
     *     charge_taxes?: bool,
     *     tax_rate?: float,
     *     tax_type?: string,
     *     shipping_cost?: float
     * }  $adjustments
     */
    public function updateAdjustments(Memo $memo, array $adjustments): Memo
    {
        $memo->update([
            'discount_value' => $adjustments['discount_value'] ?? $memo->discount_value,
            'discount_unit' => $adjustments['discount_unit'] ?? $memo->discount_unit,
            'discount_reason' => $adjustments['discount_reason'] ?? $memo->discount_reason,
            'service_fee_value' => $adjustments['service_fee_value'] ?? $memo->service_fee_value,
            'service_fee_unit' => $adjustments['service_fee_unit'] ?? $memo->service_fee_unit,
            'service_fee_reason' => $adjustments['service_fee_reason'] ?? $memo->service_fee_reason,
            'charge_taxes' => $adjustments['charge_taxes'] ?? $memo->charge_taxes,
            'tax_rate' => $adjustments['tax_rate'] ?? $memo->tax_rate,
            'tax_type' => $adjustments['tax_type'] ?? $memo->tax_type,
            'shipping_cost' => $adjustments['shipping_cost'] ?? $memo->shipping_cost,
        ]);

        $summary = $this->calculateSummary($memo);

        $memo->update([
            'discount_amount' => $summary['discount_amount'],
            'service_fee_amount' => $summary['service_fee_amount'],
            'tax_amount' => $summary['tax_amount'],
            'grand_total' => $summary['grand_total'],
            'balance_due' => $summary['balance_due'],
        ]);

        return $memo->fresh();
    }

    /**
     * Process a single payment for a memo.
     *
     * @param  array{
     *     payment_method: string,
     *     amount: float,
     *     service_fee_value?: float|null,
     *     service_fee_unit?: string|null,
     *     reference?: string|null,
     *     notes?: string|null,
     *     gateway?: string|null,
     *     gateway_payment_id?: string|null,
     *     gateway_response?: array|null,
     *     transaction_id?: string|null
     * }  $paymentData
     * @return array{
     *     payment: Payment,
     *     memo: Memo,
     *     is_fully_paid: bool
     * }
     */
    public function processPayment(Memo $memo, array $paymentData, int $userId): array
    {
        // Check if this is multiple payments
        if (isset($paymentData['payments']) && is_array($paymentData['payments'])) {
            return $this->processMultiplePayments($memo, $paymentData['payments'], $userId);
        }

        // If it's an array of payments (from getPayments())
        if (isset($paymentData[0]) && is_array($paymentData[0])) {
            return $this->processMultiplePayments($memo, $paymentData, $userId);
        }

        return DB::transaction(function () use ($memo, $paymentData, $userId) {
            $payment = $this->createPayment($memo, $paymentData, $userId);

            // Update memo payment totals
            $memo->recordPayment($paymentData['amount']);

            $isFullyPaid = $memo->fresh()->isFullyPaid();

            // If fully paid, complete the memo workflow
            if ($isFullyPaid) {
                $this->completeMemoPayment($memo);
            }

            return [
                'payment' => $payment,
                'memo' => $memo->fresh(),
                'is_fully_paid' => $isFullyPaid,
            ];
        });
    }

    /**
     * Process multiple payments for a memo (split payments).
     *
     * @param  array<int, array{
     *     payment_method: string,
     *     amount: float,
     *     service_fee_value?: float|null,
     *     service_fee_unit?: string|null,
     *     reference?: string|null,
     *     notes?: string|null
     * }>  $paymentsData
     * @return array{
     *     payments: array<Payment>,
     *     memo: Memo,
     *     is_fully_paid: bool
     * }
     */
    public function processMultiplePayments(Memo $memo, array $paymentsData, int $userId): array
    {
        return DB::transaction(function () use ($memo, $paymentsData, $userId) {
            $payments = [];
            $totalAmount = 0;

            foreach ($paymentsData as $paymentData) {
                if (($paymentData['amount'] ?? 0) <= 0) {
                    continue;
                }

                $payment = $this->createPayment($memo, $paymentData, $userId);
                $payments[] = $payment;
                $totalAmount += $paymentData['amount'];
            }

            // Update memo payment totals
            if ($totalAmount > 0) {
                $memo->recordPayment($totalAmount);
            }

            $isFullyPaid = $memo->fresh()->isFullyPaid();

            // If fully paid, complete the memo workflow
            if ($isFullyPaid) {
                $this->completeMemoPayment($memo);
            }

            return [
                'payments' => $payments,
                'payment' => $payments[0] ?? null, // For backwards compatibility
                'memo' => $memo->fresh(),
                'is_fully_paid' => $isFullyPaid,
            ];
        });
    }

    /**
     * Create a single payment record.
     */
    protected function createPayment(Memo $memo, array $paymentData, int $userId): Payment
    {
        // Calculate service fee amount
        $serviceFeeValue = $paymentData['service_fee_value'] ?? null;
        $serviceFeeUnit = $paymentData['service_fee_unit'] ?? null;
        $serviceFeeAmount = null;

        if ($serviceFeeValue && $serviceFeeValue > 0) {
            if ($serviceFeeUnit === 'percent') {
                $serviceFeeAmount = round($paymentData['amount'] * $serviceFeeValue / 100, 2);
            } else {
                $serviceFeeAmount = $serviceFeeValue;
            }
        }

        return Payment::create([
            'store_id' => $memo->store_id,
            'payable_type' => Memo::class,
            'payable_id' => $memo->id,
            'memo_id' => $memo->id, // Keep for backwards compatibility
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
     * Complete the memo payment workflow: create order, invoice, and update status.
     */
    public function completeMemoPayment(Memo $memo): array
    {
        return DB::transaction(function () use ($memo) {
            $memo = $memo->fresh(['items', 'vendor']);

            // Create the order (sale)
            $order = $this->createOrderFromMemo($memo);

            // Create the invoice
            $invoice = $this->createInvoiceFromMemo($memo, $order);

            // Link payments to invoice
            $memo->payments()->update(['invoice_id' => $invoice->id]);

            // Update memo status
            $memo->markPaymentReceived();

            // Link order to memo
            $memo->update(['order_id' => $order->id]);

            return [
                'order' => $order,
                'invoice' => $invoice,
                'memo' => $memo->fresh(),
            ];
        });
    }

    /**
     * Create an order (sale) from a memo.
     */
    protected function createOrderFromMemo(Memo $memo): Order
    {
        $order = Order::create([
            'store_id' => $memo->store_id,
            'memo_id' => $memo->id,
            'user_id' => $memo->user_id,
            'invoice_number' => 'MEM-TEMP', // Temporary, will be updated with order ID
            'sub_total' => $memo->total,
            'sales_tax' => $memo->tax_amount ?? 0,
            'shipping_cost' => $memo->shipping_cost ?? 0,
            'discount_cost' => $memo->discount_amount ?? 0,
            'total' => $memo->grand_total,
            'status' => Order::STATUS_COMPLETED,
            'source_platform' => 'memo',
            'date_of_purchase' => now(),
            'notes' => "Created from Memo #{$memo->memo_number}",
        ]);

        // Update invoice number with MEM-<order.id> format
        $order->update(['invoice_number' => "MEM-{$order->id}"]);

        // Create order items from memo items
        foreach ($memo->active_items as $memoItem) {
            OrderItem::create([
                'order_id' => $order->id,
                'product_id' => $memoItem->product_id,
                'product_variant_id' => $memoItem->product_variant_id,
                'title' => $memoItem->title ?? $memoItem->product?->title ?? 'Unknown Product',
                'sku' => $memoItem->sku,
                'quantity' => 1,
                'price' => $memoItem->price,  // Selling price
                'cost' => $memoItem->cost,    // Cost for profit calculation
            ]);
        }

        return $order;
    }

    /**
     * Create an invoice from a memo.
     */
    protected function createInvoiceFromMemo(Memo $memo, Order $order): Invoice
    {
        return Invoice::create([
            'store_id' => $memo->store_id,
            'user_id' => $memo->user_id,
            'invoiceable_type' => Memo::class,
            'invoiceable_id' => $memo->id,
            'subtotal' => $memo->total,
            'tax' => $memo->tax_amount ?? 0,
            'shipping' => $memo->shipping_cost ?? 0,
            'discount' => $memo->discount_amount ?? 0,
            'total' => $memo->grand_total,
            'total_paid' => $memo->total_paid,
            'balance_due' => 0,
            'status' => Invoice::STATUS_PAID,
            'currency' => 'USD',
            'paid_at' => now(),
            'notes' => "Invoice for Memo #{$memo->memo_number}",
        ]);
    }

    /**
     * Get payment history for a memo.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Payment>
     */
    public function getPaymentHistory(Memo $memo)
    {
        return $memo->payments()
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
            $memo = $payment->memo;

            // Update payment status
            $payment->update(['status' => Payment::STATUS_REFUNDED]);

            // Recalculate memo totals
            $totalPaid = $memo->payments()
                ->where('status', Payment::STATUS_COMPLETED)
                ->sum('amount');

            $memo->update([
                'total_paid' => $totalPaid,
                'balance_due' => max(0, (float) $memo->grand_total - $totalPaid),
            ]);

            return $payment->fresh();
        });
    }
}
