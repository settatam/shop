<?php

namespace App\Models;

use App\Traits\BelongsToStore;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductReturn extends Model
{
    use BelongsToStore, HasFactory;

    protected $table = 'returns';

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    public const TYPE_RETURN = 'return';

    public const TYPE_EXCHANGE = 'exchange';

    public const REFUND_ORIGINAL = 'original_payment';

    public const REFUND_STORE_CREDIT = 'store_credit';

    public const REFUND_CASH = 'cash';

    public const REFUND_CARD = 'card';

    public const SYNC_STATUS_PENDING = 'pending';

    public const SYNC_STATUS_SYNCED = 'synced';

    public const SYNC_STATUS_FAILED = 'failed';

    protected $fillable = [
        'store_id',
        'order_id',
        'customer_id',
        'return_policy_id',
        'processed_by',
        'return_number',
        'status',
        'type',
        'subtotal',
        'restocking_fee',
        'refund_amount',
        'refund_method',
        'store_credit_id',
        'reason',
        'customer_notes',
        'internal_notes',
        'external_return_id',
        'source_platform',
        'store_marketplace_id',
        'synced_at',
        'sync_status',
        'requested_at',
        'approved_at',
        'received_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'restocking_fee' => 'decimal:2',
            'refund_amount' => 'decimal:2',
            'synced_at' => 'datetime',
            'requested_at' => 'datetime',
            'approved_at' => 'datetime',
            'received_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function ($return) {
            if (empty($return->return_number)) {
                $return->return_number = static::generateReturnNumber($return->store_id);
            }

            if (empty($return->status)) {
                $return->status = self::STATUS_PENDING;
            }

            if (empty($return->type)) {
                $return->type = self::TYPE_RETURN;
            }

            if (empty($return->requested_at)) {
                $return->requested_at = now();
            }
        });
    }

    public static function generateReturnNumber(?int $storeId = null): string
    {
        $prefix = 'RET';
        $date = now()->format('Ymd');
        $random = strtoupper(substr(md5(uniqid()), 0, 6));

        return "{$prefix}-{$date}-{$random}";
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function returnPolicy(): BelongsTo
    {
        return $this->belongsTo(ReturnPolicy::class);
    }

    public function processedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    public function marketplace(): BelongsTo
    {
        return $this->belongsTo(StoreMarketplace::class, 'store_marketplace_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ReturnItem::class, 'return_id');
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeApproved($query)
    {
        return $query->where('status', self::STATUS_APPROVED);
    }

    public function scopeProcessing($query)
    {
        return $query->where('status', self::STATUS_PROCESSING);
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    public function scopeRejected($query)
    {
        return $query->where('status', self::STATUS_REJECTED);
    }

    public function scopeCancelled($query)
    {
        return $query->where('status', self::STATUS_CANCELLED);
    }

    public function scopeReturns($query)
    {
        return $query->where('type', self::TYPE_RETURN);
    }

    public function scopeExchanges($query)
    {
        return $query->where('type', self::TYPE_EXCHANGE);
    }

    public function scopeFromPlatform($query, string $platform)
    {
        return $query->where('source_platform', $platform);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isProcessing(): bool
    {
        return $this->status === self::STATUS_PROCESSING;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function isExchange(): bool
    {
        return $this->type === self::TYPE_EXCHANGE;
    }

    public function isFromExternalPlatform(): bool
    {
        return ! empty($this->source_platform);
    }

    public function canBeApproved(): bool
    {
        return $this->isPending();
    }

    public function canBeProcessed(): bool
    {
        return $this->isApproved();
    }

    public function canBeRejected(): bool
    {
        return $this->isPending() || $this->isApproved();
    }

    public function canBeCancelled(): bool
    {
        return $this->isPending() || $this->isApproved();
    }

    public function approve(?int $userId = null): self
    {
        $this->update([
            'status' => self::STATUS_APPROVED,
            'approved_at' => now(),
            'processed_by' => $userId,
        ]);

        return $this;
    }

    public function reject(string $reason, ?int $userId = null): self
    {
        $this->update([
            'status' => self::STATUS_REJECTED,
            'internal_notes' => $reason,
            'processed_by' => $userId,
        ]);

        return $this;
    }

    public function markAsProcessing(): self
    {
        $this->update([
            'status' => self::STATUS_PROCESSING,
        ]);

        return $this;
    }

    public function complete(string $refundMethod, float $refundAmount): self
    {
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'refund_method' => $refundMethod,
            'refund_amount' => $refundAmount,
            'completed_at' => now(),
        ]);

        return $this;
    }

    public function cancel(): self
    {
        $this->update([
            'status' => self::STATUS_CANCELLED,
        ]);

        return $this;
    }

    public function markAsReceived(): self
    {
        $this->update([
            'received_at' => now(),
        ]);

        return $this;
    }

    public function markAsSynced(): self
    {
        $this->update([
            'sync_status' => self::SYNC_STATUS_SYNCED,
            'synced_at' => now(),
        ]);

        return $this;
    }

    public function markSyncFailed(): self
    {
        $this->update([
            'sync_status' => self::SYNC_STATUS_FAILED,
        ]);

        return $this;
    }

    public function calculateTotals(): self
    {
        $subtotal = $this->items->sum('line_total');
        $restockingFee = 0;

        if ($this->returnPolicy) {
            $restockingFee = $this->returnPolicy->calculateRestockingFee($subtotal);
        }

        $refundAmount = $subtotal - $restockingFee;

        $this->update([
            'subtotal' => $subtotal,
            'restocking_fee' => $restockingFee,
            'refund_amount' => max(0, $refundAmount),
        ]);

        return $this;
    }

    public function getItemCountAttribute(): int
    {
        return $this->items->sum('quantity');
    }
}
