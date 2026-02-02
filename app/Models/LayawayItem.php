<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LayawayItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'layaway_id',
        'product_id',
        'product_variant_id',
        'sku',
        'title',
        'description',
        'quantity',
        'price',
        'line_total',
        'is_reserved',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'price' => 'decimal:2',
            'line_total' => 'decimal:2',
            'is_reserved' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (LayawayItem $item) {
            $item->line_total = $item->quantity * $item->price;
        });

        static::updating(function (LayawayItem $item) {
            $item->line_total = $item->quantity * $item->price;
        });

        static::saved(function (LayawayItem $item) {
            $item->layaway?->calculateTotals();
        });

        static::deleted(function (LayawayItem $item) {
            $item->layaway?->calculateTotals();
        });
    }

    public function layaway(): BelongsTo
    {
        return $this->belongsTo(Layaway::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function isReserved(): bool
    {
        return $this->is_reserved;
    }

    public function reserve(): self
    {
        if ($this->product) {
            // Reduce available quantity to reserve item
            $this->product->decrement('quantity', $this->quantity);
        }

        $this->update(['is_reserved' => true]);

        return $this;
    }

    public function release(): self
    {
        if ($this->product && $this->is_reserved) {
            // Return reserved quantity to available stock
            $this->product->increment('quantity', $this->quantity);
        }

        $this->update(['is_reserved' => false]);

        return $this;
    }
}
