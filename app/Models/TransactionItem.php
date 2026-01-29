<?php

namespace App\Models;

use App\Traits\HasImages;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransactionItem extends Model
{
    use HasFactory, HasImages;

    // Precious metal constants
    public const METAL_GOLD_10K = 'gold_10k';

    public const METAL_GOLD_14K = 'gold_14k';

    public const METAL_GOLD_18K = 'gold_18k';

    public const METAL_GOLD_22K = 'gold_22k';

    public const METAL_GOLD_24K = 'gold_24k';

    public const METAL_SILVER = 'silver';

    public const METAL_PLATINUM = 'platinum';

    public const METAL_PALLADIUM = 'palladium';

    // Condition constants
    public const CONDITION_NEW = 'new';

    public const CONDITION_LIKE_NEW = 'like_new';

    public const CONDITION_USED = 'used';

    public const CONDITION_DAMAGED = 'damaged';

    protected $fillable = [
        'transaction_id',
        'category_id',
        'product_id',
        'sku',
        'title',
        'description',
        'price',
        'buy_price',
        'dwt',
        'precious_metal',
        'condition',
        'is_added_to_inventory',
        'date_added_to_inventory',
        'reviewed_at',
        'reviewed_by',
        'ai_research',
        'ai_research_generated_at',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'buy_price' => 'decimal:2',
            'dwt' => 'decimal:4',
            'is_added_to_inventory' => 'boolean',
            'date_added_to_inventory' => 'datetime',
            'reviewed_at' => 'datetime',
            'ai_research' => 'array',
            'ai_research_generated_at' => 'datetime',
        ];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function isAddedToInventory(): bool
    {
        return $this->is_added_to_inventory;
    }

    public function canBeAddedToInventory(): bool
    {
        return ! $this->is_added_to_inventory
            && $this->transaction->isPaymentProcessed();
    }

    public function markAsAddedToInventory(int $productId): self
    {
        $this->update([
            'is_added_to_inventory' => true,
            'product_id' => $productId,
            'date_added_to_inventory' => now(),
        ]);

        return $this;
    }

    public function getQuantityAttribute(): int
    {
        return 1;
    }

    public function isPreciousMetal(): bool
    {
        return ! empty($this->precious_metal);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isReviewed(): bool
    {
        return $this->reviewed_at !== null;
    }

    public function markAsReviewed(int $userId): self
    {
        $this->update([
            'reviewed_at' => now(),
            'reviewed_by' => $userId,
        ]);

        return $this;
    }
}
