<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RepairItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'repair_id' => $this->repair_id,
            'product_id' => $this->product_id,
            'category_id' => $this->category_id,
            'sku' => $this->sku,
            'title' => $this->title,
            'description' => $this->description,
            'vendor_cost' => $this->vendor_cost,
            'customer_cost' => $this->customer_cost,
            'status' => $this->status,
            'dwt' => $this->dwt,
            'precious_metal' => $this->precious_metal,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),

            'product' => $this->whenLoaded('product'),
            'category' => $this->whenLoaded('category'),
        ];
    }
}
