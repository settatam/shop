<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MemoResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'store_id' => $this->store_id,
            'vendor_id' => $this->vendor_id,
            'user_id' => $this->user_id,
            'order_id' => $this->order_id,
            'memo_number' => $this->memo_number,
            'status' => $this->status,
            'tenure' => $this->tenure,
            'subtotal' => $this->subtotal,
            'tax' => $this->tax,
            'tax_rate' => $this->tax_rate,
            'charge_taxes' => $this->charge_taxes,
            'shipping_cost' => $this->shipping_cost,
            'total' => $this->total,
            'description' => $this->description,
            'duration' => $this->duration,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),

            'vendor' => $this->whenLoaded('vendor'),
            'user' => $this->whenLoaded('user'),
            'order' => $this->whenLoaded('order'),
            'items' => MemoItemResource::collection($this->whenLoaded('items')),
        ];
    }
}
