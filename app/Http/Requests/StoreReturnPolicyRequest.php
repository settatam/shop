<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreReturnPolicyRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'return_window_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'allow_refund' => ['nullable', 'boolean'],
            'allow_store_credit' => ['nullable', 'boolean'],
            'allow_exchange' => ['nullable', 'boolean'],
            'restocking_fee_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'require_receipt' => ['nullable', 'boolean'],
            'require_original_packaging' => ['nullable', 'boolean'],
            'excluded_conditions' => ['nullable', 'array'],
            'excluded_conditions.*' => ['string', 'max:100'],
            'is_default' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'A policy name is required.',
            'return_window_days.min' => 'Return window must be at least 1 day.',
            'restocking_fee_percent.max' => 'Restocking fee cannot exceed 100%.',
        ];
    }
}
