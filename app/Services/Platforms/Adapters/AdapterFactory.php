<?php

namespace App\Services\Platforms\Adapters;

use App\Contracts\Platforms\PlatformAdapterContract;
use App\Enums\Platform;
use App\Models\SalesChannel;
use InvalidArgumentException;

class AdapterFactory
{
    /**
     * Map of platform types to adapter classes.
     *
     * @var array<string, class-string<PlatformAdapterContract>>
     */
    protected static array $adapters = [
        'shopify' => ShopifyAdapter::class,
        'ebay' => EbayAdapter::class,
        'amazon' => AmazonAdapter::class,
        'etsy' => EtsyAdapter::class,
        'woocommerce' => WooCommerceAdapter::class,
        'bigcommerce' => BigCommerceAdapter::class,
        'walmart' => WalmartAdapter::class,
        'local' => LocalAdapter::class,
        'pos' => LocalAdapter::class,
    ];

    /**
     * Create an adapter for the given sales channel.
     */
    public static function make(SalesChannel $channel): PlatformAdapterContract
    {
        $type = self::resolveType($channel);

        if (! isset(self::$adapters[$type])) {
            throw new InvalidArgumentException("No adapter found for platform type: {$type}");
        }

        $adapterClass = self::$adapters[$type];

        return new $adapterClass($channel);
    }

    /**
     * Resolve the platform type from a sales channel.
     */
    protected static function resolveType(SalesChannel $channel): string
    {
        // If it's a local/POS channel
        if ($channel->isLocal()) {
            return 'local';
        }

        // If it has a marketplace connection, use that platform
        if ($channel->storeMarketplace) {
            return $channel->storeMarketplace->platform->value ?? $channel->type;
        }

        // Fall back to channel type
        return $channel->type;
    }

    /**
     * Register a custom adapter.
     */
    public static function register(string $type, string $adapterClass): void
    {
        if (! is_subclass_of($adapterClass, PlatformAdapterContract::class)) {
            throw new InvalidArgumentException(
                'Adapter class must implement PlatformAdapterContract'
            );
        }

        self::$adapters[$type] = $adapterClass;
    }

    /**
     * Check if an adapter exists for the given type.
     */
    public static function has(string $type): bool
    {
        return isset(self::$adapters[$type]);
    }
}
