<?php

namespace App\Http\Requests;

use App\Models\Payment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOrderRequest extends FormRequest
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
            'customer' => ['nullable', 'array'],
            'customer.id' => ['nullable', 'integer', 'exists:customers,id'],
            'customer.email' => ['nullable', 'email', 'max:255'],
            'customer.first_name' => ['nullable', 'string', 'max:255'],
            'customer.last_name' => ['nullable', 'string', 'max:255'],
            'customer.phone' => ['nullable', 'string', 'max:50'],

            'items' => ['required', 'array', 'min:1'],
            'items.*.product_variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
            'items.*.product_id' => ['nullable', 'integer', 'exists:products,id'],
            'items.*.sku' => ['nullable', 'string', 'max:100'],
            'items.*.title' => ['nullable', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.price' => ['nullable', 'numeric', 'min:0'],
            'items.*.cost' => ['nullable', 'numeric', 'min:0'],
            'items.*.discount' => ['nullable', 'numeric', 'min:0'],
            'items.*.tax' => ['nullable', 'numeric', 'min:0'],
            'items.*.validate_stock' => ['nullable', 'boolean'],
            'items.*.reduce_stock' => ['nullable', 'boolean'],
            'items.*.notes' => ['nullable', 'string', 'max:1000'],

            'shipping_cost' => ['nullable', 'numeric', 'min:0'],
            'shipping_weight' => ['nullable', 'numeric', 'min:0'],
            'discount_cost' => ['nullable', 'numeric', 'min:0'],
            'sales_tax' => ['nullable', 'numeric', 'min:0'],

            'payments' => ['nullable', 'array'],
            'payments.*.amount' => ['required', 'numeric', 'min:0.01'],
            'payments.*.payment_method' => ['nullable', 'string', Rule::in([
                Payment::METHOD_CASH,
                Payment::METHOD_CARD,
                Payment::METHOD_STORE_CREDIT,
                Payment::METHOD_LAYAWAY,
                Payment::METHOD_EXTERNAL,
                Payment::METHOD_CHECK,
                Payment::METHOD_BANK_TRANSFER,
            ])],
            'payments.*.reference' => ['nullable', 'string', 'max:100'],
            'payments.*.transaction_id' => ['nullable', 'string', 'max:255'],
            'payments.*.gateway' => ['nullable', 'string', 'max:50'],
            'payments.*.notes' => ['nullable', 'string', 'max:1000'],

            'billing_address' => ['nullable', 'array'],
            'billing_address.address_line1' => ['nullable', 'string', 'max:255'],
            'billing_address.address_line2' => ['nullable', 'string', 'max:255'],
            'billing_address.city' => ['nullable', 'string', 'max:100'],
            'billing_address.state' => ['nullable', 'string', 'max:100'],
            'billing_address.postal_code' => ['nullable', 'string', 'max:20'],
            'billing_address.country' => ['nullable', 'string', 'max:2'],

            'shipping_address' => ['nullable', 'array'],
            'shipping_address.address_line1' => ['nullable', 'string', 'max:255'],
            'shipping_address.address_line2' => ['nullable', 'string', 'max:255'],
            'shipping_address.city' => ['nullable', 'string', 'max:100'],
            'shipping_address.state' => ['nullable', 'string', 'max:100'],
            'shipping_address.postal_code' => ['nullable', 'string', 'max:20'],
            'shipping_address.country' => ['nullable', 'string', 'max:2'],

            'notes' => ['nullable', 'string', 'max:5000'],
            'date_of_purchase' => ['nullable', 'date'],
            'source_platform' => ['nullable', 'string', 'max:50'],
            'external_marketplace_id' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'items.required' => 'At least one item is required to create an order.',
            'items.min' => 'At least one item is required to create an order.',
            'items.*.quantity.required' => 'Each item must have a quantity.',
            'items.*.quantity.min' => 'Item quantity must be at least 1.',
            'payments.*.amount.required' => 'Payment amount is required.',
            'payments.*.amount.min' => 'Payment amount must be at least 0.01.',
        ];
    }
}
