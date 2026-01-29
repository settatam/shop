<?php

namespace App\Http\Requests;

use App\Models\Memo;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMemoRequest extends FormRequest
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
            'vendor_id' => ['nullable', 'integer', 'exists:vendors,id'],
            'tenure' => ['nullable', 'integer', Rule::in(Memo::PAYMENT_TERMS)],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'charge_taxes' => ['nullable', 'boolean'],
            'shipping_cost' => ['nullable', 'numeric', 'min:0'],
            'description' => ['nullable', 'string', 'max:5000'],

            'items' => ['nullable', 'array'],
            'items.*.product_id' => ['nullable', 'integer', 'exists:products,id'],
            'items.*.category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'items.*.sku' => ['nullable', 'string', 'max:100'],
            'items.*.title' => ['nullable', 'string', 'max:255'],
            'items.*.description' => ['nullable', 'string', 'max:2000'],
            'items.*.price' => ['nullable', 'numeric', 'min:0'],
            'items.*.cost' => ['nullable', 'numeric', 'min:0'],
            'items.*.tenor' => ['nullable', 'integer', 'min:1'],
            'items.*.due_date' => ['nullable', 'date'],
            'items.*.charge_taxes' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'tenure.in' => 'Payment terms must be 7, 14, 30, or 60 days.',
            'tax_rate.max' => 'Tax rate must be a decimal between 0 and 1 (e.g., 0.08 for 8%).',
        ];
    }
}
