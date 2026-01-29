<?php

namespace App\Models;

use App\Traits\BelongsToStore;
use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Warehouse extends Model
{
    use BelongsToStore, HasFactory, LogsActivity, SoftDeletes;

    protected $fillable = [
        'store_id',
        'name',
        'code',
        'description',
        'address_line1',
        'address_line2',
        'city',
        'state',
        'postal_code',
        'country',
        'latitude',
        'longitude',
        'phone',
        'email',
        'contact_name',
        'is_default',
        'is_active',
        'accepts_transfers',
        'fulfills_orders',
        'priority',
        'tax_rate',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:8',
            'longitude' => 'decimal:8',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'accepts_transfers' => 'boolean',
            'fulfills_orders' => 'boolean',
            'priority' => 'integer',
            'tax_rate' => 'decimal:4',
        ];
    }

    public function inventories(): HasMany
    {
        return $this->hasMany(Inventory::class);
    }

    public function incomingTransfers(): HasMany
    {
        return $this->hasMany(InventoryTransfer::class, 'to_warehouse_id');
    }

    public function outgoingTransfers(): HasMany
    {
        return $this->hasMany(InventoryTransfer::class, 'from_warehouse_id');
    }

    public function terminals(): HasMany
    {
        return $this->hasMany(PaymentTerminal::class);
    }

    public function activeTerminals(): HasMany
    {
        return $this->terminals()->where('status', PaymentTerminal::STATUS_ACTIVE);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
    }

    public function scopeFulfillable($query)
    {
        return $query->where('fulfills_orders', true)->where('is_active', true);
    }

    /**
     * Scope for retail locations (warehouses that can process payments).
     * Alias for fulfillable - makes the intent clearer in code.
     */
    public function scopeRetailLocations($query)
    {
        return $this->scopeFulfillable($query);
    }

    /**
     * Check if this warehouse is a retail location.
     */
    public function isRetailLocation(): bool
    {
        return $this->fulfills_orders && $this->is_active;
    }

    /**
     * Check if this location has any active payment terminals.
     */
    public function hasActiveTerminals(): bool
    {
        return $this->activeTerminals()->exists();
    }

    public function getFullAddressAttribute(): string
    {
        $parts = array_filter([
            $this->address_line1,
            $this->address_line2,
            $this->city,
            $this->state,
            $this->postal_code,
            $this->country,
        ]);

        return implode(', ', $parts);
    }

    public function getTotalQuantityAttribute(): int
    {
        return $this->inventories->sum('quantity');
    }

    public function getTotalValueAttribute(): float
    {
        return $this->inventories->sum(function ($inventory) {
            return $inventory->quantity * ($inventory->unit_cost ?? 0);
        });
    }

    public function makeDefault(): void
    {
        // Remove default from all other warehouses in this store
        static::where('store_id', $this->store_id)
            ->where('id', '!=', $this->id)
            ->update(['is_default' => false]);

        $this->update(['is_default' => true]);
    }

    protected function getActivityPrefix(): string
    {
        return 'warehouses';
    }

    protected function getLoggableAttributes(): array
    {
        return ['id', 'name', 'code', 'is_default', 'is_active'];
    }

    protected function getActivityIdentifier(): string
    {
        return $this->name ?? "#{$this->id}";
    }
}
