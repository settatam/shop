<?php

namespace App\Models;

use App\Traits\BelongsToStore;
use App\Traits\HasCustomStatuses;
use App\Traits\HasNotes;
use App\Traits\HasTags;
use App\Traits\LogsActivity;
use App\Traits\TracksStatusChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Scout\Searchable;

class Lead extends Model
{
    use BelongsToStore, HasCustomStatuses, HasFactory, HasNotes, HasTags, LogsActivity, Searchable, SoftDeletes, TracksStatusChanges;

    // Status constants - Kit Request Phase
    public const STATUS_PENDING_KIT_REQUEST = 'pending_kit_request';

    public const STATUS_KIT_REQUEST_CONFIRMED = 'kit_request_confirmed';

    public const STATUS_KIT_REQUEST_REJECTED = 'kit_request_rejected';

    public const STATUS_KIT_REQUEST_ON_HOLD = 'kit_request_on_hold';

    // Status constants - Kit Shipping Phase
    public const STATUS_KIT_SENT = 'kit_sent';

    public const STATUS_KIT_DELIVERED = 'kit_delivered';

    // Status constants - Items Phase
    public const STATUS_ITEMS_RECEIVED = 'items_received';

    public const STATUS_ITEMS_REVIEWED = 'items_reviewed';

    // Status constants - Offer Phase
    public const STATUS_OFFER_GIVEN = 'offer_given';

    public const STATUS_OFFER_ACCEPTED = 'offer_accepted';

    public const STATUS_CUSTOMER_DECLINED_OFFER = 'customer_declined_offer';

    // Status constants - Payment Phase
    public const STATUS_PAYMENT_PROCESSED = 'payment_processed';

    // Status constants - Return/Cancellation
    public const STATUS_ITEMS_RETURNED = 'items_returned';

    public const STATUS_CANCELLED = 'cancelled';

    // Type constants
    public const TYPE_IN_STORE = 'in_store';

    public const TYPE_MAIL_IN = 'mail_in';

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
        'customer_id',
        'shipping_address_id',
        'user_id',
        'assigned_to',
        'transaction_id',
        'lead_number',
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
        'legacy_id',
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
        static::creating(function (Lead $lead) {
            if (empty($lead->lead_number)) {
                $lead->lead_number = 'LEAD-TEMP';
            }
        });

        static::created(function (Lead $lead) {
            if ($lead->lead_number === 'LEAD-TEMP') {
                $store = $lead->store;
                $prefix = $store?->lead_id_prefix ?? 'LEAD';
                $suffix = $store?->lead_id_suffix ?? '';
                $lead->lead_number = "{$prefix}-{$lead->id}{$suffix}";
                $lead->saveQuietly();
                $lead->searchable();
            }
        });
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function shippingAddress(): BelongsTo
    {
        return $this->belongsTo(Address::class, 'shipping_address_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
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
        return $this->hasMany(LeadItem::class);
    }

    /**
     * @return HasMany<TransactionOffer, Lead>
     */
    public function offers(): HasMany
    {
        return $this->hasMany(TransactionOffer::class, 'transaction_id', 'transaction_id')->orderByDesc('created_at');
    }

    /**
     * @return MorphMany<Image, Lead>
     */
    public function images(): MorphMany
    {
        return $this->morphMany(Image::class, 'imageable');
    }

    /**
     * @return MorphMany<ShippingLabel, Lead>
     */
    public function shippingLabels(): MorphMany
    {
        return $this->morphMany(ShippingLabel::class, 'shippable');
    }

    /**
     * @return MorphOne<ShippingLabel, Lead>
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
     * @return MorphOne<ShippingLabel, Lead>
     */
    public function returnLabel(): MorphOne
    {
        return $this->morphOne(ShippingLabel::class, 'shippable')
            ->ofMany(
                ['id' => 'max'],
                fn ($query) => $query->where('type', ShippingLabel::TYPE_RETURN)
            );
    }

    // Status scopes
    public function scopePendingKitRequest($query)
    {
        return $query->where('status', self::STATUS_PENDING_KIT_REQUEST);
    }

    public function scopeItemsReceived($query)
    {
        return $query->where('status', self::STATUS_ITEMS_RECEIVED);
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
    public function scopeMailIn($query)
    {
        return $query->where('type', self::TYPE_MAIL_IN);
    }

    // Status helpers
    public function isPendingKitRequest(): bool
    {
        return $this->status === self::STATUS_PENDING_KIT_REQUEST;
    }

    public function isItemsReceived(): bool
    {
        return $this->status === self::STATUS_ITEMS_RECEIVED;
    }

    public function isOfferGiven(): bool
    {
        return $this->status === self::STATUS_OFFER_GIVEN;
    }

    public function isOfferAccepted(): bool
    {
        return $this->status === self::STATUS_OFFER_ACCEPTED;
    }

    public function isCustomerDeclinedOffer(): bool
    {
        return $this->status === self::STATUS_CUSTOMER_DECLINED_OFFER;
    }

    public function isPaymentProcessed(): bool
    {
        return $this->status === self::STATUS_PAYMENT_PROCESSED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function isConverted(): bool
    {
        return $this->transaction_id !== null;
    }

    public function canSubmitOffer(): bool
    {
        return in_array($this->status, [
            self::STATUS_ITEMS_RECEIVED,
            self::STATUS_ITEMS_REVIEWED,
            self::STATUS_CUSTOMER_DECLINED_OFFER,
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
            self::STATUS_PENDING_KIT_REQUEST,
            self::STATUS_KIT_REQUEST_CONFIRMED,
            self::STATUS_KIT_REQUEST_ON_HOLD,
            self::STATUS_KIT_SENT,
            self::STATUS_OFFER_GIVEN,
            self::STATUS_CUSTOMER_DECLINED_OFFER,
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
            'status' => self::STATUS_CUSTOMER_DECLINED_OFFER,
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

    public function markItemsReturned(): self
    {
        $this->update([
            'status' => self::STATUS_ITEMS_RETURNED,
            'return_delivered_at' => now(),
        ]);

        return $this;
    }

    /**
     * Get available statuses for manual status change.
     *
     * @return array<string, string>
     */
    public static function getAvailableStatuses(): array
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
            self::STATUS_CUSTOMER_DECLINED_OFFER => 'Customer Declined Offer',
            self::STATUS_PAYMENT_PROCESSED => 'Payment Processed',
            self::STATUS_ITEMS_RETURNED => 'Items Returned',
            self::STATUS_CANCELLED => 'Cancelled',
        ];
    }

    public function canChangeStatusTo(string $status): bool
    {
        if ($this->status === $status) {
            return false;
        }

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
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'lead_number' => $this->lead_number,
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

    public function shouldBeSearchable(): bool
    {
        return ! $this->trashed();
    }

    protected function getActivityPrefix(): string
    {
        return 'leads';
    }

    /**
     * @return array<int, string>
     */
    protected function getLoggableAttributes(): array
    {
        return ['id', 'lead_number', 'status', 'type', 'customer_id', 'final_offer', 'payment_method'];
    }

    protected function getActivityIdentifier(): string
    {
        return $this->lead_number ?? "#{$this->id}";
    }
}
