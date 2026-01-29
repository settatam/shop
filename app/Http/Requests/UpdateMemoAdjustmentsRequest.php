<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateMemoAdjustmentsRequest extends FormRequest
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
        return [
            'discount_value' => ['nullable', 'numeric', 'min:0'],
            'discount_unit' => ['nullable', 'in:percent,fixed'],
            'discount_reason' => ['nullable', 'string', 'max:255'],
            'service_fee_value' => ['nullable', 'numeric', 'min:0'],
            'service_fee_unit' => ['nullable', 'in:percent,fixed'],
            'service_fee_reason' => ['nullable', 'string', 'max:255'],
            'charge_taxes' => ['nullable', 'boolean'],
            'tax_rate' => ['nullable', 'numeric', 'min:0'],
            'tax_type' => ['nullable', 'in:percent,fixed'],
            'shipping_cost' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'discount_value.numeric' => 'The discount value must be a number.',
            'discount_value.min' => 'The discount value cannot be negative.',
            'discount_unit.in' => 'The discount unit must be either percent or fixed.',
            'service_fee_value.numeric' => 'The service fee value must be a number.',
            'service_fee_value.min' => 'The service fee value cannot be negative.',
            'service_fee_unit.in' => 'The service fee unit must be either percent or fixed.',
            'tax_rate.numeric' => 'The tax rate must be a number.',
            'tax_rate.min' => 'The tax rate cannot be negative.',
            'tax_type.in' => 'The tax type must be either percent or fixed.',
            'shipping_cost.numeric' => 'The shipping cost must be a number.',
            'shipping_cost.min' => 'The shipping cost cannot be negative.',
        ];
    }
}
