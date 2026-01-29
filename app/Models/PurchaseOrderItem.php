<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrderItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'purchase_order_id',
        'product_variant_id',
        'vendor_sku',
        'description',
        'quantity_ordered',
        'quantity_received',
        'unit_cost',
        'discount_percent',
        'tax_rate',
        'line_total',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'quantity_ordered' => 'integer',
            'quantity_received' => 'integer',
            'unit_cost' => 'decimal:2',
            'discount_percent' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (PurchaseOrderItem $item) {
            $item->calculateLineTotal();
        });

        static::saved(function (PurchaseOrderItem $item) {
            $item->purchaseOrder->recalculateTotals();
        });

        static::deleted(function (PurchaseOrderItem $item) {
            $item->purchaseOrder->recalculateTotals();
        });
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function receiptItems(): HasMany
    {
        return $this->hasMany(PurchaseOrderReceiptItem::class);
    }

    public function calculateLineTotal(): void
    {
        $subtotal = $this->quantity_ordered * $this->unit_cost;
        $discount = $subtotal * ($this->discount_percent / 100);
        $afterDiscount = $subtotal - $discount;
        $tax = $afterDiscount * ($this->tax_rate / 100);

        $this->line_total = $afterDiscount + $tax;
    }

    public function getRemainingQuantityAttribute(): int
    {
        return max(0, $this->quantity_ordered - $this->quantity_received);
    }

    public function isFullyReceived(): bool
    {
        return $this->quantity_received >= $this->quantity_ordered;
    }

    public function isPartiallyReceived(): bool
    {
        return $this->quantity_received > 0 && $this->quantity_received < $this->quantity_ordered;
    }

    public function getReceivingProgressAttribute(): float
    {
        if ($this->quantity_ordered === 0) {
            return 0;
        }

        return round(($this->quantity_received / $this->quantity_ordered) * 100, 2);
    }
}
