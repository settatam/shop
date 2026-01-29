<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RepairResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'store_id' => $this->store_id,
            'customer_id' => $this->customer_id,
            'vendor_id' => $this->vendor_id,
            'user_id' => $this->user_id,
            'order_id' => $this->order_id,
            'repair_number' => $this->repair_number,
            'status' => $this->status,
            'service_fee' => $this->service_fee,
            'subtotal' => $this->subtotal,
            'tax' => $this->tax,
            'tax_rate' => $this->tax_rate,
            'discount' => $this->discount,
            'shipping_cost' => $this->shipping_cost,
            'total' => $this->total,
            'description' => $this->description,
            'repair_days' => $this->repair_days,
            'is_appraisal' => $this->is_appraisal,
            'date_sent_to_vendor' => $this->date_sent_to_vendor?->toIso8601String(),
            'date_received_by_vendor' => $this->date_received_by_vendor?->toIso8601String(),
            'date_completed' => $this->date_completed?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),

            'customer' => $this->whenLoaded('customer'),
            'vendor' => $this->whenLoaded('vendor'),
            'user' => $this->whenLoaded('user'),
            'order' => $this->whenLoaded('order'),
            'items' => RepairItemResource::collection($this->whenLoaded('items')),
        ];
    }
}
