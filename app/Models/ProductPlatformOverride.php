<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductPlatformOverride extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'store_marketplace_id',
        'title',
        'description',
        'price',
        'compare_at_price',
        'quantity',
        'attributes',
        'category_id',
        'is_active',
        'excluded_image_ids',
        'image_order',
        'excluded_metafields',
        'custom_metafields',
        'attribute_overrides',
        'platform_settings',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'compare_at_price' => 'decimal:2',
            'attributes' => 'array',
            'is_active' => 'boolean',
            'excluded_image_ids' => 'array',
            'image_order' => 'array',
            'excluded_metafields' => 'array',
            'custom_metafields' => 'array',
            'attribute_overrides' => 'array',
            'platform_settings' => 'array',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function marketplace(): BelongsTo
    {
        return $this->belongsTo(StoreMarketplace::class, 'store_marketplace_id');
    }

    /**
     * Get the effective title (override or product default).
     */
    public function getEffectiveTitle(): string
    {
        return $this->title ?? $this->product->title;
    }

    /**
     * Get the effective description (override or product default).
     */
    public function getEffectiveDescription(): ?string
    {
        return $this->description ?? $this->product->description;
    }

    /**
     * Get the effective price (override or product default).
     */
    public function getEffectivePrice(): ?string
    {
        return $this->price ?? $this->product->price;
    }

    /**
     * Get the effective quantity (override or product default).
     */
    public function getEffectiveQuantity(): int
    {
        return $this->quantity ?? $this->product->quantity ?? 0;
    }

    /**
     * Get a platform attribute value with fallback to product template attributes.
     */
    public function getPlatformAttribute(string $key): mixed
    {
        $overrideAttrs = $this->getOriginal('attributes') ?? [];
        if (is_string($overrideAttrs)) {
            $overrideAttrs = json_decode($overrideAttrs, true) ?? [];
        }

        if (isset($overrideAttrs[$key])) {
            return $overrideAttrs[$key];
        }

        // Fallback to product template attribute values
        $product = $this->product;
        if ($product) {
            $attrValue = $product->attributeValues
                ->firstWhere(fn ($v) => $v->templateField?->name === $key);

            return $attrValue?->value;
        }

        return null;
    }
}
