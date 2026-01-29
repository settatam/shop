<?php

namespace App\Http\Requests;

use App\Models\Repair;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRepairRequest extends FormRequest
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
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'vendor_id' => ['nullable', 'integer', 'exists:customers,id'],
            'status' => ['nullable', 'string', Rule::in(Repair::STATUSES)],
            'service_fee' => ['nullable', 'numeric', 'min:0'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'shipping_cost' => ['nullable', 'numeric', 'min:0'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'description' => ['nullable', 'string', 'max:5000'],
            'is_appraisal' => ['nullable', 'boolean'],
        ];
    }
}
