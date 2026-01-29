<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryTransferItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'inventory_transfer_id',
        'product_variant_id',
        'quantity_requested',
        'quantity_shipped',
        'quantity_received',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'quantity_requested' => 'integer',
            'quantity_shipped' => 'integer',
            'quantity_received' => 'integer',
        ];
    }

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(InventoryTransfer::class, 'inventory_transfer_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function isFullyShipped(): bool
    {
        return $this->quantity_shipped >= $this->quantity_requested;
    }

    public function isFullyReceived(): bool
    {
        return $this->quantity_received >= $this->quantity_shipped;
    }

    public function isPartiallyReceived(): bool
    {
        return $this->quantity_received > 0 && $this->quantity_received < $this->quantity_shipped;
    }

    public function getShortageAttribute(): int
    {
        return max(0, $this->quantity_shipped - $this->quantity_received);
    }
}
