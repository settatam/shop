<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\ReturnPolicy
 */
class ReturnPolicyResource extends JsonResource
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
            'description' => $this->description,
            'return_window_days' => $this->return_window_days,
            'allow_refund' => $this->allow_refund,
            'allow_store_credit' => $this->allow_store_credit,
            'allow_exchange' => $this->allow_exchange,
            'restocking_fee_percent' => $this->restocking_fee_percent,
            'require_receipt' => $this->require_receipt,
            'require_original_packaging' => $this->require_original_packaging,
            'excluded_conditions' => $this->excluded_conditions,
            'is_default' => $this->is_default,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
