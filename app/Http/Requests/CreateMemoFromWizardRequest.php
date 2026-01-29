<?php

namespace App\Http\Requests;

use App\Models\Memo;
use App\Models\Vendor;
use App\Services\StoreContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateMemoFromWizardRequest extends FormRequest
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

            // Step 2: Vendor (consignee - receives goods to sell on behalf)
            'vendor_id' => ['nullable', 'integer', 'exists:vendors,id'],
            'vendor' => ['nullable', 'array', 'required_without:vendor_id'],
            'vendor.name' => ['required_with:vendor', 'string', 'max:255'],
            'vendor.company_name' => ['nullable', 'string', 'max:255'],
            'vendor.email' => [
                'nullable',
                'email',
                'max:255',
                function ($attribute, $value, $fail) {
                    if (empty($value)) {
                        return;
                    }
                    $storeId = app(StoreContext::class)->getCurrentStore()?->id;
                    if ($storeId && Vendor::where('store_id', $storeId)->where('email', $value)->exists()) {
                        $fail('A vendor with this email already exists.');
                    }
                },
            ],
            'vendor.phone' => ['nullable', 'string', 'max:50'],

            // Step 3: Items (products from inventory)
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.price' => ['required', 'numeric', 'min:0'],
            'items.*.tenor' => ['nullable', 'integer', Rule::in(Memo::PAYMENT_TERMS)],
            'items.*.title' => ['nullable', 'string', 'max:255'],
            'items.*.description' => ['nullable', 'string', 'max:1000'],

            // General settings
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'tenure' => ['nullable', 'integer', Rule::in(Memo::PAYMENT_TERMS)],
            'description' => ['nullable', 'string', 'max:5000'],
            'charge_taxes' => ['nullable', 'boolean'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:1'],
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
            'store_user_id.required' => 'Please select an employee for this memo.',
            'vendor_id.exists' => 'The selected vendor does not exist.',
            'vendor.required_without' => 'Please select or create a vendor.',
            'vendor.name.required_with' => 'Vendor name is required.',
            'items.required' => 'At least one item is required.',
            'items.min' => 'At least one item is required.',
            'items.*.product_id.required' => 'Each item must have a product selected.',
            'items.*.product_id.exists' => 'The selected product does not exist.',
            'items.*.price.required' => 'Each item must have an expected amount.',
            'items.*.price.min' => 'Item price must be at least 0.',
            'items.*.tenor.in' => 'Invalid payment term. Must be 7, 14, 30, or 60 days.',
            'tenure.in' => 'Invalid default payment term. Must be 7, 14, 30, or 60 days.',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // Set default tenure if not provided
        if (! $this->has('tenure') || $this->tenure === null) {
            $this->merge(['tenure' => Memo::TENURE_30_DAYS]);
        }

        // Format vendor phone number if provided
        if ($this->has('vendor.phone') && ! empty($this->input('vendor.phone'))) {
            $vendor = $this->input('vendor');
            $vendor['phone'] = $this->formatPhoneNumber($this->input('vendor.phone'));
            $this->merge(['vendor' => $vendor]);
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
