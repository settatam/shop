<?php

namespace App\Http\Requests;

use App\Models\Customer;
use App\Models\Layaway;
use App\Models\Memo;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Repair;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ProcessPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $paymentMethodRule = Rule::in([
            Payment::METHOD_CASH,
            Payment::METHOD_CARD,
            Payment::METHOD_CHECK,
            Payment::METHOD_BANK_TRANSFER,
            Payment::METHOD_STORE_CREDIT,
            Payment::METHOD_EXTERNAL,
        ]);

        // If 'payments' array is present, validate as multiple payments
        if ($this->has('payments')) {
            return [
                'payments' => ['required', 'array', 'min:1'],
                'payments.*.payment_method' => ['required', $paymentMethodRule],
                'payments.*.amount' => ['required', 'numeric', 'min:0.01'],
                'payments.*.service_fee_value' => ['nullable', 'numeric', 'min:0'],
                'payments.*.service_fee_unit' => ['nullable', Rule::in(['fixed', 'percent'])],
                'payments.*.reference' => ['nullable', 'string', 'max:255'],
                'payments.*.notes' => ['nullable', 'string', 'max:1000'],
            ];
        }

        // Single payment (backwards compatible)
        return [
            'payment_method' => ['required', $paymentMethodRule],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'service_fee_value' => ['nullable', 'numeric', 'min:0'],
            'service_fee_unit' => ['nullable', Rule::in(['fixed', 'percent'])],
            'reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'gateway' => ['nullable', 'string', 'max:50'],
            'gateway_payment_id' => ['nullable', 'string', 'max:255'],
            'gateway_response' => ['nullable', 'array'],
            'transaction_id' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Get the payments data in a normalized format (always returns array of payments).
     */
    public function getPayments(): array
    {
        if ($this->has('payments')) {
            return $this->validated()['payments'];
        }

        // Single payment - wrap in array
        return [$this->validated()];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'payment_method.required' => 'Please select a payment method.',
            'payment_method.in' => 'The selected payment method is invalid.',
            'payments.*.payment_method.required' => 'Please select a payment method for each payment.',
            'payments.*.payment_method.in' => 'One of the selected payment methods is invalid.',
            'amount.required' => 'Please enter the payment amount.',
            'amount.numeric' => 'The payment amount must be a number.',
            'amount.min' => 'The payment amount must be at least $0.01.',
            'payments.*.amount.required' => 'Please enter an amount for each payment.',
            'payments.*.amount.min' => 'Each payment amount must be at least $0.01.',
        ];
    }

    /**
     * Configure the validator instance to check store credit balance.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $payments = $this->has('payments')
                ? ($this->input('payments') ?? [])
                : [$this->only(['payment_method', 'amount'])];

            $storeCreditTotal = 0;
            foreach ($payments as $payment) {
                if (($payment['payment_method'] ?? '') === Payment::METHOD_STORE_CREDIT) {
                    $storeCreditTotal += (float) ($payment['amount'] ?? 0);
                }
            }

            if ($storeCreditTotal <= 0) {
                return;
            }

            $customerId = $this->resolveCustomerId();

            if (! $customerId) {
                $validator->errors()->add('payment_method', 'Store credit is not available for this payable type.');

                return;
            }

            $customer = Customer::find($customerId);

            if (! $customer) {
                $validator->errors()->add('payment_method', 'Customer not found.');

                return;
            }

            $balance = (float) $customer->store_credit_balance;

            if ($storeCreditTotal > $balance) {
                $formatted = number_format($balance, 2);
                $validator->errors()->add(
                    'payment_method',
                    "Insufficient store credit balance. Available: \${$formatted}"
                );
            }
        });
    }

    /**
     * Resolve the customer ID from the payable.
     */
    protected function resolveCustomerId(): ?int
    {
        $type = strtolower($this->route('type') ?? '');
        $id = $this->route('id');

        $modelMap = [
            'order' => Order::class,
            'orders' => Order::class,
            'repair' => Repair::class,
            'repairs' => Repair::class,
            'appraisal' => Repair::class,
            'appraisals' => Repair::class,
            'layaway' => Layaway::class,
            'layaways' => Layaway::class,
            'memo' => Memo::class,
            'memos' => Memo::class,
        ];

        $modelClass = $modelMap[$type] ?? null;

        if (! $modelClass || ! $id) {
            return null;
        }

        $payable = $modelClass::find($id);

        return $payable?->getCustomerId();
    }
}
