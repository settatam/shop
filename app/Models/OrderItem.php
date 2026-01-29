<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class OrderItem extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'order_id',
        'product_id',
        'product_variant_id',
        'sku',
        'title',
        'quantity',
        'price',
        'cost',
        'discount',
        'tax',
        'shipstation_id',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'cost' => 'decimal:2',
            'discount' => 'decimal:2',
            'tax' => 'decimal:2',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function getLineTotalAttribute(): float
    {
        $subtotal = ((float) $this->price - ((float) $this->discount ?? 0)) * $this->quantity;

        return $subtotal + ((float) $this->tax ?? 0);
    }

    public function getLineProfitAttribute(): float
    {
        if ($this->cost === null) {
            return 0;
        }

        return ($this->price - $this->discount - $this->cost) * $this->quantity;
    }
}
