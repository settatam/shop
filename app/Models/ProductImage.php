<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductImage extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'product_variant_id',
        'path',
        'url',
        'thumbnail_url',
        'alt_text',
        'sort_order',
        'is_primary',
        'is_internal',
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'is_internal' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function getFullUrlAttribute(): string
    {
        // Return stored URL if available (for DigitalOcean Spaces)
        if ($this->attributes['url'] ?? null) {
            return $this->attributes['url'];
        }

        // Fallback for legacy local storage
        if (str_starts_with($this->path, 'http')) {
            return $this->path;
        }

        return asset('storage/'.$this->path);
    }

    public function getThumbnailAttribute(): ?string
    {
        return $this->thumbnail_url;
    }
}
