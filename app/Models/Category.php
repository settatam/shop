<?php

namespace App\Models;

use App\Traits\BelongsToStore;
use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Scout\Searchable;

class Category extends Model
{
    use BelongsToStore, HasFactory, LogsActivity, Searchable, SoftDeletes;

    protected $fillable = [
        'name',
        'user_id',
        'slug',
        'language_id',
        'description',
        'meta_title',
        'meta_description',
        'meta_keyword',
        'parent_id',
        'ebay_category_id',
        'sort_order',
        'column',
        'level',
        'store_id',
        'type',
        'template_id',
        'sku_format',
        'sku_prefix',
        'sku_suffix',
        'default_bucket_id',
        'barcode_attributes',
        'label_template_id',
        'charge_taxes',
    ];

    protected function casts(): array
    {
        return [
            'charge_taxes' => 'boolean',
            'barcode_attributes' => 'array',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ProductTemplate::class, 'template_id');
    }

    public function labelTemplate(): BelongsTo
    {
        return $this->belongsTo(LabelTemplate::class, 'label_template_id');
    }

    public function defaultBucket(): BelongsTo
    {
        return $this->belongsTo(Bucket::class, 'default_bucket_id');
    }

    public function skuSequence(): HasOne
    {
        return $this->hasOne(SkuSequence::class);
    }

    /**
     * Get the effective template for this category.
     * If no template is set, it will inherit from the parent category.
     */
    public function getEffectiveTemplate(): ?ProductTemplate
    {
        if ($this->template_id) {
            return $this->template;
        }

        if ($this->parent) {
            return $this->parent->getEffectiveTemplate();
        }

        return null;
    }

    /**
     * Get the effective SKU format for this category.
     * If no format is set, it will inherit from the parent category.
     */
    public function getEffectiveSkuFormat(): ?string
    {
        if ($this->sku_format) {
            return $this->sku_format;
        }

        if ($this->parent) {
            return $this->parent->getEffectiveSkuFormat();
        }

        return null;
    }

    /**
     * Get the effective SKU prefix for this category.
     * If no prefix is set, it will inherit from the parent category.
     */
    public function getEffectiveSkuPrefix(): ?string
    {
        if ($this->sku_prefix) {
            return $this->sku_prefix;
        }

        if ($this->parent) {
            return $this->parent->getEffectiveSkuPrefix();
        }

        return null;
    }

    /**
     * Get the effective label template for this category.
     * If no template is set, it will inherit from the parent category.
     */
    public function getEffectiveLabelTemplate(): ?LabelTemplate
    {
        if ($this->label_template_id) {
            return $this->labelTemplate;
        }

        if ($this->parent) {
            return $this->parent->getEffectiveLabelTemplate();
        }

        return null;
    }

    /**
     * Get the effective SKU suffix for this category.
     * If no suffix is set, it will inherit from the parent category.
     */
    public function getEffectiveSkuSuffix(): ?string
    {
        if ($this->sku_suffix) {
            return $this->sku_suffix;
        }

        if ($this->parent) {
            return $this->parent->getEffectiveSkuSuffix();
        }

        return null;
    }

    /**
     * Get the effective default bucket for this category.
     * If no bucket is set, it will inherit from the parent category.
     */
    public function getEffectiveDefaultBucket(): ?Bucket
    {
        if ($this->default_bucket_id) {
            return $this->defaultBucket;
        }

        if ($this->parent) {
            return $this->parent->getEffectiveDefaultBucket();
        }

        return null;
    }

    /**
     * Get the effective barcode attributes for this category.
     * If no attributes are set, it will inherit from the parent category.
     * Defaults to ['category', 'sku', 'price', 'material'] if not set anywhere.
     *
     * @return array<int, string>
     */
    public function getEffectiveBarcodeAttributes(): array
    {
        if (! empty($this->barcode_attributes)) {
            return $this->barcode_attributes;
        }

        if ($this->parent) {
            return $this->parent->getEffectiveBarcodeAttributes();
        }

        // Default sequence
        return ['category', 'sku', 'price', 'material'];
    }

    /**
     * Get the effective charge_taxes setting for this category.
     * If not explicitly set, it will inherit from the parent category.
     * Defaults to true if no parent sets it.
     */
    public function getEffectiveChargeTaxes(): bool
    {
        // If this category has an explicit setting, use it
        if ($this->charge_taxes !== null) {
            return $this->charge_taxes;
        }

        // Otherwise, inherit from parent
        if ($this->parent) {
            return $this->parent->getEffectiveChargeTaxes();
        }

        // Default to true
        return true;
    }

    /**
     * Check if this category is a leaf node (has no children).
     */
    public function isLeaf(): bool
    {
        return $this->children()->count() === 0;
    }

    public function scopeRoots($query)
    {
        return $query->whereNull('parent_id')->orWhere('parent_id', 0);
    }

    public function getFullPathAttribute(): string
    {
        $path = [$this->name];
        $parent = $this->parent;

        while ($parent) {
            array_unshift($path, $parent->name);
            $parent = $parent->parent;
        }

        return implode(' > ', $path);
    }

    protected function getActivityPrefix(): string
    {
        return 'categories';
    }

    protected function getLoggableAttributes(): array
    {
        return ['id', 'name', 'slug', 'parent_id', 'template_id', 'sku_format', 'sku_prefix', 'sku_suffix', 'default_bucket_id', 'barcode_attributes', 'label_template_id'];
    }

    protected function getActivityIdentifier(): string
    {
        return $this->name ?? "#{$this->id}";
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
            'name' => $this->name,
            'description' => $this->description,
            'full_path' => $this->full_path,
            'store_id' => $this->store_id,
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
