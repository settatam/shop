<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LayawaySchedule extends Model
{
    use HasFactory;

    // Status constants
    public const STATUS_PENDING = 'pending';

    public const STATUS_PAID = 'paid';

    public const STATUS_OVERDUE = 'overdue';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_PAID,
        self::STATUS_OVERDUE,
    ];

    // Frequency constants
    public const FREQUENCY_WEEKLY = 'weekly';

    public const FREQUENCY_BIWEEKLY = 'biweekly';

    public const FREQUENCY_MONTHLY = 'monthly';

    public const FREQUENCIES = [
        self::FREQUENCY_WEEKLY,
        self::FREQUENCY_BIWEEKLY,
        self::FREQUENCY_MONTHLY,
    ];

    protected $fillable = [
        'layaway_id',
        'installment_number',
        'due_date',
        'amount_due',
        'amount_paid',
        'status',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'installment_number' => 'integer',
            'due_date' => 'date',
            'amount_due' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'paid_at' => 'datetime',
        ];
    }

    public function layaway(): BelongsTo
    {
        return $this->belongsTo(Layaway::class);
    }

    // Scopes

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopePaid($query)
    {
        return $query->where('status', self::STATUS_PAID);
    }

    public function scopeOverdue($query)
    {
        return $query->where('status', self::STATUS_OVERDUE);
    }

    public function scopeDueSoon($query, int $days = 7)
    {
        return $query->where('status', self::STATUS_PENDING)
            ->whereBetween('due_date', [now(), now()->addDays($days)]);
    }

    // Status helpers

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    public function isOverdue(): bool
    {
        return $this->status === self::STATUS_OVERDUE ||
            ($this->isPending() && $this->due_date && $this->due_date->isPast());
    }

    public function isFullyPaid(): bool
    {
        return $this->amount_paid >= $this->amount_due;
    }

    // Balance helpers

    public function getRemainingAmountAttribute(): float
    {
        return max(0, (float) $this->amount_due - (float) $this->amount_paid);
    }

    public function getDaysUntilDueAttribute(): int
    {
        if (! $this->due_date) {
            return 0;
        }

        return (int) now()->diffInDays($this->due_date, false);
    }

    // Actions

    public function recordPayment(float $amount): self
    {
        $newAmountPaid = (float) $this->amount_paid + $amount;

        $updates = [
            'amount_paid' => $newAmountPaid,
        ];

        if ($newAmountPaid >= $this->amount_due) {
            $updates['status'] = self::STATUS_PAID;
            $updates['paid_at'] = now();
        }

        $this->update($updates);

        return $this;
    }

    public function markOverdue(): self
    {
        if ($this->isPending() && ! $this->isFullyPaid()) {
            $this->update(['status' => self::STATUS_OVERDUE]);
        }

        return $this;
    }

    /**
     * Get the number of days to add for a given frequency.
     */
    public static function getFrequencyDays(string $frequency): int
    {
        return match ($frequency) {
            self::FREQUENCY_WEEKLY => 7,
            self::FREQUENCY_BIWEEKLY => 14,
            self::FREQUENCY_MONTHLY => 30,
            default => 30,
        };
    }
}
