<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class SkuSequence extends Model
{
    protected $fillable = [
        'category_id',
        'store_id',
        'current_value',
    ];

    protected function casts(): array
    {
        return [
            'current_value' => 'integer',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * Atomically increment the sequence and return the new value.
     * Uses database locking to ensure thread safety.
     */
    public function incrementAndGet(): int
    {
        return DB::transaction(function () {
            // Lock the row for update
            $sequence = static::query()
                ->where('id', $this->id)
                ->lockForUpdate()
                ->first();

            $newValue = $sequence->current_value + 1;
            $sequence->update(['current_value' => $newValue]);

            return $newValue;
        });
    }

    /**
     * Get or create a sequence for the given category and store.
     */
    public static function getOrCreate(Category $category, Store $store): self
    {
        return static::firstOrCreate(
            [
                'category_id' => $category->id,
                'store_id' => $store->id,
            ],
            [
                'current_value' => 0,
            ]
        );
    }

    /**
     * Reset the sequence to a specific value.
     */
    public function resetTo(int $value = 0): void
    {
        $this->update(['current_value' => $value]);
    }
}
