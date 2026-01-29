<?php

namespace App\Http\Requests;

use App\Models\ProductReturn;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreReturnRequest extends FormRequest
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
            'order_id' => ['required', 'integer', 'exists:orders,id'],
            'return_policy_id' => ['nullable', 'integer', 'exists:return_policies,id'],
            'type' => ['nullable', 'string', Rule::in([
                ProductReturn::TYPE_RETURN,
                ProductReturn::TYPE_EXCHANGE,
            ])],
            'reason' => ['nullable', 'string', 'max:255'],
            'customer_notes' => ['nullable', 'string', 'max:5000'],

            'items' => ['required', 'array', 'min:1'],
            'items.*.order_item_id' => ['nullable', 'integer', 'exists:order_items,id'],
            'items.*.product_variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.condition' => ['nullable', 'string', Rule::in(['new', 'like_new', 'used', 'damaged'])],
            'items.*.reason' => ['nullable', 'string', 'max:255'],
            'items.*.notes' => ['nullable', 'string', 'max:1000'],
            'items.*.restock' => ['nullable', 'boolean'],
            'items.*.exchange_variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
            'items.*.exchange_quantity' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'order_id.required' => 'An order is required to create a return.',
            'order_id.exists' => 'The specified order does not exist.',
            'items.required' => 'At least one item is required to create a return.',
            'items.min' => 'At least one item is required to create a return.',
            'items.*.quantity.required' => 'Each item must have a quantity.',
            'items.*.quantity.min' => 'Item quantity must be at least 1.',
        ];
    }
}
