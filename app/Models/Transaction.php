<?php

namespace App\Models;

use App\Traits\BelongsToStore;
use App\Traits\HasAddresses;
use App\Traits\HasCustomStatuses;
use App\Traits\HasNotes;
use App\Traits\HasTags;
use App\Traits\LogsActivity;
use App\Traits\TracksStatusChanges;
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
 * @property-read Warehouse|null $warehouse
 */
class Transaction extends Model
{
    use BelongsToStore, HasAddresses, HasCustomStatuses, HasFactory, HasNotes, HasTags, LogsActivity, Searchable, SoftDeletes, TracksStatusChanges;

    // Status constants - Kit Request Phase (Online Only)
    public const STATUS_PENDING_KIT_REQUEST = 'pending_kit_request';

    public const STATUS_KIT_REQUEST_CONFIRMED = 'kit_request_confirmed';

    public const STATUS_KIT_REQUEST_REJECTED = 'kit_request_rejected';

    public const STATUS_KIT_REQUEST_ON_HOLD = 'kit_request_on_hold';

    // Status constants - Kit Shipping Phase (Online Only)
    public const STATUS_KIT_SENT = 'kit_sent';

    public const STATUS_KIT_DELIVERED = 'kit_delivered';

    // Status constants - Items Phase
    public const STATUS_PENDING = 'pending'; // For in-house, initial state

    public const STATUS_ITEMS_RECEIVED = 'items_received';

    public const STATUS_KIT_RECEIVED = 'kit_received'; // Legacy alias

    public const STATUS_ITEMS_REVIEWED = 'items_reviewed';

    // Status constants - Offer Phase
    public const STATUS_OFFER_GIVEN = 'offer_given';

    public const STATUS_OFFER_ACCEPTED = 'offer_accepted';

    public const STATUS_OFFER_DECLINED = 'offer_declined';

    // Status constants - Payment Phase
    public const STATUS_PAYMENT_PENDING = 'payment_pending';

    public const STATUS_PAYMENT_PROCESSED = 'payment_processed';

    // Status constants - Return/Cancellation
    public const STATUS_RETURN_REQUESTED = 'return_requested';

    public const STATUS_ITEMS_RETURNED = 'items_returned';

    public const STATUS_CANCELLED = 'cancelled';

    // Type constants
    public const TYPE_IN_STORE = 'in_store';

    public const TYPE_MAIL_IN = 'mail_in';

    /**
     * @deprecated Use TYPE_IN_STORE instead. Kept for backwards compatibility.
     */
    public const TYPE_BUY = 'in_store';

    /**
     * @deprecated Use TYPE_IN_STORE instead. Kept for backwards compatibility.
     */
    public const TYPE_IN_HOUSE = 'in_store';

    // Source constants
    public const SOURCE_TRADE_IN = 'trade_in';

    public const SOURCE_ONLINE = 'online';

    // Payment method constants
    public const PAYMENT_CASH = 'cash';

    public const PAYMENT_CHECK = 'check';

    public const PAYMENT_STORE_CREDIT = 'store_credit';

    public const PAYMENT_ACH = 'ach';

    public const PAYMENT_PAYPAL = 'paypal';

    public const PAYMENT_VENMO = 'venmo';

    public const PAYMENT_WIRE_TRANSFER = 'wire_transfer';

    protected $fillable = [
        'store_id',
        'warehouse_id',
        'order_id',
        'customer_id',
        'shipping_address_id',
        'user_id',
        'assigned_to',
        'transaction_number',
        'status',
        'status_id',
        'type',
        'source',
        'preliminary_offer',
        'final_offer',
        'estimated_value',
        'payment_method',
        'payment_details',
        'bin_location',
        'customer_notes',
        'internal_notes',
        'customer_description',
        'customer_amount',
        'customer_categories',
        'outbound_tracking_number',
        'outbound_carrier',
        'return_tracking_number',
        'return_carrier',
        'offer_given_at',
        'offer_accepted_at',
        'payment_processed_at',
        'kit_sent_at',
        'kit_delivered_at',
        'items_received_at',
        'items_reviewed_at',
        'return_shipped_at',
        'return_delivered_at',
    ];

    protected function casts(): array
    {
        return [
            'preliminary_offer' => 'decimal:2',
            'final_offer' => 'decimal:2',
            'estimated_value' => 'decimal:2',
            'customer_amount' => 'decimal:2',
            'payment_details' => 'array',
            'offer_given_at' => 'datetime',
            'offer_accepted_at' => 'datetime',
            'payment_processed_at' => 'datetime',
            'kit_sent_at' => 'datetime',
            'kit_delivered_at' => 'datetime',
            'items_received_at' => 'datetime',
            'items_reviewed_at' => 'datetime',
            'return_shipped_at' => 'datetime',
            'return_delivered_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Transaction $transaction) {
            // Set temporary value to satisfy NOT NULL constraint if needed
            if (empty($transaction->transaction_number)) {
                $transaction->transaction_number = 'TXN-TEMP';
            }
        });

        static::created(function (Transaction $transaction) {
            // Generate transaction_number from store prefix/suffix
            if ($transaction->transaction_number === 'TXN-TEMP') {
                $store = $transaction->store;
                $prefix = $store?->buy_id_prefix ?? 'TXN';
                $suffix = $store?->buy_id_suffix ?? '';
                $transaction->transaction_number = "{$prefix}-{$transaction->id}{$suffix}";
                $transaction->saveQuietly();
                $transaction->searchable(); // Manually sync to Scout after saveQuietly
            }
        });
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Get the shipping address used for this transaction.
     */
    public function shippingAddress(): BelongsTo
    {
        return $this->belongsTo(Address::class, 'shipping_address_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function isTradeIn(): bool
    {
        return $this->source === self::SOURCE_TRADE_IN;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function items(): HasMany
    {
        return $this->hasMany(TransactionItem::class);
    }

    /**
     * @return HasMany<TransactionOffer, Transaction>
     */
    public function offers(): HasMany
    {
        return $this->hasMany(TransactionOffer::class)->orderByDesc('created_at');
    }

    /**
     * @return HasOne<TransactionOffer, Transaction>
     */
    public function latestOffer(): HasOne
    {
        return $this->hasOne(TransactionOffer::class)->latestOfMany();
    }

    /**
     * @return MorphMany<Image, Transaction>
     */
    public function images(): MorphMany
    {
        return $this->morphMany(Image::class, 'imageable');
    }

    /**
     * @return MorphMany<ShippingLabel, Transaction>
     */
    public function shippingLabels(): MorphMany
    {
        return $this->morphMany(ShippingLabel::class, 'shippable');
    }

    /**
     * @return MorphOne<ShippingLabel, Transaction>
     */
    public function outboundLabel(): MorphOne
    {
        return $this->morphOne(ShippingLabel::class, 'shippable')
            ->ofMany(
                ['id' => 'max'],
                fn ($query) => $query->where('type', ShippingLabel::TYPE_OUTBOUND)
            );
    }

    /**
     * @return MorphOne<ShippingLabel, Transaction>
     */
    public function returnLabel(): MorphOne
    {
        return $this->morphOne(ShippingLabel::class, 'shippable')
            ->ofMany(
                ['id' => 'max'],
                fn ($query) => $query->where('type', ShippingLabel::TYPE_RETURN)
            );
    }

    /**
     * @return HasMany<TransactionPayout, Transaction>
     */
    public function payouts(): HasMany
    {
        return $this->hasMany(TransactionPayout::class)->orderByDesc('created_at');
    }

    /**
     * @return HasOne<TransactionPayout, Transaction>
     */
    public function latestPayout(): HasOne
    {
        return $this->hasOne(TransactionPayout::class)->latestOfMany();
    }

    // Status scopes
    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeKitReceived($query)
    {
        return $query->where('status', self::STATUS_KIT_RECEIVED);
    }

    public function scopeOfferGiven($query)
    {
        return $query->where('status', self::STATUS_OFFER_GIVEN);
    }

    public function scopeOfferAccepted($query)
    {
        return $query->where('status', self::STATUS_OFFER_ACCEPTED);
    }

    public function scopePaymentProcessed($query)
    {
        return $query->where('status', self::STATUS_PAYMENT_PROCESSED);
    }

    public function scopeCancelled($query)
    {
        return $query->where('status', self::STATUS_CANCELLED);
    }

    // Type scopes
    public function scopeInStore($query)
    {
        return $query->where('type', self::TYPE_IN_STORE);
    }

    /**
     * @deprecated Use scopeInStore instead.
     */
    public function scopeInHouse($query)
    {
        return $this->scopeInStore($query);
    }

    public function scopeMailIn($query)
    {
        return $query->where('type', self::TYPE_MAIL_IN);
    }

    // Status helpers
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isKitReceived(): bool
    {
        return $this->status === self::STATUS_KIT_RECEIVED;
    }

    public function isOfferGiven(): bool
    {
        return $this->status === self::STATUS_OFFER_GIVEN;
    }

    public function isOfferAccepted(): bool
    {
        return $this->status === self::STATUS_OFFER_ACCEPTED;
    }

    public function isOfferDeclined(): bool
    {
        return $this->status === self::STATUS_OFFER_DECLINED;
    }

    public function isPaymentProcessed(): bool
    {
        return $this->status === self::STATUS_PAYMENT_PROCESSED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function canSubmitOffer(): bool
    {
        return in_array($this->status, [
            self::STATUS_PENDING,
            self::STATUS_KIT_RECEIVED,
            self::STATUS_ITEMS_RECEIVED,
            self::STATUS_ITEMS_REVIEWED,
            self::STATUS_OFFER_DECLINED, // Allow counter-offers
        ]);
    }

    public function canAcceptOffer(): bool
    {
        return $this->isOfferGiven();
    }

    public function canProcessPayment(): bool
    {
        return $this->isOfferAccepted();
    }

    public function canBeCancelled(): bool
    {
        return in_array($this->status, [
            self::STATUS_PENDING,
            self::STATUS_KIT_RECEIVED,
            self::STATUS_OFFER_GIVEN,
            self::STATUS_OFFER_DECLINED,
        ]);
    }

    // State transitions
    public function submitOffer(float $offer): self
    {
        $this->update([
            'status' => self::STATUS_OFFER_GIVEN,
            'final_offer' => $offer,
            'offer_given_at' => now(),
        ]);

        return $this;
    }

    public function acceptOffer(): self
    {
        $this->update([
            'status' => self::STATUS_OFFER_ACCEPTED,
            'offer_accepted_at' => now(),
        ]);

        return $this;
    }

    public function declineOffer(?string $reason = null): self
    {
        $this->update([
            'status' => self::STATUS_OFFER_DECLINED,
            'internal_notes' => $reason ?? $this->internal_notes,
        ]);

        return $this;
    }

    public function processPayment(string $method): self
    {
        $this->update([
            'status' => self::STATUS_PAYMENT_PROCESSED,
            'payment_method' => $method,
            'payment_processed_at' => now(),
        ]);

        return $this;
    }

    public function cancel(): self
    {
        $this->update(['status' => self::STATUS_CANCELLED]);

        return $this;
    }

    public function markKitReceived(): self
    {
        $this->update(['status' => self::STATUS_KIT_RECEIVED]);

        return $this;
    }

    // Online workflow state transitions

    public function confirmKitRequest(): self
    {
        $this->update(['status' => self::STATUS_KIT_REQUEST_CONFIRMED]);

        return $this;
    }

    public function rejectKitRequest(): self
    {
        $this->update(['status' => self::STATUS_KIT_REQUEST_REJECTED]);

        return $this;
    }

    public function holdKitRequest(): self
    {
        $this->update(['status' => self::STATUS_KIT_REQUEST_ON_HOLD]);

        return $this;
    }

    public function markKitSent(string $trackingNumber, string $carrier = 'fedex'): self
    {
        $this->update([
            'status' => self::STATUS_KIT_SENT,
            'outbound_tracking_number' => $trackingNumber,
            'outbound_carrier' => $carrier,
            'kit_sent_at' => now(),
        ]);

        return $this;
    }

    public function markKitDelivered(): self
    {
        $this->update([
            'status' => self::STATUS_KIT_DELIVERED,
            'kit_delivered_at' => now(),
        ]);

        return $this;
    }

    public function markItemsReceived(): self
    {
        $this->update([
            'status' => self::STATUS_ITEMS_RECEIVED,
            'items_received_at' => now(),
        ]);

        return $this;
    }

    public function markItemsReviewed(): self
    {
        $this->update([
            'status' => self::STATUS_ITEMS_REVIEWED,
            'items_reviewed_at' => now(),
        ]);

        return $this;
    }

    public function requestReturn(): self
    {
        $this->update(['status' => self::STATUS_RETURN_REQUESTED]);

        return $this;
    }

    public function markReturnShipped(string $trackingNumber, string $carrier = 'fedex'): self
    {
        $this->update([
            'return_tracking_number' => $trackingNumber,
            'return_carrier' => $carrier,
            'return_shipped_at' => now(),
        ]);

        return $this;
    }

    public function markItemsReturned(): self
    {
        $this->update([
            'status' => self::STATUS_ITEMS_RETURNED,
            'return_delivered_at' => now(),
        ]);

        return $this;
    }

    // Type helpers

    public function isInStore(): bool
    {
        return $this->type === self::TYPE_IN_STORE;
    }

    /**
     * @deprecated Use isInStore instead.
     */
    public function isInHouse(): bool
    {
        return $this->isInStore();
    }

    public function isMailIn(): bool
    {
        return $this->type === self::TYPE_MAIL_IN;
    }

    public function isOnline(): bool
    {
        return $this->isMailIn() || $this->source === self::SOURCE_ONLINE;
    }

    /**
     * Get available statuses for manual status change.
     *
     * @return array<string, string>
     */
    public static function getAvailableStatuses(): array
    {
        return [
            // Kit Request Phase (Online Only)
            self::STATUS_PENDING_KIT_REQUEST => 'Pending Kit Request',
            self::STATUS_KIT_REQUEST_CONFIRMED => 'Kit Request Confirmed',
            self::STATUS_KIT_REQUEST_REJECTED => 'Kit Request Rejected',
            self::STATUS_KIT_REQUEST_ON_HOLD => 'Kit Request On Hold',
            // Kit Shipping Phase (Online Only)
            self::STATUS_KIT_SENT => 'Kit Sent',
            self::STATUS_KIT_DELIVERED => 'Kit Delivered',
            // Items Phase
            self::STATUS_PENDING => 'Pending',
            self::STATUS_ITEMS_RECEIVED => 'Items Received',
            self::STATUS_ITEMS_REVIEWED => 'Items Reviewed',
            // Offer Phase
            self::STATUS_OFFER_GIVEN => 'Offer Given',
            self::STATUS_OFFER_ACCEPTED => 'Offer Accepted',
            self::STATUS_OFFER_DECLINED => 'Offer Declined',
            // Payment Phase
            self::STATUS_PAYMENT_PENDING => 'Payment Pending',
            self::STATUS_PAYMENT_PROCESSED => 'Payment Processed',
            // Return/Cancellation
            self::STATUS_RETURN_REQUESTED => 'Return Requested',
            self::STATUS_ITEMS_RETURNED => 'Items Returned',
            self::STATUS_CANCELLED => 'Cancelled',
        ];
    }

    /**
     * Get statuses available for online (mail-in) transactions.
     *
     * @return array<string, string>
     */
    public static function getOnlineStatuses(): array
    {
        return [
            self::STATUS_PENDING_KIT_REQUEST => 'Pending Kit Request',
            self::STATUS_KIT_REQUEST_CONFIRMED => 'Kit Request Confirmed',
            self::STATUS_KIT_REQUEST_REJECTED => 'Kit Request Rejected',
            self::STATUS_KIT_REQUEST_ON_HOLD => 'Kit Request On Hold',
            self::STATUS_KIT_SENT => 'Kit Sent',
            self::STATUS_KIT_DELIVERED => 'Kit Delivered',
            self::STATUS_ITEMS_RECEIVED => 'Items Received',
            self::STATUS_ITEMS_REVIEWED => 'Items Reviewed',
            self::STATUS_OFFER_GIVEN => 'Offer Given',
            self::STATUS_OFFER_ACCEPTED => 'Offer Accepted',
            self::STATUS_OFFER_DECLINED => 'Offer Declined',
            self::STATUS_PAYMENT_PENDING => 'Payment Pending',
            self::STATUS_PAYMENT_PROCESSED => 'Payment Processed',
            self::STATUS_RETURN_REQUESTED => 'Return Requested',
            self::STATUS_ITEMS_RETURNED => 'Items Returned',
            self::STATUS_CANCELLED => 'Cancelled',
        ];
    }

    /**
     * Get statuses available for in-store transactions.
     *
     * @return array<string, string>
     */
    public static function getInStoreStatuses(): array
    {
        return [
            self::STATUS_PENDING => 'Pending',
            self::STATUS_PAYMENT_PROCESSED => 'Payment Processed',
            self::STATUS_CANCELLED => 'Cancelled',
        ];
    }

    /**
     * @deprecated Use getInStoreStatuses instead.
     *
     * @return array<string, string>
     */
    public static function getInHouseStatuses(): array
    {
        return self::getInStoreStatuses();
    }

    /**
     * Check if the status can be changed to the given status.
     */
    public function canChangeStatusTo(string $status): bool
    {
        // Cannot change to current status
        if ($this->status === $status) {
            return false;
        }

        // Cannot change from payment_processed
        if ($this->isPaymentProcessed()) {
            return false;
        }

        return array_key_exists($status, self::getAvailableStatuses());
    }

    // Calculated attributes
    public function getItemCountAttribute(): int
    {
        return $this->items->count();
    }

    public function getTotalDwtAttribute(): float
    {
        return (float) $this->items->sum('dwt');
    }

    public function getTotalValueAttribute(): float
    {
        return (float) $this->items->sum('price');
    }

    public function getTotalBuyPriceAttribute(): float
    {
        return (float) $this->items->sum('buy_price');
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
            'transaction_number' => $this->transaction_number,
            'customer_name' => $this->customer?->full_name,
            'customer_email' => $this->customer?->email,
            'status' => $this->status,
            'type' => $this->type,
            'final_offer' => $this->final_offer,
            'bin_location' => $this->bin_location,
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
     * Get the formatted buy ID with store prefix/suffix.
     * Returns just the ID if no prefix/suffix is configured.
     */
    public function getFormattedBuyIdAttribute(): string
    {
        $prefix = $this->store?->buy_id_prefix ?? '';
        $suffix = $this->store?->buy_id_suffix ?? '';

        return $prefix.$this->id.$suffix;
    }

    /**
     * Get the activity prefix for this model.
     */
    protected function getActivityPrefix(): string
    {
        return 'transactions';
    }

    /**
     * Get the activity slug, detecting deletion of closed transactions.
     */
    protected function getActivitySlug(string $action): ?string
    {
        // Detect deletion of a closed transaction
        if ($action === 'delete') {
            $closedStatuses = [
                self::STATUS_PAYMENT_PROCESSED,
                self::STATUS_OFFER_ACCEPTED,
            ];

            // Check the original status (before deletion)
            $originalStatus = $this->getOriginal('status') ?? $this->status;

            if (in_array($originalStatus, $closedStatuses, true)) {
                return Activity::TRANSACTIONS_DELETE_CLOSED;
            }
        }

        $map = $this->getActivityMap();

        return $map[$action] ?? null;
    }

    /**
     * Get attributes that should be logged.
     *
     * @return array<int, string>
     */
    protected function getLoggableAttributes(): array
    {
        return ['id', 'transaction_number', 'status', 'type', 'customer_id', 'final_offer', 'payment_method'];
    }

    /**
     * Get the identifier for this model in activity descriptions.
     */
    protected function getActivityIdentifier(): string
    {
        return $this->transaction_number ?? "#{$this->id}";
    }
}
