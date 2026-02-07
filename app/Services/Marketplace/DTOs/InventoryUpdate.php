<?php

namespace App\Services\Marketplace\DTOs;

class InventoryUpdate
{
    public function __construct(
        public string $sku,
        public ?string $externalId = null,
        public ?string $externalVariantId = null,
        public int $quantity = 0,
        public ?string $locationId = null,
        public string $adjustmentType = 'set', // 'set' or 'adjust'
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            sku: $data['sku'] ?? '',
            externalId: $data['external_id'] ?? null,
            externalVariantId: $data['external_variant_id'] ?? null,
            quantity: (int) ($data['quantity'] ?? 0),
            locationId: $data['location_id'] ?? null,
            adjustmentType: $data['adjustment_type'] ?? 'set',
        );
    }

    public function toArray(): array
    {
        return [
            'sku' => $this->sku,
            'external_id' => $this->externalId,
            'external_variant_id' => $this->externalVariantId,
            'quantity' => $this->quantity,
            'location_id' => $this->locationId,
            'adjustment_type' => $this->adjustmentType,
        ];
    }
}
