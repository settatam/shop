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
            'notes' => ['nullable', 'string', 'max:500'],
        ];
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
