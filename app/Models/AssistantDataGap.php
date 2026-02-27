<?php

namespace App\Models;

use App\Traits\BelongsToStore;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssistantDataGap extends Model
{
    use BelongsToStore, HasFactory;

    protected $fillable = [
        'store_id',
        'product_id',
        'field_name',
        'question_context',
        'occurrences',
        'last_occurred_at',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'occurrences' => 'integer',
            'last_occurred_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Record a data gap occurrence for a product field.
     */
    public static function recordGap(int $storeId, int $productId, string $fieldName, ?string $questionContext = null): void
    {
        $gap = static::firstOrCreate(
            [
                'store_id' => $storeId,
                'product_id' => $productId,
                'field_name' => $fieldName,
            ],
            [
                'occurrences' => 0,
                'last_occurred_at' => now(),
                'question_context' => $questionContext,
            ]
        );

        $gap->increment('occurrences');
        $gap->update([
            'last_occurred_at' => now(),
            'question_context' => $questionContext,
            'resolved_at' => null,
        ]);
    }

    /**
     * Mark a gap as resolved.
     */
    public function markResolved(): void
    {
        $this->update(['resolved_at' => now()]);
    }

    /**
     * Scope to unresolved gaps only.
     */
    public function scopeUnresolved($query)
    {
        return $query->whereNull('resolved_at');
    }
}
