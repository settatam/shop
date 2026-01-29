<?php

namespace App\Http\Requests;

use App\Models\Memo;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMemoRequest extends FormRequest
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
            'vendor_id' => ['nullable', 'integer', 'exists:customers,id'],
            'status' => ['nullable', 'string', Rule::in(Memo::STATUSES)],
            'tenure' => ['nullable', 'integer', Rule::in(Memo::PAYMENT_TERMS)],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'charge_taxes' => ['nullable', 'boolean'],
            'shipping_cost' => ['nullable', 'numeric', 'min:0'],
            'description' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
