<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\OrderItem
 */
class OrderItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_id' => $this->order_id,
            'product_id' => $this->product_id,
            'product_variant_id' => $this->product_variant_id,
            'sku' => $this->sku,
            'title' => $this->title,
            'quantity' => $this->quantity,
            'price' => $this->price,
            'cost' => $this->cost,
            'discount' => $this->discount,
            'tax' => $this->tax,
            'line_total' => $this->line_total,
            'line_profit' => $this->line_profit,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
            'product' => $this->whenLoaded('product'),
            'variant' => $this->whenLoaded('variant'),
        ];
    }
}
