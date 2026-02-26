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

/**
 * Order model representing a customer purchase or sale.
 *
 * Orders can originate from local (in-store POS) sales via OrderCreationService,
 * or from external platforms (Shopify, eBay, Amazon, etc.) via OrderImportService
 * webhooks. Each order contains one or more OrderItems linked to ProductVariants.
 *
 * Payment lifecycle:
 * - Orders implement the Payable contract, accepting polymorphic Payment records.
 * - Payment status is computed from the sum of completed payments vs. the order total.
 * - When fully paid, the order is confirmed and synced to ShipStation for fulfillment.
 * - Invoice records are automatically kept in sync with order totals and payment status.
 *
 * Inventory impact:
 * - Stock is reduced at order creation time (OrderCreationService::reduceStock).
 * - The Inventory model's saved hook dispatches SyncProductInventoryJob to push
 *   the updated quantity to all platforms where the product is listed.
 * - Cancelled orders restore stock via OrderCreationService::restoreStock.
 *
 * @property int $id
 * @property int $store_id
 * @property int|null $customer_id
 * @property int|null $user_id
 * @property int|null $warehouse_id
 * @property int|null $sales_channel_id
 * @property string|null $order_id
 * @property string $status
 * @property float $total
 * @property float $sub_total
 * @property float|null $sales_tax
 * @property float|null $tax_rate
 * @property float|null $shipping_cost
 * @property float|null $discount_cost
 * @property float|null $trade_in_credit
 * @property string|null $source_platform
 * @property string|null $invoice_number
 * @property string|null $tracking_number
 * @property string|null $shipping_carrier
 * @property \Illuminate\Support\Carbon|null $shipped_at
 * @property \Illuminate\Support\Carbon|null $date_of_purchase
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read int $item_count
 * @property-read float $total_paid
 * @property-read float $balance_due
 * @property-read \Illuminate\Database\Eloquent\Collection<int, OrderItem> $items
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Payment> $payments
 * @property-read Customer|null $customer
 * @property-read User|null $user
 * @property-read Warehouse|null $warehouse
 */
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

    /** @var array<string> Statuses that indicate the order has been paid */
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
        // Auto-generate the display order_id from the store's prefix/suffix pattern
        static::created(function (Order $order) {
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

    // ──────────────────────────────────────────────────────────────
    //  Relationships
    // ──────────────────────────────────────────────────────────────

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** The staff user who created the order. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The warehouse from which items are fulfilled. */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /** The sales channel (In Store, Shopify, eBay, etc.) this order came from. */
    public function salesChannel(): BelongsTo
    {
        return $this->belongsTo(SalesChannel::class);
    }

    /** The linked external platform order record (for orders imported via webhooks). */
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

    /** The memo this order was converted from (if applicable). */
    public function memo(): BelongsTo
    {
        return $this->belongsTo(Memo::class);
    }

    /** The trade-in transaction applied as credit to this order. */
    public function tradeInTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'trade_in_transaction_id');
    }

    public function tradeIns(): HasMany
    {
        return $this->hasMany(OrderTradeIn::class);
    }

    public function hasTradeIn(): bool
    {
        return $this->trade_in_transaction_id !== null;
    }

    // ──────────────────────────────────────────────────────────────
    //  Scopes
    // ──────────────────────────────────────────────────────────────

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

    /** Filter orders from a specific external platform (e.g. 'shopify', 'ebay'). */
    public function scopeFromPlatform($query, string $platform)
    {
        return $query->where('source_platform', $platform);
    }

    // ──────────────────────────────────────────────────────────────
    //  Computed Attributes
    // ──────────────────────────────────────────────────────────────

    /** Total number of individual items (sum of line item quantities). */
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

    /** Whether the order is in any paid status (confirmed through completed). */
    public function isPaid(): bool
    {
        return in_array($this->status, self::PAID_STATUSES);
    }

    /** Sum of all completed payment amounts (including service fees). */
    public function getTotalPaidAttribute(): float
    {
        return (float) $this->payments()
            ->where('status', Payment::STATUS_COMPLETED)
            ->selectRaw('COALESCE(SUM(amount), 0) + COALESCE(SUM(service_fee_amount), 0) as total')
            ->value('total');
    }

    /** Remaining amount owed (total minus payments received). */
    public function getBalanceDueAttribute(): float
    {
        return max(0, (float) $this->total - $this->total_paid);
    }

    public function isFullyPaid(): bool
    {
        return $this->balance_due <= 0;
    }

    // ──────────────────────────────────────────────────────────────
    //  Status Transitions
    // ──────────────────────────────────────────────────────────────

    /**
     * Confirm the order and dispatch it to ShipStation for fulfillment.
     */
    public function confirm(): self
    {
        $this->update(['status' => self::STATUS_CONFIRMED]);

        \App\Jobs\SyncOrderToShipStation::dispatch($this);

        return $this;
    }

    public function cancel(): self
    {
        $this->update(['status' => self::STATUS_CANCELLED]);

        return $this;
    }

    /**
     * Mark as shipped with optional tracking info.
     */
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
     * Build a tracking URL based on the shipping carrier.
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

    // ──────────────────────────────────────────────────────────────
    //  Financial Calculations
    // ──────────────────────────────────────────────────────────────

    /**
     * Recalculate sub_total and total from line items, tax, shipping, and discounts.
     */
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

    /**
     * Generate an invoice number in the format INV-YYYYMMDD-000001.
     */
    public function generateInvoiceNumber(): string
    {
        $prefix = 'INV';
        $date = now()->format('Ymd');
        $sequence = str_pad($this->id, 6, '0', STR_PAD_LEFT);

        return "{$prefix}-{$date}-{$sequence}";
    }

    // ──────────────────────────────────────────────────────────────
    //  Activity Logging
    // ──────────────────────────────────────────────────────────────

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
     * Detect deletion of closed/completed orders for elevated activity logging.
     */
    protected function getActivitySlug(string $action): ?string
    {
        if ($action === 'delete') {
            $closedStatuses = [
                self::STATUS_COMPLETED,
                self::STATUS_DELIVERED,
                self::STATUS_SHIPPED,
                self::STATUS_CONFIRMED,
            ];

            $originalStatus = $this->getOriginal('status') ?? $this->status;

            if (in_array($originalStatus, $closedStatuses, true)) {
                return Activity::ORDERS_DELETE_CLOSED;
            }
        }

        $map = $this->getActivityMap();

        return $map[$action] ?? null;
    }

    // ──────────────────────────────────────────────────────────────
    //  Search (Laravel Scout)
    // ──────────────────────────────────────────────────────────────

    /**
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

    public function shouldBeSearchable(): bool
    {
        return ! $this->trashed();
    }

    // ──────────────────────────────────────────────────────────────
    //  Payable Interface
    // ──────────────────────────────────────────────────────────────

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

    /**
     * Record a payment and update order/invoice status accordingly.
     * Called by the payment processing pipeline after a successful charge.
     */
    public function recordPayment(float $amount): void
    {
        $this->refresh();

        // Auto-confirm pending orders when any payment is received
        if ($this->total_paid > 0 && $this->status === self::STATUS_PENDING) {
            $this->update(['status' => self::STATUS_CONFIRMED]);
        }

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

    /**
     * Called when all payments are complete (balance reaches zero).
     */
    public function onPaymentComplete(): void
    {
        $this->update(['status' => self::STATUS_CONFIRMED]);

        $this->syncInvoicePaymentStatus();
    }

    /**
     * @return array{discount_value: float, discount_unit: string, discount_reason: null, service_fee_value: float, service_fee_unit: string, service_fee_reason: string|null, charge_taxes: bool, tax_rate: float, tax_type: string, shipping_cost: float}
     */
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

    /**
     * Update discount, service fee, tax, and shipping from the payment UI.
     * Tax rate is received as a percentage (e.g. 8.25) and stored as decimal (0.0825).
     */
    public function updatePaymentAdjustments(array $adjustments): void
    {
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

    /**
     * Update calculated totals from the payment summary and sync to invoice.
     */
    public function updateCalculatedTotals(array $summary): void
    {
        $this->update([
            'discount_cost' => $summary['discount_amount'] ?? $this->discount_cost,
            'sales_tax' => $summary['tax_amount'] ?? $this->sales_tax,
            'total' => $summary['grand_total'] ?? $this->total,
        ]);

        $this->syncInvoiceTotals();
    }

    // ──────────────────────────────────────────────────────────────
    //  Invoice Synchronization
    // ──────────────────────────────────────────────────────────────

    /**
     * Sync the invoice's payment status (pending/partial/paid) with the order's payments.
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

    /**
     * Sync the invoice's line totals (subtotal, tax, shipping, discount, service fee)
     * with the order's current values.
     */
    public function syncInvoiceTotals(): void
    {
        if (! $this->invoice) {
            $this->load('invoice');
        }

        if ($this->invoice) {
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

            $this->syncInvoicePaymentStatus();
        }
    }
}
