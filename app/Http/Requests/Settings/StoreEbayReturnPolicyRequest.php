<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

class StoreEbayReturnPolicyRequest extends FormRequest
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
            'returnsAccepted' => ['required', 'boolean'],
            'returnPeriod' => ['nullable', 'array'],
            'returnPeriod.value' => ['nullable', 'integer'],
            'returnPeriod.unit' => ['nullable', 'string'],
            'refundMethod' => ['nullable', 'string', 'in:MONEY_BACK,MERCHANDISE_CREDIT'],
            'returnShippingCostPayer' => ['nullable', 'string', 'in:BUYER,SELLER'],
            'description' => ['nullable', 'string', 'max:5000'],
            'categoryTypes' => ['nullable', 'array'],
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
            'returnsAccepted.required' => 'Please specify whether returns are accepted.',
        ];
    }
}
