<?php

namespace App\Http\Requests;

use App\Models\StoreCredit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCreditCashOutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:0.01'],
            'payout_method' => [
                'required',
                'string',
                Rule::in([
                    StoreCredit::PAYOUT_CASH,
                    StoreCredit::PAYOUT_CHECK,
                    StoreCredit::PAYOUT_PAYPAL,
                    StoreCredit::PAYOUT_VENMO,
                    StoreCredit::PAYOUT_ACH,
                    StoreCredit::PAYOUT_WIRE_TRANSFER,
                ]),
            ],
            'payout_details' => ['nullable', 'array'],
            'payout_details.paypal_email' => ['nullable', 'email', 'max:255'],
            'payout_details.venmo_handle' => ['nullable', 'string', 'max:100'],
            'payout_details.check_number' => ['nullable', 'string', 'max:50'],
            'payout_details.check_mailing_address' => ['nullable', 'array'],
            'payout_details.check_mailing_address.address' => ['nullable', 'string', 'max:255'],
            'payout_details.check_mailing_address.city' => ['nullable', 'string', 'max:100'],
            'payout_details.check_mailing_address.state' => ['nullable', 'string', 'max:100'],
            'payout_details.check_mailing_address.zip' => ['nullable', 'string', 'max:20'],
            'payout_details.bank_name' => ['nullable', 'string', 'max:255'],
            'payout_details.account_holder_name' => ['nullable', 'string', 'max:255'],
            'payout_details.account_number' => ['nullable', 'string', 'max:50'],
            'payout_details.routing_number' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function ($validator) {
            $method = $this->input('payout_method');
            $details = $this->input('payout_details', []);

            match ($method) {
                StoreCredit::PAYOUT_PAYPAL => empty($details['paypal_email'])
                    ? $validator->errors()->add('payout_details.paypal_email', 'PayPal email is required.')
                    : null,
                StoreCredit::PAYOUT_VENMO => empty($details['venmo_handle'])
                    ? $validator->errors()->add('payout_details.venmo_handle', 'Venmo handle is required.')
                    : null,
                StoreCredit::PAYOUT_CHECK => $this->validateCheckDetails($validator, $details),
                StoreCredit::PAYOUT_ACH, StoreCredit::PAYOUT_WIRE_TRANSFER => $this->validateBankDetails($validator, $details),
                default => null,
            };
        });
    }

    protected function validateCheckDetails(\Illuminate\Validation\Validator $validator, array $details): void
    {
        $address = $details['check_mailing_address'] ?? [];
        if (empty($address['address'])) {
            $validator->errors()->add('payout_details.check_mailing_address.address', 'Mailing address is required for check payments.');
        }
        if (empty($address['city'])) {
            $validator->errors()->add('payout_details.check_mailing_address.city', 'City is required for check payments.');
        }
        if (empty($address['state'])) {
            $validator->errors()->add('payout_details.check_mailing_address.state', 'State is required for check payments.');
        }
        if (empty($address['zip'])) {
            $validator->errors()->add('payout_details.check_mailing_address.zip', 'ZIP code is required for check payments.');
        }
    }

    protected function validateBankDetails(\Illuminate\Validation\Validator $validator, array $details): void
    {
        if (empty($details['bank_name'])) {
            $validator->errors()->add('payout_details.bank_name', 'Bank name is required.');
        }
        if (empty($details['account_holder_name'])) {
            $validator->errors()->add('payout_details.account_holder_name', 'Account holder name is required.');
        }
        if (empty($details['account_number'])) {
            $validator->errors()->add('payout_details.account_number', 'Account number is required.');
        }
        if (empty($details['routing_number'])) {
            $validator->errors()->add('payout_details.routing_number', 'Routing number is required.');
        }
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'amount.required' => 'Please enter an amount to cash out.',
            'amount.min' => 'The cash out amount must be at least $0.01.',
            'payout_method.required' => 'Please select a payout method.',
            'payout_method.in' => 'Please select a valid payout method.',
        ];
    }
}
