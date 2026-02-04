<?php

namespace App\Models;

use App\Traits\BelongsToStore;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class TerminalCheckout extends Model
{
    use BelongsToStore, HasFactory;

    // Status constants
    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_TIMEOUT = 'timeout';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_PROCESSING,
        self::STATUS_COMPLETED,
        self::STATUS_FAILED,
        self::STATUS_CANCELLED,
        self::STATUS_TIMEOUT,
    ];

    public const DEFAULT_TIMEOUT_SECONDS = 300; // 5 minutes

    protected $fillable = [
        'store_id',
        'invoice_id',
        'payable_type',
        'payable_id',
        'terminal_id',
        'user_id',
        'payment_id',
        'checkout_id',
        'amount',
        'currency',
        'status',
        'external_payment_id',
        'error_message',
        'gateway_response',
        'metadata',
        'timeout_seconds',
        'expires_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'gateway_response' => 'array',
            'metadata' => 'array',
            'timeout_seconds' => 'integer',
            'expires_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (TerminalCheckout $checkout) {
            if (empty($checkout->expires_at)) {
                $timeout = $checkout->timeout_seconds ?? self::DEFAULT_TIMEOUT_SECONDS;
                $checkout->expires_at = now()->addSeconds($timeout);
            }
        });
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * Get the owning payable model (Order, Repair, Memo, Layaway).
     */
    public function payable(): MorphTo
    {
        return $this->morphTo();
    }

    public function terminal(): BelongsTo
    {
        return $this->belongsTo(PaymentTerminal::class, 'terminal_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    // Status scopes
    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeProcessing($query)
    {
        return $query->where('status', self::STATUS_PROCESSING);
    }

    public function scopeActive($query)
    {
        return $query->whereIn('status', [
            self::STATUS_PENDING,
            self::STATUS_PROCESSING,
        ]);
    }

    public function scopeExpired($query)
    {
        return $query->active()->where('expires_at', '<', now());
    }

    // Status helpers
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isProcessing(): bool
    {
        return $this->status === self::STATUS_PROCESSING;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function isTimedOut(): bool
    {
        return $this->status === self::STATUS_TIMEOUT;
    }

    public function isActive(): bool
    {
        return in_array($this->status, [
            self::STATUS_PENDING,
            self::STATUS_PROCESSING,
        ]);
    }

    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    public function canBeCancelled(): bool
    {
        return $this->isActive();
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [
            self::STATUS_COMPLETED,
            self::STATUS_FAILED,
            self::STATUS_CANCELLED,
            self::STATUS_TIMEOUT,
        ]);
    }

    // State transitions
    public function markAsProcessing(): self
    {
        $this->update(['status' => self::STATUS_PROCESSING]);

        return $this;
    }

    public function markAsCompleted(string $externalPaymentId, ?array $gatewayResponse = null): self
    {
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'external_payment_id' => $externalPaymentId,
            'gateway_response' => $gatewayResponse,
            'completed_at' => now(),
        ]);

        return $this;
    }

    public function markAsFailed(string $errorMessage, ?array $gatewayResponse = null): self
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'error_message' => $errorMessage,
            'gateway_response' => $gatewayResponse,
        ]);

        return $this;
    }

    public function markAsTimeout(): self
    {
        $this->update([
            'status' => self::STATUS_TIMEOUT,
            'error_message' => 'Checkout timed out waiting for customer payment.',
        ]);

        return $this;
    }

    public function cancel(): self
    {
        $this->update(['status' => self::STATUS_CANCELLED]);

        return $this;
    }

    // Helper methods
    public function getSecondsRemaining(): int
    {
        if (! $this->expires_at) {
            return 0;
        }

        return max(0, now()->diffInSeconds($this->expires_at, false));
    }

    public function getGateway(): string
    {
        return $this->terminal->gateway ?? '';
    }
}
