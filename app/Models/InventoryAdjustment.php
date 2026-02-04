<?php

namespace App\Models;

use App\Traits\BelongsToStore;
use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryAdjustment extends Model
{
    use BelongsToStore, HasFactory, LogsActivity;

    public const TYPE_DAMAGED = 'damaged';

    public const TYPE_LOST = 'lost';

    public const TYPE_FOUND = 'found';

    public const TYPE_CORRECTION = 'correction';

    public const TYPE_CYCLE_COUNT = 'cycle_count';

    public const TYPE_SHRINKAGE = 'shrinkage';

    public const TYPE_WRITE_OFF = 'write_off';

    public const TYPE_RECEIVED = 'received';

    public const TYPE_RETURNED = 'returned';

    public const TYPE_PURCHASE_ORDER = 'purchase_order';

    public const TYPE_SOLD = 'sold';

    public const TYPE_INITIAL = 'initial';

    public const TYPE_DELETED = 'deleted';

    public const TYPES = [
        self::TYPE_DAMAGED,
        self::TYPE_LOST,
        self::TYPE_FOUND,
        self::TYPE_CORRECTION,
        self::TYPE_CYCLE_COUNT,
        self::TYPE_SHRINKAGE,
        self::TYPE_WRITE_OFF,
        self::TYPE_RECEIVED,
        self::TYPE_RETURNED,
        self::TYPE_PURCHASE_ORDER,
        self::TYPE_SOLD,
        self::TYPE_INITIAL,
        self::TYPE_DELETED,
    ];

    protected $fillable = [
        'store_id',
        'inventory_id',
        'purchase_order_receipt_item_id',
        'user_id',
        'reference',
        'type',
        'quantity_before',
        'quantity_change',
        'quantity_after',
        'unit_cost',
        'total_cost_impact',
        'reason',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'quantity_before' => 'integer',
            'quantity_change' => 'integer',
            'quantity_after' => 'integer',
            'unit_cost' => 'decimal:4',
            'total_cost_impact' => 'decimal:4',
        ];
    }

    public function inventory(): BelongsTo
    {
        return $this->belongsTo(Inventory::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function purchaseOrderReceiptItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderReceiptItem::class);
    }

    public function isIncrease(): bool
    {
        return $this->quantity_change > 0;
    }

    public function isDecrease(): bool
    {
        return $this->quantity_change < 0;
    }

    public function getAbsoluteChangeAttribute(): int
    {
        return abs($this->quantity_change);
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    public function scopeIncreases($query)
    {
        return $query->where('quantity_change', '>', 0);
    }

    public function scopeDecreases($query)
    {
        return $query->where('quantity_change', '<', 0);
    }

    public static function generateReference(int $storeId): string
    {
        $count = static::where('store_id', $storeId)->count() + 1;

        return 'ADJ-'.str_pad($count, 6, '0', STR_PAD_LEFT);
    }

    protected function getActivityMap(): array
    {
        return [
            'create' => Activity::INVENTORY_ADJUST,
            'update' => Activity::INVENTORY_ADJUST,
            'delete' => null,
        ];
    }

    protected function getLoggableAttributes(): array
    {
        return ['id', 'reference', 'type', 'quantity_before', 'quantity_change', 'quantity_after', 'reason'];
    }

    protected function getActivityDescription(string $action): string
    {
        $changeText = $this->quantity_change > 0 ? "+{$this->quantity_change}" : "{$this->quantity_change}";

        return "Inventory adjusted by {$changeText} ({$this->type}): {$this->reference}";
    }

    protected function getActivityIdentifier(): string
    {
        return $this->reference ?? "#{$this->id}";
    }
}
