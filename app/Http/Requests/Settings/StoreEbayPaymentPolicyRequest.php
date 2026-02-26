<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

class StoreEbayPaymentPolicyRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'marketplaceId' => ['required', 'string', 'max:50'],
            'paymentMethods' => ['nullable', 'array'],
            'categoryTypes' => ['nullable', 'array'],
            'description' => ['nullable', 'string', 'max:5000'],
            'immediatePay' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'A policy name is required.',
            'marketplaceId.required' => 'The eBay marketplace is required.',
        ];
    }
}
