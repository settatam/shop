<?php

namespace App\Services\Webhooks;

use App\Enums\Platform;
use App\Jobs\SyncExternalOrderStatusJob;
use App\Jobs\SyncOrderReturnsJob;
use App\Models\Customer;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PlatformOrder;
use App\Models\ProductVariant;
use App\Models\SalesChannel;
use App\Models\Store;
use App\Models\StoreMarketplace;
use App\Notifications\InventoryOversoldNotification;
use App\Services\Marketplace\DTOs\PlatformOrder as PlatformOrderDto;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class OrderImportService
{
    public function importFromPlatformOrder(PlatformOrder $platformOrder): Order
    {
        if ($platformOrder->isImported()) {
            return $platformOrder->order;
        }

        $marketplace = $platformOrder->marketplace;
        $store = $marketplace->store;

        $order = DB::transaction(function () use ($platformOrder, $marketplace, $store) {
            $order = $this->createOrder($platformOrder, $store, $marketplace->platform);
            $this->createOrderItems($order, $platformOrder);
            $this->handlePayment($order, $platformOrder);

            $platformOrder->update([
                'order_id' => $order->id,
                'last_synced_at' => now(),
            ]);

            return $order->fresh(['items', 'customer', 'payments']);
        });

        SyncExternalOrderStatusJob::dispatch($platformOrder)->delay(now()->addSeconds(60));

        if (in_array($platformOrder->payment_status, ['refunded', 'partially_refunded'])) {
            SyncOrderReturnsJob::dispatch($platformOrder)->delay(now()->addSeconds(90));
        }

        return $order;
    }

    public function importFromWebhookPayload(
        array $payload,
        StoreMarketplace $connection,
        Platform $platform
    ): Order {
        $normalizedData = $this->normalizePayload($payload, $platform);

        $platformOrder = null;

        $order = DB::transaction(function () use ($normalizedData, $connection, $platform, &$platformOrder) {
            $store = $connection->store;

            $platformOrder = $this->findOrCreatePlatformOrder(
                $normalizedData,
                $connection
            );

            if ($platformOrder->isImported()) {
                $this->updateExistingOrder($platformOrder, $normalizedData);

                return $platformOrder->order->fresh(['items', 'customer', 'payments']);
            }

            $order = $this->createOrder($platformOrder, $store, $platform);
            $this->createOrderItems($order, $platformOrder);
            $this->handlePayment($order, $platformOrder);

            $platformOrder->update([
                'order_id' => $order->id,
                'last_synced_at' => now(),
            ]);

            return $order->fresh(['items', 'customer', 'payments']);
        });

        if ($platformOrder) {
            SyncExternalOrderStatusJob::dispatch($platformOrder)->delay(now()->addSeconds(60));

            if (in_array($platformOrder->payment_status, ['refunded', 'partially_refunded'])) {
                SyncOrderReturnsJob::dispatch($platformOrder)->delay(now()->addSeconds(90));
            }
        }

        return $order;
    }

    protected function normalizePayload(array $payload, Platform $platform): array
    {
        return match ($platform) {
            Platform::Shopify => $this->normalizeShopifyOrder($payload),
            Platform::Ebay => $this->normalizeEbayOrder($payload),
            Platform::Amazon => $this->normalizeAmazonOrder($payload),
            Platform::Etsy => $this->normalizeEtsyOrder($payload),
            Platform::Walmart => $this->normalizeWalmartOrder($payload),
            Platform::WooCommerce => $this->normalizeWooCommerceOrder($payload),
        };
    }

    protected function normalizeShopifyOrder(array $payload): array
    {
        $order = $payload['order'] ?? $payload;

        return [
            'external_order_id' => (string) $order['id'],
            'external_order_number' => $order['order_number'] ?? $order['name'] ?? null,
            'status' => $this->mapShopifyStatus($order['financial_status'] ?? 'pending'),
            'fulfillment_status' => $order['fulfillment_status'] ?? 'unfulfilled',
            'payment_status' => $order['financial_status'] ?? 'pending',
            'total' => (float) ($order['total_price'] ?? 0),
            'subtotal' => (float) ($order['subtotal_price'] ?? 0),
            'shipping_cost' => (float) ($order['total_shipping_price_set']['shop_money']['amount'] ?? 0),
            'tax' => (float) ($order['total_tax'] ?? 0),
            'discount' => (float) ($order['total_discounts'] ?? 0),
            'currency' => $order['currency'] ?? 'USD',
            'customer_data' => $this->extractShopifyCustomer($order),
            'shipping_address' => $this->normalizeShopifyAddress($order['shipping_address'] ?? null),
            'billing_address' => $this->normalizeShopifyAddress($order['billing_address'] ?? null),
            'line_items' => $this->normalizeShopifyLineItems($order['line_items'] ?? []),
            'ordered_at' => $order['created_at'] ?? now()->toIso8601String(),
            'platform_data' => $order,
        ];
    }

    protected function extractShopifyCustomer(array $order): array
    {
        $customer = $order['customer'] ?? [];

        return [
            'email' => $customer['email'] ?? $order['email'] ?? null,
            'first_name' => $customer['first_name'] ?? null,
            'last_name' => $customer['last_name'] ?? null,
            'phone' => $customer['phone'] ?? $order['phone'] ?? null,
            'external_id' => isset($customer['id']) ? (string) $customer['id'] : null,
        ];
    }

    protected function normalizeShopifyAddress(?array $address): ?array
    {
        if (! $address) {
            return null;
        }

        return [
            'first_name' => $address['first_name'] ?? null,
            'last_name' => $address['last_name'] ?? null,
            'address_line1' => $address['address1'] ?? null,
            'address_line2' => $address['address2'] ?? null,
            'city' => $address['city'] ?? null,
            'state' => $address['province_code'] ?? $address['province'] ?? null,
            'postal_code' => $address['zip'] ?? null,
            'country' => $address['country_code'] ?? null,
            'phone' => $address['phone'] ?? null,
        ];
    }

    protected function normalizeShopifyLineItems(array $items): array
    {
        return array_map(function ($item) {
            return [
                'external_id' => (string) $item['id'],
                'sku' => $item['sku'] ?? null,
                'title' => $item['title'] ?? $item['name'] ?? 'Unknown Item',
                'quantity' => (int) ($item['quantity'] ?? 1),
                'price' => (float) ($item['price'] ?? 0),
                'discount' => (float) ($item['total_discount'] ?? 0),
                'tax' => array_sum(array_column($item['tax_lines'] ?? [], 'price')),
                'variant_id' => $item['variant_id'] ?? null,
                'product_id' => $item['product_id'] ?? null,
            ];
        }, $items);
    }

    protected function mapShopifyStatus(string $status): string
    {
        return match ($status) {
            'paid' => Order::STATUS_CONFIRMED,
            'pending' => Order::STATUS_PENDING,
            'refunded' => Order::STATUS_REFUNDED,
            'partially_refunded' => Order::STATUS_CONFIRMED,
            'voided' => Order::STATUS_CANCELLED,
            default => Order::STATUS_PENDING,
        };
    }

    protected function normalizeEbayOrder(array $payload): array
    {
        $order = $payload['order'] ?? $payload;

        return [
            'external_order_id' => $order['orderId'] ?? (string) ($order['OrderID'] ?? ''),
            'external_order_number' => $order['orderId'] ?? $order['OrderID'] ?? null,
            'status' => $this->mapEbayStatus($order['orderFulfillmentStatus'] ?? $order['OrderStatus'] ?? 'Active'),
            'fulfillment_status' => $order['orderFulfillmentStatus'] ?? 'NOT_STARTED',
            'payment_status' => $order['orderPaymentStatus'] ?? 'PENDING',
            'total' => (float) ($order['pricingSummary']['total']['value'] ?? $order['Total'] ?? 0),
            'subtotal' => (float) ($order['pricingSummary']['priceSubtotal']['value'] ?? $order['Subtotal'] ?? 0),
            'shipping_cost' => (float) ($order['pricingSummary']['deliveryCost']['value'] ?? $order['ShippingCost'] ?? 0),
            'tax' => (float) ($order['pricingSummary']['tax']['value'] ?? $order['Tax'] ?? 0),
            'discount' => (float) ($order['pricingSummary']['priceDiscount']['value'] ?? 0),
            'currency' => $order['pricingSummary']['total']['currency'] ?? 'USD',
            'customer_data' => $this->extractEbayCustomer($order),
            'shipping_address' => $this->normalizeEbayAddress($order['fulfillmentStartInstructions'][0]['shippingStep']['shipTo'] ?? $order['ShippingAddress'] ?? null),
            'billing_address' => null,
            'line_items' => $this->normalizeEbayLineItems($order['lineItems'] ?? $order['TransactionArray']['Transaction'] ?? []),
            'ordered_at' => $order['creationDate'] ?? $order['CreatedTime'] ?? now()->toIso8601String(),
            'platform_data' => $order,
        ];
    }

    protected function extractEbayCustomer(array $order): array
    {
        $buyer = $order['buyer'] ?? [];

        return [
            'email' => $buyer['email'] ?? $order['BuyerUserID'] ?? null,
            'first_name' => null,
            'last_name' => $buyer['username'] ?? $order['BuyerUserID'] ?? null,
            'phone' => null,
            'external_id' => $buyer['username'] ?? $order['BuyerUserID'] ?? null,
        ];
    }

    protected function normalizeEbayAddress(?array $address): ?array
    {
        if (! $address) {
            return null;
        }

        return [
            'first_name' => $address['fullName'] ?? $address['Name'] ?? null,
            'last_name' => null,
            'address_line1' => $address['addressLine1'] ?? $address['Street1'] ?? null,
            'address_line2' => $address['addressLine2'] ?? $address['Street2'] ?? null,
            'city' => $address['city'] ?? $address['CityName'] ?? null,
            'state' => $address['stateOrProvince'] ?? $address['StateOrProvince'] ?? null,
            'postal_code' => $address['postalCode'] ?? $address['PostalCode'] ?? null,
            'country' => $address['countryCode'] ?? $address['Country'] ?? null,
            'phone' => $address['phoneNumber'] ?? $address['Phone'] ?? null,
        ];
    }

    protected function normalizeEbayLineItems(array $items): array
    {
        return array_map(function ($item) {
            return [
                'external_id' => $item['lineItemId'] ?? $item['TransactionID'] ?? null,
                'sku' => $item['sku'] ?? $item['Item']['SKU'] ?? null,
                'title' => $item['title'] ?? $item['Item']['Title'] ?? 'Unknown Item',
                'quantity' => (int) ($item['quantity'] ?? $item['QuantityPurchased'] ?? 1),
                'price' => (float) ($item['lineItemCost']['value'] ?? $item['TransactionPrice'] ?? 0),
                'discount' => 0,
                'tax' => 0,
                'variant_id' => null,
                'product_id' => $item['legacyItemId'] ?? $item['Item']['ItemID'] ?? null,
            ];
        }, $items);
    }

    protected function mapEbayStatus(string $status): string
    {
        return match (strtoupper($status)) {
            'FULFILLED', 'COMPLETED' => Order::STATUS_COMPLETED,
            'IN_PROGRESS', 'ACTIVE' => Order::STATUS_CONFIRMED,
            'NOT_STARTED' => Order::STATUS_PENDING,
            'CANCELLED' => Order::STATUS_CANCELLED,
            default => Order::STATUS_PENDING,
        };
    }

    protected function normalizeAmazonOrder(array $payload): array
    {
        $order = $payload['order'] ?? $payload;

        return [
            'external_order_id' => $order['AmazonOrderId'] ?? '',
            'external_order_number' => $order['AmazonOrderId'] ?? null,
            'status' => $this->mapAmazonStatus($order['OrderStatus'] ?? 'Pending'),
            'fulfillment_status' => $order['FulfillmentChannel'] ?? 'MFN',
            'payment_status' => $order['PaymentMethod'] ?? 'Other',
            'total' => (float) ($order['OrderTotal']['Amount'] ?? 0),
            'subtotal' => (float) ($order['OrderTotal']['Amount'] ?? 0),
            'shipping_cost' => 0,
            'tax' => 0,
            'discount' => 0,
            'currency' => $order['OrderTotal']['CurrencyCode'] ?? 'USD',
            'customer_data' => [
                'email' => $order['BuyerEmail'] ?? null,
                'first_name' => $order['BuyerName'] ?? null,
                'last_name' => null,
                'phone' => null,
                'external_id' => $order['BuyerEmail'] ?? null,
            ],
            'shipping_address' => $this->normalizeAmazonAddress($order['ShippingAddress'] ?? null),
            'billing_address' => null,
            'line_items' => $this->normalizeAmazonLineItems($order['OrderItems'] ?? []),
            'ordered_at' => $order['PurchaseDate'] ?? now()->toIso8601String(),
            'platform_data' => $order,
        ];
    }

    protected function normalizeAmazonAddress(?array $address): ?array
    {
        if (! $address) {
            return null;
        }

        return [
            'first_name' => $address['Name'] ?? null,
            'last_name' => null,
            'address_line1' => $address['AddressLine1'] ?? null,
            'address_line2' => $address['AddressLine2'] ?? null,
            'city' => $address['City'] ?? null,
            'state' => $address['StateOrRegion'] ?? null,
            'postal_code' => $address['PostalCode'] ?? null,
            'country' => $address['CountryCode'] ?? null,
            'phone' => $address['Phone'] ?? null,
        ];
    }

    protected function normalizeAmazonLineItems(array $items): array
    {
        return array_map(function ($item) {
            return [
                'external_id' => $item['OrderItemId'] ?? null,
                'sku' => $item['SellerSKU'] ?? null,
                'title' => $item['Title'] ?? 'Unknown Item',
                'quantity' => (int) ($item['QuantityOrdered'] ?? 1),
                'price' => (float) ($item['ItemPrice']['Amount'] ?? 0),
                'discount' => 0,
                'tax' => (float) ($item['ItemTax']['Amount'] ?? 0),
                'variant_id' => null,
                'product_id' => $item['ASIN'] ?? null,
            ];
        }, $items);
    }

    protected function mapAmazonStatus(string $status): string
    {
        return match ($status) {
            'Shipped' => Order::STATUS_SHIPPED,
            'Unshipped', 'PartiallyShipped' => Order::STATUS_CONFIRMED,
            'Pending' => Order::STATUS_PENDING,
            'Canceled', 'Cancelled' => Order::STATUS_CANCELLED,
            default => Order::STATUS_PENDING,
        };
    }

    protected function normalizeEtsyOrder(array $payload): array
    {
        $order = $payload['receipt'] ?? $payload;

        return [
            'external_order_id' => (string) ($order['receipt_id'] ?? ''),
            'external_order_number' => (string) ($order['receipt_id'] ?? null),
            'status' => $order['is_paid'] ? Order::STATUS_CONFIRMED : Order::STATUS_PENDING,
            'fulfillment_status' => $order['is_shipped'] ? 'shipped' : 'pending',
            'payment_status' => $order['is_paid'] ? 'paid' : 'pending',
            'total' => (float) ($order['grandtotal']['amount'] ?? $order['grandtotal'] ?? 0) / 100,
            'subtotal' => (float) ($order['subtotal']['amount'] ?? $order['subtotal'] ?? 0) / 100,
            'shipping_cost' => (float) ($order['total_shipping_cost']['amount'] ?? 0) / 100,
            'tax' => (float) ($order['total_tax_cost']['amount'] ?? 0) / 100,
            'discount' => (float) ($order['discount_amt']['amount'] ?? 0) / 100,
            'currency' => $order['grandtotal']['currency_code'] ?? 'USD',
            'customer_data' => [
                'email' => $order['buyer_email'] ?? null,
                'first_name' => $order['name'] ?? null,
                'last_name' => null,
                'phone' => null,
                'external_id' => (string) ($order['buyer_user_id'] ?? null),
            ],
            'shipping_address' => $this->normalizeEtsyAddress($order),
            'billing_address' => null,
            'line_items' => $this->normalizeEtsyLineItems($order['transactions'] ?? []),
            'ordered_at' => isset($order['created_timestamp']) ? date('c', $order['created_timestamp']) : now()->toIso8601String(),
            'platform_data' => $order,
        ];
    }

    protected function normalizeEtsyAddress(array $order): ?array
    {
        if (empty($order['name'])) {
            return null;
        }

        return [
            'first_name' => $order['name'] ?? null,
            'last_name' => null,
            'address_line1' => $order['first_line'] ?? null,
            'address_line2' => $order['second_line'] ?? null,
            'city' => $order['city'] ?? null,
            'state' => $order['state'] ?? null,
            'postal_code' => $order['zip'] ?? null,
            'country' => $order['country_iso'] ?? null,
            'phone' => null,
        ];
    }

    protected function normalizeEtsyLineItems(array $items): array
    {
        return array_map(function ($item) {
            return [
                'external_id' => (string) ($item['transaction_id'] ?? null),
                'sku' => $item['product_data']['sku'] ?? null,
                'title' => $item['title'] ?? 'Unknown Item',
                'quantity' => (int) ($item['quantity'] ?? 1),
                'price' => (float) ($item['price']['amount'] ?? 0) / 100,
                'discount' => 0,
                'tax' => 0,
                'variant_id' => null,
                'product_id' => (string) ($item['listing_id'] ?? null),
            ];
        }, $items);
    }

    protected function normalizeWalmartOrder(array $payload): array
    {
        $order = $payload['order'] ?? $payload;
        $orderLines = $order['orderLines']['orderLine'] ?? [];

        return [
            'external_order_id' => $order['purchaseOrderId'] ?? '',
            'external_order_number' => $order['customerOrderId'] ?? null,
            'status' => $this->mapWalmartStatus($order['orderLines']['orderLine'][0]['orderLineStatuses']['orderLineStatus'][0]['status'] ?? 'Created'),
            'fulfillment_status' => 'pending',
            'payment_status' => 'paid',
            'total' => (float) ($order['orderTotal'] ?? 0),
            'subtotal' => (float) ($order['orderTotal'] ?? 0),
            'shipping_cost' => 0,
            'tax' => 0,
            'discount' => 0,
            'currency' => 'USD',
            'customer_data' => $this->extractWalmartCustomer($order),
            'shipping_address' => $this->normalizeWalmartAddress($order['shippingInfo']['postalAddress'] ?? null),
            'billing_address' => null,
            'line_items' => $this->normalizeWalmartLineItems($orderLines),
            'ordered_at' => $order['orderDate'] ?? now()->toIso8601String(),
            'platform_data' => $order,
        ];
    }

    protected function extractWalmartCustomer(array $order): array
    {
        $shipping = $order['shippingInfo'] ?? [];

        return [
            'email' => $shipping['email'] ?? null,
            'first_name' => $shipping['postalAddress']['name'] ?? null,
            'last_name' => null,
            'phone' => $shipping['phone'] ?? null,
            'external_id' => $order['customerOrderId'] ?? null,
        ];
    }

    protected function normalizeWalmartAddress(?array $address): ?array
    {
        if (! $address) {
            return null;
        }

        return [
            'first_name' => $address['name'] ?? null,
            'last_name' => null,
            'address_line1' => $address['address1'] ?? null,
            'address_line2' => $address['address2'] ?? null,
            'city' => $address['city'] ?? null,
            'state' => $address['state'] ?? null,
            'postal_code' => $address['postalCode'] ?? null,
            'country' => $address['country'] ?? 'US',
            'phone' => null,
        ];
    }

    protected function normalizeWalmartLineItems(array $items): array
    {
        return array_map(function ($item) {
            $charges = $item['charges']['charge'] ?? [];
            $price = 0;
            foreach ($charges as $charge) {
                if (($charge['chargeType'] ?? '') === 'PRODUCT') {
                    $price = (float) ($charge['chargeAmount']['amount'] ?? 0);
                }
            }

            return [
                'external_id' => $item['lineNumber'] ?? null,
                'sku' => $item['item']['sku'] ?? null,
                'title' => $item['item']['productName'] ?? 'Unknown Item',
                'quantity' => (int) ($item['orderLineQuantity']['amount'] ?? 1),
                'price' => $price,
                'discount' => 0,
                'tax' => 0,
                'variant_id' => null,
                'product_id' => $item['item']['sku'] ?? null,
            ];
        }, $items);
    }

    protected function mapWalmartStatus(string $status): string
    {
        return match ($status) {
            'Shipped' => Order::STATUS_SHIPPED,
            'Delivered' => Order::STATUS_DELIVERED,
            'Acknowledged', 'Created' => Order::STATUS_CONFIRMED,
            'Cancelled' => Order::STATUS_CANCELLED,
            default => Order::STATUS_PENDING,
        };
    }

    protected function normalizeWooCommerceOrder(array $payload): array
    {
        $order = $payload['order'] ?? $payload;

        return [
            'external_order_id' => (string) ($order['id'] ?? ''),
            'external_order_number' => $order['number'] ?? (string) ($order['id'] ?? null),
            'status' => $this->mapWooCommerceStatus($order['status'] ?? 'pending'),
            'fulfillment_status' => $order['status'] ?? 'pending',
            'payment_status' => $order['date_paid'] ? 'paid' : 'pending',
            'total' => (float) ($order['total'] ?? 0),
            'subtotal' => (float) ($order['subtotal'] ?? 0),
            'shipping_cost' => (float) ($order['shipping_total'] ?? 0),
            'tax' => (float) ($order['total_tax'] ?? 0),
            'discount' => (float) ($order['discount_total'] ?? 0),
            'currency' => $order['currency'] ?? 'USD',
            'customer_data' => $this->extractWooCommerceCustomer($order),
            'shipping_address' => $this->normalizeWooCommerceAddress($order['shipping'] ?? null),
            'billing_address' => $this->normalizeWooCommerceAddress($order['billing'] ?? null),
            'line_items' => $this->normalizeWooCommerceLineItems($order['line_items'] ?? []),
            'ordered_at' => $order['date_created'] ?? now()->toIso8601String(),
            'platform_data' => $order,
        ];
    }

    protected function extractWooCommerceCustomer(array $order): array
    {
        $billing = $order['billing'] ?? [];

        return [
            'email' => $billing['email'] ?? null,
            'first_name' => $billing['first_name'] ?? null,
            'last_name' => $billing['last_name'] ?? null,
            'phone' => $billing['phone'] ?? null,
            'external_id' => (string) ($order['customer_id'] ?? null),
        ];
    }

    protected function normalizeWooCommerceAddress(?array $address): ?array
    {
        if (! $address || empty($address['address_1'])) {
            return null;
        }

        return [
            'first_name' => $address['first_name'] ?? null,
            'last_name' => $address['last_name'] ?? null,
            'address_line1' => $address['address_1'] ?? null,
            'address_line2' => $address['address_2'] ?? null,
            'city' => $address['city'] ?? null,
            'state' => $address['state'] ?? null,
            'postal_code' => $address['postcode'] ?? null,
            'country' => $address['country'] ?? null,
            'phone' => $address['phone'] ?? null,
        ];
    }

    protected function normalizeWooCommerceLineItems(array $items): array
    {
        return array_map(function ($item) {
            return [
                'external_id' => (string) ($item['id'] ?? null),
                'sku' => $item['sku'] ?? null,
                'title' => $item['name'] ?? 'Unknown Item',
                'quantity' => (int) ($item['quantity'] ?? 1),
                'price' => (float) ($item['price'] ?? 0),
                'discount' => (float) ($item['subtotal'] ?? 0) - (float) ($item['total'] ?? 0),
                'tax' => (float) ($item['total_tax'] ?? 0),
                'variant_id' => $item['variation_id'] ?? null,
                'product_id' => (string) ($item['product_id'] ?? null),
            ];
        }, $items);
    }

    protected function mapWooCommerceStatus(string $status): string
    {
        return match ($status) {
            'completed' => Order::STATUS_COMPLETED,
            'processing' => Order::STATUS_CONFIRMED,
            'on-hold' => Order::STATUS_PENDING,
            'pending' => Order::STATUS_PENDING,
            'cancelled', 'failed' => Order::STATUS_CANCELLED,
            'refunded' => Order::STATUS_REFUNDED,
            default => Order::STATUS_PENDING,
        };
    }

    protected function findOrCreatePlatformOrder(
        array $normalizedData,
        StoreMarketplace $connection
    ): PlatformOrder {
        return PlatformOrder::updateOrCreate(
            [
                'store_marketplace_id' => $connection->id,
                'external_order_id' => $normalizedData['external_order_id'],
            ],
            [
                'external_order_number' => $normalizedData['external_order_number'],
                'status' => $normalizedData['status'],
                'fulfillment_status' => $normalizedData['fulfillment_status'],
                'payment_status' => $normalizedData['payment_status'],
                'total' => $normalizedData['total'],
                'subtotal' => $normalizedData['subtotal'],
                'shipping_cost' => $normalizedData['shipping_cost'],
                'tax' => $normalizedData['tax'],
                'discount' => $normalizedData['discount'],
                'currency' => $normalizedData['currency'],
                'customer_data' => $normalizedData['customer_data'],
                'shipping_address' => $normalizedData['shipping_address'],
                'billing_address' => $normalizedData['billing_address'],
                'line_items' => $normalizedData['line_items'],
                'platform_data' => $normalizedData['platform_data'],
                'ordered_at' => $normalizedData['ordered_at'],
            ]
        );
    }

    protected function createOrder(PlatformOrder $platformOrder, Store $store, Platform $platform): Order
    {
        $customer = $this->findOrCreateCustomer($platformOrder->customer_data, $store);

        // Find or create a sales channel for this marketplace
        $salesChannelId = $this->findOrCreateSalesChannel($platformOrder, $store, $platform);

        return Order::create([
            'store_id' => $store->id,
            'sales_channel_id' => $salesChannelId,
            'customer_id' => $customer?->id,
            'status' => $platformOrder->status,
            'sub_total' => $platformOrder->subtotal,
            'shipping_cost' => $platformOrder->shipping_cost,
            'sales_tax' => $platformOrder->tax,
            'discount_cost' => $platformOrder->discount,
            'total' => $platformOrder->total,
            'billing_address' => $platformOrder->billing_address,
            'shipping_address' => $platformOrder->shipping_address,
            'source_platform' => $platform->value,
            'external_marketplace_id' => $platformOrder->external_order_id,
            'date_of_purchase' => $platformOrder->ordered_at,
        ]);
    }

    /**
     * Find or create a sales channel for the given platform/marketplace.
     */
    protected function findOrCreateSalesChannel(PlatformOrder $platformOrder, Store $store, Platform $platform): ?int
    {
        $marketplaceId = $platformOrder->store_marketplace_id;

        // First, try to find a sales channel linked to this specific marketplace
        if ($marketplaceId) {
            $channel = SalesChannel::where('store_id', $store->id)
                ->where('store_marketplace_id', $marketplaceId)
                ->first();

            if ($channel) {
                return $channel->id;
            }
        }

        // Try to find a sales channel by platform type
        $channel = SalesChannel::where('store_id', $store->id)
            ->where('type', $platform->value)
            ->first();

        if ($channel) {
            // If we found a channel by type but it's not linked to a marketplace, link it now
            if ($marketplaceId && ! $channel->store_marketplace_id) {
                $channel->update(['store_marketplace_id' => $marketplaceId]);
            }

            return $channel->id;
        }

        // Auto-create a sales channel for this platform
        $marketplace = $platformOrder->marketplace;
        $channelName = $marketplace?->name ?? ucfirst($platform->value);
        $channelCode = strtolower(preg_replace('/[^a-zA-Z0-9]/', '_', $channelName));

        // Ensure code is unique for this store
        $baseCode = $channelCode;
        $counter = 1;
        while (SalesChannel::where('store_id', $store->id)->where('code', $channelCode)->exists()) {
            $channelCode = $baseCode.'_'.$counter;
            $counter++;
        }

        $channel = SalesChannel::create([
            'store_id' => $store->id,
            'name' => $channelName,
            'code' => $channelCode,
            'type' => $platform->value,
            'is_local' => false,
            'store_marketplace_id' => $marketplaceId,
            'is_active' => true,
            'is_default' => false,
        ]);

        return $channel->id;
    }

    protected function findOrCreateCustomer(?array $customerData, Store $store): ?Customer
    {
        if (! $customerData || empty($customerData['email'])) {
            return null;
        }

        return Customer::firstOrCreate(
            [
                'store_id' => $store->id,
                'email' => $customerData['email'],
            ],
            [
                'first_name' => $customerData['first_name'],
                'last_name' => $customerData['last_name'],
                'phone_number' => $customerData['phone'],
            ]
        );
    }

    protected function createOrderItems(Order $order, PlatformOrder $platformOrder): void
    {
        $productsToSync = [];

        foreach ($platformOrder->line_items as $item) {
            $variant = $this->findMatchingVariant($item, $order->store_id);

            $order->items()->create([
                'product_id' => $variant?->product_id,
                'product_variant_id' => $variant?->id,
                'category_id' => $variant?->product?->category_id,
                'sku' => $item['sku'] ?? $variant?->sku,
                'title' => $item['title'],
                'quantity' => $item['quantity'],
                'price' => $item['price'],
                'cost' => $variant?->cost,
                'wholesale_value' => $variant?->wholesale_price,
                'discount' => $item['discount'] ?? 0,
                'tax' => $item['tax'] ?? 0,
                'external_item_id' => $item['external_id'] ?? $item['id'] ?? null,
            ]);

            // Reduce inventory for matching variant using proper stock management
            if ($variant && $item['quantity'] > 0) {
                $this->reduceVariantStock(
                    $variant,
                    (int) $item['quantity'],
                    $order->store,
                    $platformOrder->marketplace->platform->value,
                    $platformOrder->external_order_number
                );

                // Track product for inventory sync to other platforms
                if ($variant->product && ! in_array($variant->product_id, $productsToSync)) {
                    $productsToSync[] = $variant->product_id;
                }
            }
        }

        // Sync inventory to all other platforms for affected products
        $platformName = $platformOrder->marketplace?->platform->value ?? 'external';
        foreach ($productsToSync as $productId) {
            $product = \App\Models\Product::find($productId);
            if ($product) {
                $product->syncInventoryToAllPlatforms("{$platformName}_order_received");
            }
        }
    }

    protected function findMatchingVariant(array $item, int $storeId): ?ProductVariant
    {
        if (! empty($item['sku'])) {
            $variant = ProductVariant::with('product')
                ->whereHas('product', function ($query) use ($storeId) {
                    $query->where('store_id', $storeId);
                })->where('sku', $item['sku'])->first();

            if ($variant) {
                return $variant;
            }
        }

        return null;
    }

    protected function handlePayment(Order $order, PlatformOrder $platformOrder): void
    {
        if ($platformOrder->payment_status === 'paid' && $platformOrder->total > 0) {
            Payment::create([
                'store_id' => $order->store_id,
                'order_id' => $order->id,
                'customer_id' => $order->customer_id,
                'payment_method' => Payment::METHOD_EXTERNAL,
                'status' => Payment::STATUS_COMPLETED,
                'amount' => $platformOrder->total,
                'currency' => $platformOrder->currency,
                'notes' => "Payment from {$platformOrder->marketplace->platform->value}",
                'paid_at' => $platformOrder->ordered_at,
            ]);
        }
    }

    protected function updateExistingOrder(PlatformOrder $platformOrder, array $normalizedData): void
    {
        $order = $platformOrder->order;

        $order->update([
            'status' => $normalizedData['status'],
            'shipping_address' => $normalizedData['shipping_address'],
            'billing_address' => $normalizedData['billing_address'],
        ]);

        $platformOrder->update([
            'status' => $normalizedData['status'],
            'fulfillment_status' => $normalizedData['fulfillment_status'],
            'payment_status' => $normalizedData['payment_status'],
            'platform_data' => $normalizedData['platform_data'],
            'last_synced_at' => now(),
        ]);
    }

    /**
     * Reduce stock for a variant using proper locking to prevent overselling.
     *
     * For external platform orders, we're more lenient - the sale already happened
     * on the platform, so we reduce what we can and notify if we go short.
     */
    protected function reduceVariantStock(
        ProductVariant $variant,
        int $quantity,
        Store $store,
        string $platform,
        ?string $orderNumber = null
    ): void {
        $remaining = $quantity;

        // Try to reduce from inventory records first (proper warehouse-level tracking)
        $inventories = Inventory::where('product_variant_id', $variant->id)
            ->where('quantity', '>', 0)
            ->orderBy('quantity', 'desc')
            ->lockForUpdate()
            ->get();

        foreach ($inventories as $inventory) {
            if ($remaining <= 0) {
                break;
            }

            $available = $inventory->quantity - ($inventory->reserved_quantity ?? 0);
            $reduceBy = min($available, $remaining);

            if ($reduceBy > 0) {
                // Atomic conditional update to prevent going below zero
                $updated = Inventory::where('id', $inventory->id)
                    ->where('quantity', '>=', $reduceBy)
                    ->update([
                        'quantity' => DB::raw("quantity - {$reduceBy}"),
                        'last_sold_at' => now(),
                    ]);

                if ($updated) {
                    $remaining -= $reduceBy;
                }
            }
        }

        // If there's still remaining quantity, reduce from variant directly
        // This handles cases where inventory tracking isn't set up
        if ($remaining > 0 && $variant->quantity > 0) {
            $reduceBy = min($variant->quantity, $remaining);

            // Atomic conditional update
            $updated = ProductVariant::where('id', $variant->id)
                ->where('quantity', '>=', $reduceBy)
                ->update([
                    'quantity' => DB::raw("quantity - {$reduceBy}"),
                ]);

            if ($updated) {
                $remaining -= $reduceBy;
            }
        }

        // Notify store owners if we couldn't fulfill the entire quantity
        if ($remaining > 0) {
            Log::warning('OrderImportService: Insufficient stock for external order', [
                'variant_id' => $variant->id,
                'sku' => $variant->sku,
                'requested' => $quantity,
                'unfulfilled' => $remaining,
                'store_id' => $store->id,
                'platform' => $platform,
            ]);

            // Send notification to store owners
            $this->notifyOversold($store, $variant, $quantity, $remaining, $platform, $orderNumber);
        }
    }

    /**
     * Notify store owners about an oversold situation.
     */
    protected function notifyOversold(
        Store $store,
        ProductVariant $variant,
        int $requested,
        int $unfulfilled,
        string $platform,
        ?string $orderNumber = null
    ): void {
        // Get store owners/admins to notify
        $usersToNotify = $store->users()
            ->wherePivotIn('role', ['owner', 'admin'])
            ->get();

        if ($usersToNotify->isEmpty()) {
            return;
        }

        Notification::send(
            $usersToNotify,
            new InventoryOversoldNotification(
                $store,
                $variant,
                $requested,
                $unfulfilled,
                $platform,
                $orderNumber
            )
        );
    }

    /**
     * Map a DTO's status fields to an Order::STATUS_* constant.
     */
    public function mapDtoStatusToOrderStatus(PlatformOrderDto $dto, Platform $platform): string
    {
        if ($dto->status === 'cancelled') {
            return Order::STATUS_CANCELLED;
        }

        if ($dto->status === 'completed') {
            if ($dto->fulfillmentStatus === 'fulfilled' && $dto->paymentStatus === 'paid') {
                return Order::STATUS_COMPLETED;
            }

            return Order::STATUS_SHIPPED;
        }

        if ($dto->fulfillmentStatus === 'fulfilled' && $dto->paymentStatus === 'paid') {
            return Order::STATUS_SHIPPED;
        }

        if ($dto->paymentStatus === 'paid') {
            return Order::STATUS_CONFIRMED;
        }

        if (in_array($dto->paymentStatus, ['refunded', 'partially_refunded'])) {
            return Order::STATUS_REFUNDED;
        }

        return Order::STATUS_PENDING;
    }

    /**
     * Sync a PlatformOrder and its linked Order from a connector DTO.
     */
    public function syncOrderFromDto(PlatformOrder $platformOrderModel, PlatformOrderDto $dto, Platform $platform): void
    {
        $platformOrderModel->update([
            'status' => $dto->status,
            'fulfillment_status' => $dto->fulfillmentStatus,
            'payment_status' => $dto->paymentStatus,
            'platform_data' => $dto->metadata,
            'last_synced_at' => now(),
        ]);

        if (! $platformOrderModel->isImported()) {
            return;
        }

        $order = $platformOrderModel->order;
        $newStatus = $this->mapDtoStatusToOrderStatus($dto, $platform);

        if ($this->isStatusProgression($order->status, $newStatus)) {
            $order->update(['status' => $newStatus]);
        }
    }

    /**
     * Check if moving from one status to another is a forward progression.
     *
     * Terminal statuses (cancelled, refunded) can always be set.
     * Otherwise, only allow moving forward in the progression.
     */
    protected function isStatusProgression(string $currentStatus, string $newStatus): bool
    {
        if ($currentStatus === $newStatus) {
            return false;
        }

        $terminalStatuses = [Order::STATUS_CANCELLED, Order::STATUS_REFUNDED];

        if (in_array($newStatus, $terminalStatuses, true)) {
            return true;
        }

        $progression = [
            Order::STATUS_DRAFT => 0,
            Order::STATUS_PENDING => 1,
            Order::STATUS_CONFIRMED => 2,
            Order::STATUS_PROCESSING => 3,
            Order::STATUS_SHIPPED => 4,
            Order::STATUS_DELIVERED => 5,
            Order::STATUS_COMPLETED => 6,
        ];

        $currentIndex = $progression[$currentStatus] ?? -1;
        $newIndex = $progression[$newStatus] ?? -1;

        return $newIndex > $currentIndex;
    }
}
