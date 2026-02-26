<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

class StoreEbayFulfillmentPolicyRequest extends FormRequest
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
            'handlingTime' => ['nullable', 'array'],
            'handlingTime.value' => ['nullable', 'integer', 'min:0'],
            'handlingTime.unit' => ['nullable', 'string'],
            'shippingOptions' => ['nullable', 'array'],
            'categoryTypes' => ['nullable', 'array'],
            'description' => ['nullable', 'string', 'max:5000'],
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
