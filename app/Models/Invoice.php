<?php

namespace App\Models;

use App\Traits\BelongsToStore;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Invoice extends Model
{
    use BelongsToStore, HasFactory, SoftDeletes;

    // Status constants
    public const STATUS_DRAFT = 'draft';

    public const STATUS_PENDING = 'pending';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_PAID = 'paid';

    public const STATUS_OVERDUE = 'overdue';

    public const STATUS_VOID = 'void';

    public const STATUS_REFUNDED = 'refunded';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_PENDING,
        self::STATUS_PARTIAL,
        self::STATUS_PAID,
        self::STATUS_OVERDUE,
        self::STATUS_VOID,
        self::STATUS_REFUNDED,
    ];

    public static function getStatuses(): array
    {
        return [
            ['value' => self::STATUS_PENDING, 'label' => 'Pending'],
            ['value' => self::STATUS_PARTIAL, 'label' => 'Partial'],
            ['value' => self::STATUS_PAID, 'label' => 'Paid'],
            ['value' => self::STATUS_OVERDUE, 'label' => 'Overdue'],
            ['value' => self::STATUS_VOID, 'label' => 'Void'],
            ['value' => self::STATUS_REFUNDED, 'label' => 'Refunded'],
        ];
    }

    protected $fillable = [
        'store_id',
        'customer_id',
        'user_id',
        'invoice_number',
        'invoiceable_type',
        'invoiceable_id',
        'subtotal',
        'tax',
        'shipping',
        'discount',
        'total',
        'total_paid',
        'balance_due',
        'status',
        'currency',
        'due_date',
        'paid_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'tax' => 'decimal:2',
            'shipping' => 'decimal:2',
            'discount' => 'decimal:2',
            'total' => 'decimal:2',
            'total_paid' => 'decimal:2',
            'balance_due' => 'decimal:2',
            'due_date' => 'date',
            'paid_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Invoice $invoice) {
            if (empty($invoice->invoice_number)) {
                $invoice->invoice_number = static::generateInvoiceNumber();
            }
        });
    }

    public static function generateInvoiceNumber(): string
    {
        $prefix = 'INV';
        $date = now()->format('Ymd');
        $random = strtoupper(substr(md5(uniqid()), 0, 6));

        return "{$prefix}-{$date}-{$random}";
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function invoiceable(): MorphTo
    {
        return $this->morphTo();
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function terminalCheckouts(): HasMany
    {
        return $this->hasMany(TerminalCheckout::class);
    }

    // Status scopes
    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopePaid($query)
    {
        return $query->where('status', self::STATUS_PAID);
    }

    public function scopePartial($query)
    {
        return $query->where('status', self::STATUS_PARTIAL);
    }

    public function scopeOverdue($query)
    {
        return $query->where('status', self::STATUS_OVERDUE);
    }

    public function scopeUnpaid($query)
    {
        return $query->whereIn('status', [
            self::STATUS_PENDING,
            self::STATUS_PARTIAL,
            self::STATUS_OVERDUE,
        ]);
    }

    // Status helpers
    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isPartial(): bool
    {
        return $this->status === self::STATUS_PARTIAL;
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    public function isOverdue(): bool
    {
        return $this->status === self::STATUS_OVERDUE;
    }

    public function isVoid(): bool
    {
        return $this->status === self::STATUS_VOID;
    }

    public function isRefunded(): bool
    {
        return $this->status === self::STATUS_REFUNDED;
    }

    public function isFullyPaid(): bool
    {
        return $this->balance_due <= 0;
    }

    public function canAcceptPayment(): bool
    {
        return in_array($this->status, [
            self::STATUS_PENDING,
            self::STATUS_PARTIAL,
            self::STATUS_OVERDUE,
        ]);
    }

    public function canBeVoided(): bool
    {
        return in_array($this->status, [
            self::STATUS_DRAFT,
            self::STATUS_PENDING,
        ]) && $this->total_paid <= 0;
    }

    // Financial calculations
    public function recalculateTotals(): self
    {
        $totalPaid = $this->payments()
            ->where('status', Payment::STATUS_COMPLETED)
            ->sum('amount');

        $balanceDue = max(0, $this->total - $totalPaid);

        $this->update([
            'total_paid' => $totalPaid,
            'balance_due' => $balanceDue,
        ]);

        $this->updateStatus();

        return $this;
    }

    public function updateStatus(): self
    {
        if ($this->isVoid() || $this->isRefunded()) {
            return $this;
        }

        if ($this->balance_due <= 0) {
            $this->markAsPaid();
        } elseif ($this->total_paid > 0) {
            $this->update(['status' => self::STATUS_PARTIAL]);
        } elseif ($this->due_date && $this->due_date->isPast()) {
            $this->update(['status' => self::STATUS_OVERDUE]);
        }

        return $this;
    }

    // State transitions
    public function markAsPaid(): self
    {
        $this->update([
            'status' => self::STATUS_PAID,
            'paid_at' => now(),
        ]);

        return $this;
    }

    public function markAsVoid(): self
    {
        $this->update(['status' => self::STATUS_VOID]);

        return $this;
    }

    public function markAsRefunded(): self
    {
        $this->update(['status' => self::STATUS_REFUNDED]);

        return $this;
    }

    public function getInvoiceableTypeNameAttribute(): string
    {
        return match ($this->invoiceable_type) {
            Order::class => 'Order',
            Repair::class => 'Repair',
            Memo::class => 'Memo',
            default => 'Unknown',
        };
    }
}
