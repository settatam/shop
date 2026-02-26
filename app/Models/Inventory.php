<?php

namespace App\Models;

use App\Jobs\SyncProductInventoryJob;
use App\Traits\BelongsToStore;
use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Inventory record representing stock for a single variant in a single warehouse.
 *
 * This is the source of truth for stock quantities. Each record tracks:
 * - quantity: on-hand stock
 * - reserved_quantity: stock held for pending orders (not yet fulfilled)
 * - incoming_quantity: stock expected from purchase orders or transfers
 *
 * Cascade behavior (via Eloquent booted hooks):
 * 1. On saved/deleted → syncVariantQuantity() sums all warehouse rows into ProductVariant.quantity
 * 2. Then → syncProductQuantity() sums all variant quantities into Product.quantity
 * 3. Then → dispatchPlatformSync() dispatches SyncProductInventoryJob to push the
 *    new quantity to every platform where the product has a "listed" PlatformListing
 *
 * This means ANY inventory change — sale, manual adjustment, transfer receive,
 * stock count correction — automatically propagates to variant → product → all platforms.
 *
 * @property int $id
 * @property int $store_id
 * @property int $product_variant_id
 * @property int $warehouse_id
 * @property int $quantity
 * @property int $reserved_quantity
 * @property int $incoming_quantity
 * @property int|null $reorder_point
 * @property int|null $reorder_quantity
 * @property int $safety_stock
 * @property string|null $bin_location
 * @property string|null $zone
 * @property float|null $unit_cost
 * @property float|null $last_cost
 * @property \Illuminate\Support\Carbon|null $last_counted_at
 * @property \Illuminate\Support\Carbon|null $last_received_at
 * @property \Illuminate\Support\Carbon|null $last_sold_at
 * @property-read int $available_quantity  quantity minus reserved
 * @property-read int $expected_quantity   quantity plus incoming
 * @property-read float $total_value       quantity times unit_cost
 * @property-read ProductVariant $variant
 * @property-read Warehouse $warehouse
 */
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

    // ──────────────────────────────────────────────────────────────
    //  Relationships
    // ──────────────────────────────────────────────────────────────

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

    // ──────────────────────────────────────────────────────────────
    //  Computed Attributes
    // ──────────────────────────────────────────────────────────────

    /** Sellable stock: on-hand minus reserved. */
    public function getAvailableQuantityAttribute(): int
    {
        return max(0, $this->quantity - $this->reserved_quantity);
    }

    /** Projected stock: on-hand plus expected incoming. */
    public function getExpectedQuantityAttribute(): int
    {
        return $this->quantity + $this->incoming_quantity;
    }

    /** Total value of on-hand stock at unit cost. */
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

    // ──────────────────────────────────────────────────────────────
    //  Stock Operations
    // ──────────────────────────────────────────────────────────────

    /**
     * Adjust quantity by a positive or negative amount with full audit trail.
     *
     * Creates an InventoryAdjustment record and, for manual adjustment types,
     * logs an activity entry for notification purposes.
     *
     * The save() call triggers the booted hooks which cascade:
     * variant quantity sync → product quantity sync → platform inventory sync.
     */
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

        // Log manual adjustments for notifications (exclude automated sale/fulfill/order types)
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

    /**
     * Reserve stock for a pending order. Returns false if insufficient available quantity.
     */
    public function reserve(int $quantity): bool
    {
        if ($this->available_quantity < $quantity) {
            return false;
        }

        $this->reserved_quantity += $quantity;
        $this->save();

        return true;
    }

    /**
     * Release previously reserved stock (e.g. order cancelled before fulfillment).
     */
    public function releaseReservation(int $quantity): void
    {
        $this->reserved_quantity = max(0, $this->reserved_quantity - $quantity);
        $this->save();
    }

    /**
     * Fulfill an order: deduct from on-hand and release the reservation.
     * Returns false if insufficient on-hand quantity.
     */
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

    /**
     * Receive incoming stock (e.g. from a purchase order or transfer).
     * Optionally updates weighted average cost when unitCost is provided.
     */
    public function receive(int $quantity, ?float $unitCost = null): void
    {
        $this->quantity += $quantity;
        $this->incoming_quantity = max(0, $this->incoming_quantity - $quantity);
        $this->last_received_at = now();

        if ($unitCost !== null) {
            $this->last_cost = $unitCost;
            if ($this->quantity > 0) {
                $oldValue = ($this->quantity - $quantity) * ($this->unit_cost ?? 0);
                $newValue = $quantity * $unitCost;
                $this->unit_cost = ($oldValue + $newValue) / $this->quantity;
            }
        }

        $this->save();
    }

    /**
     * Get or create an inventory record for a variant/warehouse pair.
     * Used when adding stock to a warehouse where no record exists yet.
     */
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

    // ──────────────────────────────────────────────────────────────
    //  Scopes
    // ──────────────────────────────────────────────────────────────

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

    // ──────────────────────────────────────────────────────────────
    //  Quantity Cascade Sync
    // ──────────────────────────────────────────────────────────────

    /**
     * Sum all inventory rows for a variant and write the total to ProductVariant.quantity.
     */
    public static function syncVariantQuantity(int $variantId): void
    {
        $total = static::where('product_variant_id', $variantId)->sum('quantity');
        ProductVariant::where('id', $variantId)->update(['quantity' => $total]);
    }

    /**
     * Sum all variant quantities for a product and write the total to Product.quantity.
     * Logs an activity entry on the product when the quantity changes.
     */
    public static function syncProductQuantity(int $productId): void
    {
        $product = Product::find($productId);

        if (! $product) {
            return;
        }

        $oldQuantity = (int) $product->quantity;
        $newQuantity = (int) ProductVariant::where('product_id', $productId)->sum('quantity');

        if ($oldQuantity === $newQuantity) {
            Product::where('id', $productId)->update(['quantity' => $newQuantity]);

            return;
        }

        Product::where('id', $productId)->update(['quantity' => $newQuantity]);

        $product->logActivity('quantity_change', [
            'old' => ['quantity' => $oldQuantity],
            'new' => ['quantity' => $newQuantity],
        ], "Quantity changed: {$oldQuantity} → {$newQuantity}");
    }

    /**
     * Dispatch SyncProductInventoryJob to push updated quantity to all listed platforms.
     */
    protected static function dispatchPlatformSync(int $productId, string $reason): void
    {
        $product = Product::find($productId);

        if ($product) {
            SyncProductInventoryJob::dispatch($product, $reason);
        }
    }

    /**
     * Eloquent model events: cascade quantity sync and platform push on every change.
     *
     * Flow: Inventory saved/deleted → variant qty sync → product qty sync → platform sync job
     */
    protected static function booted(): void
    {
        static::saved(function (Inventory $inventory) {
            static::syncVariantQuantity($inventory->product_variant_id);
            if ($variant = ProductVariant::find($inventory->product_variant_id)) {
                static::syncProductQuantity($variant->product_id);
                static::dispatchPlatformSync($variant->product_id, 'inventory_changed');
            }
        });

        static::deleted(function (Inventory $inventory) {
            static::syncVariantQuantity($inventory->product_variant_id);
            if ($variant = ProductVariant::find($inventory->product_variant_id)) {
                static::syncProductQuantity($variant->product_id);
                static::dispatchPlatformSync($variant->product_id, 'inventory_deleted');
            }
        });
    }

    // ──────────────────────────────────────────────────────────────
    //  Activity Logging
    // ──────────────────────────────────────────────────────────────

    /**
     * Disable automatic activity logging from the LogsActivity trait.
     * We only log manually in adjustQuantity() for manual adjustment types,
     * to avoid noisy logs from automated sales and fulfillment operations.
     */
    public static function bootLogsActivity(): void
    {
        // Intentionally empty
    }

    protected function getActivityPrefix(): string
    {
        return 'inventory';
    }

    protected function getActivityMap(): array
    {
        return [
            'create' => 'inventory.create',
            'update' => 'inventory.update',
            'delete' => 'inventory.delete',
            'manual_adjust' => Activity::INVENTORY_QUANTITY_MANUAL_ADJUST,
        ];
    }

    protected function getLoggableAttributes(): array
    {
        return ['id', 'product_variant_id', 'warehouse_id', 'quantity'];
    }

    protected function getActivityIdentifier(): string
    {
        $sku = $this->variant?->sku ?? '';
        $warehouse = $this->warehouse?->name ?? '';

        return "{$sku} @ {$warehouse}";
    }
}
