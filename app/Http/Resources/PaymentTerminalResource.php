<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentTerminalResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'store_id' => $this->store_id,
            'name' => $this->name,
            'gateway' => $this->gateway,
            'device_id' => $this->device_id,
            'location_id' => $this->location_id,
            'status' => $this->status,
            'capabilities' => $this->capabilities,
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
            'paired_at' => $this->paired_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),

            'is_active' => $this->isActive(),

            'checkouts' => TerminalCheckoutResource::collection($this->whenLoaded('checkouts')),
        ];
    }
}
