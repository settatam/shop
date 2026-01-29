<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReturnItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'return_id',
        'order_item_id',
        'product_variant_id',
        'quantity',
        'unit_price',
        'line_total',
        'condition',
        'reason',
        'notes',
        'restock',
        'restocked',
        'restocked_at',
        'exchange_variant_id',
        'exchange_quantity',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'decimal:2',
            'line_total' => 'decimal:2',
            'restock' => 'boolean',
            'restocked' => 'boolean',
            'restocked_at' => 'datetime',
            'exchange_quantity' => 'integer',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function ($item) {
            if (empty($item->line_total)) {
                $item->line_total = $item->quantity * $item->unit_price;
            }
        });
    }

    public function return(): BelongsTo
    {
        return $this->belongsTo(ProductReturn::class, 'return_id');
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function exchangeVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'exchange_variant_id');
    }

    public function isExchange(): bool
    {
        return ! empty($this->exchange_variant_id);
    }

    public function wasRestocked(): bool
    {
        return $this->restocked;
    }

    public function shouldRestock(): bool
    {
        return $this->restock && ! $this->restocked;
    }

    public function markAsRestocked(): self
    {
        $this->update([
            'restocked' => true,
            'restocked_at' => now(),
        ]);

        return $this;
    }
}
