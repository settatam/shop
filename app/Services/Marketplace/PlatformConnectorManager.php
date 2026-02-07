<?php

namespace App\Services\Marketplace;

use App\Enums\Platform;
use App\Models\StoreMarketplace;
use App\Services\Marketplace\Connectors\AmazonConnector;
use App\Services\Marketplace\Connectors\BigCommerceConnector;
use App\Services\Marketplace\Connectors\ShopifyConnector;
use App\Services\Marketplace\Connectors\WalmartConnector;
use App\Services\Marketplace\Contracts\PlatformConnectorInterface;
use InvalidArgumentException;

class PlatformConnectorManager
{
    /**
     * @var array<string, class-string<PlatformConnectorInterface>>
     */
    protected array $connectors = [
        'shopify' => ShopifyConnector::class,
        'amazon' => AmazonConnector::class,
        'walmart' => WalmartConnector::class,
        'bigcommerce' => BigCommerceConnector::class,
    ];

    /**
     * Get a connector for a specific platform.
     */
    public function getConnector(Platform $platform): PlatformConnectorInterface
    {
        $connectorClass = $this->connectors[$platform->value] ?? null;

        if (! $connectorClass) {
            throw new InvalidArgumentException("No connector available for platform: {$platform->value}");
        }

        return app($connectorClass);
    }

    /**
     * Get an initialized connector for a store marketplace.
     */
    public function getConnectorForMarketplace(StoreMarketplace $marketplace): PlatformConnectorInterface
    {
        $connector = $this->getConnector($marketplace->platform);

        return $connector->initialize($marketplace);
    }

    /**
     * Check if a connector is available for a platform.
     */
    public function hasConnector(Platform $platform): bool
    {
        return isset($this->connectors[$platform->value]);
    }

    /**
     * Get all available platforms with connectors.
     *
     * @return Platform[]
     */
    public function getAvailablePlatforms(): array
    {
        return array_map(
            fn ($slug) => Platform::from($slug),
            array_keys($this->connectors)
        );
    }

    /**
     * Register a custom connector for a platform.
     *
     * @param  class-string<PlatformConnectorInterface>  $connectorClass
     */
    public function registerConnector(Platform $platform, string $connectorClass): void
    {
        $this->connectors[$platform->value] = $connectorClass;
    }

    /**
     * Test connection for a marketplace.
     */
    public function testConnection(StoreMarketplace $marketplace): bool
    {
        try {
            $connector = $this->getConnectorForMarketplace($marketplace);

            return $connector->testConnection();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Sync products from a marketplace to local database.
     *
     * @return array{synced: int, errors: int, products: array}
     */
    public function syncProducts(StoreMarketplace $marketplace, int $limit = 250): array
    {
        $connector = $this->getConnectorForMarketplace($marketplace);
        $products = $connector->getProducts($limit);

        $synced = 0;
        $errors = 0;
        $results = [];

        foreach ($products as $product) {
            try {
                // Update or create platform listing
                $listing = $marketplace->listings()->updateOrCreate(
                    ['external_listing_id' => $product->externalId],
                    [
                        'platform_price' => $product->price,
                        'platform_quantity' => $product->quantity,
                        'platform_data' => $product->toArray(),
                        'status' => $product->status === 'active' ? 'active' : 'inactive',
                        'last_synced_at' => now(),
                    ]
                );

                $results[] = [
                    'external_id' => $product->externalId,
                    'title' => $product->title,
                    'status' => 'synced',
                ];
                $synced++;
            } catch (\Throwable $e) {
                $results[] = [
                    'external_id' => $product->externalId,
                    'title' => $product->title,
                    'status' => 'error',
                    'error' => $e->getMessage(),
                ];
                $errors++;
            }
        }

        $marketplace->recordSync();

        return [
            'synced' => $synced,
            'errors' => $errors,
            'products' => $results,
        ];
    }

    /**
     * Sync orders from a marketplace to local database.
     *
     * @return array{synced: int, errors: int, orders: array}
     */
    public function syncOrders(StoreMarketplace $marketplace, ?\DateTimeInterface $since = null): array
    {
        $connector = $this->getConnectorForMarketplace($marketplace);
        $orders = $connector->getOrders($since);

        $synced = 0;
        $errors = 0;
        $results = [];

        foreach ($orders as $order) {
            try {
                // Update or create platform order
                $platformOrder = $marketplace->platformOrders()->updateOrCreate(
                    ['external_order_id' => $order->externalId],
                    [
                        'external_order_number' => $order->orderNumber,
                        'status' => $order->status,
                        'fulfillment_status' => $order->fulfillmentStatus,
                        'payment_status' => $order->paymentStatus,
                        'total' => $order->total,
                        'subtotal' => $order->subtotal,
                        'shipping_cost' => $order->shippingCost,
                        'tax' => $order->tax,
                        'discount' => $order->discount,
                        'currency' => $order->currency,
                        'customer_data' => $order->customer,
                        'shipping_address' => $order->shippingAddress,
                        'billing_address' => $order->billingAddress,
                        'line_items' => $order->lineItems,
                        'platform_data' => $order->metadata,
                        'ordered_at' => $order->orderedAt,
                        'last_synced_at' => now(),
                    ]
                );

                $results[] = [
                    'external_id' => $order->externalId,
                    'order_number' => $order->orderNumber,
                    'status' => 'synced',
                ];
                $synced++;
            } catch (\Throwable $e) {
                $results[] = [
                    'external_id' => $order->externalId,
                    'order_number' => $order->orderNumber,
                    'status' => 'error',
                    'error' => $e->getMessage(),
                ];
                $errors++;
            }
        }

        $marketplace->recordSync();

        return [
            'synced' => $synced,
            'errors' => $errors,
            'orders' => $results,
        ];
    }
}
