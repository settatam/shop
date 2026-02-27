<?php

namespace App\Models;

use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PlatformListing extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    // Listing statuses
    public const STATUS_NOT_LISTED = 'not_listed';   // Never published to platform

    public const STATUS_LISTED = 'listed';           // Currently active on platform

    public const STATUS_ENDED = 'ended';             // Was published, now ended

    public const STATUS_ARCHIVED = 'archived';       // Archived (hidden from UI)

    public const STATUS_ERROR = 'error';             // Sync error occurred

    public const STATUS_PENDING = 'pending';         // Awaiting platform sync

    // Legacy status constants for backwards compatibility
    public const STATUS_DRAFT = 'draft';             // @deprecated Use STATUS_NOT_LISTED

    public const STATUS_ACTIVE = 'active';           // @deprecated Use STATUS_LISTED

    public const STATUS_UNLISTED = 'unlisted';       // @deprecated Use STATUS_ENDED

    public const STATUS_NOT_FOR_SALE = 'not_for_sale'; // @deprecated Use STATUS_NOT_LISTED

    /**
     * Valid statuses for validation.
     */
    public const VALID_STATUSES = [
        self::STATUS_NOT_LISTED,
        self::STATUS_LISTED,
        self::STATUS_ENDED,
        self::STATUS_ARCHIVED,
        self::STATUS_ERROR,
        self::STATUS_PENDING,
    ];

    /**
     * Valid status transitions.
     *
     * @var array<string, array<string>>
     */
    protected static array $validTransitions = [
        self::STATUS_NOT_LISTED => [self::STATUS_LISTED, self::STATUS_PENDING, self::STATUS_ARCHIVED],
        self::STATUS_LISTED => [self::STATUS_ENDED, self::STATUS_ERROR, self::STATUS_ARCHIVED],
        self::STATUS_ENDED => [self::STATUS_LISTED, self::STATUS_PENDING, self::STATUS_ARCHIVED],
        self::STATUS_ARCHIVED => [self::STATUS_NOT_LISTED, self::STATUS_LISTED],
        self::STATUS_ERROR => [self::STATUS_NOT_LISTED, self::STATUS_LISTED, self::STATUS_PENDING, self::STATUS_ARCHIVED],
        self::STATUS_PENDING => [self::STATUS_LISTED, self::STATUS_ERROR, self::STATUS_NOT_LISTED],
    ];

    protected $fillable = [
        'store_marketplace_id',
        'sales_channel_id',
        'product_id',
        'external_listing_id',
        'status',
        'should_list',
        'title',
        'description',
        'images',
        'attributes',
        'platform_category_id',
        'platform_category_options',
        'platform_settings',
        'metafield_overrides',
        'listing_url',
        'platform_price',
        'platform_quantity',
        'quantity_override',
        'platform_data',
        'category_mapping',
        'last_error',
        'last_synced_at',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'should_list' => 'boolean',
            'platform_price' => 'decimal:2',
            'quantity_override' => 'integer',
            'platform_data' => 'array',
            'images' => 'array',
            'attributes' => 'array',
            'platform_category_options' => 'array',
            'platform_settings' => 'array',
            'metafield_overrides' => 'array',
            'category_mapping' => 'array',
            'last_synced_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    /**
     * Boot the model - prevent deletion by archiving instead.
     */
    protected static function booted(): void
    {
        static::deleting(function (PlatformListing $listing) {
            // If this is a force delete, allow it
            if ($listing->isForceDeleting()) {
                return true;
            }

            // Otherwise, archive instead of soft delete
            $listing->update(['status' => self::STATUS_ARCHIVED]);

            return false;
        });
    }

    /**
     * Override delete to archive instead.
     */
    public function delete(): ?bool
    {
        // Archive instead of deleting
        $this->update(['status' => self::STATUS_ARCHIVED]);

        return true;
    }

    /**
     * Check if a status transition is valid.
     */
    public function canTransitionTo(string $newStatus): bool
    {
        if (! in_array($newStatus, self::VALID_STATUSES)) {
            return false;
        }

        // Same status is always valid (no-op)
        if ($this->status === $newStatus) {
            return true;
        }

        // Handle legacy statuses
        $currentStatus = $this->normalizeStatus($this->status);

        return isset(self::$validTransitions[$currentStatus])
            && in_array($newStatus, self::$validTransitions[$currentStatus]);
    }

    /**
     * Transition to a new status with validation.
     *
     * @throws \InvalidArgumentException
     */
    public function transitionTo(string $newStatus): self
    {
        if (! $this->canTransitionTo($newStatus)) {
            throw new \InvalidArgumentException(
                "Invalid status transition from '{$this->status}' to '{$newStatus}'"
            );
        }

        $this->update(['status' => $newStatus]);

        return $this;
    }

    /**
     * Normalize legacy statuses to new status values.
     */
    protected function normalizeStatus(?string $status): string
    {
        return match ($status) {
            'draft', 'not_for_sale' => self::STATUS_NOT_LISTED,
            'active' => self::STATUS_LISTED,
            'unlisted' => self::STATUS_ENDED,
            default => $status ?? self::STATUS_NOT_LISTED,
        };
    }

    /**
     * Get the normalized status value.
     */
    public function getNormalizedStatusAttribute(): string
    {
        return $this->normalizeStatus($this->status);
    }

    /**
     * Whether this product should be listed on this platform.
     */
    public function shouldList(): bool
    {
        return $this->should_list;
    }

    /**
     * Exclude this product from being listed on this platform.
     */
    public function excludeFromListing(): void
    {
        $this->update(['should_list' => false]);
    }

    /**
     * Include this product for listing on this platform.
     */
    public function includeInListing(): void
    {
        $this->update(['should_list' => true]);
    }

    /**
     * Get the effective quantity for this listing.
     * Uses quantity_override (capped at inventory) if set, otherwise inventory quantity.
     */
    public function getEffectiveQuantity(): int
    {
        $inventoryQuantity = $this->product?->total_quantity ?? 0;

        if ($this->quantity_override !== null) {
            return min($this->quantity_override, $inventoryQuantity);
        }

        return $inventoryQuantity;
    }

    /**
     * Whether a manual quantity override is set for this listing.
     */
    public function hasQuantityOverride(): bool
    {
        return $this->quantity_override !== null;
    }

    /**
     * Clear the manual quantity override, reverting to inventory quantity.
     */
    public function clearQuantityOverride(): void
    {
        $this->update(['quantity_override' => null]);
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

    public function listingVariants(): HasMany
    {
        return $this->hasMany(PlatformListingVariant::class, 'platform_listing_id');
    }

    /**
     * Get effective title — falls back to Product title.
     */
    public function getEffectiveTitle(): string
    {
        return $this->title ?? $this->product?->title ?? '';
    }

    /**
     * Get effective description — falls back to Product description.
     */
    public function getEffectiveDescription(): ?string
    {
        return $this->description ?? $this->product?->description;
    }

    /**
     * Get effective images — falls back to Product images.
     *
     * @return array<string>
     */
    public function getEffectiveImages(): array
    {
        return $this->images ?? $this->product?->images?->pluck('url')->toArray() ?? [];
    }

    /**
     * Get effective price — from first listing variant or listing-level platform_price.
     */
    public function getEffectivePrice(): float
    {
        $firstVariant = $this->listingVariants->first();
        if ($firstVariant) {
            return $firstVariant->getEffectivePrice();
        }

        return (float) ($this->platform_price ?? $this->product?->variants?->first()?->price ?? 0);
    }

    public function isPublished(): bool
    {
        $normalizedStatus = $this->normalizeStatus($this->status);

        return $normalizedStatus === self::STATUS_LISTED && $this->published_at !== null;
    }

    public function markAsPublished(): void
    {
        $this->update([
            'status' => self::STATUS_LISTED,
            'published_at' => now(),
            'last_synced_at' => now(),
        ]);
    }

    /**
     * Mark as listed on this platform.
     */
    public function markAsListed(): void
    {
        $this->update([
            'status' => self::STATUS_LISTED,
            'published_at' => now(),
            'last_synced_at' => now(),
        ]);
    }

    /**
     * Mark as ended on this platform.
     */
    public function markAsEnded(): void
    {
        $this->update([
            'status' => self::STATUS_ENDED,
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
     *
     * @deprecated Use markAsNotListed() instead
     */
    public function markAsNotForSale(): void
    {
        $this->update([
            'status' => self::STATUS_NOT_LISTED,
        ]);
    }

    /**
     * Mark as not listed on this platform.
     */
    public function markAsNotListed(): void
    {
        $this->update([
            'status' => self::STATUS_NOT_LISTED,
        ]);
    }

    /**
     * Mark as archived (hidden from UI).
     */
    public function markAsArchived(): void
    {
        $this->update([
            'status' => self::STATUS_ARCHIVED,
        ]);
    }

    /**
     * Re-enable for sale on this platform.
     *
     * @deprecated Use markAsListed() instead
     */
    public function enableForSale(): void
    {
        $this->markAsListed();
    }

    /**
     * Check if product is currently listed on this platform.
     */
    public function isListed(): bool
    {
        $normalizedStatus = $this->normalizeStatus($this->status);

        return $normalizedStatus === self::STATUS_LISTED;
    }

    /**
     * Check if product is available for sale on this platform.
     *
     * @deprecated Use isListed() instead
     */
    public function isForSale(): bool
    {
        return $this->isListed();
    }

    /**
     * Check if product is not listed on this platform.
     */
    public function isNotListed(): bool
    {
        $normalizedStatus = $this->normalizeStatus($this->status);

        return $normalizedStatus === self::STATUS_NOT_LISTED;
    }

    /**
     * Check if product is marked as not for sale on this platform.
     *
     * @deprecated Use isNotListed() instead
     */
    public function isNotForSale(): bool
    {
        return $this->isNotListed();
    }

    /**
     * Check if listing is ended.
     */
    public function isEnded(): bool
    {
        $normalizedStatus = $this->normalizeStatus($this->status);

        return $normalizedStatus === self::STATUS_ENDED;
    }

    /**
     * Check if listing is archived.
     */
    public function isArchived(): bool
    {
        return $this->status === self::STATUS_ARCHIVED;
    }

    /**
     * Check if listing is pending sync.
     */
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Check if listing is in draft/preparation mode.
     *
     * @deprecated Use isNotListed() instead
     */
    public function isDraft(): bool
    {
        return $this->isNotListed();
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
        return match ($this->normalizeStatus($this->status)) {
            self::STATUS_NOT_LISTED => 'Not Listed',
            self::STATUS_LISTED => 'Listed',
            self::STATUS_ENDED => 'Ended',
            self::STATUS_ARCHIVED => 'Archived',
            self::STATUS_ERROR => 'Error',
            self::STATUS_PENDING => 'Pending',
            default => ucfirst($this->status ?? 'Unknown'),
        };
    }

    /**
     * Get all status options with labels.
     *
     * @return array<string, string>
     */
    public static function getStatusOptions(): array
    {
        return [
            self::STATUS_NOT_LISTED => 'Not Listed',
            self::STATUS_LISTED => 'Listed',
            self::STATUS_ENDED => 'Ended',
            self::STATUS_ARCHIVED => 'Archived',
            self::STATUS_ERROR => 'Error',
            self::STATUS_PENDING => 'Pending',
        ];
    }

    /**
     * Get a single effective setting, resolving through: listing override → marketplace default → fallback.
     */
    public function getEffectiveSetting(string $key, mixed $default = null): mixed
    {
        $listingValue = $this->platform_settings[$key] ?? null;
        if ($listingValue !== null) {
            return $listingValue;
        }

        $marketplaceSettings = $this->marketplace?->settings ?? [];

        return $marketplaceSettings[$key] ?? $default;
    }

    /**
     * Get all effective settings — marketplace defaults merged with listing overrides.
     *
     * @return array<string, mixed>
     */
    public function getEffectiveSettings(): array
    {
        $marketplaceSettings = $this->marketplace?->settings ?? [];
        $listingSettings = $this->platform_settings ?? [];

        return array_merge($marketplaceSettings, array_filter($listingSettings, fn ($v) => $v !== null));
    }

    /**
     * Check if a setting key is overridden at the listing level.
     */
    public function isSettingOverridden(string $key): bool
    {
        return array_key_exists($key, $this->platform_settings ?? [])
            && $this->platform_settings[$key] !== null;
    }

    /**
     * Get the activity prefix for logging.
     */
    protected function getActivityPrefix(): string
    {
        return 'listings';
    }

    /**
     * Get loggable attributes for activity logging.
     */
    protected function getLoggableAttributes(): array
    {
        return ['id', 'status', 'platform_price', 'platform_quantity', 'published_at'];
    }

    /**
     * Get activity identifier for logging.
     */
    protected function getActivityIdentifier(): string
    {
        return $this->product?->title ?? "#{$this->id}";
    }
}
