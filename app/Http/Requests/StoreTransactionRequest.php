<?php

namespace App\Http\Requests;

use App\Models\Transaction;
use App\Models\TransactionItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTransactionRequest extends FormRequest
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
            'type' => ['nullable', 'string', Rule::in([
                Transaction::TYPE_IN_HOUSE,
                Transaction::TYPE_MAIL_IN,
            ])],
            'preliminary_offer' => ['nullable', 'numeric', 'min:0'],
            'estimated_value' => ['nullable', 'numeric', 'min:0'],
            'bin_location' => ['nullable', 'string', 'max:50'],
            'customer_notes' => ['nullable', 'string', 'max:2000'],

            'items' => ['nullable', 'array'],
            'items.*.category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'items.*.sku' => ['nullable', 'string', 'max:100'],
            'items.*.title' => ['nullable', 'string', 'max:255'],
            'items.*.description' => ['nullable', 'string', 'max:2000'],
            'items.*.price' => ['nullable', 'numeric', 'min:0'],
            'items.*.buy_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.dwt' => ['nullable', 'numeric', 'min:0'],
            'items.*.precious_metal' => ['nullable', 'string', Rule::in([
                TransactionItem::METAL_GOLD_10K,
                TransactionItem::METAL_GOLD_14K,
                TransactionItem::METAL_GOLD_18K,
                TransactionItem::METAL_GOLD_22K,
                TransactionItem::METAL_GOLD_24K,
                TransactionItem::METAL_SILVER,
                TransactionItem::METAL_PLATINUM,
                TransactionItem::METAL_PALLADIUM,
            ])],
            'items.*.condition' => ['nullable', 'string', Rule::in([
                TransactionItem::CONDITION_NEW,
                TransactionItem::CONDITION_LIKE_NEW,
                TransactionItem::CONDITION_USED,
                TransactionItem::CONDITION_DAMAGED,
            ])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'type.in' => 'Transaction type must be either in_house or mail_in.',
            'items.*.precious_metal.in' => 'Invalid precious metal type.',
            'items.*.condition.in' => 'Invalid item condition.',
        ];
    }
}
