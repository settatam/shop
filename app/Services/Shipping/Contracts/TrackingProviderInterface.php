<?php

namespace App\Services\Shipping\Contracts;

use App\Models\Store;
use App\Services\Shipping\TrackingResult;

interface TrackingProviderInterface
{
    /**
     * Get the carrier code (e.g., 'fedex', 'ups', 'usps').
     */
    public function getCarrierCode(): string;

    /**
     * Get the carrier display name.
     */
    public function getCarrierName(): string;

    /**
     * Check if the provider is configured and ready to use.
     */
    public function isConfigured(): bool;

    /**
     * Track a shipment by tracking number.
     */
    public function track(string $trackingNumber): ?TrackingResult;

    /**
     * Track multiple shipments at once (batch tracking).
     *
     * @param  array<string>  $trackingNumbers
     * @return array<string, TrackingResult|null>
     */
    public function trackMultiple(array $trackingNumbers): array;

    /**
     * Check if this provider can handle the given tracking number format.
     * Used for auto-detecting carrier from tracking number.
     */
    public function canHandleTrackingNumber(string $trackingNumber): bool;

    /**
     * Create an instance of this provider for a specific store.
     */
    public static function forStore(Store $store): static;
}
