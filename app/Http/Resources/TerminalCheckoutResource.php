<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TerminalCheckoutResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'store_id' => $this->store_id,
            'invoice_id' => $this->invoice_id,
            'terminal_id' => $this->terminal_id,
            'user_id' => $this->user_id,
            'payment_id' => $this->payment_id,
            'checkout_id' => $this->checkout_id,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'status' => $this->status,
            'external_payment_id' => $this->external_payment_id,
            'error_message' => $this->error_message,
            'timeout_seconds' => $this->timeout_seconds,
            'seconds_remaining' => $this->getSecondsRemaining(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),

            'is_active' => $this->isActive(),
            'is_expired' => $this->isExpired(),
            'is_completed' => $this->isCompleted(),
            'can_be_cancelled' => $this->canBeCancelled(),

            'terminal' => new PaymentTerminalResource($this->whenLoaded('terminal')),
            'invoice' => new InvoiceResource($this->whenLoaded('invoice')),
            'payment' => $this->whenLoaded('payment'),
            'user' => $this->whenLoaded('user'),
        ];
    }
}
