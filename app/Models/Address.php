<?php

namespace App\Models;

use App\Jobs\SyncCustomerToLegacyJob;
use App\Traits\BelongsToStore;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Address extends Model
{
    use BelongsToStore, HasFactory, SoftDeletes;

    protected static function booted(): void
    {
        static::saved(function (Address $address) {
            if ($address->addressable_type === Customer::class && $address->addressable) {
                SyncCustomerToLegacyJob::dispatch($address->addressable);
            }
        });
    }

    public const TYPE_HOME = 'home';

    public const TYPE_WORK = 'work';

    public const TYPE_SHIPPING = 'shipping';

    public const TYPE_BILLING = 'billing';

    public const TYPE_OTHER = 'other';

    protected $fillable = [
        'store_id',
        'addressable_type',
        'addressable_id',
        'first_name',
        'last_name',
        'company',
        'nickname',
        'address',
        'address2',
        'city',
        'state_id',
        'country_id',
        'zip',
        'phone',
        'extension',
        'is_default',
        'is_shipping',
        'is_billing',
        'is_verified',
        'type',
        'latitude',
        'longitude',
        'location_type',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_shipping' => 'boolean',
            'is_billing' => 'boolean',
            'is_verified' => 'boolean',
            'latitude' => 'decimal:8',
            'longitude' => 'decimal:8',
        ];
    }

    protected $appends = [
        'full_name',
        'formatted_address',
        'one_line_address',
    ];

    /**
     * Get the parent addressable model.
     */
    public function addressable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get the state for this address.
     */
    public function state(): BelongsTo
    {
        return $this->belongsTo(State::class);
    }

    /**
     * Get the state abbreviation.
     */
    public function getStateAbbreviationAttribute(): ?string
    {
        return $this->state?->abbreviation;
    }

    /**
     * Get the full name for the address.
     */
    public function getFullNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    /**
     * Get a formatted multi-line address.
     */
    public function getFormattedAddressAttribute(): string
    {
        $lines = array_filter([
            $this->address,
            $this->address2,
            $this->getCityStateZipLine(),
        ]);

        return implode("\n", $lines);
    }

    /**
     * Get a one-line address representation.
     */
    public function getOneLineAddressAttribute(): string
    {
        $parts = array_filter([
            $this->address,
            $this->address2,
            $this->city,
            $this->state?->abbreviation,
            $this->zip,
        ]);

        return implode(', ', $parts);
    }

    /**
     * Get city, state, zip line.
     */
    protected function getCityStateZipLine(): ?string
    {
        $parts = array_filter([
            $this->city,
            $this->state?->abbreviation,
            $this->zip,
        ]);

        return $parts ? implode(', ', $parts) : null;
    }

    /**
     * Format the address for shipping label creation.
     *
     * @return array<string, mixed>
     */
    public function toShippingFormat(): array
    {
        return [
            'name' => $this->full_name ?: $this->company,
            'company' => $this->company ?? '',
            'street' => $this->address ?? '',
            'street2' => $this->address2,
            'city' => $this->city ?? '',
            'state' => $this->state?->abbreviation ?? '',
            'postal_code' => $this->zip ?? '',
            'country' => 'US', // Default to US, can be expanded later
            'phone' => $this->phone ?? '',
        ];
    }

    /**
     * Check if address has minimum required fields for shipping.
     */
    public function isValidForShipping(): bool
    {
        return ! empty($this->address)
            && ! empty($this->city)
            && ! empty($this->zip)
            && (! empty($this->full_name) || ! empty($this->company));
    }

    /**
     * Get available address types.
     *
     * @return array<string, string>
     */
    public static function getTypes(): array
    {
        return [
            self::TYPE_HOME => 'Home',
            self::TYPE_WORK => 'Work',
            self::TYPE_SHIPPING => 'Shipping',
            self::TYPE_BILLING => 'Billing',
            self::TYPE_OTHER => 'Other',
        ];
    }
}
