<?php

namespace App\Models;

use App\Contracts\Payable;
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

class Repair extends Model implements Payable
{
    use BelongsToStore, HasCustomStatuses, HasFactory, HasNotes, HasTags, LogsActivity, Searchable, SoftDeletes;

    // Status constants
    public const STATUS_PENDING = 'pending';

    public const STATUS_SENT_TO_VENDOR = 'sent_to_vendor';

    public const STATUS_RECEIVED_BY_VENDOR = 'received_by_vendor';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_PAYMENT_RECEIVED = 'payment_received';

    public const STATUS_REFUNDED = 'refunded';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_ARCHIVED = 'archived';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_SENT_TO_VENDOR,
        self::STATUS_RECEIVED_BY_VENDOR,
        self::STATUS_COMPLETED,
        self::STATUS_PAYMENT_RECEIVED,
        self::STATUS_REFUNDED,
        self::STATUS_CANCELLED,
        self::STATUS_ARCHIVED,
    ];

    protected $fillable = [
        'store_id',
        'warehouse_id',
        'customer_id',
        'vendor_id',
        'user_id',
        'order_id',
        'repair_number',
        'status',
        'status_id',
        'service_fee',
        'service_fee_value',
        'service_fee_unit',
        'service_fee_reason',
        'subtotal',
        'tax',
        'tax_rate',
        'charge_taxes',
        'tax_type',
        'discount',
        'discount_value',
        'discount_unit',
        'discount_reason',
        'shipping_cost',
        'total',
        'grand_total',
        'total_paid',
        'balance_due',
        'description',
        'repair_days',
        'is_appraisal',
        'date_sent_to_vendor',
        'date_received_by_vendor',
        'date_completed',
    ];

    protected function casts(): array
    {
        return [
            'service_fee' => 'decimal:2',
            'service_fee_value' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'tax' => 'decimal:2',
            'tax_rate' => 'decimal:4',
            'charge_taxes' => 'boolean',
            'discount' => 'decimal:2',
            'discount_value' => 'decimal:2',
            'shipping_cost' => 'decimal:2',
            'total' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'total_paid' => 'decimal:2',
            'balance_due' => 'decimal:2',
            'repair_days' => 'integer',
            'is_appraisal' => 'boolean',
            'date_sent_to_vendor' => 'datetime',
            'date_received_by_vendor' => 'datetime',
            'date_completed' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Repair $repair) {
            // Set temporary value to satisfy NOT NULL constraint
            if (empty($repair->repair_number)) {
                $repair->repair_number = 'REP-TEMP';
            }
        });

        static::created(function (Repair $repair) {
            // Generate repair_number from store prefix/suffix
            if ($repair->repair_number === 'REP-TEMP') {
                $store = $repair->store;
                $prefix = $store?->repair_id_prefix ?? 'REP';
                $suffix = $store?->repair_id_suffix ?? '';
                $repair->repair_number = "{$prefix}-{$repair->id}{$suffix}";
                $repair->saveQuietly();
                $repair->searchable(); // Manually sync to Scout after saveQuietly
            }
        });
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
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
        return $this->hasMany(RepairItem::class);
    }

    public function invoice(): MorphOne
    {
        return $this->morphOne(Invoice::class, 'invoiceable');
    }

    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'payable');
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

    public function scopeReceivedByVendor($query)
    {
        return $query->where('status', self::STATUS_RECEIVED_BY_VENDOR);
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    public function scopeOpen($query)
    {
        return $query->whereIn('status', [
            self::STATUS_PENDING,
            self::STATUS_SENT_TO_VENDOR,
            self::STATUS_RECEIVED_BY_VENDOR,
        ]);
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

    public function isReceivedByVendor(): bool
    {
        return $this->status === self::STATUS_RECEIVED_BY_VENDOR;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isPaymentReceived(): bool
    {
        return $this->status === self::STATUS_PAYMENT_RECEIVED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function canBeSentToVendor(): bool
    {
        return $this->isPending();
    }

    public function canBeMarkedAsReceived(): bool
    {
        return $this->isSentToVendor();
    }

    public function canBeCompleted(): bool
    {
        return $this->isReceivedByVendor();
    }

    public function canReceivePayment(): bool
    {
        return $this->isCompleted() && ! $this->isPaymentReceived();
    }

    public function canBeCancelled(): bool
    {
        return in_array($this->status, [
            self::STATUS_PENDING,
            self::STATUS_SENT_TO_VENDOR,
            self::STATUS_RECEIVED_BY_VENDOR,
        ]) && ! $this->isPaymentReceived();
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

    public function markReceivedByVendor(): self
    {
        $this->update([
            'status' => self::STATUS_RECEIVED_BY_VENDOR,
            'date_received_by_vendor' => now(),
        ]);

        return $this;
    }

    public function markCompleted(): self
    {
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'date_completed' => now(),
        ]);

        $this->computeRepairDays();

        return $this;
    }

    public function markPaymentReceived(): self
    {
        $this->update(['status' => self::STATUS_PAYMENT_RECEIVED]);

        return $this;
    }

    public function cancel(): self
    {
        $this->update(['status' => self::STATUS_CANCELLED]);

        return $this;
    }

    public function archive(): self
    {
        $this->update(['status' => self::STATUS_ARCHIVED]);

        return $this;
    }

    public function computeRepairDays(): void
    {
        if (! $this->date_received_by_vendor) {
            $this->update(['repair_days' => 0]);

            return;
        }

        $end = $this->date_completed ?? now();
        $days = Carbon::parse($this->date_received_by_vendor)->diffInDays($end);

        $this->update(['repair_days' => $days]);
    }

    public function calculateTotals(): self
    {
        $subtotal = $this->items->sum('customer_cost');
        $taxAmount = $this->tax_rate > 0 ? $subtotal * $this->tax_rate : 0;
        $total = $subtotal + $this->service_fee + $taxAmount + $this->shipping_cost - $this->discount;

        $this->update([
            'subtotal' => $subtotal,
            'tax' => $taxAmount,
            'total' => max(0, $total),
        ]);

        return $this;
    }

    public function getVendorTotalAttribute(): float
    {
        return (float) $this->items->sum('vendor_cost');
    }

    public function getCustomerTotalAttribute(): float
    {
        return (float) $this->items->sum('customer_cost');
    }

    public function isFullyPaid(): bool
    {
        return $this->balance_due <= 0;
    }

    // Payable interface methods

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
        return $this->repair_number;
    }

    public static function getPayableTypeName(): string
    {
        return 'repair';
    }

    public function onPaymentComplete(): void
    {
        app(\App\Services\RepairPaymentService::class)->completeRepairPayment($this);
    }

    public function getPaymentAdjustments(): array
    {
        // Convert tax_rate from decimal (0.08) to percentage (8) for PaymentService
        $taxRate = (float) ($this->tax_rate ?? 0);
        if ($taxRate > 0 && $taxRate < 1) {
            $taxRate = $taxRate * 100;
        }

        return [
            'discount_value' => (float) ($this->discount_value ?? 0),
            'discount_unit' => $this->discount_unit ?? 'fixed',
            'discount_reason' => $this->discount_reason,
            'service_fee_value' => (float) ($this->service_fee_value ?? 0),
            'service_fee_unit' => $this->service_fee_unit ?? 'fixed',
            'service_fee_reason' => $this->service_fee_reason,
            'charge_taxes' => (bool) ($this->charge_taxes ?? true),
            'tax_rate' => $taxRate,
            'tax_type' => 'percent',
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
            'discount' => $summary['discount_amount'],
            'service_fee' => $summary['service_fee_amount'],
            'tax' => $summary['tax_amount'],
            'grand_total' => $summary['grand_total'],
            'balance_due' => $summary['grand_total'] - (float) $this->total_paid,
        ]);

        // Sync invoice totals after updating
        $this->syncInvoiceTotals();
    }

    /**
     * Sync the invoice totals with the repair.
     */
    public function syncInvoiceTotals(): void
    {
        if (! $this->invoice) {
            $this->load('invoice');
        }

        if ($this->invoice) {
            $this->invoice->update([
                'subtotal' => $this->subtotal ?? 0,
                'tax' => $this->tax ?? 0,
                'shipping' => $this->shipping_cost ?? 0,
                'discount' => $this->discount ?? 0,
                'service_fee' => $this->service_fee ?? 0,
                'total' => $this->grand_total ?? $this->total ?? 0,
            ]);

            // Recalculate balance
            $this->invoice->recalculateTotals();
        }
    }

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
     * Get the indexable data array for the model.
     *
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'repair_number' => $this->repair_number,
            'customer_name' => $this->customer?->full_name,
            'customer_email' => $this->customer?->email,
            'vendor_name' => $this->vendor?->display_name,
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

    /**
     * Get the formatted repair ID with store prefix/suffix.
     * Returns just the ID if no prefix/suffix is configured.
     */
    public function getFormattedRepairIdAttribute(): string
    {
        $prefix = $this->store?->repair_id_prefix ?? '';
        $suffix = $this->store?->repair_id_suffix ?? '';

        return $prefix.$this->id.$suffix;
    }

    /**
     * Get the activity prefix for this model.
     */
    protected function getActivityPrefix(): string
    {
        return 'repairs';
    }

    /**
     * Get attributes that should be logged.
     *
     * @return array<int, string>
     */
    protected function getLoggableAttributes(): array
    {
        return ['id', 'repair_number', 'status', 'customer_id', 'total'];
    }

    /**
     * Get the identifier for this model in activity descriptions.
     */
    protected function getActivityIdentifier(): string
    {
        return $this->repair_number ?? "#{$this->id}";
    }
}
