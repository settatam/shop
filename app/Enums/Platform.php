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
            self::Paperform => 'Paperform',
        };
    }

    public function requiresOAuth(): bool
    {
        return match ($this) {
            self::Shopify, self::Ebay, self::Amazon, self::Etsy => true,
            self::Walmart, self::WooCommerce, self::Paperform => false,
        };
    }
}
