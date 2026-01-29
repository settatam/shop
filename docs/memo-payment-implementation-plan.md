# Memo Payment System Implementation Plan

## Overview

This plan details implementing a configurable payment collection system for Memos that supports:
- Configurable discounts (by % or $)
- Configurable service fees (by % or $)
- Configurable taxes (enable/disable, % or $)
- Multiple payment methods including credit cards
- Creation of Order, Invoice, and Payment records upon completion

---

## Database Schema Changes

### 1. Add Payment Fields to `memos` Table

```php
// Migration: add_payment_fields_to_memos_table.php
Schema::table('memos', function (Blueprint $table) {
    // Discount fields
    $table->decimal('discount_value', 10, 2)->default(0)->after('total');
    $table->enum('discount_unit', ['percent', 'fixed'])->default('fixed')->after('discount_value');
    $table->string('discount_reason')->nullable()->after('discount_unit');

    // Service fee fields
    $table->decimal('service_fee_value', 10, 2)->default(0)->after('discount_reason');
    $table->enum('service_fee_unit', ['percent', 'fixed'])->default('fixed')->after('service_fee_value');
    $table->string('service_fee_reason')->nullable()->after('service_fee_unit');

    // Calculated amounts
    $table->decimal('discount_amount', 10, 2)->default(0)->after('service_fee_reason');
    $table->decimal('service_fee_amount', 10, 2)->default(0)->after('discount_amount');
    $table->decimal('tax_amount', 10, 2)->default(0)->after('service_fee_amount');

    // Tax configuration
    $table->enum('tax_type', ['percent', 'fixed'])->default('percent')->after('charge_taxes');
});
```

### 2. Add `memo_id` to Existing Tables

```php
// Migration: add_memo_id_to_payments_table.php
Schema::table('payments', function (Blueprint $table) {
    $table->foreignId('memo_id')->nullable()->after('invoice_id')->constrained()->nullOnDelete();
});

// Migration: add_memo_id_to_orders_table.php
Schema::table('orders', function (Blueprint $table) {
    $table->foreignId('memo_id')->nullable()->after('store_id')->constrained()->nullOnDelete();
});
```

---

## Backend Implementation

### 1. Update Memo Model (`app/Models/Memo.php`)

```php
// Add to $fillable
'discount_value',
'discount_unit',
'discount_reason',
'service_fee_value',
'service_fee_unit',
'service_fee_reason',
'discount_amount',
'service_fee_amount',
'tax_amount',
'tax_type',

// Add relationships
public function payments(): HasMany
{
    return $this->hasMany(Payment::class);
}

public function sales(): HasMany
{
    return $this->hasMany(Order::class);
}

// Add calculation methods
public function calculateDiscountAmount(): float
{
    if ($this->discount_value <= 0) {
        return 0;
    }

    $subtotal = $this->subtotal;

    return $this->discount_unit === 'percent'
        ? round($subtotal * ($this->discount_value / 100), 2)
        : $this->discount_value;
}

public function calculateServiceFeeAmount(): float
{
    if ($this->service_fee_value <= 0) {
        return 0;
    }

    $afterDiscount = $this->subtotal - $this->calculateDiscountAmount();

    return $this->service_fee_unit === 'percent'
        ? round($afterDiscount * ($this->service_fee_value / 100), 2)
        : $this->service_fee_value;
}

public function calculateTaxAmount(): float
{
    if (!$this->charge_taxes || $this->tax_rate <= 0) {
        return 0;
    }

    $taxableAmount = $this->subtotal - $this->calculateDiscountAmount();

    return $this->tax_type === 'percent'
        ? round($taxableAmount * ($this->tax_rate / 100), 2)
        : $this->tax_rate; // Fixed tax amount
}

public function calculateGrandTotal(): float
{
    $subtotal = $this->subtotal;
    $discount = $this->calculateDiscountAmount();
    $serviceFee = $this->calculateServiceFeeAmount();
    $tax = $this->calculateTaxAmount();
    $shipping = $this->shipping_cost ?? 0;

    return max(0, $subtotal - $discount + $serviceFee + $tax + $shipping);
}

public function recalculateAllTotals(): self
{
    $items = $this->items()->where('is_returned', false)->get();
    $subtotal = $items->sum('price');

    $this->update([
        'subtotal' => $subtotal,
        'discount_amount' => $this->calculateDiscountAmount(),
        'service_fee_amount' => $this->calculateServiceFeeAmount(),
        'tax_amount' => $this->calculateTaxAmount(),
        'total' => $this->calculateGrandTotal(),
    ]);

    return $this;
}

public function getTotalPaidAttribute(): float
{
    return (float) $this->payments()
        ->where('status', Payment::STATUS_COMPLETED)
        ->sum('amount');
}

public function getBalanceDueAttribute(): float
{
    return max(0, (float) $this->total - $this->total_paid);
}

public function isFullyPaid(): bool
{
    return $this->balance_due <= 0;
}
```

### 2. Create MemoPaymentService (`app/Services/Memos/MemoPaymentService.php`)

```php
<?php

namespace App\Services\Memos;

use App\Models\Invoice;
use App\Models\Memo;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;

class MemoPaymentService
{
    /**
     * Process a payment for a memo.
     * Creates Order, Invoice, and Payment records.
     */
    public function processPayment(Memo $memo, array $paymentData): array
    {
        return DB::transaction(function () use ($memo, $paymentData) {
            // 1. Update memo financial fields if provided
            $this->updateMemoFinancials($memo, $paymentData);

            // 2. Create or update Order (Sale)
            $order = $this->createOrUpdateOrder($memo, $paymentData);

            // 3. Create or update Invoice
            $invoice = $this->createOrUpdateInvoice($memo, $order);

            // 4. Create Payment record
            $payment = $this->createPayment($memo, $invoice, $paymentData);

            // 5. Update memo and invoice status
            $this->updateStatuses($memo, $invoice);

            return [
                'memo' => $memo->fresh(),
                'order' => $order,
                'invoice' => $invoice,
                'payment' => $payment,
            ];
        });
    }

    protected function updateMemoFinancials(Memo $memo, array $data): void
    {
        $updates = [];

        // Discount
        if (isset($data['discount'])) {
            $updates['discount_value'] = $data['discount']['value'] ?? 0;
            $updates['discount_unit'] = $data['discount']['unit'] ?? 'fixed';
            $updates['discount_reason'] = $data['discount']['reason'] ?? null;
        }

        // Service fee
        if (isset($data['service_fee'])) {
            $updates['service_fee_value'] = $data['service_fee']['value'] ?? 0;
            $updates['service_fee_unit'] = $data['service_fee']['unit'] ?? 'fixed';
            $updates['service_fee_reason'] = $data['service_fee']['reason'] ?? null;
        }

        // Shipping
        if (isset($data['shipping'])) {
            $updates['shipping_cost'] = $data['shipping']['cost'] ?? 0;
        }

        // Taxes
        if (isset($data['taxes'])) {
            $updates['charge_taxes'] = $data['taxes']['enabled'] ?? false;
            $updates['tax_rate'] = $data['taxes']['rate'] ?? 0;
            $updates['tax_type'] = $data['taxes']['type'] ?? 'percent';
        }

        if (!empty($updates)) {
            $memo->update($updates);
        }

        // Recalculate all totals
        $memo->recalculateAllTotals();
    }

    protected function createOrUpdateOrder(Memo $memo, array $data): Order
    {
        $order = $memo->sales()->first() ?? new Order();

        $order->fill([
            'store_id' => $memo->store_id,
            'memo_id' => $memo->id,
            'customer_id' => $memo->vendor?->customer_id, // Link vendor to customer if exists
            'user_id' => auth()->id(),
            'sub_total' => $memo->subtotal,
            'sales_tax' => $memo->tax_amount,
            'shipping_cost' => $memo->shipping_cost ?? 0,
            'discount_cost' => $memo->discount_amount,
            'total' => $memo->total,
            'status' => Order::STATUS_PENDING,
            'source_platform' => 'memo',
            'notes' => "Sale from Memo #{$memo->memo_number}",
            'date_of_purchase' => now(),
        ]);

        if (!$order->invoice_number) {
            $order->invoice_number = $order->generateInvoiceNumber();
        }

        $order->save();

        // Sync order items from memo items
        $this->syncOrderItems($order, $memo);

        return $order;
    }

    protected function syncOrderItems(Order $order, Memo $memo): void
    {
        // Remove existing items
        $order->items()->delete();

        // Create items from non-returned memo items
        foreach ($memo->active_items as $memoItem) {
            $order->items()->create([
                'product_id' => $memoItem->product_id,
                'title' => $memoItem->title,
                'sku' => $memoItem->product?->variants->first()?->sku,
                'quantity' => 1,
                'price' => $memoItem->price,
                'total' => $memoItem->price,
            ]);
        }
    }

    protected function createOrUpdateInvoice(Memo $memo, Order $order): Invoice
    {
        $invoice = $memo->invoice ?? new Invoice();

        $invoice->fill([
            'store_id' => $memo->store_id,
            'customer_id' => $memo->vendor?->customer_id,
            'user_id' => auth()->id(),
            'invoiceable_type' => Memo::class,
            'invoiceable_id' => $memo->id,
            'subtotal' => $memo->subtotal,
            'discount' => $memo->discount_amount,
            'tax' => $memo->tax_amount,
            'shipping' => $memo->shipping_cost ?? 0,
            'total' => $memo->total,
            'status' => Invoice::STATUS_PENDING,
            'currency' => 'USD',
            'due_date' => $memo->due_date,
        ]);

        $invoice->save();

        return $invoice;
    }

    protected function createPayment(Memo $memo, Invoice $invoice, array $data): Payment
    {
        $paymentData = $data['payment'];

        $payment = Payment::create([
            'store_id' => $memo->store_id,
            'memo_id' => $memo->id,
            'invoice_id' => $invoice->id,
            'customer_id' => $memo->vendor?->customer_id,
            'user_id' => auth()->id(),
            'payment_method' => $paymentData['method'],
            'amount' => $paymentData['amount'],
            'status' => Payment::STATUS_COMPLETED,
            'reference' => $paymentData['reference'] ?? null,
            'gateway' => $paymentData['gateway'] ?? null,
            'gateway_payment_id' => $paymentData['gateway_payment_id'] ?? null,
            'notes' => $paymentData['notes'] ?? null,
            'paid_at' => now(),
        ]);

        return $payment;
    }

    protected function updateStatuses(Memo $memo, Invoice $invoice): void
    {
        // Update invoice totals and status
        $invoice->recalculateTotals();

        // Update memo status if fully paid
        if ($memo->isFullyPaid()) {
            $memo->markPaymentReceived();
        }
    }

    /**
     * Calculate payment summary without saving.
     */
    public function calculateSummary(Memo $memo, array $config): array
    {
        $subtotal = $memo->subtotal;

        // Discount calculation
        $discountValue = $config['discount']['value'] ?? 0;
        $discountUnit = $config['discount']['unit'] ?? 'fixed';
        $discountAmount = $discountUnit === 'percent'
            ? round($subtotal * ($discountValue / 100), 2)
            : $discountValue;

        $afterDiscount = $subtotal - $discountAmount;

        // Service fee calculation
        $serviceFeeValue = $config['service_fee']['value'] ?? 0;
        $serviceFeeUnit = $config['service_fee']['unit'] ?? 'fixed';
        $serviceFeeAmount = $serviceFeeUnit === 'percent'
            ? round($afterDiscount * ($serviceFeeValue / 100), 2)
            : $serviceFeeValue;

        // Tax calculation
        $chargeTaxes = $config['taxes']['enabled'] ?? false;
        $taxRate = $config['taxes']['rate'] ?? 0;
        $taxType = $config['taxes']['type'] ?? 'percent';
        $taxAmount = 0;

        if ($chargeTaxes && $taxRate > 0) {
            $taxAmount = $taxType === 'percent'
                ? round($afterDiscount * ($taxRate / 100), 2)
                : $taxRate;
        }

        // Shipping
        $shippingCost = $config['shipping']['cost'] ?? 0;

        // Grand total
        $grandTotal = max(0, $afterDiscount + $serviceFeeAmount + $taxAmount + $shippingCost);

        return [
            'subtotal' => $subtotal,
            'discount' => [
                'value' => $discountValue,
                'unit' => $discountUnit,
                'amount' => $discountAmount,
            ],
            'service_fee' => [
                'value' => $serviceFeeValue,
                'unit' => $serviceFeeUnit,
                'amount' => $serviceFeeAmount,
            ],
            'taxes' => [
                'enabled' => $chargeTaxes,
                'rate' => $taxRate,
                'type' => $taxType,
                'amount' => $taxAmount,
            ],
            'shipping' => [
                'cost' => $shippingCost,
            ],
            'grand_total' => $grandTotal,
            'balance_due' => max(0, $grandTotal - $memo->total_paid),
        ];
    }
}
```

### 3. Create MemoPaymentController (`app/Http/Controllers/Web/MemoPaymentController.php`)

```php
<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProcessMemoPaymentRequest;
use App\Http\Requests\CalculateMemoSummaryRequest;
use App\Models\Memo;
use App\Services\Memos\MemoPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

class MemoPaymentController extends Controller
{
    public function __construct(
        protected MemoPaymentService $paymentService
    ) {}

    /**
     * Calculate payment summary without saving (for preview).
     */
    public function calculateSummary(CalculateMemoSummaryRequest $request, Memo $memo): JsonResponse
    {
        $summary = $this->paymentService->calculateSummary($memo, $request->validated());

        return response()->json(['summary' => $summary]);
    }

    /**
     * Process payment and create sale/invoice/payment records.
     */
    public function processPayment(ProcessMemoPaymentRequest $request, Memo $memo): JsonResponse|RedirectResponse
    {
        if (!$memo->canReceivePayment()) {
            return response()->json([
                'error' => 'This memo cannot receive payments in its current state.'
            ], 422);
        }

        $result = $this->paymentService->processPayment($memo, $request->validated());

        if ($request->wantsJson()) {
            return response()->json([
                'message' => 'Payment processed successfully.',
                'data' => $result,
            ]);
        }

        return redirect()->route('web.memos.show', $memo)
            ->with('success', 'Payment recorded successfully.');
    }
}
```

### 4. Create Form Requests

**CalculateMemoSummaryRequest.php:**
```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CalculateMemoSummaryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'discount' => ['nullable', 'array'],
            'discount.value' => ['nullable', 'numeric', 'min:0'],
            'discount.unit' => ['nullable', 'in:percent,fixed'],
            'discount.reason' => ['nullable', 'string', 'max:255'],

            'service_fee' => ['nullable', 'array'],
            'service_fee.value' => ['nullable', 'numeric', 'min:0'],
            'service_fee.unit' => ['nullable', 'in:percent,fixed'],
            'service_fee.reason' => ['nullable', 'string', 'max:255'],

            'taxes' => ['nullable', 'array'],
            'taxes.enabled' => ['nullable', 'boolean'],
            'taxes.rate' => ['nullable', 'numeric', 'min:0'],
            'taxes.type' => ['nullable', 'in:percent,fixed'],

            'shipping' => ['nullable', 'array'],
            'shipping.cost' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
```

**ProcessMemoPaymentRequest.php:**
```php
<?php

namespace App\Http\Requests;

use App\Models\Payment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProcessMemoPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Payment info (required)
            'payment' => ['required', 'array'],
            'payment.method' => ['required', Rule::in([
                Payment::METHOD_CASH,
                Payment::METHOD_CARD,
                Payment::METHOD_CHECK,
                Payment::METHOD_BANK_TRANSFER,
                Payment::METHOD_EXTERNAL,
            ])],
            'payment.amount' => ['required', 'numeric', 'min:0.01'],
            'payment.reference' => ['nullable', 'string', 'max:255'],
            'payment.gateway' => ['nullable', 'string', 'max:50'],
            'payment.gateway_payment_id' => ['nullable', 'string', 'max:255'],
            'payment.notes' => ['nullable', 'string', 'max:1000'],

            // Financial configuration (optional - uses existing if not provided)
            'discount' => ['nullable', 'array'],
            'discount.value' => ['nullable', 'numeric', 'min:0'],
            'discount.unit' => ['nullable', 'in:percent,fixed'],
            'discount.reason' => ['nullable', 'string', 'max:255'],

            'service_fee' => ['nullable', 'array'],
            'service_fee.value' => ['nullable', 'numeric', 'min:0'],
            'service_fee.unit' => ['nullable', 'in:percent,fixed'],
            'service_fee.reason' => ['nullable', 'string', 'max:255'],

            'taxes' => ['nullable', 'array'],
            'taxes.enabled' => ['nullable', 'boolean'],
            'taxes.rate' => ['nullable', 'numeric', 'min:0'],
            'taxes.type' => ['nullable', 'in:percent,fixed'],

            'shipping' => ['nullable', 'array'],
            'shipping.cost' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
```

### 5. Add Routes (`routes/web.php`)

```php
// Memo payment routes
Route::post('memos/{memo}/calculate-summary', [MemoPaymentController::class, 'calculateSummary'])
    ->name('web.memos.calculate-summary');
Route::post('memos/{memo}/process-payment', [MemoPaymentController::class, 'processPayment'])
    ->name('web.memos.process-payment');
```

---

## Frontend Implementation

### 1. Create CollectPaymentModal Component

**Location:** `resources/js/components/memos/CollectPaymentModal.vue`

This modal will have:

1. **Payment Summary Section**
   - Subtotal (from memo items)
   - Discount configuration (toggle, $ or %, value, reason)
   - Service fee configuration (toggle, $ or %, value, reason)
   - Tax configuration (toggle, $ or %, rate/value)
   - Shipping (toggle, value)
   - Grand Total (calculated)
   - Balance Due

2. **Payment Method Section**
   - Payment method selector (Cash, Card, Check, Bank Transfer)
   - Amount to pay
   - Reference/Notes
   - For Card: Square SDK integration

3. **Action Buttons**
   - Calculate/Preview
   - Process Payment
   - Cancel

### 2. Key Vue Component Structure

```vue
<script setup lang="ts">
import { ref, computed, watch } from 'vue';
import axios from 'axios';

interface PaymentConfig {
    discount: { value: number; unit: 'percent' | 'fixed'; reason: string };
    service_fee: { value: number; unit: 'percent' | 'fixed'; reason: string };
    taxes: { enabled: boolean; rate: number; type: 'percent' | 'fixed' };
    shipping: { cost: number };
}

interface PaymentSummary {
    subtotal: number;
    discount: { value: number; unit: string; amount: number };
    service_fee: { value: number; unit: string; amount: number };
    taxes: { enabled: boolean; rate: number; type: string; amount: number };
    shipping: { cost: number };
    grand_total: number;
    balance_due: number;
}

const props = defineProps<{
    memo: {
        id: number;
        memo_number: string;
        subtotal: number;
        total: number;
        tax_rate: number;
        charge_taxes: boolean;
    };
    show: boolean;
}>();

const emit = defineEmits(['close', 'payment-processed']);

// Configuration state
const config = ref<PaymentConfig>({
    discount: { value: 0, unit: 'fixed', reason: '' },
    service_fee: { value: 0, unit: 'fixed', reason: '' },
    taxes: { enabled: props.memo.charge_taxes, rate: props.memo.tax_rate * 100, type: 'percent' },
    shipping: { cost: 0 },
});

// Payment state
const paymentMethod = ref('cash');
const paymentAmount = ref(0);
const paymentReference = ref('');
const paymentNotes = ref('');

// UI state
const summary = ref<PaymentSummary | null>(null);
const isCalculating = ref(false);
const isProcessing = ref(false);

// Calculate summary when config changes
async function calculateSummary() {
    isCalculating.value = true;
    try {
        const response = await axios.post(`/memos/${props.memo.id}/calculate-summary`, config.value);
        summary.value = response.data.summary;
        paymentAmount.value = summary.value.balance_due;
    } finally {
        isCalculating.value = false;
    }
}

// Process payment
async function processPayment() {
    isProcessing.value = true;
    try {
        await axios.post(`/memos/${props.memo.id}/process-payment`, {
            ...config.value,
            payment: {
                method: paymentMethod.value,
                amount: paymentAmount.value,
                reference: paymentReference.value,
                notes: paymentNotes.value,
            },
        });
        emit('payment-processed');
    } finally {
        isProcessing.value = false;
    }
}

// Initial calculation
watch(() => props.show, (show) => {
    if (show) calculateSummary();
}, { immediate: true });
</script>
```

### 3. Payment Summary Display

```vue
<template>
    <!-- Payment Summary -->
    <div class="space-y-3 text-sm">
        <div class="flex justify-between">
            <span>Subtotal</span>
            <span>${{ summary?.subtotal.toFixed(2) }}</span>
        </div>

        <!-- Discount -->
        <div v-if="summary?.discount.amount > 0" class="flex justify-between text-green-600">
            <span>Discount ({{ summary.discount.unit === 'percent' ? summary.discount.value + '%' : '$' + summary.discount.value }})</span>
            <span>-${{ summary.discount.amount.toFixed(2) }}</span>
        </div>

        <!-- Service Fee -->
        <div v-if="summary?.service_fee.amount > 0" class="flex justify-between">
            <span>Service Fee ({{ summary.service_fee.unit === 'percent' ? summary.service_fee.value + '%' : '$' + summary.service_fee.value }})</span>
            <span>${{ summary.service_fee.amount.toFixed(2) }}</span>
        </div>

        <!-- Tax -->
        <div v-if="summary?.taxes.amount > 0" class="flex justify-between">
            <span>Tax ({{ summary.taxes.type === 'percent' ? summary.taxes.rate + '%' : 'Fixed' }})</span>
            <span>${{ summary.taxes.amount.toFixed(2) }}</span>
        </div>

        <!-- Shipping -->
        <div v-if="summary?.shipping.cost > 0" class="flex justify-between">
            <span>Shipping</span>
            <span>${{ summary.shipping.cost.toFixed(2) }}</span>
        </div>

        <div class="border-t pt-3 flex justify-between font-semibold text-lg">
            <span>Grand Total</span>
            <span>${{ summary?.grand_total.toFixed(2) }}</span>
        </div>

        <div class="flex justify-between font-semibold text-indigo-600">
            <span>Balance Due</span>
            <span>${{ summary?.balance_due.toFixed(2) }}</span>
        </div>
    </div>
</template>
```

---

## Implementation Steps

### Phase 1: Database & Backend Foundation
1. Create migration for memo payment fields
2. Create migration for `memo_id` in payments and orders tables
3. Run migrations
4. Update Memo model with new fields and methods
5. Create MemoPaymentService
6. Create form request classes
7. Create MemoPaymentController
8. Add routes
9. Write tests for service and controller

### Phase 2: Frontend - Basic Payment Modal
1. Create CollectPaymentModal.vue component
2. Add payment summary display
3. Add discount configuration UI (toggle, $/%, value, reason)
4. Add service fee configuration UI (toggle, $/%, value, reason)
5. Add tax configuration UI (toggle, $/%, rate)
6. Add shipping configuration UI (toggle, value)
7. Wire up calculate summary API
8. Add payment method selector (Cash, Check, Bank Transfer)
9. Add payment amount and notes fields
10. Wire up process payment API

### Phase 3: Payment Gateway Integration
1. Create Dejavoo config file (`config/dejavoo.php`)
2. Create Square config file (`config/square.php`)
3. Create DejavooService (`app/Services/Payments/DejavooService.php`)
4. Create SquareTerminalService (`app/Services/Payments/SquareTerminalService.php`)
5. Create PaymentGatewayFactory
6. Add terminal checkout endpoints to controller
7. Create TerminalPayment.vue component
8. Integrate terminal payment flow into modal

### Phase 4: Square Web SDK Integration (Online Card Payments)
1. Add Square Web SDK loader
2. Create card input component
3. Integrate card tokenization
4. Handle online card payment flow
5. Test web card payments

### Phase 5: Testing & Polish
1. Write comprehensive feature tests for all payment methods
2. Test Dejavoo terminal payments
3. Test Square terminal payments
4. Test Square web payments
5. Test partial payments
6. Test edge cases (full payment, overpayment, etc.)
7. Add proper error handling and retry logic
8. Add loading states and status polling
9. Add success/error toasts
10. Test refund flows

---

## Files to Create/Modify

| File | Action | Description |
|------|--------|-------------|
| **Database Migrations** | | |
| `database/migrations/xxx_add_payment_fields_to_memos_table.php` | Create | Add discount, service fee, tax fields |
| `database/migrations/xxx_add_memo_id_to_payments_table.php` | Create | Link payments to memos |
| `database/migrations/xxx_add_memo_id_to_orders_table.php` | Create | Link orders to memos |
| **Models** | | |
| `app/Models/Memo.php` | Modify | Add fields, relationships, calculation methods |
| `app/Models/Payment.php` | Modify | Add memo relationship, gateway fields |
| `app/Models/Order.php` | Modify | Add memo relationship |
| `app/Models/StoreIntegration.php` | Modify | Ensure gateway credential support |
| **Services** | | |
| `app/Services/Memos/MemoPaymentService.php` | Create | Payment processing logic |
| `app/Services/Payments/DejavooService.php` | Create | Dejavoo terminal integration |
| `app/Services/Payments/SquareTerminalService.php` | Create | Square terminal integration |
| `app/Services/Payments/SquareWebService.php` | Create | Square web SDK integration |
| `app/Services/Payments/PaymentGatewayFactory.php` | Create | Gateway factory pattern |
| **Controllers** | | |
| `app/Http/Controllers/Web/MemoPaymentController.php` | Create | API endpoints |
| **Form Requests** | | |
| `app/Http/Requests/CalculateMemoSummaryRequest.php` | Create | Summary calculation validation |
| `app/Http/Requests/ProcessMemoPaymentRequest.php` | Create | Payment processing validation |
| `app/Http/Requests/TerminalCheckoutRequest.php` | Create | Terminal checkout validation |
| **Configuration** | | |
| `config/dejavoo.php` | Create | Dejavoo API config |
| `config/square.php` | Create | Square API config |
| **Routes** | | |
| `routes/web.php` | Modify | Add payment routes |
| **Frontend Components** | | |
| `resources/js/components/memos/CollectPaymentModal.vue` | Create | Main payment modal |
| `resources/js/components/memos/PaymentSummary.vue` | Create | Payment summary display |
| `resources/js/components/memos/TerminalPayment.vue` | Create | Terminal payment UI |
| `resources/js/components/memos/CardPayment.vue` | Create | Web card payment UI |
| `resources/js/pages/memos/Show.vue` | Modify | Integrate payment modal |
| **Tests** | | |
| `tests/Feature/MemoPaymentTest.php` | Create | Payment feature tests |
| `tests/Feature/DejavooPaymentTest.php` | Create | Dejavoo integration tests |
| `tests/Feature/SquarePaymentTest.php` | Create | Square integration tests |

---

## API Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/memos/{memo}/calculate-summary` | Preview payment summary |
| POST | `/memos/{memo}/process-payment` | Process payment |
| POST | `/memos/{memo}/terminal-checkout` | Create terminal checkout (Square/Dejavoo) |
| GET | `/memos/{memo}/terminal-checkout/{id}/status` | Check terminal checkout status |

---

## Payment Gateway Integration

### Supported Payment Methods

| Method | Type | Gateway |
|--------|------|---------|
| Cash | Manual | None |
| Check | Manual | None |
| Bank Transfer | Manual | None |
| Credit Card (Web) | Online | Square Web SDK |
| Credit Card (Terminal) | Terminal | Square Terminal / Dejavoo |
| Debit Card (Terminal) | Terminal | Square Terminal / Dejavoo |

### Payment Gateway Configuration

#### Store Integration Model

The `store_integrations` table stores gateway credentials per store:

```php
// Existing structure in shopmata
Schema::create('store_integrations', function (Blueprint $table) {
    $table->id();
    $table->foreignId('store_id')->constrained();
    $table->string('provider'); // 'square', 'dejavoo'
    $table->string('name')->nullable();
    $table->boolean('is_active')->default(true);
    $table->json('credentials')->nullable(); // Encrypted gateway credentials
    $table->json('settings')->nullable();
    $table->timestamps();
});
```

#### Dejavoo Configuration

```php
// config/dejavoo.php
return [
    'base_url' => env('DEJAVOO_BASE_URL', 'https://spinpos.net/spin'),
    'api_version' => env('DEJAVOO_API_VERSION', 'v2.6'),
];

// Store integration credentials (stored in store_integrations.credentials)
// - tpn: Terminal ID (TPN)
// - auth_key: API Authentication Key
// - device_name: Friendly device name
```

#### Square Configuration

```php
// config/square.php
return [
    'environment' => env('SQUARE_ENVIRONMENT', 'sandbox'),
    'application_id' => env('SQUARE_APPLICATION_ID'),
    'access_token' => env('SQUARE_ACCESS_TOKEN'),
];

// Store integration credentials (stored in store_integrations.credentials)
// - location_id: Square location ID
// - device_id: Terminal device ID
```

### Dejavoo Service (`app/Services/Payments/DejavooService.php`)

```php
<?php

namespace App\Services\Payments;

use App\Models\Payment;
use App\Models\StoreIntegration;
use Illuminate\Support\Facades\Http;

class DejavooService
{
    protected StoreIntegration $integration;
    protected array $credentials;

    public function __construct(StoreIntegration $integration)
    {
        $this->integration = $integration;
        $this->credentials = $integration->credentials;
    }

    /**
     * Create a sale transaction on Dejavoo terminal.
     */
    public function createSale(Payment $payment, array $options = []): array
    {
        $endpoint = $this->buildEndpoint('Payment/Sale');

        $data = [
            'Amount' => $payment->amount,
            'TipAmount' => $options['tip_amount'] ?? 0,
            'PaymentType' => $options['payment_type'] ?? 'Credit',
            'ReferenceId' => $payment->id . '_' . ($payment->memo_id ?? $payment->order_id),
            'CaptureSignature' => $options['capture_signature'] ?? true,
            'InvoiceNumber' => $options['invoice_number'] ?? '',
            'PrintReceipt' => $options['print_receipt'] ?? 'Both',
            'GetReceipt' => 'Both',
            'Tpn' => $this->credentials['tpn'],
            'AuthKey' => $this->credentials['auth_key'],
            'GetExtendedData' => true,
        ];

        $response = Http::asJson()
            ->acceptJson()
            ->post($endpoint, $data);

        return $this->processResponse($response->json(), $payment);
    }

    /**
     * Process refund on Dejavoo terminal.
     */
    public function createRefund(Payment $payment, float $amount): array
    {
        $endpoint = $this->buildEndpoint('Payment/Refund');

        $data = [
            'Amount' => $amount,
            'PaymentType' => 'Credit',
            'ReferenceId' => $payment->gateway_payment_id,
            'Tpn' => $this->credentials['tpn'],
            'AuthKey' => $this->credentials['auth_key'],
        ];

        $response = Http::asJson()
            ->acceptJson()
            ->post($endpoint, $data);

        return $response->json();
    }

    protected function buildEndpoint(string $path): string
    {
        return sprintf(
            '%s/%s/%s',
            config('dejavoo.base_url'),
            config('dejavoo.api_version'),
            $path
        );
    }

    protected function processResponse(array $response, Payment $payment): array
    {
        $generalResponse = $response['GeneralResponse'] ?? [];
        $status = $generalResponse['Message'] ?? '';

        if ($status === 'Approved') {
            $payment->update([
                'status' => Payment::STATUS_COMPLETED,
                'gateway_payment_id' => $response['TransactionNumber'] ?? null,
                'gateway_response' => $response,
                'paid_at' => now(),
            ]);

            // Store card details if available
            if ($cardData = $response['CardData'] ?? null) {
                $payment->update([
                    'metadata' => array_merge($payment->metadata ?? [], [
                        'card_type' => $cardData['CardType'] ?? null,
                        'last_4' => $cardData['Last4'] ?? null,
                        'first_4' => $cardData['First4'] ?? null,
                    ]),
                ]);
            }

            return ['success' => true, 'payment' => $payment];
        }

        $payment->update([
            'status' => $status === 'Cancelled' ? Payment::STATUS_FAILED : Payment::STATUS_FAILED,
            'gateway_response' => $response,
        ]);

        return [
            'success' => false,
            'error' => $generalResponse['DetailedMessage'] ?? 'Payment failed',
            'payment' => $payment,
        ];
    }
}
```

### Square Terminal Service (`app/Services/Payments/SquareTerminalService.php`)

```php
<?php

namespace App\Services\Payments;

use App\Models\Payment;
use App\Models\StoreIntegration;
use App\Models\TerminalCheckout;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class SquareTerminalService
{
    protected StoreIntegration $integration;
    protected array $credentials;

    public function __construct(StoreIntegration $integration)
    {
        $this->integration = $integration;
        $this->credentials = $integration->credentials;
    }

    /**
     * Create a terminal checkout for Square terminal.
     */
    public function createCheckout(Payment $payment, array $options = []): array
    {
        $endpoint = $this->buildEndpoint('terminals/checkouts');

        $data = [
            'idempotency_key' => Str::uuid()->toString(),
            'checkout' => [
                'amount_money' => [
                    'amount' => (int) ($payment->amount * 100), // Convert to cents
                    'currency' => 'USD',
                ],
                'reference_id' => (string) $payment->id,
                'note' => $options['note'] ?? "Payment for Memo #{$payment->memo_id}",
                'device_options' => [
                    'device_id' => $this->credentials['device_id'],
                ],
            ],
        ];

        $response = Http::withToken(config('square.access_token'))
            ->asJson()
            ->acceptJson()
            ->post($endpoint, $data);

        $result = $response->json();

        if (isset($result['checkout'])) {
            // Create terminal checkout record
            $terminalCheckout = TerminalCheckout::create([
                'store_id' => $payment->store_id,
                'invoice_id' => $payment->invoice_id,
                'payment_id' => $payment->id,
                'checkout_id' => $result['checkout']['id'],
                'amount' => $payment->amount,
                'status' => $result['checkout']['status'],
                'device_id' => $this->credentials['device_id'],
            ]);

            return ['success' => true, 'checkout' => $terminalCheckout];
        }

        return ['success' => false, 'errors' => $result['errors'] ?? []];
    }

    /**
     * Check status of terminal checkout.
     */
    public function getCheckoutStatus(TerminalCheckout $checkout): array
    {
        $endpoint = $this->buildEndpoint("terminals/checkouts/{$checkout->checkout_id}");

        $response = Http::withToken(config('square.access_token'))
            ->asJson()
            ->acceptJson()
            ->get($endpoint);

        return $response->json();
    }

    /**
     * Cancel terminal checkout.
     */
    public function cancelCheckout(TerminalCheckout $checkout): array
    {
        $endpoint = $this->buildEndpoint("terminals/checkouts/{$checkout->checkout_id}/cancel");

        $response = Http::withToken(config('square.access_token'))
            ->asJson()
            ->acceptJson()
            ->post($endpoint);

        return $response->json();
    }

    protected function buildEndpoint(string $path): string
    {
        $baseUrl = config('square.environment') === 'production'
            ? 'https://connect.squareup.com/v2'
            : 'https://connect.squareupsandbox.com/v2';

        return "{$baseUrl}/{$path}";
    }
}
```

### Payment Gateway Factory (`app/Services/Payments/PaymentGatewayFactory.php`)

```php
<?php

namespace App\Services\Payments;

use App\Models\Store;
use App\Models\StoreIntegration;
use InvalidArgumentException;

class PaymentGatewayFactory
{
    public static function make(Store $store, string $provider): DejavooService|SquareTerminalService
    {
        $integration = StoreIntegration::where('store_id', $store->id)
            ->where('provider', $provider)
            ->where('is_active', true)
            ->firstOrFail();

        return match ($provider) {
            'dejavoo' => new DejavooService($integration),
            'square' => new SquareTerminalService($integration),
            default => throw new InvalidArgumentException("Unknown payment provider: {$provider}"),
        };
    }

    public static function getAvailableGateways(Store $store): array
    {
        return StoreIntegration::where('store_id', $store->id)
            ->where('is_active', true)
            ->whereIn('provider', ['square', 'dejavoo'])
            ->pluck('provider')
            ->toArray();
    }
}
```

---

## Frontend Terminal Payment Flow

### Terminal Payment Component

```vue
<script setup lang="ts">
import { ref, computed } from 'vue';
import axios from 'axios';

const props = defineProps<{
    memo: { id: number };
    amount: number;
    availableGateways: string[];
}>();

const emit = defineEmits(['payment-complete', 'payment-failed']);

const selectedGateway = ref(props.availableGateways[0] || 'dejavoo');
const isProcessing = ref(false);
const checkoutStatus = ref<string | null>(null);
const checkoutId = ref<string | null>(null);
let statusPollInterval: number | null = null;

async function initiateTerminalPayment() {
    isProcessing.value = true;
    checkoutStatus.value = 'PENDING';

    try {
        const response = await axios.post(`/memos/${props.memo.id}/terminal-checkout`, {
            gateway: selectedGateway.value,
            amount: props.amount,
        });

        if (response.data.success) {
            checkoutId.value = response.data.checkout.id;

            // For Square, poll for status updates
            if (selectedGateway.value === 'square') {
                startStatusPolling();
            } else {
                // Dejavoo returns immediately
                handlePaymentResult(response.data);
            }
        } else {
            throw new Error(response.data.error || 'Failed to initiate payment');
        }
    } catch (error) {
        checkoutStatus.value = 'FAILED';
        emit('payment-failed', error);
    }
}

function startStatusPolling() {
    statusPollInterval = setInterval(async () => {
        try {
            const response = await axios.get(
                `/memos/${props.memo.id}/terminal-checkout/${checkoutId.value}/status`
            );

            checkoutStatus.value = response.data.status;

            if (['COMPLETED', 'CANCELED', 'FAILED'].includes(response.data.status)) {
                stopStatusPolling();
                handlePaymentResult(response.data);
            }
        } catch (error) {
            stopStatusPolling();
            emit('payment-failed', error);
        }
    }, 2000); // Poll every 2 seconds
}

function stopStatusPolling() {
    if (statusPollInterval) {
        clearInterval(statusPollInterval);
        statusPollInterval = null;
    }
}

function handlePaymentResult(data: any) {
    isProcessing.value = false;

    if (data.success || data.status === 'COMPLETED') {
        emit('payment-complete', data);
    } else {
        emit('payment-failed', data);
    }
}

async function cancelCheckout() {
    if (checkoutId.value) {
        await axios.post(`/memos/${props.memo.id}/terminal-checkout/${checkoutId.value}/cancel`);
        stopStatusPolling();
        isProcessing.value = false;
        checkoutStatus.value = null;
    }
}
</script>

<template>
    <div class="space-y-4">
        <!-- Gateway Selection -->
        <div v-if="availableGateways.length > 1">
            <label class="block text-sm font-medium text-gray-700">Payment Terminal</label>
            <select v-model="selectedGateway" class="mt-1 block w-full rounded-md border-gray-300">
                <option v-for="gateway in availableGateways" :key="gateway" :value="gateway">
                    {{ gateway === 'square' ? 'Square Terminal' : 'Dejavoo Terminal' }}
                </option>
            </select>
        </div>

        <!-- Processing State -->
        <div v-if="isProcessing" class="text-center py-8">
            <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-indigo-600 mx-auto"></div>
            <p class="mt-4 text-sm text-gray-600">
                {{ checkoutStatus === 'PENDING' ? 'Waiting for payment on terminal...' : 'Processing payment...' }}
            </p>
            <button @click="cancelCheckout" class="mt-4 text-red-600 text-sm hover:underline">
                Cancel Payment
            </button>
        </div>

        <!-- Initiate Button -->
        <button
            v-else
            @click="initiateTerminalPayment"
            class="w-full bg-indigo-600 text-white py-3 rounded-lg hover:bg-indigo-700"
        >
            Pay ${{ amount.toFixed(2) }} on Terminal
        </button>
    </div>
</template>
```

---

## Data Flow

```
User clicks "Collect Payment" on Memo Show page
    ↓
CollectPaymentModal opens
    ↓
User configures discount/fees/taxes/shipping
    ↓
API: POST /memos/{memo}/calculate-summary
    ↓
Display calculated totals
    ↓
User selects payment method & enters amount
    ↓
API: POST /memos/{memo}/process-payment
    ↓
Backend creates: Order → Invoice → Payment
    ↓
Update Memo status if fully paid
    ↓
Return success, close modal, refresh page
```
