<?php

namespace App\Http\Requests;

use App\Models\Customer;
use App\Services\StoreContext;
use Illuminate\Foundation\Http\FormRequest;

class CreateOrderFromWizardRequest extends FormRequest
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
            // Step 1: Store User (Employee)
            'store_user_id' => ['required', 'integer', 'exists:store_users,id'],

            // Step 2: Customer
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'customer' => ['nullable', 'array'],
            'customer.first_name' => ['required_with:customer', 'string', 'max:100'],
            'customer.last_name' => ['required_with:customer', 'string', 'max:100'],
            'customer.email' => [
                'nullable',
                'email',
                'max:255',
                function ($attribute, $value, $fail) {
                    if (empty($value)) {
                        return;
                    }
                    $storeId = app(StoreContext::class)->getCurrentStore()?->id;
                    if ($storeId && Customer::where('store_id', $storeId)->where('email', $value)->exists()) {
                        $fail('A customer with this email already exists.');
                    }
                },
            ],
            'customer.phone' => ['nullable', 'string', 'max:50'],

            // Step 3: Items (products) - allow empty if bucket_items provided
            'items' => ['required_without:bucket_items', 'array'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.price' => ['required', 'numeric', 'min:0'],
            'items.*.cost' => ['nullable', 'numeric', 'min:0'],
            'items.*.discount' => ['nullable', 'numeric', 'min:0'],
            'items.*.title' => ['nullable', 'string', 'max:255'],
            'items.*.sku' => ['nullable', 'string', 'max:255'],
            'items.*.notes' => ['nullable', 'string', 'max:1000'],

            // Order settings
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'shipping_cost' => ['nullable', 'numeric', 'min:0'],
            'discount_cost' => ['nullable', 'numeric', 'min:0'],
            'date_of_purchase' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],

            // Trade-in items
            'has_trade_in' => ['nullable', 'boolean'],
            'trade_in_items' => ['nullable', 'array'],
            'trade_in_items.*.id' => ['nullable', 'string'],
            'trade_in_items.*.title' => ['required', 'string', 'max:255'],
            'trade_in_items.*.description' => ['nullable', 'string', 'max:1000'],
            'trade_in_items.*.category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'trade_in_items.*.buy_price' => ['required', 'numeric', 'min:0'],
            'trade_in_items.*.attributes' => ['nullable', 'array'],
            'trade_in_items.*.images' => ['nullable', 'array'],
            'trade_in_items.*.images.*' => ['nullable', 'file', 'image', 'max:10240'],
            'excess_credit_payout_method' => ['nullable', 'string', 'in:cash,check'],

            // Bucket items (selling from junk buckets)
            'sell_from_bucket' => ['nullable', 'boolean'],
            'bucket_items' => ['nullable', 'array'],
            'bucket_items.*.id' => ['required', 'integer', 'exists:bucket_items,id'],
            'bucket_items.*.price' => ['nullable', 'numeric', 'min:0'],
            'bucket_items.*.notes' => ['nullable', 'string', 'max:1000'],

            // Addresses (optional for in-store sales)
            'billing_address' => ['nullable', 'array'],
            'billing_address.address_line_1' => ['nullable', 'string', 'max:255'],
            'billing_address.address_line_2' => ['nullable', 'string', 'max:255'],
            'billing_address.city' => ['nullable', 'string', 'max:100'],
            'billing_address.state' => ['nullable', 'string', 'max:100'],
            'billing_address.postal_code' => ['nullable', 'string', 'max:20'],
            'billing_address.country' => ['nullable', 'string', 'max:100'],

            'shipping_address' => ['nullable', 'array'],
            'shipping_address.address_line_1' => ['nullable', 'string', 'max:255'],
            'shipping_address.address_line_2' => ['nullable', 'string', 'max:255'],
            'shipping_address.city' => ['nullable', 'string', 'max:100'],
            'shipping_address.state' => ['nullable', 'string', 'max:100'],
            'shipping_address.postal_code' => ['nullable', 'string', 'max:20'],
            'shipping_address.country' => ['nullable', 'string', 'max:100'],
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
            'store_user_id.required' => 'Please select an employee for this order.',
            'customer_id.exists' => 'The selected customer does not exist.',
            'customer.first_name.required_with' => 'Customer first name is required.',
            'customer.last_name.required_with' => 'Customer last name is required.',
            'items.required_without' => 'At least one product or bucket item is required.',
            'items.*.product_id.required' => 'Each item must have a product selected.',
            'items.*.product_id.exists' => 'The selected product does not exist.',
            'items.*.quantity.required' => 'Each item must have a quantity.',
            'items.*.quantity.min' => 'Item quantity must be at least 1.',
            'items.*.price.required' => 'Each item must have a price.',
            'items.*.price.min' => 'Item price must be at least 0.',
            'trade_in_items.*.title.required' => 'Each trade-in item must have a title.',
            'trade_in_items.*.buy_price.required' => 'Each trade-in item must have a buy price.',
            'trade_in_items.*.buy_price.min' => 'Trade-in buy price must be at least 0.',
            'bucket_items.*.id.required' => 'Each bucket item must be selected.',
            'bucket_items.*.id.exists' => 'The selected bucket item does not exist.',
            'bucket_items.*.price.min' => 'Bucket item price must be at least 0.',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // Format customer phone number if provided
        if ($this->has('customer.phone') && ! empty($this->input('customer.phone'))) {
            $customer = $this->input('customer');
            $customer['phone'] = $this->formatPhoneNumber($this->input('customer.phone'));
            $this->merge(['customer' => $customer]);
        }
    }

    /**
     * Format phone number to (123) 456-7890 format.
     */
    protected function formatPhoneNumber(string $phone): string
    {
        // Remove all non-numeric characters
        $digits = preg_replace('/[^0-9]/', '', $phone);

        // Handle 10-digit US phone numbers
        if (strlen($digits) === 10) {
            return sprintf('(%s) %s-%s',
                substr($digits, 0, 3),
                substr($digits, 3, 3),
                substr($digits, 6, 4)
            );
        }

        // Handle 11-digit numbers starting with 1 (country code)
        if (strlen($digits) === 11 && $digits[0] === '1') {
            return sprintf('(%s) %s-%s',
                substr($digits, 1, 3),
                substr($digits, 4, 3),
                substr($digits, 7, 4)
            );
        }

        // Return original if not a standard US number
        return $phone;
    }
}
