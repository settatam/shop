<?php

namespace App\Models;

use App\Traits\BelongsToStore;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PaymentTerminal extends Model
{
    use BelongsToStore, HasFactory, SoftDeletes;

    // Gateway constants
    public const GATEWAY_SQUARE = 'square';

    public const GATEWAY_DEJAVOO = 'dejavoo';

    public const GATEWAYS = [
        self::GATEWAY_SQUARE,
        self::GATEWAY_DEJAVOO,
    ];

    // Status constants
    public const STATUS_PENDING = 'pending';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const STATUS_DISCONNECTED = 'disconnected';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_ACTIVE,
        self::STATUS_INACTIVE,
        self::STATUS_DISCONNECTED,
    ];

    protected $fillable = [
        'store_id',
        'warehouse_id',
        'name',
        'gateway',
        'device_id',
        'device_code',
        'location_id',
        'status',
        'settings',
        'capabilities',
        'last_seen_at',
        'paired_at',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'capabilities' => 'array',
            'last_seen_at' => 'datetime',
            'paired_at' => 'datetime',
        ];
    }

    public function checkouts(): HasMany
    {
        return $this->hasMany(TerminalCheckout::class, 'terminal_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    // Status scopes
    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeByGateway($query, string $gateway)
    {
        return $query->where('gateway', $gateway);
    }

    public function scopeAtWarehouse($query, int $warehouseId)
    {
        return $query->where('warehouse_id', $warehouseId);
    }

    public function scopeAtLocation($query, int $warehouseId)
    {
        return $this->scopeAtWarehouse($query, $warehouseId);
    }

    // Status helpers
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isInactive(): bool
    {
        return $this->status === self::STATUS_INACTIVE;
    }

    public function isDisconnected(): bool
    {
        return $this->status === self::STATUS_DISCONNECTED;
    }

    public function canProcessPayments(): bool
    {
        return $this->isActive();
    }

    // State transitions
    public function activate(): self
    {
        $this->update([
            'status' => self::STATUS_ACTIVE,
            'paired_at' => $this->paired_at ?? now(),
        ]);

        return $this;
    }

    public function deactivate(): self
    {
        $this->update(['status' => self::STATUS_INACTIVE]);

        return $this;
    }

    public function disconnect(): self
    {
        $this->update(['status' => self::STATUS_DISCONNECTED]);

        return $this;
    }

    public function updateLastSeen(): self
    {
        $this->update(['last_seen_at' => now()]);

        return $this;
    }

    // Gateway helpers
    public function isSquare(): bool
    {
        return $this->gateway === self::GATEWAY_SQUARE;
    }

    public function isDejavoo(): bool
    {
        return $this->gateway === self::GATEWAY_DEJAVOO;
    }

    public function getSetting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings, $key, $default);
    }

    public function setSetting(string $key, mixed $value): self
    {
        $settings = $this->settings ?? [];
        data_set($settings, $key, $value);
        $this->update(['settings' => $settings]);

        return $this;
    }

    public function hasCapability(string $capability): bool
    {
        return in_array($capability, $this->capabilities ?? []);
    }
}
