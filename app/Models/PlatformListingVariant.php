<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlatformListingVariant extends Model
{
    /** @use HasFactory<\Database\Factories\PlatformListingVariantFactory> */
    use HasFactory;

    protected $fillable = [
        'platform_listing_id',
        'product_variant_id',
        'external_variant_id',
        'external_inventory_item_id',
        'price',
        'compare_at_price',
        'quantity',
        'sku',
        'barcode',
        'platform_data',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'compare_at_price' => 'decimal:2',
            'platform_data' => 'array',
        ];
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(PlatformListing::class, 'platform_listing_id');
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /**
     * Get effective price — falls back to ProductVariant price.
     */
    public function getEffectivePrice(): float
    {
        return (float) ($this->price ?? $this->productVariant?->price ?? 0);
    }

    /**
     * Get effective quantity — falls back to ProductVariant quantity.
     */
    public function getEffectiveQuantity(): int
    {
        return (int) ($this->quantity ?? $this->productVariant?->quantity ?? 0);
    }

    /**
     * Get effective SKU — falls back to ProductVariant SKU.
     */
    public function getEffectiveSku(): ?string
    {
        return $this->sku ?? $this->productVariant?->sku;
    }

    /**
     * Get effective barcode — falls back to ProductVariant barcode.
     */
    public function getEffectiveBarcode(): ?string
    {
        return $this->barcode ?? $this->productVariant?->barcode;
    }
}
