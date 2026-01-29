<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MemoItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'memo_id' => $this->memo_id,
            'product_id' => $this->product_id,
            'category_id' => $this->category_id,
            'sku' => $this->sku,
            'title' => $this->title,
            'description' => $this->description,
            'price' => $this->price,
            'cost' => $this->cost,
            'tenor' => $this->tenor,
            'due_date' => $this->due_date?->toDateString(),
            'is_returned' => $this->is_returned,
            'charge_taxes' => $this->charge_taxes,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),

            'product' => $this->whenLoaded('product'),
            'category' => $this->whenLoaded('category'),
        ];
    }
}
