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

/**
 * Product model representing a store's inventory item.
 *
 * A product contains one or more variants, each with its own SKU, price, and quantity.
 * Products are listed on sales channels (In Store, Shopify, eBay, Amazon, etc.) via
 * PlatformListing records. Inventory is tracked per-variant per-warehouse via the
 * Inventory model, and quantity changes automatically dispatch SyncProductInventoryJob
 * to push updated quantities to all listed platforms.
 *
 * Lifecycle:
 * - On create: listings are created for all active sales channels (local = listed, external = not_listed).
 * - On status change to active: local listings are listed, external listings are ensured.
 * - On status change away from active: all listings are ended.
 * - Inventory changes (via Inventory model): variant/product quantities are synced, then
 *   SyncProductInventoryJob is dispatched to update all listed platforms.
 *
 * @property int $id
 * @property int $store_id
 * @property string $title
 * @property string|null $description
 * @property string|null $handle
 * @property string $status
 * @property bool $is_published
 * @property bool $is_draft
 * @property bool $has_variants
 * @property bool $track_quantity
 * @property bool $sell_out_of_stock
 * @property bool $charge_taxes
 * @property int $quantity
 * @property int|null $category_id
 * @property int|null $template_id
 * @property int|null $brand_id
 * @property int|null $vendor_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read int $total_quantity
 * @property-read string $status_label
 * @property-read \Illuminate\Database\Eloquent\Collection<int, ProductVariant> $variants
 * @property-read \Illuminate\Database\Eloquent\Collection<int, PlatformListing> $platformListings
 * @property-read Category|null $category
 * @property-read Brand|null $brand
 * @property-read Vendor|null $vendor
 * @property-read ProductTemplate|null $template
 */
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
        'fulfillment_policy_id',
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
        // When product is created, create listings for ALL active channels
        // Local channels get 'listed' status, external get 'not_listed'
        static::created(function (Product $product) {
            $product->createListingsForAllActiveChannels();
        });

        // When product status changes, manage listings accordingly
        static::updated(function (Product $product) {
            if ($product->wasChanged('status')) {
                if ($product->status === self::STATUS_ACTIVE) {
                    // When product becomes active, ensure all listings exist and list local channels
                    $product->createListingsForAllActiveChannels();
                } else {
                    // When product moves away from active, end all listed items
                    $product->endAllListings();
                }
            }
        });
    }

    // ──────────────────────────────────────────────────────────────
    //  Sales Channel / Platform Listing Management
    // ──────────────────────────────────────────────────────────────

    /**
     * Create listings for ALL active channels.
     * Local channels get 'listed' status (if product is active), external get 'not_listed'.
     *
     * @return array<PlatformListing>
     */
    public function createListingsForAllActiveChannels(): array
    {
        $channels = SalesChannel::where('store_id', $this->store_id)
            ->where('is_active', true)
            ->get();

        $listings = [];
        foreach ($channels as $channel) {
            $listings[] = $this->ensureListingExists($channel);
        }

        return $listings;
    }

    /**
     * Ensure a listing exists for the given channel.
     * Creates one if it doesn't exist with appropriate default status.
     * Also ensures listing variants are in sync with product variants.
     */
    public function ensureListingExists(SalesChannel $channel): PlatformListing
    {
        $existingListing = $this->platformListings()
            ->where('sales_channel_id', $channel->id)
            ->first();

        if ($existingListing) {
            // Ensure variants are in sync (new variants added to product get listing variants)
            $this->syncListingVariants($existingListing);

            // If product just became active and channel has auto_list, list it (unless excluded)
            if ($this->status === self::STATUS_ACTIVE && $channel->auto_list && $existingListing->isNotListed() && $existingListing->should_list) {
                $existingListing->markAsListed();
            }

            return $existingListing;
        }

        // Determine initial status:
        // - Channels with auto_list: 'listed' if product is active, 'not_listed' otherwise
        // - Other channels: always 'not_listed' initially (user must explicitly list)
        $status = PlatformListing::STATUS_NOT_LISTED;
        if ($channel->auto_list && $this->status === self::STATUS_ACTIVE) {
            $status = PlatformListing::STATUS_LISTED;
        }

        $defaultVariant = $this->variants()->first();

        $listing = PlatformListing::create([
            'sales_channel_id' => $channel->id,
            'store_marketplace_id' => $channel->store_marketplace_id,
            'product_id' => $this->id,
            'status' => $status,
            'platform_price' => $defaultVariant?->price ?? 0,
            'platform_quantity' => null,
            'published_at' => $status === PlatformListing::STATUS_LISTED ? now() : null,
        ]);

        // Create variant rows for each product variant
        foreach ($this->variants as $variant) {
            $listing->listingVariants()->create([
                'product_variant_id' => $variant->id,
                'price' => $variant->price,
                'quantity' => $variant->quantity,
            ]);
        }

        return $listing;
    }

    /**
     * Sync listing variants to match product variants.
     * Creates missing listing variants for newly added product variants.
     */
    public function syncListingVariants(PlatformListing $listing): void
    {
        $existingVariantIds = $listing->listingVariants()->pluck('product_variant_id')->toArray();
        $productVariants = $this->variants()->get();

        foreach ($productVariants as $variant) {
            if (! in_array($variant->id, $existingVariantIds)) {
                $listing->listingVariants()->create([
                    'product_variant_id' => $variant->id,
                    'price' => $variant->price,
                    'quantity' => $variant->quantity,
                ]);
            }
        }
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
    public function listOnChannel(SalesChannel $channel, string $status = PlatformListing::STATUS_LISTED): PlatformListing
    {
        $existingListing = $this->platformListings()
            ->where('sales_channel_id', $channel->id)
            ->first();

        if ($existingListing) {
            // Normalize legacy status to new status
            $normalizedStatus = match ($status) {
                'active' => PlatformListing::STATUS_LISTED,
                'unlisted', 'draft', 'not_for_sale' => PlatformListing::STATUS_NOT_LISTED,
                default => $status,
            };

            // Update status if listing exists and status is different
            if ($existingListing->normalized_status !== $normalizedStatus) {
                $existingListing->update([
                    'status' => $normalizedStatus,
                    'published_at' => $normalizedStatus === PlatformListing::STATUS_LISTED ? now() : $existingListing->published_at,
                ]);
            }

            return $existingListing;
        }

        // Normalize status for creation
        $normalizedStatus = match ($status) {
            'active' => PlatformListing::STATUS_LISTED,
            'unlisted', 'draft', 'not_for_sale' => PlatformListing::STATUS_NOT_LISTED,
            default => $status,
        };

        $defaultVariant = $this->variants()->first();

        $listing = PlatformListing::create([
            'sales_channel_id' => $channel->id,
            'store_marketplace_id' => $channel->store_marketplace_id,
            'product_id' => $this->id,
            'status' => $normalizedStatus,
            'platform_price' => $defaultVariant?->price ?? 0,
            'platform_quantity' => null,
            'published_at' => $normalizedStatus === PlatformListing::STATUS_LISTED ? now() : null,
        ]);

        // Create variant rows for each product variant
        foreach ($this->variants as $variant) {
            $listing->listingVariants()->create([
                'product_variant_id' => $variant->id,
                'price' => $variant->price,
                'quantity' => $variant->quantity,
            ]);
        }

        return $listing;
    }

    /**
     * List product on all active external platforms.
     * Called when user clicks "List on All Platforms".
     *
     * @return array<PlatformListing>
     */
    /**
     * @return array<PlatformListing>
     */
    public function listOnAllPlatforms(bool $respectShouldList = false): array
    {
        $channels = SalesChannel::where('store_id', $this->store_id)
            ->where('is_active', true)
            ->where('is_local', false) // External platforms only
            ->get();

        if ($respectShouldList) {
            $excludedChannelIds = $this->platformListings()
                ->where('should_list', false)
                ->pluck('sales_channel_id')
                ->toArray();

            $channels = $channels->reject(fn ($ch) => in_array($ch->id, $excludedChannelIds));
        }

        $listings = [];
        foreach ($channels as $channel) {
            $listings[] = $this->listOnChannel($channel, PlatformListing::STATUS_LISTED);
        }

        return $listings;
    }

    /**
     * End (unlist) product from a specific channel.
     */
    public function unlistFromChannel(SalesChannel $channel): bool
    {
        return $this->platformListings()
            ->where('sales_channel_id', $channel->id)
            ->update(['status' => PlatformListing::STATUS_ENDED]) > 0;
    }

    /**
     * End (unlist) product from ALL platforms/channels.
     * Called when product status changes away from active.
     *
     * @deprecated Use endAllListings() instead
     */
    public function unlistFromAllPlatforms(): int
    {
        return $this->endAllListings();
    }

    /**
     * End all active listings for this product.
     * Called when product status changes away from active.
     */
    public function endAllListings(): int
    {
        return $this->platformListings()
            ->whereIn('status', [
                PlatformListing::STATUS_LISTED,
                PlatformListing::STATUS_PENDING,
                'active', // Legacy status
            ])
            ->update(['status' => PlatformListing::STATUS_ENDED]);
    }

    /**
     * Dispatch SyncProductInventoryJob to push current quantity to all listed platforms.
     *
     * This is the single entry point for platform inventory sync. It is called
     * automatically by the Inventory model's saved/deleted hooks whenever stock
     * changes (sales, manual adjustments, transfers, corrections, etc.).
     *
     * The dispatched job will:
     * - Update quantity on each platform where the product has a "listed" listing.
     * - End the listing on platforms if the product's quantity reaches zero.
     *
     * @param  string|null  $reason  Human-readable trigger reason for logging (e.g. 'inventory_changed', 'order_placed')
     */
    public function syncInventoryToAllPlatforms(?string $reason = null): void
    {
        \App\Jobs\SyncProductInventoryJob::dispatch($this, $reason);
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
            ->whereIn('status', [PlatformListing::STATUS_LISTED, 'active'])
            ->exists();
    }

    // ──────────────────────────────────────────────────────────────
    //  Relationships
    // ──────────────────────────────────────────────────────────────

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

    public function fulfillmentPolicy(): BelongsTo
    {
        return $this->belongsTo(FulfillmentPolicy::class);
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

    // ──────────────────────────────────────────────────────────────
    //  Template / Attributes
    // ──────────────────────────────────────────────────────────────

    /**
     * Get the effective template for this product.
     * Uses stored template_id first, falls back to category's effective template.
     */
    public function getTemplate(): ?ProductTemplate
    {
        if ($this->template_id) {
            return $this->template;
        }

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
        // Treat "0" as empty for select/radio/checkbox fields (legacy "not selected" value)
        if ($value === '0') {
            $field = ProductTemplateField::find($fieldId);
            if ($field && $field->hasOptions()) {
                $value = null;
            }
        }

        if ($value === null || $value === '') {
            $this->attributeValues()
                ->where('product_template_field_id', $fieldId)
                ->delete();

            return;
        }

        $this->attributeValues()->updateOrCreate(
            ['product_template_field_id' => $fieldId],
            ['value' => $value]
        );
    }

    // ──────────────────────────────────────────────────────────────
    //  Computed Attributes
    // ──────────────────────────────────────────────────────────────

    /**
     * Total quantity across all variants (sum of variant quantities).
     */
    public function getTotalQuantityAttribute(): int
    {
        return (int) $this->variants->sum('quantity');
    }

    /**
     * Human-readable status label.
     */
    public function getStatusLabelAttribute(): string
    {
        return self::getStatuses()[$this->status] ?? ucfirst($this->status ?? 'Unknown');
    }

    // ──────────────────────────────────────────────────────────────
    //  Scopes
    // ──────────────────────────────────────────────────────────────

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

    // ──────────────────────────────────────────────────────────────
    //  Status Helpers
    // ──────────────────────────────────────────────────────────────

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
     * Check if product can be edited (only draft and active products).
     */
    public function canBeEdited(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_ACTIVE]);
    }

    /**
     * Check if product is available for sale (active with stock).
     */
    public function isAvailableForSale(): bool
    {
        return $this->status === self::STATUS_ACTIVE && $this->total_quantity > 0;
    }

    // ──────────────────────────────────────────────────────────────
    //  Activity Logging
    // ──────────────────────────────────────────────────────────────

    protected function getActivityPrefix(): string
    {
        return 'products';
    }

    protected function getActivityMap(): array
    {
        return [
            'create' => Activity::PRODUCTS_CREATE,
            'update' => Activity::PRODUCTS_UPDATE,
            'delete' => Activity::PRODUCTS_DELETE,
            'view' => 'products.view',
            'quantity_change' => Activity::PRODUCTS_QUANTITY_CHANGE,
        ];
    }

    protected function getLoggableAttributes(): array
    {
        return ['id', 'title', 'handle', 'is_published', 'is_draft', 'category_id', 'brand_id', 'quantity'];
    }

    protected function getActivityIdentifier(): string
    {
        return $this->title ?? "#{$this->id}";
    }

    // ──────────────────────────────────────────────────────────────
    //  Search (Laravel Scout)
    // ──────────────────────────────────────────────────────────────

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
            'barcode' => $this->variants->pluck('barcode')->filter()->implode(' '),
            'upc' => $this->upc,
            'mpn' => $this->mpn,
            'brand' => $this->brand?->name,
            'category' => $this->category?->name,
            'store_id' => $this->store_id,
            'category_id' => $this->category_id,
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

    // ──────────────────────────────────────────────────────────────
    //  SKU Generation
    // ──────────────────────────────────────────────────────────────

    /**
     * Generate SKU in the format: CATEGORY_PREFIX-product_id.
     */
    public function generateSku(): string
    {
        $prefix = $this->category?->getEffectiveSkuPrefix() ?? 'PROD';

        return strtoupper($prefix).'-'.$this->id;
    }

    /**
     * Generate SKU and barcode for all variants of this product.
     * Single variant gets base SKU; multiple variants get base SKU suffixed with index.
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
