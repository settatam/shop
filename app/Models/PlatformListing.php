<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class PlatformListing extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'store_marketplace_id',
        'product_id',
        'product_variant_id',
        'external_listing_id',
        'external_variant_id',
        'status',
        'listing_url',
        'platform_price',
        'platform_quantity',
        'platform_data',
        'category_mapping',
        'last_error',
        'last_synced_at',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'platform_price' => 'decimal:2',
            'platform_data' => 'array',
            'category_mapping' => 'array',
            'last_synced_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    public function marketplace(): BelongsTo
    {
        return $this->belongsTo(StoreMarketplace::class, 'store_marketplace_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function isPublished(): bool
    {
        return $this->status === 'active' && $this->published_at !== null;
    }

    public function markAsPublished(): void
    {
        $this->update([
            'status' => 'active',
            'published_at' => now(),
            'last_synced_at' => now(),
        ]);
    }

    public function markAsError(string $error): void
    {
        $this->update([
            'status' => 'error',
            'last_error' => $error,
        ]);
    }
}
