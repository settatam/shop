<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateReturnPolicyRequest extends FormRequest
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
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'return_window_days' => ['sometimes', 'integer', 'min:1', 'max:365'],
            'allow_refund' => ['sometimes', 'boolean'],
            'allow_store_credit' => ['sometimes', 'boolean'],
            'allow_exchange' => ['sometimes', 'boolean'],
            'restocking_fee_percent' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'require_receipt' => ['sometimes', 'boolean'],
            'require_original_packaging' => ['sometimes', 'boolean'],
            'excluded_conditions' => ['sometimes', 'nullable', 'array'],
            'excluded_conditions.*' => ['string', 'max:100'],
            'is_default' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
