<?php

namespace App\Traits;

use App\Models\Address;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

trait HasAddresses
{
    /**
     * Get all addresses for this model.
     */
    public function addresses(): MorphMany
    {
        return $this->morphMany(Address::class, 'addressable');
    }

    /**
     * Get the default address.
     */
    public function defaultAddress(): MorphOne
    {
        return $this->morphOne(Address::class, 'addressable')
            ->where('is_default', true)
            ->latest();
    }

    /**
     * Get the default shipping address.
     */
    public function defaultShippingAddress(): MorphOne
    {
        return $this->morphOne(Address::class, 'addressable')
            ->where('is_shipping', true)
            ->where('is_default', true)
            ->latest();
    }

    /**
     * Get the default billing address.
     */
    public function defaultBillingAddress(): MorphOne
    {
        return $this->morphOne(Address::class, 'addressable')
            ->where('is_billing', true)
            ->where('is_default', true)
            ->latest();
    }

    /**
     * Get all shipping addresses.
     */
    public function shippingAddresses(): MorphMany
    {
        return $this->morphMany(Address::class, 'addressable')
            ->where('is_shipping', true);
    }

    /**
     * Get all billing addresses.
     */
    public function billingAddresses(): MorphMany
    {
        return $this->morphMany(Address::class, 'addressable')
            ->where('is_billing', true);
    }

    /**
     * Add a new address.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function addAddress(array $attributes): Address
    {
        // If this is set as default, unset other defaults
        if (! empty($attributes['is_default'])) {
            $this->addresses()->update(['is_default' => false]);
        }

        // Ensure store_id is set
        if (method_exists($this, 'getAttribute') && $this->getAttribute('store_id')) {
            $attributes['store_id'] = $attributes['store_id'] ?? $this->getAttribute('store_id');
        }

        return $this->addresses()->create($attributes);
    }

    /**
     * Set an address as the default.
     */
    public function setDefaultAddress(Address $address): void
    {
        $this->addresses()->update(['is_default' => false]);
        $address->update(['is_default' => true]);
    }

    /**
     * Get the primary address to use for shipping.
     * Returns the default shipping address, or the first shipping address, or the default address.
     */
    public function getPrimaryShippingAddress(): ?Address
    {
        return $this->defaultShippingAddress
            ?? $this->shippingAddresses()->first()
            ?? $this->defaultAddress
            ?? $this->addresses()->first();
    }

    /**
     * Check if this model has any addresses.
     */
    public function hasAddresses(): bool
    {
        return $this->addresses()->exists();
    }

    /**
     * Check if this model has a valid shipping address.
     */
    public function hasValidShippingAddress(): bool
    {
        $address = $this->getPrimaryShippingAddress();

        return $address && $address->isValidForShipping();
    }
}
