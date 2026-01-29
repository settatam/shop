<?php

namespace App\Models;

use App\Traits\BelongsToStore;
use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryTransfer extends Model
{
    use BelongsToStore, HasFactory, LogsActivity;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PENDING = 'pending';

    public const STATUS_IN_TRANSIT = 'in_transit';

    public const STATUS_RECEIVED = 'received';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_PENDING,
        self::STATUS_IN_TRANSIT,
        self::STATUS_RECEIVED,
        self::STATUS_CANCELLED,
    ];

    protected $fillable = [
        'store_id',
        'from_warehouse_id',
        'to_warehouse_id',
        'created_by',
        'received_by',
        'reference',
        'status',
        'notes',
        'shipped_at',
        'expected_at',
        'received_at',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'shipped_at' => 'datetime',
            'expected_at' => 'datetime',
            'received_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function fromWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'from_warehouse_id');
    }

    public function toWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'to_warehouse_id');
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function receivedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(InventoryTransferItem::class);
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isInTransit(): bool
    {
        return $this->status === self::STATUS_IN_TRANSIT;
    }

    public function isReceived(): bool
    {
        return $this->status === self::STATUS_RECEIVED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function canBeEdited(): bool
    {
        return $this->isDraft();
    }

    public function canBeShipped(): bool
    {
        return $this->isPending() && $this->items()->count() > 0;
    }

    public function canBeReceived(): bool
    {
        return $this->isInTransit();
    }

    public function canBeCancelled(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_PENDING]);
    }

    public function submit(): void
    {
        if (! $this->isDraft()) {
            throw new \RuntimeException('Only draft transfers can be submitted');
        }

        $this->update(['status' => self::STATUS_PENDING]);
    }

    public function ship(): void
    {
        if (! $this->canBeShipped()) {
            throw new \RuntimeException('Transfer cannot be shipped');
        }

        // Reduce inventory at source warehouse
        foreach ($this->items as $item) {
            $inventory = Inventory::where('product_variant_id', $item->product_variant_id)
                ->where('warehouse_id', $this->from_warehouse_id)
                ->first();

            if ($inventory) {
                $inventory->adjustQuantity(
                    -$item->quantity_requested,
                    InventoryAdjustment::TYPE_CORRECTION,
                    null,
                    'Transfer out: '.$this->reference
                );
            }

            $item->update(['quantity_shipped' => $item->quantity_requested]);
        }

        // Add to incoming quantity at destination
        foreach ($this->items as $item) {
            $inventory = Inventory::getOrCreate(
                $this->store_id,
                $item->product_variant_id,
                $this->to_warehouse_id
            );
            $inventory->increment('incoming_quantity', $item->quantity_shipped);
        }

        $this->update([
            'status' => self::STATUS_IN_TRANSIT,
            'shipped_at' => now(),
        ]);
    }

    public function receive(?int $userId = null): void
    {
        if (! $this->canBeReceived()) {
            throw new \RuntimeException('Transfer cannot be received');
        }

        // Add inventory at destination warehouse
        foreach ($this->items as $item) {
            $inventory = Inventory::getOrCreate(
                $this->store_id,
                $item->product_variant_id,
                $this->to_warehouse_id
            );

            $inventory->receive($item->quantity_shipped);
            $item->update(['quantity_received' => $item->quantity_shipped]);
        }

        $this->update([
            'status' => self::STATUS_RECEIVED,
            'received_at' => now(),
            'received_by' => $userId,
        ]);
    }

    public function cancel(): void
    {
        if (! $this->canBeCancelled()) {
            throw new \RuntimeException('Transfer cannot be cancelled');
        }

        $this->update([
            'status' => self::STATUS_CANCELLED,
            'cancelled_at' => now(),
        ]);
    }

    public function getTotalItemsAttribute(): int
    {
        return $this->items->sum('quantity_requested');
    }

    public function getTotalShippedAttribute(): int
    {
        return $this->items->sum('quantity_shipped');
    }

    public function getTotalReceivedAttribute(): int
    {
        return $this->items->sum('quantity_received');
    }

    public function scopeOfStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeInTransit($query)
    {
        return $query->where('status', self::STATUS_IN_TRANSIT);
    }

    public static function generateReference(int $storeId): string
    {
        $count = static::where('store_id', $storeId)->count() + 1;

        return 'TRF-'.str_pad($count, 6, '0', STR_PAD_LEFT);
    }

    protected function getActivityMap(): array
    {
        return [
            'create' => Activity::INVENTORY_TRANSFER,
            'update' => Activity::INVENTORY_TRANSFER,
            'delete' => null,
        ];
    }

    protected function getLoggableAttributes(): array
    {
        return ['id', 'reference', 'status', 'from_warehouse_id', 'to_warehouse_id'];
    }

    protected function getActivityDescription(string $action): string
    {
        return match ($action) {
            'create' => "Inventory transfer {$this->reference} was created",
            'update' => "Inventory transfer {$this->reference} status changed to {$this->status}",
            default => "{$action} performed on transfer {$this->reference}",
        };
    }

    protected function getActivityIdentifier(): string
    {
        return $this->reference ?? "#{$this->id}";
    }
}
