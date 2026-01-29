<?php

namespace App\Services\Platforms;

use App\Enums\Platform;
use App\Services\Platforms\Amazon\AmazonService;
use App\Services\Platforms\Contracts\PlatformInterface;
use App\Services\Platforms\Ebay\EbayService;
use App\Services\Platforms\Etsy\EtsyService;
use App\Services\Platforms\Shopify\ShopifyService;
use App\Services\Platforms\Walmart\WalmartService;
use App\Services\Platforms\WooCommerce\WooCommerceService;
use InvalidArgumentException;

class PlatformManager
{
    /**
     * @var array<string, PlatformInterface>
     */
    protected array $platforms = [];

    public function __construct()
    {
        $this->registerPlatforms();
    }

    protected function registerPlatforms(): void
    {
        $this->platforms = [
            Platform::Shopify->value => app(ShopifyService::class),
            Platform::Ebay->value => app(EbayService::class),
            Platform::Amazon->value => app(AmazonService::class),
            Platform::Etsy->value => app(EtsyService::class),
            Platform::Walmart->value => app(WalmartService::class),
            Platform::WooCommerce->value => app(WooCommerceService::class),
        ];
    }

    public function driver(string|Platform $platform): PlatformInterface
    {
        $key = $platform instanceof Platform ? $platform->value : $platform;

        if (! isset($this->platforms[$key])) {
            throw new InvalidArgumentException("Platform [{$key}] is not supported.");
        }

        return $this->platforms[$key];
    }

    public function getAvailablePlatforms(): array
    {
        return Platform::cases();
    }

    public function isSupported(string $platform): bool
    {
        return isset($this->platforms[$platform]);
    }
}
