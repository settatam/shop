<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePaymentAdjustmentsRequest extends FormRequest
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
            'discount_value' => ['nullable', 'numeric', 'min:0'],
            'discount_unit' => ['nullable', Rule::in(['fixed', 'percent'])],
            'discount_reason' => ['nullable', 'string', 'max:255'],
            'service_fee_value' => ['nullable', 'numeric', 'min:0'],
            'service_fee_unit' => ['nullable', Rule::in(['fixed', 'percent'])],
            'service_fee_reason' => ['nullable', 'string', 'max:255'],
            'charge_taxes' => ['nullable', 'boolean'],
            'tax_rate' => ['nullable', 'numeric', 'min:0'],
            'tax_type' => ['nullable', Rule::in(['fixed', 'percent'])],
            'shipping_cost' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
