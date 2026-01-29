<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\ProductReturn
 */
class ProductReturnResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'store_id' => $this->store_id,
            'order_id' => $this->order_id,
            'customer_id' => $this->customer_id,
            'return_policy_id' => $this->return_policy_id,
            'processed_by' => $this->processed_by,
            'return_number' => $this->return_number,
            'status' => $this->status,
            'type' => $this->type,
            'subtotal' => $this->subtotal,
            'restocking_fee' => $this->restocking_fee,
            'refund_amount' => $this->refund_amount,
            'refund_method' => $this->refund_method,
            'reason' => $this->reason,
            'customer_notes' => $this->customer_notes,
            'internal_notes' => $this->internal_notes,
            'external_return_id' => $this->external_return_id,
            'source_platform' => $this->source_platform,
            'store_marketplace_id' => $this->store_marketplace_id,
            'sync_status' => $this->sync_status,
            'synced_at' => $this->synced_at?->toIso8601String(),
            'requested_at' => $this->requested_at?->toIso8601String(),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'received_at' => $this->received_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'item_count' => $this->item_count,
            'is_pending' => $this->isPending(),
            'is_approved' => $this->isApproved(),
            'is_completed' => $this->isCompleted(),
            'is_exchange' => $this->isExchange(),
            'can_be_approved' => $this->canBeApproved(),
            'can_be_processed' => $this->canBeProcessed(),
            'can_be_cancelled' => $this->canBeCancelled(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'order' => $this->whenLoaded('order', fn () => new OrderResource($this->order)),
            'customer' => $this->whenLoaded('customer'),
            'items' => ReturnItemResource::collection($this->whenLoaded('items')),
            'return_policy' => $this->whenLoaded('returnPolicy', fn () => new ReturnPolicyResource($this->returnPolicy)),
            'processed_by_user' => $this->whenLoaded('processedByUser'),
        ];
    }
}
