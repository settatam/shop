<?php

namespace App\Http\Requests\Portal;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePayoutPreferenceRequest extends FormRequest
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
        return [
            'payments' => ['required', 'array', 'min:1'],
            'payments.*.method' => ['required', Rule::in(['check', 'paypal', 'venmo', 'ach'])],
            'payments.*.amount' => ['required', 'numeric', 'min:0'],
            'payments.*.details' => ['nullable', 'array'],

            // Check details
            'payments.*.details.mailing_name' => ['required_if:payments.*.method,check', 'nullable', 'string', 'max:255'],
            'payments.*.details.mailing_address' => ['required_if:payments.*.method,check', 'nullable', 'string', 'max:255'],
            'payments.*.details.mailing_city' => ['required_if:payments.*.method,check', 'nullable', 'string', 'max:255'],
            'payments.*.details.mailing_state' => ['required_if:payments.*.method,check', 'nullable', 'string', 'max:255'],
            'payments.*.details.mailing_zip' => ['required_if:payments.*.method,check', 'nullable', 'string', 'max:20'],

            // PayPal details
            'payments.*.details.paypal_email' => ['required_if:payments.*.method,paypal', 'nullable', 'email', 'max:255'],

            // Venmo details
            'payments.*.details.venmo_handle' => ['required_if:payments.*.method,venmo', 'nullable', 'string', 'max:255'],

            // ACH details
            'payments.*.details.bank_name' => ['required_if:payments.*.method,ach', 'nullable', 'string', 'max:255'],
            'payments.*.details.account_name' => ['required_if:payments.*.method,ach', 'nullable', 'string', 'max:255'],
            'payments.*.details.account_number' => ['required_if:payments.*.method,ach', 'nullable', 'string', 'max:255'],
            'payments.*.details.routing_number' => ['required_if:payments.*.method,ach', 'nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'payments.required' => 'At least one payment method is required.',
            'payments.*.method.required' => 'Please select a payout method.',
            'payments.*.method.in' => 'The selected payout method is invalid.',
            'payments.*.amount.required' => 'Please enter an amount for each payout method.',
            'payments.*.details.mailing_name.required_if' => 'Mailing name is required for check payments.',
            'payments.*.details.mailing_address.required_if' => 'Mailing address is required for check payments.',
            'payments.*.details.mailing_city.required_if' => 'City is required for check payments.',
            'payments.*.details.mailing_state.required_if' => 'State is required for check payments.',
            'payments.*.details.mailing_zip.required_if' => 'ZIP code is required for check payments.',
            'payments.*.details.paypal_email.required_if' => 'PayPal email is required for PayPal payments.',
            'payments.*.details.paypal_email.email' => 'Please enter a valid PayPal email address.',
            'payments.*.details.venmo_handle.required_if' => 'Venmo handle is required for Venmo payments.',
            'payments.*.details.bank_name.required_if' => 'Bank name is required for ACH payments.',
            'payments.*.details.account_name.required_if' => 'Account name is required for ACH payments.',
            'payments.*.details.account_number.required_if' => 'Account number is required for ACH payments.',
            'payments.*.details.routing_number.required_if' => 'Routing number is required for ACH payments.',
        ];
    }
}
