<?php

namespace App\Services\Shipping;

use App\Models\Store;
use App\Services\Shipping\Contracts\TrackingProviderInterface;
use App\Services\Shipping\Providers\FedExTrackingProvider;
use App\Services\Shipping\Providers\UpsTrackingProvider;
use App\Services\Shipping\Providers\UspsTrackingProvider;
use InvalidArgumentException;

class TrackingProviderFactory
{
    /**
     * Registered provider classes.
     *
     * @var array<string, class-string<TrackingProviderInterface>>
     */
    protected static array $providers = [
        'fedex' => FedExTrackingProvider::class,
        'ups' => UpsTrackingProvider::class,
        'usps' => UspsTrackingProvider::class,
    ];

    /**
     * Create a tracking provider for the given carrier.
     */
    public static function make(string $carrier, ?Store $store = null): TrackingProviderInterface
    {
        $carrier = strtolower($carrier);

        if (! isset(self::$providers[$carrier])) {
            throw new InvalidArgumentException("Unknown carrier: {$carrier}");
        }

        $providerClass = self::$providers[$carrier];

        return $store
            ? $providerClass::forStore($store)
            : new $providerClass;
    }

    /**
     * Create a tracking provider for a store by carrier code.
     */
    public static function forStore(Store $store, string $carrier): TrackingProviderInterface
    {
        return self::make($carrier, $store);
    }

    /**
     * Get all registered carrier codes.
     *
     * @return array<string>
     */
    public static function getCarriers(): array
    {
        return array_keys(self::$providers);
    }

    /**
     * Check if a carrier is supported.
     */
    public static function supports(string $carrier): bool
    {
        return isset(self::$providers[strtolower($carrier)]);
    }

    /**
     * Detect carrier from tracking number and return the appropriate provider.
     */
    public static function detectFromTrackingNumber(
        string $trackingNumber,
        ?Store $store = null
    ): ?TrackingProviderInterface {
        foreach (self::$providers as $carrier => $providerClass) {
            $provider = $store
                ? $providerClass::forStore($store)
                : new $providerClass;

            if ($provider->canHandleTrackingNumber($trackingNumber)) {
                return $provider;
            }
        }

        return null;
    }

    /**
     * Get all configured providers for a store.
     *
     * @return array<string, TrackingProviderInterface>
     */
    public static function getConfiguredProviders(?Store $store = null): array
    {
        $configured = [];

        foreach (self::$providers as $carrier => $providerClass) {
            $provider = $store
                ? $providerClass::forStore($store)
                : new $providerClass;

            if ($provider->isConfigured()) {
                $configured[$carrier] = $provider;
            }
        }

        return $configured;
    }

    /**
     * Register a custom provider.
     *
     * @param  class-string<TrackingProviderInterface>  $providerClass
     */
    public static function register(string $carrier, string $providerClass): void
    {
        self::$providers[strtolower($carrier)] = $providerClass;
    }
}
