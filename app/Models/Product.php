<?php

namespace App\Models;

use App\Traits\BelongsToStore;
use App\Traits\HasImages;
use App\Traits\HasTags;
use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Scout\Searchable;

class Product extends Model
{
    use BelongsToStore, HasFactory, HasImages, HasTags, LogsActivity, Searchable, SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_AWAITING_CONFIRMATION = 'awaiting_confirmation';

    public const STATUS_SOLD = 'sold';

    public const STATUS_IN_REPAIR = 'in_repair';

    public const STATUS_IN_MEMO = 'in_memo';

    public const STATUS_ARCHIVE = 'archive';

    public const STATUS_IN_BUCKET = 'in_bucket';

    protected $fillable = [
        'title',
        'description',
        'category_id',
        'template_id',
        'weight',
        'weight_unit',
        'compare_at_price',
        'price_code',
        'currency_code',
        'store_id',
        'handle',
        'upc',
        'ean',
        'jan',
        'isbn',
        'mpn',
        'location',
        'manufacturer_id',
        'tax_class',
        'date_available',
        'length',
        'width',
        'height',
        'length_class',
        'minimum_order',
        'views',
        'sort_order',
        'brand_id',
        'vendor_id',
        'return_policy_id',
        'sort_attribute',
        'has_variants',
        'country_of_origin',
        'step',
        'is_published',
        'condition',
        'domestic_shipping_cost',
        'international_shipping_cost',
        'is_draft',
        'seo_description',
        'seo_page_title',
        'track_quantity',
        'sell_out_of_stock',
        'charge_taxes',
        'quantity',
        'custom_product_type_id',
        'product_type_id',
        'status',
        'last_price_check_at',
    ];

    protected function casts(): array
    {
        return [
            'has_variants' => 'boolean',
            'last_price_check_at' => 'datetime',
            'is_published' => 'boolean',
            'track_quantity' => 'boolean',
            'sell_out_of_stock' => 'boolean',
            'charge_taxes' => 'boolean',
            'date_available' => 'datetime',
            'weight' => 'decimal:4',
            'compare_at_price' => 'decimal:2',
            'length' => 'decimal:2',
            'width' => 'decimal:2',
            'height' => 'decimal:2',
            'domestic_shipping_cost' => 'decimal:2',
            'international_shipping_cost' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::updated(function (Product $product) {
            // When product becomes active, auto-list on "In Store" channel only
            if ($product->wasChanged('status') && $product->status === self::STATUS_ACTIVE) {
                $product->listOnInStore();
            }
        });
    }

    /**
     * List product on the "In Store" channel.
     * Called automatically when product becomes active.
     */
    public function listOnInStore(): ?PlatformListing
    {
        $inStoreChannel = SalesChannel::where('store_id', $this->store_id)
            ->where('is_local', true)
            ->where('is_active', true)
            ->first();

        if (! $inStoreChannel) {
            return null;
        }

        return $this->listOnChannel($inStoreChannel);
    }

    /**
     * List product on a specific sales channel.
     */
    public function listOnChannel(SalesChannel $channel, string $status = 'active'): PlatformListing
    {
        $existingListing = $this->platformListings()
            ->where('sales_channel_id', $channel->id)
            ->first();

        if ($existingListing) {
            // Update status if listing exists
            if ($existingListing->status !== $status) {
                $existingListing->update(['status' => $status]);
            }

            return $existingListing;
        }

        $defaultVariant = $this->variants()->first();

        return PlatformListing::create([
            'sales_channel_id' => $channel->id,
            'store_marketplace_id' => $channel->store_marketplace_id,
            'product_id' => $this->id,
            'status' => $status,
            'platform_price' => $defaultVariant?->price ?? 0,
            'platform_quantity' => $defaultVariant?->quantity ?? 0,
            'platform_data' => [
                'title' => $this->title,
                'description' => $this->description,
            ],
        ]);
    }

    /**
     * List product on all active external platforms.
     * Called when user clicks "List on All Platforms".
     */
    public function listOnAllPlatforms(): array
    {
        $channels = SalesChannel::where('store_id', $this->store_id)
            ->where('is_active', true)
            ->where('is_local', false) // External platforms only
            ->get();

        $listings = [];
        foreach ($channels as $channel) {
            $listings[] = $this->listOnChannel($channel);
        }

        return $listings;
    }

    /**
     * Unlist product from a specific channel.
     */
    public function unlistFromChannel(SalesChannel $channel): bool
    {
        return $this->platformListings()
            ->where('sales_channel_id', $channel->id)
            ->update(['status' => 'unlisted']) > 0;
    }

    /**
     * Get listing for a specific channel.
     */
    public function getListingForChannel(SalesChannel $channel): ?PlatformListing
    {
        return $this->platformListings()
            ->where('sales_channel_id', $channel->id)
            ->first();
    }

    /**
     * Check if product is listed on a specific channel.
     */
    public function isListedOn(SalesChannel $channel): bool
    {
        return $this->platformListings()
            ->where('sales_channel_id', $channel->id)
            ->where('status', 'active')
            ->exists();
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function returnPolicy(): BelongsTo
    {
        return $this->belongsTo(ReturnPolicy::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ProductTemplate::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    /**
     * Legacy product images relationship.
     *
     * @deprecated Use images() from HasImages trait instead
     */
    public function legacyImages(): HasMany
    {
        return $this->hasMany(ProductImage::class);
    }

    public function videos(): HasMany
    {
        return $this->hasMany(ProductVideo::class)->orderBy('sort_order');
    }

    /**
     * Get internal images only.
     */
    public function internalImages(): HasMany
    {
        return $this->hasMany(ProductImage::class)->where('is_internal', true)->orderBy('sort_order');
    }

    /**
     * Get public images only (non-internal).
     */
    public function publicImages(): HasMany
    {
        return $this->hasMany(ProductImage::class)->where('is_internal', false)->orderBy('sort_order');
    }

    public function attributeValues(): HasMany
    {
        return $this->hasMany(ProductAttributeValue::class);
    }

    public function platformListings(): HasMany
    {
        return $this->hasMany(PlatformListing::class);
    }

    public function platformOverrides(): HasMany
    {
        return $this->hasMany(ProductPlatformOverride::class);
    }

    /**
     * Get the override for a specific marketplace.
     */
    public function getOverrideForMarketplace(int $storeMarketplaceId): ?ProductPlatformOverride
    {
        return $this->platformOverrides->firstWhere('store_marketplace_id', $storeMarketplaceId);
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function memoItems(): HasMany
    {
        return $this->hasMany(MemoItem::class);
    }

    public function repairItems(): HasMany
    {
        return $this->hasMany(RepairItem::class);
    }

    public function transactionItems(): HasMany
    {
        return $this->hasMany(TransactionItem::class);
    }

    /**
     * Get the effective template for this product.
     * Uses stored template_id first, falls back to category's effective template.
     */
    public function getTemplate(): ?ProductTemplate
    {
        // Use stored template if available
        if ($this->template_id) {
            return $this->template;
        }

        // Fall back to category's effective template
        if ($this->category) {
            return $this->category->getEffectiveTemplate();
        }

        return null;
    }

    /**
     * Get template attribute value for a specific field.
     */
    public function getTemplateAttributeValue(int $fieldId): ?string
    {
        return $this->attributeValues
            ->where('product_template_field_id', $fieldId)
            ->first()?->value;
    }

    /**
     * Set template attribute value for a specific field.
     */
    public function setTemplateAttributeValue(int $fieldId, ?string $value): void
    {
        $this->attributeValues()->updateOrCreate(
            ['product_template_field_id' => $fieldId],
            ['value' => $value]
        );
    }

    public function getTotalQuantityAttribute(): int
    {
        if ($this->has_variants) {
            return $this->variants->sum('quantity');
        }

        return $this->quantity ?? 0;
    }

    public function scopePublished($query)
    {
        return $query->where('is_published', true);
    }

    public function scopeDraft($query)
    {
        return $query->where('status', self::STATUS_DRAFT);
    }

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeArchived($query)
    {
        return $query->where('status', self::STATUS_ARCHIVE);
    }

    public function scopeInMemo($query)
    {
        return $query->where('status', self::STATUS_IN_MEMO);
    }

    public function scopeInRepair($query)
    {
        return $query->where('status', self::STATUS_IN_REPAIR);
    }

    public function scopeSold($query)
    {
        return $query->where('status', self::STATUS_SOLD);
    }

    public function scopeAwaitingConfirmation($query)
    {
        return $query->where('status', self::STATUS_AWAITING_CONFIRMATION);
    }

    public function scopeInBucket($query)
    {
        return $query->where('status', self::STATUS_IN_BUCKET);
    }

    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Get all available statuses.
     *
     * @return array<string, string>
     */
    public static function getStatuses(): array
    {
        return [
            self::STATUS_DRAFT => 'Draft',
            self::STATUS_ACTIVE => 'Active',
            self::STATUS_AWAITING_CONFIRMATION => 'Awaiting Confirmation',
            self::STATUS_SOLD => 'Sold',
            self::STATUS_IN_REPAIR => 'In Repair',
            self::STATUS_IN_MEMO => 'In Memo',
            self::STATUS_ARCHIVE => 'Archive',
            self::STATUS_IN_BUCKET => 'In Bucket',
        ];
    }

    /**
     * Get available statuses for a store based on its edition features.
     *
     * @return array<string, string>
     */
    public static function getStatusesForStore(Store $store): array
    {
        $featureManager = app(\App\Services\FeatureManager::class);

        // Base statuses available to all editions
        $statuses = [
            self::STATUS_DRAFT => 'Draft',
            self::STATUS_ACTIVE => 'Active',
            self::STATUS_SOLD => 'Sold',
            self::STATUS_ARCHIVE => 'Archive',
        ];

        // Add feature-gated statuses
        if ($featureManager->storeHasFeature($store, 'product_status_awaiting_confirmation')) {
            $statuses[self::STATUS_AWAITING_CONFIRMATION] = 'Awaiting Confirmation';
        }

        if ($featureManager->storeHasFeature($store, 'product_status_in_repair')) {
            $statuses[self::STATUS_IN_REPAIR] = 'In Repair';
        }

        if ($featureManager->storeHasFeature($store, 'product_status_in_memo')) {
            $statuses[self::STATUS_IN_MEMO] = 'In Memo';
        }

        if ($featureManager->storeHasFeature($store, 'product_status_in_bucket')) {
            $statuses[self::STATUS_IN_BUCKET] = 'In Bucket';
        }

        return $statuses;
    }

    /**
     * Get human-readable status label.
     */
    public function getStatusLabelAttribute(): string
    {
        return self::getStatuses()[$this->status] ?? ucfirst($this->status ?? 'Unknown');
    }

    /**
     * Check if product can be edited.
     */
    public function canBeEdited(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_ACTIVE]);
    }

    /**
     * Check if product is available for sale.
     */
    public function isAvailableForSale(): bool
    {
        return $this->status === self::STATUS_ACTIVE && $this->total_quantity > 0;
    }

    protected function getActivityPrefix(): string
    {
        return 'products';
    }

    protected function getLoggableAttributes(): array
    {
        return ['id', 'title', 'handle', 'is_published', 'is_draft', 'category_id', 'brand_id'];
    }

    protected function getActivityIdentifier(): string
    {
        return $this->title ?? "#{$this->id}";
    }

    /**
     * Get the indexable data array for the model.
     *
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'handle' => $this->handle,
            'sku' => $this->variants->pluck('sku')->filter()->implode(' '),
            'upc' => $this->upc,
            'mpn' => $this->mpn,
            'brand' => $this->brand?->name,
            'category' => $this->category?->name,
            'store_id' => $this->store_id,
            'is_published' => $this->is_published,
            'created_at' => $this->created_at?->timestamp,
        ];
    }

    /**
     * Determine if the model should be searchable.
     */
    public function shouldBeSearchable(): bool
    {
        return ! $this->trashed();
    }

    /**
     * Generate SKU in the format: CATEGORY_PREFIX-product_id
     */
    public function generateSku(): string
    {
        $prefix = $this->category?->getEffectiveSkuPrefix() ?? 'PROD';

        return strtoupper($prefix).'-'.$this->id;
    }

    /**
     * Generate SKU and barcode for all variants of this product.
     */
    public function generateSkusForVariants(): void
    {
        $baseSku = $this->generateSku();

        $variants = $this->variants()->get();

        if ($variants->count() === 1) {
            // Single variant - use base SKU
            $variants->first()->update([
                'sku' => $baseSku,
                'barcode' => $baseSku,
            ]);
        } else {
            // Multiple variants - append variant index
            foreach ($variants as $index => $variant) {
                $variantSku = $baseSku.'-'.($index + 1);
                $variant->update([
                    'sku' => $variantSku,
                    'barcode' => $variantSku,
                ]);
            }
        }
    }
}
