<?php

namespace App\Http\Requests;

use App\Models\Customer;
use App\Models\Layaway;
use App\Models\LayawaySchedule;
use App\Services\StoreContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateLayawayFromWizardRequest extends FormRequest
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
            'customer' => ['nullable', 'array', 'required_without:customer_id'],
            'customer.first_name' => ['required_with:customer', 'string', 'max:255'],
            'customer.last_name' => ['nullable', 'string', 'max:255'],
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

            // Step 3: Items (products from inventory)
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.price' => ['required', 'numeric', 'min:0'],
            'items.*.quantity' => ['nullable', 'integer', 'min:1'],
            'items.*.title' => ['nullable', 'string', 'max:255'],
            'items.*.description' => ['nullable', 'string', 'max:1000'],

            // Step 4: Terms
            'payment_type' => ['required', 'string', Rule::in(Layaway::PAYMENT_TYPES)],
            'term_days' => ['required', 'integer', Rule::in(Layaway::TERM_OPTIONS)],
            'minimum_deposit_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'cancellation_fee_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],

            // Scheduled payment options
            'num_payments' => ['required_if:payment_type,scheduled', 'nullable', 'integer', 'min:2', 'max:24'],
            'payment_frequency' => ['required_if:payment_type,scheduled', 'nullable', 'string', Rule::in(LayawaySchedule::FREQUENCIES)],

            // General settings
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'admin_notes' => ['nullable', 'string', 'max:5000'],
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
            'store_user_id.required' => 'Please select an employee for this layaway.',
            'customer_id.exists' => 'The selected customer does not exist.',
            'customer.required_without' => 'Please select or create a customer.',
            'customer.first_name.required_with' => 'Customer first name is required.',
            'items.required' => 'At least one item is required.',
            'items.min' => 'At least one item is required.',
            'items.*.product_id.required' => 'Each item must have a product selected.',
            'items.*.product_id.exists' => 'The selected product does not exist.',
            'items.*.price.required' => 'Each item must have a price.',
            'items.*.price.min' => 'Item price must be at least 0.',
            'payment_type.required' => 'Please select a payment type.',
            'payment_type.in' => 'Invalid payment type.',
            'term_days.required' => 'Please select a layaway term.',
            'term_days.in' => 'Invalid layaway term. Must be 30, 60, 90, or 120 days.',
            'num_payments.required_if' => 'Number of payments is required for scheduled payments.',
            'num_payments.min' => 'At least 2 payments are required for scheduled payments.',
            'payment_frequency.required_if' => 'Payment frequency is required for scheduled payments.',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // Set default term if not provided
        if (! $this->has('term_days') || $this->term_days === null) {
            $this->merge(['term_days' => Layaway::TERM_90_DAYS]);
        }

        // Set default payment type if not provided
        if (! $this->has('payment_type') || $this->payment_type === null) {
            $this->merge(['payment_type' => Layaway::PAYMENT_TYPE_FLEXIBLE]);
        }

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
