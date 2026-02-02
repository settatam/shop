<?php

namespace App\Models;

use App\Contracts\Payable;
use App\Traits\BelongsToStore;
use App\Traits\HasNotes;
use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Layaway extends Model implements Payable
{
    use BelongsToStore, HasFactory, HasNotes, LogsActivity, SoftDeletes;

    // Status constants
    public const STATUS_PENDING = 'pending';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_DEFAULTED = 'defaulted';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_ACTIVE,
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
        self::STATUS_DEFAULTED,
    ];

    // Payment types
    public const PAYMENT_TYPE_FLEXIBLE = 'flexible';

    public const PAYMENT_TYPE_SCHEDULED = 'scheduled';

    public const PAYMENT_TYPES = [
        self::PAYMENT_TYPE_FLEXIBLE,
        self::PAYMENT_TYPE_SCHEDULED,
    ];

    // Term options (in days)
    public const TERM_30_DAYS = 30;

    public const TERM_60_DAYS = 60;

    public const TERM_90_DAYS = 90;

    public const TERM_120_DAYS = 120;

    public const TERM_OPTIONS = [
        self::TERM_30_DAYS,
        self::TERM_60_DAYS,
        self::TERM_90_DAYS,
        self::TERM_120_DAYS,
    ];

    protected $fillable = [
        'store_id',
        'warehouse_id',
        'customer_id',
        'user_id',
        'order_id',
        'layaway_number',
        'status',
        'payment_type',
        'term_days',
        'minimum_deposit_percent',
        'cancellation_fee_percent',
        'subtotal',
        'tax_rate',
        'tax_amount',
        'total',
        'deposit_amount',
        'total_paid',
        'balance_due',
        'start_date',
        'due_date',
        'completed_at',
        'cancelled_at',
        'admin_notes',
    ];

    protected function casts(): array
    {
        return [
            'term_days' => 'integer',
            'minimum_deposit_percent' => 'decimal:2',
            'cancellation_fee_percent' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'tax_rate' => 'decimal:4',
            'tax_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'deposit_amount' => 'decimal:2',
            'total_paid' => 'decimal:2',
            'balance_due' => 'decimal:2',
            'start_date' => 'date',
            'due_date' => 'date',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Layaway $layaway) {
            if (empty($layaway->layaway_number)) {
                $layaway->layaway_number = 'LAY-TEMP';
            }
        });

        static::created(function (Layaway $layaway) {
            if ($layaway->layaway_number === 'LAY-TEMP') {
                $year = now()->format('Y');
                $sequence = str_pad($layaway->id, 4, '0', STR_PAD_LEFT);
                $layaway->layaway_number = "LAY-{$year}-{$sequence}";
                $layaway->saveQuietly();
            }
        });
    }

    // Relationships

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(LayawayItem::class);
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(LayawaySchedule::class);
    }

    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'payable');
    }

    // Scopes

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    public function scopeCancelled($query)
    {
        return $query->where('status', self::STATUS_CANCELLED);
    }

    public function scopeDefaulted($query)
    {
        return $query->where('status', self::STATUS_DEFAULTED);
    }

    public function scopeOverdue($query)
    {
        return $query->where('status', self::STATUS_ACTIVE)
            ->where('due_date', '<', now());
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

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function isDefaulted(): bool
    {
        return $this->status === self::STATUS_DEFAULTED;
    }

    public function isOverdue(): bool
    {
        return $this->isActive() && $this->due_date && $this->due_date->isPast();
    }

    public function isFlexible(): bool
    {
        return $this->payment_type === self::PAYMENT_TYPE_FLEXIBLE;
    }

    public function isScheduled(): bool
    {
        return $this->payment_type === self::PAYMENT_TYPE_SCHEDULED;
    }

    // State transitions

    public function activate(): self
    {
        if (! $this->isPending()) {
            throw new \InvalidArgumentException('Can only activate pending layaways.');
        }

        $this->update([
            'status' => self::STATUS_ACTIVE,
            'start_date' => $this->start_date ?? now(),
        ]);

        return $this;
    }

    public function complete(): self
    {
        if (! $this->isActive()) {
            throw new \InvalidArgumentException('Can only complete active layaways.');
        }

        if (! $this->isFullyPaid()) {
            throw new \InvalidArgumentException('Cannot complete layaway with outstanding balance.');
        }

        $this->update([
            'status' => self::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);

        return $this;
    }

    public function cancel(?float $restockingFee = null): self
    {
        if ($this->isCompleted()) {
            throw new \InvalidArgumentException('Cannot cancel a completed layaway.');
        }

        // Release reserved items
        $this->items()->update(['is_reserved' => false]);

        $this->update([
            'status' => self::STATUS_CANCELLED,
            'cancelled_at' => now(),
        ]);

        return $this;
    }

    public function markAsDefaulted(): self
    {
        if (! $this->isActive()) {
            throw new \InvalidArgumentException('Can only default active layaways.');
        }

        $this->update(['status' => self::STATUS_DEFAULTED]);

        return $this;
    }

    // Calculation helpers

    public function calculateTotals(): self
    {
        $subtotal = $this->items->sum('line_total');
        $taxAmount = $this->tax_rate > 0 ? $subtotal * $this->tax_rate : 0;
        $total = $subtotal + $taxAmount;
        $balanceDue = max(0, $total - $this->total_paid);

        $this->update([
            'subtotal' => $subtotal,
            'tax_amount' => $taxAmount,
            'total' => $total,
            'balance_due' => $balanceDue,
        ]);

        return $this;
    }

    public function getMinimumDepositAttribute(): float
    {
        return (float) $this->total * ($this->minimum_deposit_percent / 100);
    }

    public function getCancellationFeeAttribute(): float
    {
        return (float) $this->total_paid * ($this->cancellation_fee_percent / 100);
    }

    public function getProgressPercentage(): float
    {
        if ($this->total <= 0) {
            return 0;
        }

        return min(100, ($this->total_paid / $this->total) * 100);
    }

    public function getDaysRemainingAttribute(): int
    {
        if (! $this->due_date) {
            return 0;
        }

        return max(0, now()->diffInDays($this->due_date, false));
    }

    public function getNextScheduledPayment(): ?LayawaySchedule
    {
        return $this->schedules()
            ->where('status', LayawaySchedule::STATUS_PENDING)
            ->orderBy('due_date')
            ->first();
    }

    // Payable interface implementation

    public function getStoreId(): int
    {
        return (int) $this->store_id;
    }

    public function getSubtotal(): float
    {
        return (float) $this->subtotal;
    }

    public function getGrandTotal(): float
    {
        return (float) $this->total;
    }

    public function getTotalPaid(): float
    {
        return (float) $this->total_paid;
    }

    public function getBalanceDue(): float
    {
        return (float) $this->balance_due;
    }

    public function canReceivePayment(): bool
    {
        return in_array($this->status, [
            self::STATUS_PENDING,
            self::STATUS_ACTIVE,
        ]) && ! $this->isFullyPaid();
    }

    public function isFullyPaid(): bool
    {
        return $this->balance_due <= 0 && $this->total > 0;
    }

    public function hasPayments(): bool
    {
        return $this->total_paid > 0;
    }

    public function recordPayment(float $amount): void
    {
        $newTotalPaid = (float) $this->total_paid + $amount;
        $balanceDue = max(0, (float) $this->total - $newTotalPaid);

        $this->update([
            'total_paid' => $newTotalPaid,
            'balance_due' => $balanceDue,
        ]);

        // If this is the first payment (deposit), set deposit amount and activate the layaway
        if ($this->isPending() && $newTotalPaid >= $this->minimum_deposit) {
            $this->update(['deposit_amount' => $newTotalPaid]);
            $this->activate();
        }
    }

    public function getDisplayIdentifier(): string
    {
        return $this->layaway_number;
    }

    public static function getPayableTypeName(): string
    {
        return 'layaway';
    }

    public function onPaymentComplete(): void
    {
        $this->complete();
    }

    public function getPaymentAdjustments(): array
    {
        return [
            'discount_value' => 0,
            'discount_unit' => 'fixed',
            'discount_reason' => null,
            'service_fee_value' => 0,
            'service_fee_unit' => 'fixed',
            'service_fee_reason' => null,
            'charge_taxes' => $this->tax_rate > 0,
            'tax_rate' => (float) $this->tax_rate,
            'tax_type' => 'percent',
            'shipping_cost' => 0,
        ];
    }

    public function updatePaymentAdjustments(array $adjustments): void
    {
        $this->update([
            'tax_rate' => $adjustments['tax_rate'] ?? $this->tax_rate,
        ]);

        $this->calculateTotals();
    }

    public function updateCalculatedTotals(array $summary): void
    {
        $this->update([
            'tax_amount' => $summary['tax_amount'] ?? $this->tax_amount,
            'total' => $summary['grand_total'] ?? $this->total,
            'balance_due' => $summary['balance_due'] ?? $this->balance_due,
        ]);
    }

    // Activity logging

    protected function getActivityPrefix(): string
    {
        return 'layaways';
    }

    protected function getLoggableAttributes(): array
    {
        return ['id', 'layaway_number', 'status', 'customer_id', 'total', 'balance_due'];
    }

    protected function getActivityIdentifier(): string
    {
        return $this->layaway_number ?? "#{$this->id}";
    }
}
