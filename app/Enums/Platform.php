<?php

namespace App\Enums;

enum Platform: string
{
    case Shopify = 'shopify';
    case Ebay = 'ebay';
    case Amazon = 'amazon';
    case Etsy = 'etsy';
    case Walmart = 'walmart';
    case WooCommerce = 'woocommerce';
    case BigCommerce = 'bigcommerce';
    case Paperform = 'paperform';

    public function label(): string
    {
        return match ($this) {
            self::Shopify => 'Shopify',
            self::Ebay => 'eBay',
            self::Amazon => 'Amazon',
            self::Etsy => 'Etsy',
            self::Walmart => 'Walmart',
            self::WooCommerce => 'WooCommerce',
            self::BigCommerce => 'BigCommerce',
            self::Paperform => 'Paperform',
        };
    }

    public function requiresOAuth(): bool
    {
        return match ($this) {
            self::Shopify, self::Ebay, self::Amazon, self::Etsy, self::BigCommerce => true,
            self::Walmart, self::WooCommerce, self::Paperform => false,
        };
    }

    public function isMarketplace(): bool
    {
        return match ($this) {
            self::Amazon, self::Ebay, self::Etsy, self::Walmart => true,
            self::Shopify, self::WooCommerce, self::BigCommerce, self::Paperform => false,
        };
    }

    public function isEcommercePlatform(): bool
    {
        return match ($this) {
            self::Shopify, self::WooCommerce, self::BigCommerce => true,
            self::Amazon, self::Ebay, self::Etsy, self::Walmart, self::Paperform => false,
        };
    }

    public function supportsInventorySync(): bool
    {
        return match ($this) {
            self::Paperform => false,
            default => true,
        };
    }

    public function supportsOrderSync(): bool
    {
        return match ($this) {
            self::Paperform => false,
            default => true,
        };
    }

    /**
     * @return string[]
     */
    public static function marketplaces(): array
    {
        return [
            self::Amazon->value,
            self::Ebay->value,
            self::Etsy->value,
            self::Walmart->value,
        ];
    }

    /**
     * @return string[]
     */
    public static function ecommercePlatforms(): array
    {
        return [
            self::Shopify->value,
            self::WooCommerce->value,
            self::BigCommerce->value,
        ];
    }
}
