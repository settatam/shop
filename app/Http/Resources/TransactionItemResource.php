<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransactionItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'transaction_id' => $this->transaction_id,
            'category_id' => $this->category_id,
            'product_id' => $this->product_id,
            'sku' => $this->sku,
            'title' => $this->title,
            'description' => $this->description,
            'price' => $this->price,
            'buy_price' => $this->buy_price,
            'dwt' => $this->dwt,
            'precious_metal' => $this->precious_metal,
            'condition' => $this->condition,
            'is_added_to_inventory' => $this->is_added_to_inventory,
            'date_added_to_inventory' => $this->date_added_to_inventory?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),

            'category' => $this->whenLoaded('category'),
            'product' => $this->whenLoaded('product'),
        ];
    }
}
