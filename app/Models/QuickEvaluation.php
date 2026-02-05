<?php

namespace App\Models;

use App\Traits\BelongsToStore;
use App\Traits\HasImages;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuickEvaluation extends Model
{
    use BelongsToStore, HasFactory, HasImages;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_CONVERTED = 'converted';

    public const STATUS_DISCARDED = 'discarded';

    protected $fillable = [
        'store_id',
        'user_id',
        'transaction_id',
        'title',
        'description',
        'category_id',
        'attributes',
        'estimated_value',
        'similar_items',
        'ai_research',
        'ai_research_generated_at',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'attributes' => 'array',
            'estimated_value' => 'decimal:2',
            'similar_items' => 'array',
            'ai_research' => 'array',
            'ai_research_generated_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isConverted(): bool
    {
        return $this->status === self::STATUS_CONVERTED;
    }

    public function isDiscarded(): bool
    {
        return $this->status === self::STATUS_DISCARDED;
    }

    public function markAsConverted(Transaction $transaction): self
    {
        $this->update([
            'status' => self::STATUS_CONVERTED,
            'transaction_id' => $transaction->id,
        ]);

        return $this;
    }

    public function markAsDiscarded(): self
    {
        $this->update(['status' => self::STATUS_DISCARDED]);

        return $this;
    }

    /**
     * Get available statuses.
     *
     * @return array<string, string>
     */
    public static function getStatuses(): array
    {
        return [
            self::STATUS_DRAFT => 'Draft',
            self::STATUS_CONVERTED => 'Converted',
            self::STATUS_DISCARDED => 'Discarded',
        ];
    }
}
