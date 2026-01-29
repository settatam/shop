<?php

namespace App\Http\Requests;

use App\Models\ProductReturn;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProcessReturnRequest extends FormRequest
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
            'refund_method' => ['required', 'string', Rule::in([
                ProductReturn::REFUND_ORIGINAL,
                ProductReturn::REFUND_STORE_CREDIT,
                ProductReturn::REFUND_CASH,
                ProductReturn::REFUND_CARD,
            ])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'refund_method.required' => 'A refund method is required to process the return.',
            'refund_method.in' => 'Invalid refund method. Must be one of: original_payment, store_credit, cash, card.',
        ];
    }
}
