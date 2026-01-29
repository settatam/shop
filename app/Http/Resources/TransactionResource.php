<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransactionResource extends JsonResource
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
            'transaction_number' => $this->transaction_number,
            'status' => $this->status,
            'type' => $this->type,
            'preliminary_offer' => $this->preliminary_offer,
            'final_offer' => $this->final_offer,
            'estimated_value' => $this->estimated_value,
            'payment_method' => $this->payment_method,
            'bin_location' => $this->bin_location,
            'customer_notes' => $this->customer_notes,
            'internal_notes' => $this->internal_notes,
            'offer_given_at' => $this->offer_given_at?->toIso8601String(),
            'offer_accepted_at' => $this->offer_accepted_at?->toIso8601String(),
            'payment_processed_at' => $this->payment_processed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),

            'customer' => $this->whenLoaded('customer'),
            'user' => $this->whenLoaded('user'),
            'items' => TransactionItemResource::collection($this->whenLoaded('items')),
        ];
    }
}
