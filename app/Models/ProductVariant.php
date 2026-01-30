<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductVariant extends Model
{
    use HasFactory, SoftDeletes;

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
}
