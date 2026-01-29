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
    ];

    protected function casts(): array
    {
        return [
            'has_variants' => 'boolean',
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
        return $query->where('is_draft', '1');
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
}
