<?php

namespace App\Models;

use App\Contracts\Payable;
use App\Services\MemoPaymentService;
use App\Traits\BelongsToStore;
use App\Traits\HasCustomStatuses;
use App\Traits\HasNotes;
use App\Traits\HasTags;
use App\Traits\LogsActivity;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Scout\Searchable;

class Memo extends Model implements Payable
{
    use BelongsToStore, HasCustomStatuses, HasFactory, HasNotes, HasTags, LogsActivity, Searchable, SoftDeletes;

    // Status constants
    public const STATUS_PENDING = 'pending';

    public const STATUS_SENT_TO_VENDOR = 'sent_to_vendor';

    public const STATUS_VENDOR_RECEIVED = 'vendor_received';

    public const STATUS_VENDOR_RETURNED = 'vendor_returned';

    public const STATUS_PAYMENT_RECEIVED = 'payment_received';

    public const STATUS_ARCHIVED = 'archived';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_SENT_TO_VENDOR,
        self::STATUS_VENDOR_RECEIVED,
        self::STATUS_VENDOR_RETURNED,
        self::STATUS_PAYMENT_RECEIVED,
        self::STATUS_ARCHIVED,
        self::STATUS_CANCELLED,
    ];

    // Payment terms
    public const TENURE_7_DAYS = 7;

    public const TENURE_14_DAYS = 14;

    public const TENURE_30_DAYS = 30;

    public const TENURE_60_DAYS = 60;

    public const PAYMENT_TERMS = [
        self::TENURE_7_DAYS,
        self::TENURE_14_DAYS,
        self::TENURE_30_DAYS,
        self::TENURE_60_DAYS,
    ];

    public const INVOICE_PREFIX = 'MEMO';

    protected $fillable = [
        'store_id',
        'warehouse_id',
        'vendor_id',
        'user_id',
        'order_id',
        'memo_number',
        'status',
        'status_id',
        'tenure',
        'subtotal',
        'tax',
        'tax_rate',
        'charge_taxes',
        'shipping_cost',
        'total',
        'description',
        'duration',
        // Payment fields
        'discount_value',
        'discount_unit',
        'discount_reason',
        'discount_amount',
        'service_fee_value',
        'service_fee_unit',
        'service_fee_reason',
        'service_fee_amount',
        'tax_type',
        'tax_amount',
        'grand_total',
        'total_paid',
        'balance_due',
        'date_sent_to_vendor',
        'date_vendor_received',
    ];

    protected function casts(): array
    {
        return [
            'tenure' => 'integer',
            'subtotal' => 'decimal:2',
            'tax' => 'decimal:2',
            'tax_rate' => 'decimal:4',
            'charge_taxes' => 'boolean',
            'shipping_cost' => 'decimal:2',
            'total' => 'decimal:2',
            'duration' => 'integer',
            // Payment field casts
            'discount_value' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'service_fee_value' => 'decimal:2',
            'service_fee_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'total_paid' => 'decimal:2',
            'balance_due' => 'decimal:2',
            'date_sent_to_vendor' => 'datetime',
            'date_vendor_received' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Memo $memo) {
            // Set temporary value to satisfy NOT NULL constraint
            if (empty($memo->memo_number)) {
                $memo->memo_number = 'MEM-TEMP';
            }
        });

        static::created(function (Memo $memo) {
            // Update with actual ID-based number
            if ($memo->memo_number === 'MEM-TEMP') {
                $memo->memo_number = "MEM-{$memo->id}";
                $memo->saveQuietly();
            }
        });
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(MemoItem::class);
    }

    public function invoice(): MorphOne
    {
        return $this->morphOne(Invoice::class, 'invoiceable');
    }

    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'payable');
    }

    /**
     * Legacy relationship for backward compatibility.
     *
     * @deprecated Use payments() instead
     */
    public function legacyPayments(): HasMany
    {
        return $this->hasMany(Payment::class, 'memo_id');
    }

    // Payable interface implementation
    public function getStoreId(): int
    {
        return (int) $this->store_id;
    }

    public function getSubtotal(): float
    {
        return (float) $this->total;
    }

    public function getGrandTotal(): float
    {
        return (float) ($this->grand_total ?? $this->total);
    }

    public function getTotalPaid(): float
    {
        return (float) ($this->total_paid ?? 0);
    }

    public function getBalanceDue(): float
    {
        return (float) ($this->balance_due ?? $this->getGrandTotal() - $this->getTotalPaid());
    }

    public function getDisplayIdentifier(): string
    {
        return $this->memo_number;
    }

    public static function getPayableTypeName(): string
    {
        return 'memo';
    }

    public function onPaymentComplete(): void
    {
        app(MemoPaymentService::class)->completeMemoPayment($this);
    }

    public function getPaymentAdjustments(): array
    {
        return [
            'discount_value' => (float) ($this->discount_value ?? 0),
            'discount_unit' => $this->discount_unit ?? 'fixed',
            'discount_reason' => $this->discount_reason,
            'service_fee_value' => (float) ($this->service_fee_value ?? 0),
            'service_fee_unit' => $this->service_fee_unit ?? 'fixed',
            'service_fee_reason' => $this->service_fee_reason,
            'charge_taxes' => (bool) ($this->charge_taxes ?? false),
            'tax_rate' => (float) ($this->tax_rate ?? 0),
            'tax_type' => $this->tax_type ?? 'percent',
            'shipping_cost' => (float) ($this->shipping_cost ?? 0),
        ];
    }

    public function updatePaymentAdjustments(array $adjustments): void
    {
        $this->update([
            'discount_value' => $adjustments['discount_value'] ?? $this->discount_value,
            'discount_unit' => $adjustments['discount_unit'] ?? $this->discount_unit,
            'discount_reason' => $adjustments['discount_reason'] ?? $this->discount_reason,
            'service_fee_value' => $adjustments['service_fee_value'] ?? $this->service_fee_value,
            'service_fee_unit' => $adjustments['service_fee_unit'] ?? $this->service_fee_unit,
            'service_fee_reason' => $adjustments['service_fee_reason'] ?? $this->service_fee_reason,
            'charge_taxes' => $adjustments['charge_taxes'] ?? $this->charge_taxes,
            'tax_rate' => $adjustments['tax_rate'] ?? $this->tax_rate,
            'tax_type' => $adjustments['tax_type'] ?? $this->tax_type,
            'shipping_cost' => $adjustments['shipping_cost'] ?? $this->shipping_cost,
        ]);
    }

    public function updateCalculatedTotals(array $summary): void
    {
        $this->update([
            'discount_amount' => $summary['discount_amount'],
            'service_fee_amount' => $summary['service_fee_amount'],
            'tax_amount' => $summary['tax_amount'],
            'grand_total' => $summary['grand_total'],
            'balance_due' => $summary['balance_due'],
        ]);
    }

    // Status scopes
    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeSentToVendor($query)
    {
        return $query->where('status', self::STATUS_SENT_TO_VENDOR);
    }

    public function scopeVendorReceived($query)
    {
        return $query->where('status', self::STATUS_VENDOR_RECEIVED);
    }

    public function scopeOpen($query)
    {
        return $query->whereIn('status', [
            self::STATUS_PENDING,
            self::STATUS_SENT_TO_VENDOR,
            self::STATUS_VENDOR_RECEIVED,
        ]);
    }

    public function scopeCancelled($query)
    {
        return $query->where('status', self::STATUS_CANCELLED);
    }

    // Status helpers
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isSentToVendor(): bool
    {
        return $this->status === self::STATUS_SENT_TO_VENDOR;
    }

    public function isVendorReceived(): bool
    {
        return $this->status === self::STATUS_VENDOR_RECEIVED;
    }

    public function isVendorReturned(): bool
    {
        return $this->status === self::STATUS_VENDOR_RETURNED;
    }

    public function isPaymentReceived(): bool
    {
        return $this->status === self::STATUS_PAYMENT_RECEIVED;
    }

    public function isArchived(): bool
    {
        return $this->status === self::STATUS_ARCHIVED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function canBeSentToVendor(): bool
    {
        return $this->isPending() && $this->items->isNotEmpty();
    }

    public function canBeMarkedAsReceived(): bool
    {
        return $this->isSentToVendor();
    }

    public function canReceivePayment(): bool
    {
        return $this->isVendorReceived() && $this->active_items->isNotEmpty();
    }

    public function canBeCancelled(): bool
    {
        return in_array($this->status, [
            self::STATUS_PENDING,
            self::STATUS_SENT_TO_VENDOR,
        ]);
    }

    public function canBeMarkedAsReturned(): bool
    {
        return $this->isVendorReceived();
    }

    // State transitions
    public function sendToVendor(): self
    {
        $this->update([
            'status' => self::STATUS_SENT_TO_VENDOR,
            'date_sent_to_vendor' => now(),
        ]);

        return $this;
    }

    public function markVendorReceived(): self
    {
        $this->update([
            'status' => self::STATUS_VENDOR_RECEIVED,
            'date_vendor_received' => now(),
        ]);

        return $this;
    }

    public function markVendorReturned(): self
    {
        $this->update(['status' => self::STATUS_VENDOR_RETURNED]);
        $this->computeDuration();

        return $this;
    }

    public function markPaymentReceived(): self
    {
        $this->update(['status' => self::STATUS_PAYMENT_RECEIVED]);
        $this->computeDuration();

        return $this;
    }

    public function archive(): self
    {
        $this->update(['status' => self::STATUS_ARCHIVED]);

        return $this;
    }

    /**
     * Cancel the memo and return all items to stock.
     */
    public function cancel(): self
    {
        // Return all non-returned items to stock
        $this->items()->where('is_returned', false)->each(function (MemoItem $item) {
            $item->returnToStock();
        });

        $this->update(['status' => self::STATUS_CANCELLED]);
        $this->calculateTotals();

        return $this;
    }

    public function computeDuration(): void
    {
        $sentActivity = $this->created_at;

        if ($this->isVendorReceived()) {
            $sentActivity = $this->updated_at;
        }

        $end = in_array($this->status, [self::STATUS_PAYMENT_RECEIVED, self::STATUS_VENDOR_RETURNED])
            ? $this->updated_at
            : now();

        $this->update([
            'duration' => Carbon::parse($sentActivity)->diffInDays($end),
        ]);
    }

    public function calculateTotals(): self
    {
        $items = $this->items()->where('is_returned', false)->get();
        $subtotal = $items->sum('price');
        $taxAmount = $this->charge_taxes && $this->tax_rate > 0 ? $subtotal * $this->tax_rate : 0;
        $total = $subtotal + $taxAmount + $this->shipping_cost;

        $this->update([
            'subtotal' => $subtotal,
            'tax' => $taxAmount,
            'total' => max(0, $total),
        ]);

        return $this;
    }

    /**
     * Calculate the discount amount based on value and unit type.
     */
    public function calculateDiscountAmount(float $subtotal): float
    {
        if ($this->discount_value <= 0) {
            return 0;
        }

        return $this->discount_unit === 'percent'
            ? ($subtotal * $this->discount_value / 100)
            : $this->discount_value;
    }

    /**
     * Calculate the service fee amount based on value and unit type.
     */
    public function calculateServiceFeeAmount(float $subtotal): float
    {
        if ($this->service_fee_value <= 0) {
            return 0;
        }

        return $this->service_fee_unit === 'percent'
            ? ($subtotal * $this->service_fee_value / 100)
            : $this->service_fee_value;
    }

    /**
     * Calculate the tax amount based on type (percent or fixed).
     * Note: tax_rate is stored as a decimal (e.g., 0.08 for 8%).
     */
    public function calculateTaxAmount(float $taxableAmount): float
    {
        if (! $this->charge_taxes || $this->tax_rate <= 0) {
            return 0;
        }

        return $this->tax_type === 'percent'
            ? ($taxableAmount * $this->tax_rate)
            : $this->tax_rate;
    }

    /**
     * Calculate the grand total with all adjustments.
     */
    public function calculateGrandTotal(): array
    {
        $subtotal = (float) $this->total; // total = subtotal of items (price sum)
        $discountAmount = $this->calculateDiscountAmount($subtotal);
        $afterDiscount = $subtotal - $discountAmount;
        $serviceFeeAmount = $this->calculateServiceFeeAmount($afterDiscount);
        $taxableAmount = $afterDiscount + $serviceFeeAmount;
        $taxAmount = $this->calculateTaxAmount($taxableAmount);
        $shippingCost = (float) ($this->shipping_cost ?? 0);
        $grandTotal = $taxableAmount + $taxAmount + $shippingCost;
        $totalPaid = (float) ($this->total_paid ?? 0);
        $balanceDue = max(0, $grandTotal - $totalPaid);

        return [
            'subtotal' => round($subtotal, 2),
            'discount_amount' => round($discountAmount, 2),
            'service_fee_amount' => round($serviceFeeAmount, 2),
            'tax_amount' => round($taxAmount, 2),
            'shipping_cost' => round($shippingCost, 2),
            'grand_total' => round($grandTotal, 2),
            'total_paid' => round($totalPaid, 2),
            'balance_due' => round($balanceDue, 2),
        ];
    }

    /**
     * Update and persist all payment-related totals.
     */
    public function updatePaymentTotals(): self
    {
        $totals = $this->calculateGrandTotal();

        $this->update([
            'discount_amount' => $totals['discount_amount'],
            'service_fee_amount' => $totals['service_fee_amount'],
            'tax_amount' => $totals['tax_amount'],
            'grand_total' => $totals['grand_total'],
            'balance_due' => $totals['balance_due'],
        ]);

        return $this;
    }

    /**
     * Record a payment and update balances.
     */
    public function recordPayment(float $amount): void
    {
        $newTotalPaid = (float) $this->total_paid + $amount;
        $balanceDue = max(0, (float) $this->grand_total - $newTotalPaid);

        $this->update([
            'total_paid' => $newTotalPaid,
            'balance_due' => $balanceDue,
        ]);
    }

    /**
     * Check if the memo is fully paid.
     */
    public function isFullyPaid(): bool
    {
        return $this->balance_due <= 0 && $this->grand_total > 0;
    }

    /**
     * Check if the memo has any payments.
     */
    public function hasPayments(): bool
    {
        return $this->total_paid > 0;
    }

    /**
     * Get remaining balance percentage.
     */
    public function getPaymentProgressPercentage(): float
    {
        if ($this->grand_total <= 0) {
            return 0;
        }

        return min(100, ($this->total_paid / $this->grand_total) * 100);
    }

    public function getActiveItemsAttribute()
    {
        return $this->items->where('is_returned', false);
    }

    public function getReturnedItemsAttribute()
    {
        return $this->items->where('is_returned', true);
    }

    /**
     * Get the indexable data array for the model.
     *
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'memo_number' => $this->memo_number,
            'vendor_name' => $this->vendor?->name,
            'vendor_company' => $this->vendor?->company_name,
            'vendor_email' => $this->vendor?->email,
            'description' => $this->description,
            'status' => $this->status,
            'total' => $this->total,
            'store_id' => $this->store_id,
            'created_at' => $this->created_at?->timestamp,
        ];
    }

    /**
     * Determine if the model should be searchable.
     */
    public function shouldBeSearchable(): bool
    {
        return ! $this->trashed();
    }

    public function getDueDateAttribute(): ?Carbon
    {
        if (! $this->created_at) {
            return null;
        }

        return $this->created_at->addDays($this->tenure);
    }

    /**
     * Get the number of days the items have been with the vendor.
     * Calculated from date_vendor_received (when vendor received the memo).
     */
    public function getDaysWithVendorAttribute(): int
    {
        // Only count days if memo is with vendor or completed
        if (! $this->date_vendor_received) {
            return 0;
        }

        // For completed statuses, use the completion date
        $endDate = match ($this->status) {
            self::STATUS_PAYMENT_RECEIVED,
            self::STATUS_VENDOR_RETURNED,
            self::STATUS_ARCHIVED,
            self::STATUS_CANCELLED => $this->updated_at,
            default => now(),
        };

        return (int) $this->date_vendor_received->diffInDays($endDate);
    }

    public function isOverdue(): bool
    {
        return $this->due_date && $this->due_date->isPast() && $this->isVendorReceived();
    }

    /**
     * Get the formatted memo ID with store prefix/suffix.
     * Returns just the ID if no prefix/suffix is configured.
     */
    public function getFormattedMemoIdAttribute(): string
    {
        $prefix = $this->store?->memo_id_prefix ?? '';
        $suffix = $this->store?->memo_id_suffix ?? '';

        return $prefix.$this->id.$suffix;
    }

    /**
     * Get the activity prefix for this model.
     */
    protected function getActivityPrefix(): string
    {
        return 'memos';
    }

    /**
     * Get attributes that should be logged.
     *
     * @return array<int, string>
     */
    protected function getLoggableAttributes(): array
    {
        return ['id', 'memo_number', 'status', 'vendor_id', 'tenure', 'total'];
    }

    /**
     * Get the identifier for this model in activity descriptions.
     */
    protected function getActivityIdentifier(): string
    {
        return $this->memo_number ?? "#{$this->id}";
    }
}
