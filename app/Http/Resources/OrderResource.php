<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Order
 */
class OrderResource extends JsonResource
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
            'user_id' => $this->user_id,
            'invoice_number' => $this->invoice_number,
            'status' => $this->status,
            'sub_total' => $this->sub_total,
            'shipping_cost' => $this->shipping_cost,
            'sales_tax' => $this->sales_tax,
            'discount_cost' => $this->discount_cost,
            'total' => $this->total,
            'total_paid' => $this->total_paid,
            'balance_due' => $this->balance_due,
            'is_fully_paid' => $this->isFullyPaid(),
            'item_count' => $this->item_count,
            'billing_address' => $this->billing_address,
            'shipping_address' => $this->shipping_address,
            'notes' => $this->notes,
            'source_platform' => $this->source_platform,
            'external_marketplace_id' => $this->external_marketplace_id,
            'date_of_purchase' => $this->date_of_purchase?->toDateString(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'customer' => $this->whenLoaded('customer'),
            'items' => OrderItemResource::collection($this->whenLoaded('items')),
            'payments' => PaymentResource::collection($this->whenLoaded('payments')),
        ];
    }
}
