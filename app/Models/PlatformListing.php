<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class PlatformListing extends Model
{
    use HasFactory, SoftDeletes;

    // Listing statuses
    public const STATUS_DRAFT = 'draft';           // Not yet listed, in preparation

    public const STATUS_ACTIVE = 'active';          // Listed and for sale

    public const STATUS_UNLISTED = 'unlisted';      // Temporarily removed from sale

    public const STATUS_NOT_FOR_SALE = 'not_for_sale'; // Marked as not for sale on this platform

    public const STATUS_ERROR = 'error';            // Sync error

    public const STATUS_PENDING = 'pending';        // Waiting for platform sync

    protected $fillable = [
        'store_marketplace_id',
        'sales_channel_id',
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

    /**
     * Alias for marketplace relationship.
     */
    public function storeMarketplace(): BelongsTo
    {
        return $this->marketplace();
    }

    public function salesChannel(): BelongsTo
    {
        return $this->belongsTo(SalesChannel::class);
    }

    /**
     * Check if this is a local listing (In Store) vs external platform.
     */
    public function isLocal(): bool
    {
        return $this->salesChannel?->is_local ?? false;
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
            'status' => self::STATUS_ERROR,
            'last_error' => $error,
        ]);
    }

    /**
     * Mark as not for sale on this platform.
     */
    public function markAsNotForSale(): void
    {
        $this->update([
            'status' => self::STATUS_NOT_FOR_SALE,
        ]);
    }

    /**
     * Re-enable for sale on this platform.
     */
    public function enableForSale(): void
    {
        $this->update([
            'status' => self::STATUS_ACTIVE,
        ]);
    }

    /**
     * Check if product is available for sale on this platform.
     */
    public function isForSale(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * Check if product is marked as not for sale on this platform.
     */
    public function isNotForSale(): bool
    {
        return $this->status === self::STATUS_NOT_FOR_SALE;
    }

    /**
     * Check if listing is in draft/preparation mode.
     */
    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    /**
     * Check if listing has an error.
     */
    public function hasError(): bool
    {
        return $this->status === self::STATUS_ERROR;
    }

    /**
     * Get human-readable status label.
     */
    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_DRAFT => 'Draft',
            self::STATUS_ACTIVE => 'Listed',
            self::STATUS_UNLISTED => 'Unlisted',
            self::STATUS_NOT_FOR_SALE => 'Not For Sale',
            self::STATUS_ERROR => 'Error',
            self::STATUS_PENDING => 'Pending',
            default => ucfirst($this->status ?? 'Unknown'),
        };
    }
}
