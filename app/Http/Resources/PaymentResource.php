<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Payment
 */
class PaymentResource extends JsonResource
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
            'user_id' => $this->user_id,
            'payment_method' => $this->payment_method,
            'status' => $this->status,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'reference' => $this->reference,
            'transaction_id' => $this->transaction_id,
            'gateway' => $this->gateway,
            'notes' => $this->notes,
            'paid_at' => $this->paid_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
