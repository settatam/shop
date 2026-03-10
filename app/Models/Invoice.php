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
        'service_fee',
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
            'service_fee' => 'decimal:2',
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
            // Set temporary value to satisfy NOT NULL constraint if needed
            if (empty($invoice->invoice_number)) {
                $invoice->invoice_number = 'INV-TEMP';
            }
        });

        static::created(function (Invoice $invoice) {
            if ($invoice->invoice_number !== 'INV-TEMP') {
                return;
            }

            // For order invoices, use the order's order_id so they share the same number
            if ($invoice->invoiceable_type === Order::class && $invoice->invoiceable_id) {
                $order = Order::find($invoice->invoiceable_id);
                if ($order && $order->order_id) {
                    // Only use the order_id if no other invoice already has it
                    $exists = Invoice::where('invoice_number', $order->order_id)
                        ->where('id', '!=', $invoice->id)
                        ->exists();

                    if (! $exists) {
                        $invoice->invoice_number = $order->order_id;
                        $invoice->saveQuietly();

                        return;
                    }
                }
            }

            // For other types, generate from store prefix/suffix
            $store = $invoice->store;
            [$prefix, $suffix] = static::getPrefixAndSuffixForInvoiceableType($invoice->invoiceable_type, $store);
            $invoice->invoice_number = "{$prefix}{$invoice->id}{$suffix}";
            $invoice->saveQuietly();
        });
    }

    /**
     * Get the appropriate prefix and suffix based on the invoiceable type and store settings.
     *
     * @return array{0: string, 1: string}
     */
    protected static function getPrefixAndSuffixForInvoiceableType(?string $invoiceableType, ?Store $store): array
    {
        if (! $store) {
            return ['', ''];
        }

        return match ($invoiceableType) {
            Order::class => [$store->order_id_prefix ?? '', $store->order_id_suffix ?? ''],
            Repair::class => [$store->repair_id_prefix ?? '', $store->repair_id_suffix ?? ''],
            Memo::class => [$store->memo_id_prefix ?? '', $store->memo_id_suffix ?? ''],
            Transaction::class => [$store->buy_id_prefix ?? '', $store->buy_id_suffix ?? ''],
            default => ['', ''],
        };
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
