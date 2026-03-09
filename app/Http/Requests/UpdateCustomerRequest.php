<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCustomerRequest extends FormRequest
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
        $customer = $this->route('customer');
        $storeId = $customer->store_id;

        return [
            'first_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'email' => [
                'nullable', 'email', 'max:255',
                Rule::unique('customers')->where('store_id', $storeId)->ignore($customer->id),
            ],
            'phone_number' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:255'],
            'address2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'zip' => ['nullable', 'string', 'max:20'],
            'id_number' => ['nullable', 'string', 'max:100'],
            'id_issuing_state' => ['nullable', 'string', 'max:5'],
            'id_expiration_date' => ['nullable', 'date'],
            'date_of_birth' => ['nullable', 'date'],
            'lead_source_id' => ['nullable', 'integer', 'exists:lead_sources,id'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'accepts_marketing' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
