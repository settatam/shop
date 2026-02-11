<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RepairItem extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    protected $fillable = [
        'repair_id',
        'product_id',
        'category_id',
        'sku',
        'title',
        'description',
        'vendor_cost',
        'customer_cost',
        'status',
        'dwt',
        'precious_metal',
    ];

    protected function casts(): array
    {
        return [
            'vendor_cost' => 'decimal:2',
            'customer_cost' => 'decimal:2',
            'dwt' => 'decimal:4',
        ];
    }

    public function repair(): BelongsTo
    {
        return $this->belongsTo(Repair::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function markCompleted(): self
    {
        $this->update(['status' => self::STATUS_COMPLETED]);

        return $this;
    }

    public function returnToStock(): ?Product
    {
        if ($this->product) {
            $this->product->update(['status' => Product::STATUS_ACTIVE]);

            return $this->product;
        }

        return null;
    }

    /**
     * Mark a product as in repair when added to a repair.
     */
    public static function markProductInRepair(Product $product): void
    {
        $product->update(['status' => Product::STATUS_IN_REPAIR]);
    }

    public function getQuantityAttribute(): int
    {
        return 1;
    }

    public function getPriceAttribute(): float
    {
        return (float) $this->customer_cost;
    }

    public function getProfitAttribute(): float
    {
        return (float) $this->customer_cost - (float) $this->vendor_cost;
    }
}
