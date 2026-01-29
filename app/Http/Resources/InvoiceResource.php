<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceResource extends JsonResource
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
            'invoiceable_type' => $this->invoiceable_type,
            'invoiceable_type_name' => $this->invoiceable_type_name,
            'invoiceable_id' => $this->invoiceable_id,
            'subtotal' => $this->subtotal,
            'tax' => $this->tax,
            'shipping' => $this->shipping,
            'discount' => $this->discount,
            'total' => $this->total,
            'total_paid' => $this->total_paid,
            'balance_due' => $this->balance_due,
            'status' => $this->status,
            'currency' => $this->currency,
            'due_date' => $this->due_date?->toDateString(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),

            'is_paid' => $this->isPaid(),
            'is_overdue' => $this->isOverdue(),
            'can_accept_payment' => $this->canAcceptPayment(),

            'customer' => $this->whenLoaded('customer'),
            'user' => $this->whenLoaded('user'),
            'invoiceable' => $this->whenLoaded('invoiceable'),
            'payments' => $this->whenLoaded('payments'),
            'terminal_checkouts' => $this->whenLoaded('terminalCheckouts'),
        ];
    }
}
