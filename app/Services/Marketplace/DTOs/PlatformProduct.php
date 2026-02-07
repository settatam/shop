<?php

namespace App\Services\Marketplace\DTOs;

class PlatformProduct
{
    public function __construct(
        public ?string $externalId = null,
        public string $title = '',
        public string $description = '',
        public ?string $sku = null,
        public ?string $barcode = null,
        public float $price = 0.0,
        public ?float $compareAtPrice = null,
        public int $quantity = 0,
        public ?float $weight = null,
        public ?string $weightUnit = 'lb',
        public ?string $brand = null,
        public ?string $category = null,
        public ?string $categoryId = null,
        public array $images = [],
        public array $attributes = [],
        public array $variants = [],
        public ?string $condition = 'new',
        public ?string $status = 'active',
        public array $metadata = [],
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            externalId: $data['external_id'] ?? $data['id'] ?? null,
            title: $data['title'] ?? $data['name'] ?? '',
            description: $data['description'] ?? $data['body_html'] ?? '',
            sku: $data['sku'] ?? null,
            barcode: $data['barcode'] ?? $data['upc'] ?? $data['ean'] ?? null,
            price: (float) ($data['price'] ?? 0),
            compareAtPrice: isset($data['compare_at_price']) ? (float) $data['compare_at_price'] : null,
            quantity: (int) ($data['quantity'] ?? $data['inventory_quantity'] ?? 0),
            weight: isset($data['weight']) ? (float) $data['weight'] : null,
            weightUnit: $data['weight_unit'] ?? 'lb',
            brand: $data['brand'] ?? $data['vendor'] ?? null,
            category: $data['category'] ?? null,
            categoryId: $data['category_id'] ?? null,
            images: $data['images'] ?? [],
            attributes: $data['attributes'] ?? $data['options'] ?? [],
            variants: $data['variants'] ?? [],
            condition: $data['condition'] ?? 'new',
            status: $data['status'] ?? 'active',
            metadata: $data['metadata'] ?? [],
        );
    }

    public function toArray(): array
    {
        return [
            'external_id' => $this->externalId,
            'title' => $this->title,
            'description' => $this->description,
            'sku' => $this->sku,
            'barcode' => $this->barcode,
            'price' => $this->price,
            'compare_at_price' => $this->compareAtPrice,
            'quantity' => $this->quantity,
            'weight' => $this->weight,
            'weight_unit' => $this->weightUnit,
            'brand' => $this->brand,
            'category' => $this->category,
            'category_id' => $this->categoryId,
            'images' => $this->images,
            'attributes' => $this->attributes,
            'variants' => $this->variants,
            'condition' => $this->condition,
            'status' => $this->status,
            'metadata' => $this->metadata,
        ];
    }
}
