<?php

namespace App\Http\Requests;

use App\Models\TransactionItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTransactionItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'buy_price' => ['nullable', 'numeric', 'min:0'],
            'dwt' => ['nullable', 'numeric', 'min:0'],
            'precious_metal' => ['nullable', 'string', Rule::in([
                TransactionItem::METAL_GOLD_10K,
                TransactionItem::METAL_GOLD_14K,
                TransactionItem::METAL_GOLD_18K,
                TransactionItem::METAL_GOLD_22K,
                TransactionItem::METAL_GOLD_24K,
                TransactionItem::METAL_SILVER,
                TransactionItem::METAL_PLATINUM,
                TransactionItem::METAL_PALLADIUM,
            ])],
            'condition' => ['nullable', 'string', Rule::in([
                TransactionItem::CONDITION_NEW,
                TransactionItem::CONDITION_LIKE_NEW,
                TransactionItem::CONDITION_USED,
                TransactionItem::CONDITION_DAMAGED,
            ])],
            'attributes' => ['nullable', 'array'],
            'attributes.*' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
