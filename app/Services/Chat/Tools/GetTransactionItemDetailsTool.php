<?php

namespace App\Services\Chat\Tools;

use App\Models\TransactionItem;

class GetTransactionItemDetailsTool implements ChatToolInterface
{
    public function __construct(
        protected ?int $itemId = null,
    ) {}

    public function name(): string
    {
        return 'get_transaction_item_details';
    }

    public function definition(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'Get details about the current transaction item being viewed, including title, description, category, metal type, weight, pricing, and AI research data.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'item_id' => [
                        'type' => 'integer',
                        'description' => 'The transaction item ID. If not provided, uses the current item context.',
                    ],
                ],
                'required' => [],
            ],
        ];
    }

    public function execute(array $params, int $storeId): array
    {
        $itemId = $params['item_id'] ?? $this->itemId;

        if (! $itemId) {
            return ['error' => 'No item ID provided'];
        }

        $item = TransactionItem::with(['category', 'transaction.customer', 'images'])
            ->whereHas('transaction', fn ($q) => $q->where('store_id', $storeId))
            ->find($itemId);

        if (! $item) {
            return ['error' => 'Item not found'];
        }

        return [
            'id' => $item->id,
            'title' => $item->title,
            'description' => $item->description,
            'sku' => $item->sku,
            'category' => $item->category?->name,
            'precious_metal' => $item->precious_metal,
            'dwt' => $item->dwt,
            'condition' => $item->condition,
            'price' => $item->price,
            'buy_price' => $item->buy_price,
            'is_added_to_inventory' => $item->is_added_to_inventory,
            'image_count' => $item->images->count(),
            'ai_research' => $item->ai_research,
            'transaction' => [
                'id' => $item->transaction->id,
                'number' => $item->transaction->transaction_number,
                'status' => $item->transaction->status,
                'customer' => $item->transaction->customer?->full_name,
            ],
        ];
    }

    public function setItemId(int $itemId): self
    {
        $this->itemId = $itemId;

        return $this;
    }
}
