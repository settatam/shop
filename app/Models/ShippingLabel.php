<?php

namespace App\Models;

use App\Traits\BelongsToStore;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ShippingLabel extends Model
{
    /** @use HasFactory<\Database\Factories\ShippingLabelFactory> */
    use BelongsToStore, HasFactory, SoftDeletes;

    // Type constants
    public const TYPE_OUTBOUND = 'outbound';

    public const TYPE_RETURN = 'return';

    // Status constants
    public const STATUS_CREATED = 'created';

    public const STATUS_VOIDED = 'voided';

    public const STATUS_IN_TRANSIT = 'in_transit';

    public const STATUS_DELIVERED = 'delivered';

    // Carrier constants
    public const CARRIER_FEDEX = 'fedex';

    public const CARRIER_UPS = 'ups';

    public const CARRIER_USPS = 'usps';

    public const CARRIER_DHL = 'dhl';

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'store_id',
        'shippable_type',
        'shippable_id',
        'type',
        'carrier',
        'tracking_number',
        'service_type',
        'label_format',
        'label_path',
        'label_zpl',
        'shipment_details',
        'sender_address',
        'recipient_address',
        'shipping_cost',
        'status',
        'fedex_shipment_id',
        'shipped_at',
        'delivered_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'shipment_details' => 'array',
            'sender_address' => 'array',
            'recipient_address' => 'array',
            'shipping_cost' => 'decimal:2',
            'shipped_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    /**
     * @return MorphTo<Model, ShippingLabel>
     */
    public function shippable(): MorphTo
    {
        return $this->morphTo();
    }

    public function isOutbound(): bool
    {
        return $this->type === self::TYPE_OUTBOUND;
    }

    public function isReturn(): bool
    {
        return $this->type === self::TYPE_RETURN;
    }

    public function isCreated(): bool
    {
        return $this->status === self::STATUS_CREATED;
    }

    public function isVoided(): bool
    {
        return $this->status === self::STATUS_VOIDED;
    }

    public function isInTransit(): bool
    {
        return $this->status === self::STATUS_IN_TRANSIT;
    }

    public function isDelivered(): bool
    {
        return $this->status === self::STATUS_DELIVERED;
    }

    public function getTrackingUrl(): ?string
    {
        if (! $this->tracking_number) {
            return null;
        }

        return match ($this->carrier) {
            self::CARRIER_FEDEX => "https://www.fedex.com/fedextrack/?trknbr={$this->tracking_number}",
            self::CARRIER_UPS => "https://www.ups.com/track?tracknum={$this->tracking_number}",
            self::CARRIER_USPS => "https://tools.usps.com/go/TrackConfirmAction?tLabels={$this->tracking_number}",
            self::CARRIER_DHL => "https://www.dhl.com/us-en/home/tracking/tracking-express.html?submit=1&tracking-id={$this->tracking_number}",
            default => null,
        };
    }

    /**
     * Get all available types.
     *
     * @return array<string, string>
     */
    public static function getTypes(): array
    {
        return [
            self::TYPE_OUTBOUND => 'Outbound',
            self::TYPE_RETURN => 'Return',
        ];
    }

    /**
     * Get all available statuses.
     *
     * @return array<string, string>
     */
    public static function getStatuses(): array
    {
        return [
            self::STATUS_CREATED => 'Created',
            self::STATUS_VOIDED => 'Voided',
            self::STATUS_IN_TRANSIT => 'In Transit',
            self::STATUS_DELIVERED => 'Delivered',
        ];
    }

    /**
     * Get all available carriers.
     *
     * @return array<string, string>
     */
    public static function getCarriers(): array
    {
        return [
            self::CARRIER_FEDEX => 'FedEx',
            self::CARRIER_UPS => 'UPS',
            self::CARRIER_USPS => 'USPS',
            self::CARRIER_DHL => 'DHL',
        ];
    }
}
