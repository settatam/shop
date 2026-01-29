<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class PlatformOrder extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'store_marketplace_id',
        'order_id',
        'external_order_id',
        'external_order_number',
        'status',
        'fulfillment_status',
        'payment_status',
        'total',
        'subtotal',
        'shipping_cost',
        'tax',
        'discount',
        'currency',
        'customer_data',
        'shipping_address',
        'billing_address',
        'line_items',
        'platform_data',
        'ordered_at',
        'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'total' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'shipping_cost' => 'decimal:2',
            'tax' => 'decimal:2',
            'discount' => 'decimal:2',
            'customer_data' => 'array',
            'shipping_address' => 'array',
            'billing_address' => 'array',
            'line_items' => 'array',
            'platform_data' => 'array',
            'ordered_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    public function marketplace(): BelongsTo
    {
        return $this->belongsTo(StoreMarketplace::class, 'store_marketplace_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function isImported(): bool
    {
        return $this->order_id !== null;
    }

    public function isFulfilled(): bool
    {
        return $this->fulfillment_status === 'fulfilled';
    }

    public function isPaid(): bool
    {
        return $this->payment_status === 'paid';
    }
}
