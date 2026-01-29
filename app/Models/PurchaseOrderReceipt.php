<?php

namespace App\Models;

use App\Traits\BelongsToStore;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class PurchaseOrderReceipt extends Model
{
    use BelongsToStore, HasFactory;

    protected $fillable = [
        'store_id',
        'purchase_order_id',
        'received_by',
        'receipt_number',
        'received_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (PurchaseOrderReceipt $receipt) {
            if (empty($receipt->receipt_number)) {
                $receipt->receipt_number = static::generateReceiptNumber();
            }
        });
    }

    public static function generateReceiptNumber(): string
    {
        $prefix = 'RCV';
        $date = now()->format('Ymd');
        $random = strtoupper(Str::random(4));

        return "{$prefix}-{$date}-{$random}";
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderReceiptItem::class);
    }

    public function getTotalQuantityAttribute(): int
    {
        return $this->items->sum('quantity_received');
    }

    public function getTotalValueAttribute(): float
    {
        return $this->items->sum(function ($item) {
            return $item->quantity_received * $item->unit_cost;
        });
    }
}
