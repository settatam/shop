<?php

namespace App\Http\Requests;

use App\Models\Transaction;
use App\Models\TransactionItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateBuyTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            // Step 1: Store User (Employee)
            'store_user_id' => ['required', 'integer', 'exists:store_users,id'],

            // Step 2: Customer
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'customer' => ['nullable', 'array', 'required_without:customer_id'],
            'customer.first_name' => ['required_with:customer', 'string', 'max:100'],
            'customer.last_name' => ['required_with:customer', 'string', 'max:100'],
            'customer.company_name' => ['nullable', 'string', 'max:255'],
            'customer.email' => ['nullable', 'email', 'max:255'],
            'customer.phone_number' => ['nullable', 'string', 'max:50'],
            'customer.address' => ['nullable', 'string', 'max:255'],
            'customer.address2' => ['nullable', 'string', 'max:255'],
            'customer.city' => ['nullable', 'string', 'max:100'],
            'customer.state_id' => ['nullable', 'integer', 'exists:states,id'],
            'customer.zip' => ['nullable', 'string', 'max:20'],
            'customer.country_id' => ['nullable', 'integer', 'exists:countries,id'],

            // Step 3: Items
            'items' => ['required', 'array', 'min:1'],
            'items.*.title' => ['required', 'string', 'max:255'],
            'items.*.description' => ['nullable', 'string', 'max:2000'],
            'items.*.category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'items.*.precious_metal' => ['nullable', 'string', Rule::in([
                TransactionItem::METAL_GOLD_10K,
                TransactionItem::METAL_GOLD_14K,
                TransactionItem::METAL_GOLD_18K,
                TransactionItem::METAL_GOLD_22K,
                TransactionItem::METAL_GOLD_24K,
                TransactionItem::METAL_SILVER,
                TransactionItem::METAL_PLATINUM,
                TransactionItem::METAL_PALLADIUM,
            ])],
            'items.*.dwt' => ['nullable', 'numeric', 'min:0'],
            'items.*.condition' => ['nullable', 'string', Rule::in([
                TransactionItem::CONDITION_NEW,
                TransactionItem::CONDITION_LIKE_NEW,
                TransactionItem::CONDITION_USED,
                TransactionItem::CONDITION_DAMAGED,
            ])],
            'items.*.price' => ['nullable', 'numeric', 'min:0'],
            'items.*.buy_price' => ['required', 'numeric', 'min:0'],

            // Transaction location
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],

            // Step 4: Payments (multiple)
            'payments' => ['required', 'array', 'min:1'],
            'payments.*.method' => ['required', 'string', Rule::in([
                Transaction::PAYMENT_CASH,
                Transaction::PAYMENT_CHECK,
                Transaction::PAYMENT_PAYPAL,
                Transaction::PAYMENT_VENMO,
                Transaction::PAYMENT_STORE_CREDIT,
                Transaction::PAYMENT_ACH,
                Transaction::PAYMENT_WIRE_TRANSFER,
            ])],
            'payments.*.amount' => ['required', 'numeric', 'min:0.01'],
            'payments.*.details' => ['nullable', 'array'],
            'payments.*.details.paypal_email' => ['nullable', 'email', 'max:255'],
            'payments.*.details.venmo_handle' => ['nullable', 'string', 'max:100'],
            'payments.*.details.check_mailing_address' => ['nullable', 'array'],
            'payments.*.details.check_mailing_address.address' => ['nullable', 'string', 'max:255'],
            'payments.*.details.check_mailing_address.city' => ['nullable', 'string', 'max:100'],
            'payments.*.details.check_mailing_address.state' => ['nullable', 'string', 'max:100'],
            'payments.*.details.check_mailing_address.zip' => ['nullable', 'string', 'max:20'],
            'payments.*.details.bank_name' => ['nullable', 'string', 'max:255'],
            'payments.*.details.account_holder_name' => ['nullable', 'string', 'max:255'],
            'payments.*.details.account_number' => ['nullable', 'string', 'max:50'],
            'payments.*.details.routing_number' => ['nullable', 'string', 'max:50'],

            // Notes
            'customer_notes' => ['nullable', 'string', 'max:2000'],
            'internal_notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function ($validator) {
            $this->validatePaymentDetails($validator);
            $this->validatePaymentTotals($validator);
        });
    }

    protected function validatePaymentDetails(\Illuminate\Validation\Validator $validator): void
    {
        $payments = $this->input('payments', []);

        foreach ($payments as $index => $payment) {
            $method = $payment['method'] ?? null;
            $details = $payment['details'] ?? [];

            switch ($method) {
                case Transaction::PAYMENT_PAYPAL:
                    if (empty($details['paypal_email'])) {
                        $validator->errors()->add("payments.{$index}.details.paypal_email", 'PayPal email is required for PayPal payments.');
                    }
                    break;

                case Transaction::PAYMENT_VENMO:
                    if (empty($details['venmo_handle'])) {
                        $validator->errors()->add("payments.{$index}.details.venmo_handle", 'Venmo handle is required for Venmo payments.');
                    }
                    break;

                case Transaction::PAYMENT_CHECK:
                    $address = $details['check_mailing_address'] ?? [];
                    if (empty($address['address'])) {
                        $validator->errors()->add("payments.{$index}.details.check_mailing_address.address", 'Mailing address is required for check payments.');
                    }
                    if (empty($address['city'])) {
                        $validator->errors()->add("payments.{$index}.details.check_mailing_address.city", 'City is required for check payments.');
                    }
                    if (empty($address['state'])) {
                        $validator->errors()->add("payments.{$index}.details.check_mailing_address.state", 'State is required for check payments.');
                    }
                    if (empty($address['zip'])) {
                        $validator->errors()->add("payments.{$index}.details.check_mailing_address.zip", 'ZIP code is required for check payments.');
                    }
                    break;

                case Transaction::PAYMENT_ACH:
                case Transaction::PAYMENT_WIRE_TRANSFER:
                    if (empty($details['bank_name'])) {
                        $validator->errors()->add("payments.{$index}.details.bank_name", 'Bank name is required for ACH/Wire transfers.');
                    }
                    if (empty($details['account_holder_name'])) {
                        $validator->errors()->add("payments.{$index}.details.account_holder_name", 'Account holder name is required for ACH/Wire transfers.');
                    }
                    if (empty($details['account_number'])) {
                        $validator->errors()->add("payments.{$index}.details.account_number", 'Account number is required for ACH/Wire transfers.');
                    }
                    if (empty($details['routing_number'])) {
                        $validator->errors()->add("payments.{$index}.details.routing_number", 'Routing number is required for ACH/Wire transfers.');
                    }
                    break;
            }
        }
    }

    protected function validatePaymentTotals(\Illuminate\Validation\Validator $validator): void
    {
        $items = $this->input('items', []);
        $payments = $this->input('payments', []);

        $totalBuyPrice = collect($items)->sum(fn ($item) => (float) ($item['buy_price'] ?? 0));
        $totalPayments = collect($payments)->sum(fn ($payment) => (float) ($payment['amount'] ?? 0));

        if (abs($totalBuyPrice - $totalPayments) > 0.01) {
            $validator->errors()->add('payments', 'Total payments must equal the total buy price.');
        }
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'store_user_id.required' => 'Please select an employee for this transaction.',
            'store_user_id.exists' => 'The selected employee is not valid.',
            'items.required' => 'At least one item is required.',
            'items.min' => 'At least one item is required.',
            'items.*.title.required' => 'Each item must have a title.',
            'items.*.buy_price.required' => 'Each item must have a buy price.',
            'items.*.buy_price.min' => 'Buy price must be at least 0.',
            'payments.required' => 'At least one payment is required.',
            'payments.min' => 'At least one payment is required.',
            'payments.*.method.required' => 'Please select a payment method.',
            'payments.*.method.in' => 'Invalid payment method selected.',
            'payments.*.amount.required' => 'Payment amount is required.',
            'payments.*.amount.min' => 'Payment amount must be at least $0.01.',
        ];
    }
}
