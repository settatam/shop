<?php

namespace App\Http\Requests;

use App\Models\Payment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProcessMemoPaymentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        // Support both single payment and array of payments
        $paymentMethodRule = Rule::in([
            Payment::METHOD_CASH,
            Payment::METHOD_CARD,
            Payment::METHOD_CHECK,
            Payment::METHOD_BANK_TRANSFER,
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
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'payment_method.required' => 'Please select a payment method.',
            'payment_method.in' => 'The selected payment method is invalid.',
            'amount.required' => 'Please enter the payment amount.',
            'amount.numeric' => 'The payment amount must be a number.',
            'amount.min' => 'The payment amount must be at least $0.01.',
        ];
    }
}
