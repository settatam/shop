<?php

namespace App\Services\Invoices;

use App\Models\Invoice;
use App\Models\Memo;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Repair;
use App\Services\StoreContext;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class InvoiceService
{
    public function __construct(
        protected StoreContext $storeContext,
    ) {}

    public function createFromOrder(Order $order): Invoice
    {
        $existingInvoice = Invoice::where('invoiceable_type', Order::class)
            ->where('invoiceable_id', $order->id)
            ->first();

        if ($existingInvoice) {
            return $existingInvoice;
        }

        return DB::transaction(function () use ($order) {
            $invoice = Invoice::create([
                'store_id' => $order->store_id,
                'customer_id' => $order->customer_id,
                'user_id' => $order->user_id,
                'invoiceable_type' => Order::class,
                'invoiceable_id' => $order->id,
                'subtotal' => $order->sub_total ?? 0,
                'tax' => $order->sales_tax ?? 0,
                'shipping' => $order->shipping_cost ?? 0,
                'discount' => $order->discount_cost ?? 0,
                'total' => $order->total ?? 0,
                'balance_due' => $order->total ?? 0,
                'status' => Invoice::STATUS_PENDING,
                'due_date' => now()->addDays(30),
            ]);

            return $invoice;
        });
    }

    public function createFromRepair(Repair $repair): Invoice
    {
        if ($repair->invoice) {
            return $repair->invoice;
        }

        return DB::transaction(function () use ($repair) {
            $invoice = Invoice::create([
                'store_id' => $repair->store_id,
                'customer_id' => $repair->customer_id,
                'user_id' => $repair->user_id,
                'invoiceable_type' => Repair::class,
                'invoiceable_id' => $repair->id,
                'subtotal' => $repair->subtotal ?? 0,
                'tax' => $repair->tax ?? 0,
                'shipping' => $repair->shipping_cost ?? 0,
                'discount' => $repair->discount ?? 0,
                'total' => $repair->total ?? 0,
                'balance_due' => $repair->total ?? 0,
                'status' => Invoice::STATUS_PENDING,
                'due_date' => now()->addDays(30),
            ]);

            return $invoice;
        });
    }

    public function createFromMemo(Memo $memo): Invoice
    {
        if ($memo->invoice) {
            return $memo->invoice;
        }

        return DB::transaction(function () use ($memo) {
            // Vendor-based memos don't have a customer_id - set to null
            $invoice = Invoice::create([
                'store_id' => $memo->store_id,
                'customer_id' => null,
                'user_id' => $memo->user_id,
                'invoiceable_type' => Memo::class,
                'invoiceable_id' => $memo->id,
                'subtotal' => $memo->subtotal ?? 0,
                'tax' => $memo->tax ?? 0,
                'shipping' => $memo->shipping_cost ?? 0,
                'discount' => 0,
                'total' => $memo->total ?? 0,
                'balance_due' => $memo->total ?? 0,
                'status' => Invoice::STATUS_PENDING,
                'due_date' => $memo->due_date ?? now()->addDays($memo->tenure ?? 30),
            ]);

            return $invoice;
        });
    }

    public function addPayment(Invoice $invoice, array $data): Payment
    {
        if (! $invoice->canAcceptPayment()) {
            throw new InvalidArgumentException('Invoice cannot accept payments in its current state.');
        }

        $amount = (float) ($data['amount'] ?? 0);

        if ($amount <= 0) {
            throw new InvalidArgumentException('Payment amount must be greater than zero.');
        }

        if ($amount > $invoice->balance_due) {
            throw new InvalidArgumentException('Payment amount exceeds balance due.');
        }

        return DB::transaction(function () use ($invoice, $data, $amount) {
            $payment = Payment::create([
                'store_id' => $invoice->store_id,
                'invoice_id' => $invoice->id,
                'order_id' => $invoice->invoiceable_type === Order::class ? $invoice->invoiceable_id : null,
                'customer_id' => $invoice->customer_id,
                'user_id' => $data['user_id'] ?? auth()->id(),
                'payment_method' => $data['payment_method'] ?? Payment::METHOD_CASH,
                'status' => $data['status'] ?? Payment::STATUS_COMPLETED,
                'amount' => $amount,
                'currency' => $data['currency'] ?? 'USD',
                'reference' => $data['reference'] ?? null,
                'transaction_id' => $data['transaction_id'] ?? null,
                'gateway' => $data['gateway'] ?? null,
                'gateway_payment_id' => $data['gateway_payment_id'] ?? null,
                'gateway_response' => $data['gateway_response'] ?? null,
                'notes' => $data['notes'] ?? null,
                'metadata' => $data['metadata'] ?? null,
                'paid_at' => ($data['status'] ?? Payment::STATUS_COMPLETED) === Payment::STATUS_COMPLETED
                    ? now()
                    : null,
            ]);

            $invoice->recalculateTotals();

            return $payment;
        });
    }

    public function recalculateTotals(Invoice $invoice): Invoice
    {
        return $invoice->recalculateTotals();
    }

    public function markAsPaid(Invoice $invoice): Invoice
    {
        if ($invoice->isPaid()) {
            return $invoice;
        }

        return $invoice->markAsPaid();
    }

    public function voidInvoice(Invoice $invoice): Invoice
    {
        if (! $invoice->canBeVoided()) {
            throw new InvalidArgumentException('Invoice cannot be voided in its current state.');
        }

        return $invoice->markAsVoid();
    }

    public function refundPayment(Payment $payment, ?float $amount = null): Payment
    {
        if (! $payment->isCompleted()) {
            throw new InvalidArgumentException('Can only refund completed payments.');
        }

        $refundAmount = $amount ?? $payment->amount;

        if ($refundAmount > $payment->amount) {
            throw new InvalidArgumentException('Refund amount cannot exceed original payment amount.');
        }

        return DB::transaction(function () use ($payment, $refundAmount) {
            $payment->update([
                'status' => $refundAmount >= $payment->amount
                    ? Payment::STATUS_REFUNDED
                    : Payment::STATUS_PARTIALLY_REFUNDED,
                'metadata' => array_merge($payment->metadata ?? [], [
                    'refunded_amount' => $refundAmount,
                    'refunded_at' => now()->toIso8601String(),
                ]),
            ]);

            if ($payment->invoice) {
                $payment->invoice->recalculateTotals();
            }

            return $payment->fresh();
        });
    }

    public function syncInvoiceTotals(Invoice $invoice): Invoice
    {
        $invoiceable = $invoice->invoiceable;

        if (! $invoiceable) {
            return $invoice;
        }

        $totals = match ($invoice->invoiceable_type) {
            Order::class => [
                'subtotal' => $invoiceable->sub_total ?? 0,
                'tax' => $invoiceable->sales_tax ?? 0,
                'shipping' => $invoiceable->shipping_cost ?? 0,
                'discount' => $invoiceable->discount_cost ?? 0,
                'total' => $invoiceable->total ?? 0,
            ],
            Repair::class => [
                'subtotal' => $invoiceable->subtotal ?? 0,
                'tax' => $invoiceable->tax ?? 0,
                'shipping' => $invoiceable->shipping_cost ?? 0,
                'discount' => $invoiceable->discount ?? 0,
                'total' => $invoiceable->total ?? 0,
            ],
            Memo::class => [
                'subtotal' => $invoiceable->subtotal ?? 0,
                'tax' => $invoiceable->tax ?? 0,
                'shipping' => $invoiceable->shipping_cost ?? 0,
                'discount' => 0,
                'total' => $invoiceable->total ?? 0,
            ],
            default => [],
        };

        if (! empty($totals)) {
            $invoice->update(array_merge($totals, [
                'balance_due' => max(0, $totals['total'] - $invoice->total_paid),
            ]));

            $invoice->updateStatus();
        }

        return $invoice->fresh();
    }
}
