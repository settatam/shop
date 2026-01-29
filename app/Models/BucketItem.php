<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BucketItem extends Model
{
    /** @use HasFactory<\Database\Factories\BucketItemFactory> */
    use HasFactory;

    protected $fillable = [
        'bucket_id',
        'transaction_item_id',
        'title',
        'description',
        'value',
        'sold_at',
        'order_item_id',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
            'sold_at' => 'datetime',
        ];
    }

    public function bucket(): BelongsTo
    {
        return $this->belongsTo(Bucket::class);
    }

    public function transactionItem(): BelongsTo
    {
        return $this->belongsTo(TransactionItem::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function isSold(): bool
    {
        return $this->sold_at !== null;
    }

    public function isActive(): bool
    {
        return $this->sold_at === null;
    }

    public function markAsSold(OrderItem $orderItem): self
    {
        $this->update([
            'sold_at' => now(),
            'order_item_id' => $orderItem->id,
        ]);

        $this->bucket->recalculateTotal();

        return $this;
    }
}
