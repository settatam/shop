<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class MemoItem extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'memo_id',
        'product_id',
        'category_id',
        'sku',
        'title',
        'description',
        'price',
        'cost',
        'tenor',
        'due_date',
        'is_returned',
        'charge_taxes',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'cost' => 'decimal:2',
            'tenor' => 'integer',
            'due_date' => 'date',
            'is_returned' => 'boolean',
            'charge_taxes' => 'boolean',
        ];
    }

    public function memo(): BelongsTo
    {
        return $this->belongsTo(Memo::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function isReturned(): bool
    {
        return $this->is_returned;
    }

    public function canBeReturned(): bool
    {
        return ! $this->is_returned;
    }

    public function returnToStock(): self
    {
        if ($this->product) {
            // Restore product quantity and status to mark it as back in stock
            $this->product->update([
                'quantity' => 1,
                'status' => Product::STATUS_ACTIVE,
            ]);
        }

        $this->update(['is_returned' => true]);

        return $this;
    }

    /**
     * Mark a product as on memo when added to a memo.
     * Sets quantity to 0 and status to in_memo.
     */
    public static function markProductOnMemo(Product $product): void
    {
        $product->update([
            'quantity' => 0,
            'status' => Product::STATUS_IN_MEMO,
        ]);
    }

    public function returnItem(): self
    {
        $this->returnToStock();
        $this->memo->calculateTotals();

        return $this;
    }

    public function getQuantityAttribute(): int
    {
        return 1;
    }

    public function getProfitAttribute(): float
    {
        return (float) $this->price - (float) $this->cost;
    }

    public function getEffectiveDueDateAttribute()
    {
        if ($this->due_date) {
            return $this->due_date;
        }

        if ($this->tenor && $this->created_at) {
            return $this->created_at->addDays($this->tenor);
        }

        return $this->memo?->due_date;
    }
}
