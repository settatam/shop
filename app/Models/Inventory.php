<?php

namespace App\Models;

use App\Traits\BelongsToStore;
use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Inventory extends Model
{
    use BelongsToStore, HasFactory, LogsActivity;

    protected $table = 'inventory';

    protected $fillable = [
        'store_id',
        'product_variant_id',
        'warehouse_id',
        'quantity',
        'reserved_quantity',
        'incoming_quantity',
        'reorder_point',
        'reorder_quantity',
        'safety_stock',
        'bin_location',
        'zone',
        'unit_cost',
        'last_cost',
        'last_counted_at',
        'last_received_at',
        'last_sold_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'reserved_quantity' => 'integer',
            'incoming_quantity' => 'integer',
            'reorder_point' => 'integer',
            'reorder_quantity' => 'integer',
            'safety_stock' => 'integer',
            'unit_cost' => 'decimal:4',
            'last_cost' => 'decimal:4',
            'last_counted_at' => 'datetime',
            'last_received_at' => 'datetime',
            'last_sold_at' => 'datetime',
        ];
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(InventoryAdjustment::class);
    }

    public function getAvailableQuantityAttribute(): int
    {
        return max(0, $this->quantity - $this->reserved_quantity);
    }

    public function getExpectedQuantityAttribute(): int
    {
        return $this->quantity + $this->incoming_quantity;
    }

    public function getTotalValueAttribute(): float
    {
        return $this->quantity * ($this->unit_cost ?? 0);
    }

    public function needsReorder(): bool
    {
        if ($this->reorder_point === null) {
            return false;
        }

        return $this->available_quantity <= $this->reorder_point;
    }

    public function isLowStock(): bool
    {
        return $this->available_quantity <= $this->safety_stock;
    }

    public function adjustQuantity(int $adjustment, string $type, ?int $userId = null, ?string $reason = null, ?string $notes = null): InventoryAdjustment
    {
        $quantityBefore = $this->quantity;
        $this->quantity += $adjustment;
        $this->save();

        $inventoryAdjustment = InventoryAdjustment::create([
            'store_id' => $this->store_id,
            'inventory_id' => $this->id,
            'user_id' => $userId,
            'type' => $type,
            'quantity_before' => $quantityBefore,
            'quantity_change' => $adjustment,
            'quantity_after' => $this->quantity,
            'unit_cost' => $this->unit_cost,
            'total_cost_impact' => $adjustment * ($this->unit_cost ?? 0),
            'reason' => $reason,
            'notes' => $notes,
        ]);

        // Log manual quantity adjustments for notifications
        // Exclude automatic adjustments from sales (type: sale, fulfill, order)
        $manualTypes = ['manual', 'adjustment', 'count', 'damage', 'loss', 'return', 'correction'];
        if (in_array($type, $manualTypes, true)) {
            $this->logActivity('manual_adjust', [
                'old' => ['quantity' => $quantityBefore],
                'new' => ['quantity' => $this->quantity],
                'adjustment' => $adjustment,
                'type' => $type,
                'reason' => $reason,
                'notes' => $notes,
            ], "Inventory adjusted: {$quantityBefore} → {$this->quantity} ({$type})");
        }

        return $inventoryAdjustment;
    }

    public function reserve(int $quantity): bool
    {
        if ($this->available_quantity < $quantity) {
            return false;
        }

        $this->reserved_quantity += $quantity;
        $this->save();

        return true;
    }

    public function releaseReservation(int $quantity): void
    {
        $this->reserved_quantity = max(0, $this->reserved_quantity - $quantity);
        $this->save();
    }

    public function fulfill(int $quantity): bool
    {
        if ($this->quantity < $quantity) {
            return false;
        }

        $this->quantity -= $quantity;
        $this->reserved_quantity = max(0, $this->reserved_quantity - $quantity);
        $this->last_sold_at = now();
        $this->save();

        return true;
    }

    public function receive(int $quantity, ?float $unitCost = null): void
    {
        $this->quantity += $quantity;
        $this->incoming_quantity = max(0, $this->incoming_quantity - $quantity);
        $this->last_received_at = now();

        if ($unitCost !== null) {
            $this->last_cost = $unitCost;
            // Update weighted average cost
            if ($this->quantity > 0) {
                $oldValue = ($this->quantity - $quantity) * ($this->unit_cost ?? 0);
                $newValue = $quantity * $unitCost;
                $this->unit_cost = ($oldValue + $newValue) / $this->quantity;
            }
        }

        $this->save();
    }

    public static function getOrCreate(int $storeId, int $variantId, int $warehouseId): self
    {
        return static::firstOrCreate(
            [
                'product_variant_id' => $variantId,
                'warehouse_id' => $warehouseId,
            ],
            [
                'store_id' => $storeId,
                'quantity' => 0,
                'reserved_quantity' => 0,
                'incoming_quantity' => 0,
                'safety_stock' => 0,
            ]
        );
    }

    public function scopeLowStock($query)
    {
        return $query->whereRaw('(quantity - reserved_quantity) <= safety_stock');
    }

    public function scopeNeedsReorder($query)
    {
        return $query->whereNotNull('reorder_point')
            ->whereRaw('(quantity - reserved_quantity) <= reorder_point');
    }

    public function scopeInWarehouse($query, int $warehouseId)
    {
        return $query->where('warehouse_id', $warehouseId);
    }

    public function scopeForVariant($query, int $variantId)
    {
        return $query->where('product_variant_id', $variantId);
    }

    /**
     * Override boot to disable automatic activity logging.
     * We only want to log manual adjustments, not automatic ones from sales.
     */
    public static function bootLogsActivity(): void
    {
        // Intentionally empty - we manually log in adjustQuantity() for manual adjustments only
    }

    /**
     * Get the activity prefix for this model.
     */
    protected function getActivityPrefix(): string
    {
        return 'inventory';
    }

    /**
     * Get the mapping of actions to activity slugs.
     */
    protected function getActivityMap(): array
    {
        return [
            'create' => 'inventory.create',
            'update' => 'inventory.update',
            'delete' => 'inventory.delete',
            'manual_adjust' => Activity::INVENTORY_QUANTITY_MANUAL_ADJUST,
        ];
    }

    /**
     * Get attributes that should be logged.
     */
    protected function getLoggableAttributes(): array
    {
        return ['id', 'product_variant_id', 'warehouse_id', 'quantity'];
    }

    /**
     * Get the identifier for this model in activity descriptions.
     */
    protected function getActivityIdentifier(): string
    {
        $sku = $this->variant?->sku ?? '';
        $warehouse = $this->warehouse?->name ?? '';

        return "{$sku} @ {$warehouse}";
    }
}
