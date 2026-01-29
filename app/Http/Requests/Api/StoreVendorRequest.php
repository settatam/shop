<?php

namespace App\Http\Requests\Api;

use App\Models\Vendor;
use App\Services\StoreContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreVendorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $storeId = app(StoreContext::class)->getCurrentStoreId();

        return [
            'name' => ['required', 'string', 'max:191'],
            'code' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('vendors')->where('store_id', $storeId),
            ],
            'company_name' => ['nullable', 'string', 'max:191'],
            'email' => ['nullable', 'email', 'max:191'],
            'phone' => ['nullable', 'string', 'max:50'],
            'website' => ['nullable', 'url', 'max:191'],
            'address_line1' => ['nullable', 'string', 'max:191'],
            'address_line2' => ['nullable', 'string', 'max:191'],
            'city' => ['nullable', 'string', 'max:191'],
            'state' => ['nullable', 'string', 'max:191'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'country' => ['nullable', 'string', 'max:100'],
            'tax_id' => ['nullable', 'string', 'max:50'],
            'payment_terms' => ['nullable', 'string', Rule::in(Vendor::PAYMENT_TERMS)],
            'lead_time_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'currency_code' => ['nullable', 'string', 'size:3'],
            'contact_name' => ['nullable', 'string', 'max:191'],
            'contact_email' => ['nullable', 'email', 'max:191'],
            'contact_phone' => ['nullable', 'string', 'max:50'],
            'is_active' => ['boolean'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
