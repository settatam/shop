<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\ReturnItem
 */
class ReturnItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'return_id' => $this->return_id,
            'order_item_id' => $this->order_item_id,
            'product_variant_id' => $this->product_variant_id,
            'quantity' => $this->quantity,
            'unit_price' => $this->unit_price,
            'line_total' => $this->line_total,
            'condition' => $this->condition,
            'reason' => $this->reason,
            'notes' => $this->notes,
            'restock' => $this->restock,
            'restocked' => $this->restocked,
            'restocked_at' => $this->restocked_at?->toIso8601String(),
            'exchange_variant_id' => $this->exchange_variant_id,
            'exchange_quantity' => $this->exchange_quantity,
            'is_exchange' => $this->isExchange(),
            'was_restocked' => $this->wasRestocked(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'order_item' => $this->whenLoaded('orderItem', fn () => new OrderItemResource($this->orderItem)),
            'product_variant' => $this->whenLoaded('productVariant'),
            'exchange_variant' => $this->whenLoaded('exchangeVariant'),
        ];
    }
}
