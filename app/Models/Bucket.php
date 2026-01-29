<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Bucket extends Model
{
    /** @use HasFactory<\Database\Factories\BucketFactory> */
    use HasFactory;

    protected $fillable = [
        'store_id',
        'name',
        'description',
        'total_value',
    ];

    protected function casts(): array
    {
        return [
            'total_value' => 'decimal:2',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(BucketItem::class);
    }

    public function activeItems(): HasMany
    {
        return $this->hasMany(BucketItem::class)->whereNull('sold_at');
    }

    public function soldItems(): HasMany
    {
        return $this->hasMany(BucketItem::class)->whereNotNull('sold_at');
    }

    public function recalculateTotal(): self
    {
        $this->total_value = $this->activeItems()->sum('value');
        $this->save();

        return $this;
    }

    public function isEmpty(): bool
    {
        return $this->items()->count() === 0;
    }

    public function hasActiveItems(): bool
    {
        return $this->activeItems()->exists();
    }
}
