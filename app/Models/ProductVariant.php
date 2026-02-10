<?php

namespace App\Models;

use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductVariant extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    /**
     * Price fields that trigger price change notifications.
     */
    protected array $priceFields = ['price', 'wholesale_price', 'cost'];

    protected $fillable = [
        'product_id',
        'sku',
        'price',
        'wholesale_price',
        'cost',
        'quantity',
        'barcode',
        'status',
        'sort_order',
        'is_active',
        'weight',
        'weight_unit',
        'option1_name',
        'option1_value',
        'option2_name',
        'option2_value',
        'option3_name',
        'option3_value',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'wholesale_price' => 'decimal:2',
            'cost' => 'decimal:2',
            'weight' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class);
    }

    public function inventories(): HasMany
    {
        return $this->hasMany(Inventory::class);
    }

    public function vendors(): BelongsToMany
    {
        return $this->belongsToMany(Vendor::class, 'product_vendor')
            ->withPivot(['vendor_sku', 'cost', 'lead_time_days', 'minimum_order_qty', 'is_preferred', 'notes'])
            ->withTimestamps();
    }

    public function preferredVendor(): ?Vendor
    {
        return $this->vendors()->wherePivot('is_preferred', true)->first();
    }

    public function getTotalInventoryAttribute(): int
    {
        return $this->inventories->sum('quantity');
    }

    public function getOptionsAttribute(): array
    {
        $options = [];

        if ($this->option1_name && $this->option1_value) {
            $options[$this->option1_name] = $this->option1_value;
        }
        if ($this->option2_name && $this->option2_value) {
            $options[$this->option2_name] = $this->option2_value;
        }
        if ($this->option3_name && $this->option3_value) {
            $options[$this->option3_name] = $this->option3_value;
        }

        return $options;
    }

    public function getOptionsTitleAttribute(): string
    {
        return implode(' / ', array_values($this->options));
    }

    /**
     * Get the effective cost for profit calculation.
     * Priority: wholesale_price > cost
     */
    public function getEffectiveCostAttribute(): ?float
    {
        if ($this->wholesale_price !== null && $this->wholesale_price > 0) {
            return (float) $this->wholesale_price;
        }

        return $this->cost !== null ? (float) $this->cost : null;
    }

    /**
     * Get the activity prefix for this model.
     */
    protected function getActivityPrefix(): string
    {
        return 'products';
    }

    /**
     * Get the activity slug for this model and action.
     * Override to use price_change activity when price fields are modified.
     */
    protected function getActivitySlug(string $action): ?string
    {
        // Check if this is an update with price field changes
        if ($action === 'update' && $this->hasPriceFieldChanges()) {
            return Activity::PRODUCTS_PRICE_CHANGE;
        }

        // For variants, we still use products prefix for consistency
        $map = $this->getActivityMap();

        return $map[$action] ?? null;
    }

    /**
     * Check if any price fields were changed in this update.
     */
    protected function hasPriceFieldChanges(): bool
    {
        $changedFields = array_keys($this->getChanges());

        return ! empty(array_intersect($changedFields, $this->priceFields));
    }

    /**
     * Get attributes that should be logged.
     */
    protected function getLoggableAttributes(): array
    {
        return ['id', 'product_id', 'sku', 'price', 'wholesale_price', 'cost', 'quantity'];
    }

    /**
     * Get a human-readable description for the activity.
     */
    protected function getActivityDescription(string $action): string
    {
        $product = $this->product;
        $identifier = $product?->title ?? "Product #{$this->product_id}";
        $variantInfo = $this->sku ? " (SKU: {$this->sku})" : " (Variant #{$this->id})";

        if ($action === 'update' && $this->hasPriceFieldChanges()) {
            $changes = $this->getPriceChangeDescription();

            return "Price changed for {$identifier}{$variantInfo}: {$changes}";
        }

        return match ($action) {
            'create' => "Variant{$variantInfo} was added to {$identifier}",
            'update' => "Variant{$variantInfo} was updated for {$identifier}",
            'delete' => "Variant{$variantInfo} was removed from {$identifier}",
            default => "{$action} performed on variant{$variantInfo} for {$identifier}",
        };
    }

    /**
     * Get a human-readable description of price changes.
     */
    protected function getPriceChangeDescription(): string
    {
        $changes = [];
        $original = $this->getOriginal();

        foreach ($this->priceFields as $field) {
            if ($this->wasChanged($field)) {
                $oldValue = $original[$field] ?? 0;
                $newValue = $this->getAttribute($field) ?? 0;
                $fieldLabel = match ($field) {
                    'price' => 'Price',
                    'wholesale_price' => 'Wholesale Price',
                    'cost' => 'Cost',
                    default => ucfirst(str_replace('_', ' ', $field)),
                };
                $changes[] = "{$fieldLabel}: \${$oldValue} → \${$newValue}";
            }
        }

        return implode(', ', $changes);
    }

    /**
     * Get the identifier for this model in activity descriptions.
     */
    protected function getActivityIdentifier(): string
    {
        return $this->sku ?? "#{$this->id}";
    }
}
