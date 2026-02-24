<?php

namespace App\Models;

use App\Contracts\Payable;
use App\Traits\BelongsToStore;
use App\Traits\HasCustomStatuses;
use App\Traits\HasNotes;
use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Scout\Searchable;

class Order extends Model implements Payable
{
    use BelongsToStore, HasCustomStatuses, HasFactory, HasNotes, LogsActivity, Searchable, SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PENDING = 'pending';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_SHIPPED = 'shipped';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_REFUNDED = 'refunded';

    public const STATUS_PARTIAL_PAYMENT = 'partial_payment';

    public const PAID_STATUSES = [
        self::STATUS_CONFIRMED,
        self::STATUS_PROCESSING,
        self::STATUS_SHIPPED,
        self::STATUS_DELIVERED,
        self::STATUS_COMPLETED,
    ];

    protected $fillable = [
        'store_id',
        'sales_channel_id',
        'memo_id',
        'trade_in_transaction_id',
        'customer_id',
        'user_id',
        'warehouse_id',
        'total',
        'sub_total',
        'status',
        'status_id',
        'payment_gateway_id',
        'sales_tax',
        'tax_rate',
        'shipping_cost',
        'discount_cost',
        'trade_in_credit',
        'service_fee_value',
        'service_fee_unit',
        'service_fee_reason',
        'cart_id',
        'shipping_weight',
        'order_id',
        'shipping_gateway_id',
        'tracking_number',
        'shipping_carrier',
        'shipped_at',
        'invoice_number',
        'shipstation_store',
        'square_order_id',
        'date_of_purchase',
        'external_marketplace_id',
        'source_platform',
        'notes',
        'billing_address',
        'shipping_address',
    ];

    protected function casts(): array
    {
        return [
            'total' => 'decimal:2',
            'sub_total' => 'decimal:2',
            'sales_tax' => 'decimal:2',
            'tax_rate' => 'decimal:4',
            'shipping_cost' => 'decimal:2',
            'discount_cost' => 'decimal:2',
            'trade_in_credit' => 'decimal:2',
            'service_fee_value' => 'decimal:2',
            'shipping_weight' => 'decimal:4',
            'date_of_purchase' => 'date',
            'shipped_at' => 'datetime',
            'billing_address' => 'array',
            'shipping_address' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::created(function (Order $order) {
            // Generate order_id from store prefix/suffix if not already set
            if (empty($order->order_id)) {
                $store = $order->store;
                $prefix = $store?->order_id_prefix ?? '';
                $suffix = $store?->order_id_suffix ?? '';
                $order->order_id = "{$prefix}{$order->id}{$suffix}";
                $order->saveQuietly();
                $order->searchable(); // Manually sync to Scout after saveQuietly
            }
        });
    }

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

    public function salesChannel(): BelongsTo
    {
        return $this->belongsTo(SalesChannel::class);
    }

    public function platformOrder(): HasOne
    {
        return $this->hasOne(PlatformOrder::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'payable');
    }

    public function hasPayments(): bool
    {
        return $this->payments()->exists();
    }

    public function returns(): HasMany
    {
        return $this->hasMany(ProductReturn::class);
    }

    public function invoice(): MorphOne
    {
        return $this->morphOne(Invoice::class, 'invoiceable');
    }

    /**
     * @return MorphMany<ShippingLabel, $this>
     */
    public function shippingLabels(): MorphMany
    {
        return $this->morphMany(ShippingLabel::class, 'shippable');
    }

    public function memo(): BelongsTo
    {
        return $this->belongsTo(Memo::class);
    }

    public function tradeInTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'trade_in_transaction_id');
    }

    public function hasTradeIn(): bool
    {
        return $this->trade_in_transaction_id !== null;
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeConfirmed($query)
    {
        return $query->where('status', self::STATUS_CONFIRMED);
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    public function scopeCancelled($query)
    {
        return $query->where('status', self::STATUS_CANCELLED);
    }

    public function scopeFromPlatform($query, string $platform)
    {
        return $query->where('source_platform', $platform);
    }

    public function getItemCountAttribute(): int
    {
        return $this->items->sum('quantity');
    }

    public function isFromExternalPlatform(): bool
    {
        return ! empty($this->source_platform);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isConfirmed(): bool
    {
        return $this->status === self::STATUS_CONFIRMED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function isPaid(): bool
    {
        return in_array($this->status, self::PAID_STATUSES);
    }

    public function getTotalPaidAttribute(): float
    {
        return (float) $this->payments()
            ->where('status', Payment::STATUS_COMPLETED)
            ->selectRaw('COALESCE(SUM(amount), 0) + COALESCE(SUM(service_fee_amount), 0) as total')
            ->value('total');
    }

    public function getBalanceDueAttribute(): float
    {
        return max(0, (float) $this->total - $this->total_paid);
    }

    public function isFullyPaid(): bool
    {
        return $this->balance_due <= 0;
    }

    public function confirm(): self
    {
        $this->update(['status' => self::STATUS_CONFIRMED]);

        // Dispatch job to sync order to ShipStation
        \App\Jobs\SyncOrderToShipStation::dispatch($this);

        return $this;
    }

    public function cancel(): self
    {
        $this->update(['status' => self::STATUS_CANCELLED]);

        return $this;
    }

    public function markAsShipped(?string $trackingNumber = null, ?string $carrier = null): self
    {
        $data = [
            'status' => self::STATUS_SHIPPED,
            'shipped_at' => now(),
        ];

        if ($trackingNumber) {
            $data['tracking_number'] = $trackingNumber;
        }

        if ($carrier) {
            $data['shipping_carrier'] = $carrier;
        }

        $this->update($data);

        return $this;
    }

    /**
     * Get the tracking URL based on carrier.
     */
    public function getTrackingUrl(): ?string
    {
        if (! $this->tracking_number) {
            return null;
        }

        return match ($this->shipping_carrier) {
            'fedex' => "https://www.fedex.com/fedextrack/?trknbr={$this->tracking_number}",
            'ups' => "https://www.ups.com/track?tracknum={$this->tracking_number}",
            'usps' => "https://tools.usps.com/go/TrackConfirmAction?tLabels={$this->tracking_number}",
            'dhl' => "https://www.dhl.com/us-en/home/tracking/tracking-express.html?submit=1&tracking-id={$this->tracking_number}",
            default => null,
        };
    }

    public function markAsDelivered(): self
    {
        $this->update(['status' => self::STATUS_DELIVERED]);

        return $this;
    }

    public function markAsCompleted(): self
    {
        $this->update(['status' => self::STATUS_COMPLETED]);

        return $this;
    }

    public function calculateTotals(): self
    {
        $subTotal = $this->items->sum(fn ($item) => $item->line_total);
        $total = $subTotal
            + ($this->shipping_cost ?? 0)
            + ($this->sales_tax ?? 0)
            - ($this->discount_cost ?? 0)
            - ($this->trade_in_credit ?? 0);

        $this->update([
            'sub_total' => $subTotal,
            'total' => max(0, $total),
        ]);

        return $this;
    }

    public function generateInvoiceNumber(): string
    {
        $prefix = 'INV';
        $date = now()->format('Ymd');
        $sequence = str_pad($this->id, 6, '0', STR_PAD_LEFT);

        return "{$prefix}-{$date}-{$sequence}";
    }

    protected function getActivityPrefix(): string
    {
        return 'orders';
    }

    protected function getLoggableAttributes(): array
    {
        return ['id', 'invoice_number', 'status', 'total', 'customer_id', 'source_platform'];
    }

    protected function getActivityIdentifier(): string
    {
        return $this->invoice_number ?? "#{$this->id}";
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
            'invoice_number' => $this->invoice_number,
            'external_marketplace_id' => $this->external_marketplace_id,
            'customer_name' => $this->customer?->full_name,
            'customer_email' => $this->customer?->email,
            'status' => $this->status,
            'total' => $this->total,
            'source_platform' => $this->source_platform,
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

    // Payable interface implementation

    public function getStoreId(): int
    {
        return (int) $this->store_id;
    }

    public function getSubtotal(): float
    {
        return (float) $this->sub_total;
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
            self::STATUS_CONFIRMED,
            self::STATUS_PARTIAL_PAYMENT,
        ]) && ! $this->isFullyPaid();
    }

    public function recordPayment(float $amount): void
    {
        // Order uses computed total_paid attribute, no need to store it
        // Just refresh to recalculate balance
        $this->refresh();

        // Update status to confirmed when any payment is made
        if ($this->total_paid > 0 && $this->status === self::STATUS_PENDING) {
            $this->update(['status' => self::STATUS_CONFIRMED]);
        }

        // Sync invoice if exists
        $this->syncInvoicePaymentStatus();
    }

    public function getDisplayIdentifier(): string
    {
        return $this->invoice_number ?? "Order #{$this->id}";
    }

    public static function getPayableTypeName(): string
    {
        return 'order';
    }

    public function onPaymentComplete(): void
    {
        $this->update(['status' => self::STATUS_CONFIRMED]);

        // Sync invoice if exists
        $this->syncInvoicePaymentStatus();
    }

    /**
     * Sync the invoice payment status with the order's payment status.
     */
    public function syncInvoicePaymentStatus(): void
    {
        if (! $this->invoice) {
            $this->load('invoice');
        }

        if ($this->invoice) {
            $totalPaid = $this->total_paid;
            $balanceDue = $this->balance_due;

            $status = match (true) {
                $balanceDue <= 0 => Invoice::STATUS_PAID,
                $totalPaid > 0 => Invoice::STATUS_PARTIAL,
                default => Invoice::STATUS_PENDING,
            };

            $this->invoice->update([
                'total_paid' => $totalPaid,
                'balance_due' => $balanceDue,
                'status' => $status,
            ]);
        }
    }

    public function getPaymentAdjustments(): array
    {
        return [
            'discount_value' => (float) ($this->discount_cost ?? 0),
            'discount_unit' => 'fixed',
            'discount_reason' => null,
            'service_fee_value' => (float) ($this->service_fee_value ?? 0),
            'service_fee_unit' => $this->service_fee_unit ?? 'fixed',
            'service_fee_reason' => $this->service_fee_reason,
            'charge_taxes' => (float) ($this->tax_rate ?? 0) > 0,
            'tax_rate' => (float) ($this->tax_rate ?? 0) * 100,
            'tax_type' => 'percent',
            'shipping_cost' => (float) ($this->shipping_cost ?? 0),
        ];
    }

    public function updatePaymentAdjustments(array $adjustments): void
    {
        // Convert tax rate from percentage (e.g. 8.25) to decimal (e.g. 0.0825)
        $taxRate = isset($adjustments['tax_rate'])
            ? $adjustments['tax_rate'] / 100
            : $this->tax_rate;

        $this->update([
            'discount_cost' => $adjustments['discount_value'] ?? $this->discount_cost,
            'service_fee_value' => $adjustments['service_fee_value'] ?? $this->service_fee_value,
            'service_fee_unit' => $adjustments['service_fee_unit'] ?? $this->service_fee_unit,
            'service_fee_reason' => $adjustments['service_fee_reason'] ?? $this->service_fee_reason,
            'tax_rate' => $taxRate,
            'shipping_cost' => $adjustments['shipping_cost'] ?? $this->shipping_cost,
        ]);
    }

    public function updateCalculatedTotals(array $summary): void
    {
        $this->update([
            'discount_cost' => $summary['discount_amount'] ?? $this->discount_cost,
            'sales_tax' => $summary['tax_amount'] ?? $this->sales_tax,
            'total' => $summary['grand_total'] ?? $this->total,
        ]);

        // Sync invoice totals after updating
        $this->syncInvoiceTotals();
    }

    /**
     * Get the activity slug, detecting deletion of closed orders.
     */
    protected function getActivitySlug(string $action): ?string
    {
        // Detect deletion of a closed order/sale
        if ($action === 'delete') {
            $closedStatuses = [
                self::STATUS_COMPLETED,
                self::STATUS_DELIVERED,
                self::STATUS_SHIPPED,
                self::STATUS_CONFIRMED,
            ];

            // Check the original status (before deletion)
            $originalStatus = $this->getOriginal('status') ?? $this->status;

            if (in_array($originalStatus, $closedStatuses, true)) {
                return Activity::ORDERS_DELETE_CLOSED;
            }
        }

        $map = $this->getActivityMap();

        return $map[$action] ?? null;
    }

    /**
     * Sync the invoice totals (subtotal, tax, shipping, discount, total) with the order.
     */
    public function syncInvoiceTotals(): void
    {
        if (! $this->invoice) {
            $this->load('invoice');
        }

        if ($this->invoice) {
            // Calculate service fee amount for the invoice
            $serviceFeeAmount = 0;
            if (($this->service_fee_value ?? 0) > 0) {
                $subtotalAfterDiscount = $this->sub_total - ($this->discount_cost ?? 0);
                $serviceFeeAmount = ($this->service_fee_unit === 'percent')
                    ? $subtotalAfterDiscount * $this->service_fee_value / 100
                    : $this->service_fee_value;
            }

            $this->invoice->update([
                'subtotal' => $this->sub_total ?? 0,
                'tax' => $this->sales_tax ?? 0,
                'shipping' => $this->shipping_cost ?? 0,
                'discount' => $this->discount_cost ?? 0,
                'service_fee' => $serviceFeeAmount,
                'total' => $this->total ?? 0,
            ]);

            // Also sync payment status
            $this->syncInvoicePaymentStatus();
        }
    }
}
