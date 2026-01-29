<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateRepairFromWizardRequest extends FormRequest
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

            // Step 2: Customer (owner of the item to be repaired)
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'customer' => ['nullable', 'array', 'required_without:customer_id'],
            'customer.first_name' => ['required_with:customer', 'string', 'max:100'],
            'customer.last_name' => ['required_with:customer', 'string', 'max:100'],
            'customer.company_name' => ['nullable', 'string', 'max:255'],
            'customer.email' => ['nullable', 'email', 'max:255'],
            'customer.phone_number' => ['nullable', 'string', 'max:50'],

            // Step 3: Items (products brought for repair)
            'items' => ['required', 'array', 'min:1'],
            'items.*.title' => ['required', 'string', 'max:255'],
            'items.*.description' => ['nullable', 'string', 'max:2000'],
            'items.*.category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'items.*.vendor_cost' => ['nullable', 'numeric', 'min:0'],
            'items.*.customer_cost' => ['nullable', 'numeric', 'min:0'],
            'items.*.dwt' => ['nullable', 'numeric', 'min:0'],
            'items.*.precious_metal' => ['nullable', 'string', 'max:50'],

            // Step 4: Vendor (repair service provider)
            'vendor_id' => ['nullable', 'integer', 'exists:vendors,id'],
            'vendor' => ['nullable', 'array'],
            'vendor.name' => ['required_with:vendor', 'string', 'max:255'],
            'vendor.company_name' => ['nullable', 'string', 'max:255'],
            'vendor.email' => ['nullable', 'email', 'max:255'],
            'vendor.phone' => ['nullable', 'string', 'max:50'],

            // Step 5: Review settings
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'service_fee' => ['nullable', 'numeric', 'min:0'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'shipping_cost' => ['nullable', 'numeric', 'min:0'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'description' => ['nullable', 'string', 'max:5000'],
            'is_appraisal' => ['nullable', 'boolean'],
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
            'store_user_id.required' => 'Please select an employee for this repair.',
            'customer_id.exists' => 'The selected customer does not exist.',
            'customer.required_without' => 'Please select or create a customer.',
            'customer.first_name.required_with' => 'Customer first name is required.',
            'customer.last_name.required_with' => 'Customer last name is required.',
            'items.required' => 'At least one item is required.',
            'items.min' => 'At least one item is required.',
            'items.*.title.required' => 'Each item must have a title.',
            'items.*.vendor_cost.min' => 'Vendor cost must be at least 0.',
            'items.*.customer_cost.min' => 'Customer cost must be at least 0.',
            'vendor.name.required_with' => 'Vendor name is required.',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // Format phone numbers if provided
        if ($this->has('customer.phone_number') && ! empty($this->input('customer.phone_number'))) {
            $customer = $this->input('customer');
            $customer['phone_number'] = $this->formatPhoneNumber($this->input('customer.phone_number'));
            $this->merge(['customer' => $customer]);
        }

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
            return sprintf(
                '(%s) %s-%s',
                substr($digits, 0, 3),
                substr($digits, 3, 3),
                substr($digits, 6, 4)
            );
        }

        // Handle 11-digit numbers starting with 1 (country code)
        if (strlen($digits) === 11 && $digits[0] === '1') {
            return sprintf(
                '(%s) %s-%s',
                substr($digits, 1, 3),
                substr($digits, 4, 3),
                substr($digits, 7, 4)
            );
        }

        // Return original if not a standard US number
        return $phone;
    }
}
