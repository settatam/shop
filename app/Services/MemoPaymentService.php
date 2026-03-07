<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Memo;
use App\Models\MemoItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Vendor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
            $memo = $memo->fresh(['items', 'items.product.variants', 'vendor']);

            // Ensure grand_total is set (may be 0 if never calculated via payment modal)
            if ((float) $memo->grand_total <= 0 && (float) $memo->total > 0) {
                $memo->update(['grand_total' => $memo->total]);
            }

            // Find or create a customer from the vendor
            $customer = $this->findOrCreateCustomerFromVendor($memo->vendor, $memo->store_id);

            // Create the order (sale)
            $order = $this->createOrderFromMemo($memo, $customer);

            // Create the invoice
            $invoice = $this->createInvoiceFromMemo($memo, $order, $customer);

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
    protected function createOrderFromMemo(Memo $memo, ?Customer $customer = null): Order
    {
        $order = Order::create([
            'store_id' => $memo->store_id,
            'memo_id' => $memo->id,
            'user_id' => $memo->user_id,
            'customer_id' => $customer?->id,
            'invoice_number' => $memo->memo_number,
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

        // Create order items from memo items and mark products as sold
        foreach ($memo->active_items as $memoItem) {
            // Create ad-hoc product for items without a product
            if (! $memoItem->product_id) {
                $this->createAdHocProduct($memoItem, $memo->store_id);
            }

            $variant = $memoItem->product?->variants?->first();

            OrderItem::create([
                'order_id' => $order->id,
                'product_id' => $memoItem->product_id,
                'product_variant_id' => $variant?->id,
                'title' => $memoItem->title ?? $memoItem->product?->title ?? 'Unknown Product',
                'sku' => $memoItem->sku ?? $variant?->sku,
                'quantity' => 1,
                'price' => $memoItem->price,
                'cost' => $memoItem->cost,
            ]);

            // Mark the product as sold
            if ($memoItem->product_id) {
                Product::where('id', $memoItem->product_id)
                    ->whereIn('status', [Product::STATUS_IN_MEMO, Product::STATUS_DRAFT])
                    ->update(['status' => Product::STATUS_SOLD, 'quantity' => 0]);
            }
        }

        return $order;
    }

    /**
     * Create an ad-hoc product for a memo item that has no linked product.
     */
    protected function createAdHocProduct(MemoItem $memoItem, int $storeId): void
    {
        $title = $memoItem->title ?? 'Memo Item';
        $handle = Str::slug($title).'-'.Str::random(6);

        $product = Product::create([
            'store_id' => $storeId,
            'title' => $title,
            'handle' => $handle,
            'description' => $memoItem->description,
            'category_id' => $memoItem->category_id,
            'status' => Product::STATUS_SOLD,
            'quantity' => 0,
            'is_published' => false,
            'is_draft' => false,
            'has_variants' => false,
            'track_quantity' => true,
        ]);

        $variant = $product->variants()->create([
            'sku' => $memoItem->sku ?? 'SKU-'.strtoupper(Str::random(8)),
            'price' => $memoItem->price ?? 0,
            'cost' => $memoItem->cost ?? 0,
            'quantity' => 0,
        ]);

        // Link the product back to the memo item
        $memoItem->update(['product_id' => $product->id]);
    }

    /**
     * Create an invoice from a memo.
     */
    protected function createInvoiceFromMemo(Memo $memo, Order $order, ?Customer $customer = null): Invoice
    {
        return Invoice::create([
            'store_id' => $memo->store_id,
            'user_id' => $memo->user_id,
            'customer_id' => $customer?->id,
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
     * Find or create a Customer record from a Vendor.
     */
    protected function findOrCreateCustomerFromVendor(?Vendor $vendor, int $storeId): ?Customer
    {
        if (! $vendor) {
            return null;
        }

        // Try to find an existing customer by email first, then by name + store
        if ($vendor->email) {
            $customer = Customer::where('store_id', $storeId)
                ->where('email', $vendor->email)
                ->first();

            if ($customer) {
                return $customer;
            }
        }

        // Parse vendor name into first/last
        $nameParts = explode(' ', trim($vendor->name ?? ''), 2);
        $firstName = $nameParts[0] ?? $vendor->company_name ?? 'Vendor';
        $lastName = $nameParts[1] ?? '';

        // Try matching by name
        $customer = Customer::where('store_id', $storeId)
            ->where('first_name', $firstName)
            ->where('last_name', $lastName)
            ->first();

        if ($customer) {
            return $customer;
        }

        return Customer::create([
            'store_id' => $storeId,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'company_name' => $vendor->company_name,
            'email' => $vendor->email,
            'phone_number' => $vendor->phone,
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
