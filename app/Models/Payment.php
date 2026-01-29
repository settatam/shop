<?php

namespace App\Models;

use App\Traits\BelongsToStore;
use App\Traits\HasNotes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Payment extends Model
{
    use BelongsToStore, HasFactory, HasNotes, SoftDeletes;

    public const METHOD_CASH = 'cash';

    public const METHOD_CARD = 'card';

    public const METHOD_STORE_CREDIT = 'store_credit';

    public const METHOD_LAYAWAY = 'layaway';

    public const METHOD_EXTERNAL = 'external';

    public const METHOD_CHECK = 'check';

    public const METHOD_BANK_TRANSFER = 'bank_transfer';

    public const STATUS_PENDING = 'pending';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_REFUNDED = 'refunded';

    public const STATUS_PARTIALLY_REFUNDED = 'partially_refunded';

    protected $fillable = [
        'store_id',
        'payable_type',
        'payable_id',
        'order_id',
        'invoice_id',
        'memo_id',
        'terminal_checkout_id',
        'customer_id',
        'user_id',
        'payment_method',
        'status',
        'amount',
        'service_fee_value',
        'service_fee_unit',
        'service_fee_amount',
        'currency',
        'reference',
        'transaction_id',
        'gateway',
        'gateway_payment_id',
        'gateway_response',
        'notes',
        'metadata',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'service_fee_value' => 'decimal:2',
            'service_fee_amount' => 'decimal:2',
            'metadata' => 'array',
            'gateway_response' => 'array',
            'paid_at' => 'datetime',
        ];
    }

    /**
     * Get the total amount including service fee.
     */
    public function getTotalWithFeeAttribute(): float
    {
        return (float) $this->amount + (float) ($this->service_fee_amount ?? 0);
    }

    /**
     * Calculate and return the service fee amount based on value and unit.
     */
    public function calculateServiceFeeAmount(): float
    {
        if (! $this->service_fee_value || $this->service_fee_value <= 0) {
            return 0;
        }

        if ($this->service_fee_unit === 'percent') {
            return round($this->amount * $this->service_fee_value / 100, 2);
        }

        return (float) $this->service_fee_value;
    }

    /**
     * Get the payable model (Memo, Repair, Order, etc.).
     */
    public function payable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @deprecated Use payable() relationship instead
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @deprecated Use payable() relationship instead
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * @deprecated Use payable() relationship instead
     */
    public function memo(): BelongsTo
    {
        return $this->belongsTo(Memo::class);
    }

    public function terminalCheckout(): BelongsTo
    {
        return $this->belongsTo(TerminalCheckout::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function markAsCompleted(): self
    {
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'paid_at' => now(),
        ]);

        return $this;
    }

    public function markAsFailed(): self
    {
        $this->update(['status' => self::STATUS_FAILED]);

        return $this;
    }
}
