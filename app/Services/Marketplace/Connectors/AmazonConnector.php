<?php

namespace App\Services\Marketplace\Connectors;

use App\Enums\Platform;
use App\Services\Marketplace\DTOs\InventoryUpdate;
use App\Services\Marketplace\DTOs\PlatformOrder;
use App\Services\Marketplace\DTOs\PlatformProduct;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;

class AmazonConnector extends BasePlatformConnector
{
    protected const SP_API_VERSION = '2021-08-01';

    protected const REGIONS = [
        'na' => 'https://sellingpartnerapi-na.amazon.com',
        'eu' => 'https://sellingpartnerapi-eu.amazon.com',
        'fe' => 'https://sellingpartnerapi-fe.amazon.com',
    ];

    public function getPlatform(): Platform
    {
        return Platform::Amazon;
    }

    protected function getBaseUrl(): string
    {
        $this->ensureInitialized();

        $region = $this->marketplace->settings['region'] ?? 'na';

        return self::REGIONS[$region] ?? self::REGIONS['na'];
    }

    protected function getAuthHeaders(): array
    {
        $this->ensureInitialized();

        // Amazon SP-API requires AWS Signature Version 4
        // For simplicity, using LWA token here - full implementation needs AWS signing
        return [
            'x-amz-access-token' => $this->marketplace->access_token,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
    }

    protected function refreshTokens(): bool
    {
        if (! $this->marketplace) {
            return false;
        }

        $credentials = $this->marketplace->credentials;
        $clientId = $credentials['client_id'] ?? null;
        $clientSecret = $credentials['client_secret'] ?? null;
        $refreshToken = $this->marketplace->refresh_token;

        if (! $clientId || ! $clientSecret || ! $refreshToken) {
            return false;
        }

        try {
            $response = Http::asForm()->post('https://api.amazon.com/auth/o2/token', [
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken,
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
            ]);

            if ($response->successful()) {
                $data = $response->json();
                $this->marketplace->update([
                    'access_token' => $data['access_token'],
                    'token_expires_at' => now()->addSeconds($data['expires_in'] ?? 3600),
                ]);

                return true;
            }
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
        }

        return false;
    }

    // ========================================
    // Product Operations
    // ========================================

    public function getProducts(int $limit = 250, ?string $cursor = null): array
    {
        // Amazon uses Catalog Items API
        $params = [
            'marketplaceIds' => $this->getMarketplaceId(),
            'pageSize' => min($limit, 20),
            'includedData' => 'summaries,attributes,images,salesRanks',
        ];

        if ($cursor) {
            $params['pageToken'] = $cursor;
        }

        try {
            $response = $this->request('GET', '/catalog/2022-04-01/items', $params);
            $data = $response->json('items', []);

            return array_map(fn ($item) => $this->transformProduct($item), $data);
        } catch (\Throwable) {
            return [];
        }
    }

    public function getProduct(string $externalId): ?PlatformProduct
    {
        try {
            $params = [
                'marketplaceIds' => $this->getMarketplaceId(),
                'includedData' => 'summaries,attributes,images,salesRanks',
            ];

            $response = $this->request('GET', "/catalog/2022-04-01/items/{$externalId}", $params);
            $data = $response->json();

            return $this->transformProduct($data);
        } catch (\Throwable) {
            return null;
        }
    }

    public function createProduct(PlatformProduct $product): ?string
    {
        // Amazon product creation is complex - requires feeds API
        // This is a simplified version
        try {
            $feed = $this->buildProductFeed($product);

            $response = $this->request('POST', '/feeds/2021-06-30/feeds', [
                'feedType' => 'POST_PRODUCT_DATA',
                'marketplaceIds' => [$this->getMarketplaceId()],
                'inputFeedDocumentId' => $this->uploadFeedDocument($feed),
            ]);

            return $response->json('feedId');
        } catch (\Throwable) {
            return null;
        }
    }

    public function updateProduct(string $externalId, PlatformProduct $product): bool
    {
        // Similar to create - uses feeds API
        return $this->createProduct($product) !== null;
    }

    public function deleteProduct(string $externalId): bool
    {
        // Amazon doesn't really delete products - you can only close listings
        try {
            $this->request('DELETE', "/listings/2021-08-01/items/{$this->getSellerId()}/{$externalId}", [
                'marketplaceIds' => $this->getMarketplaceId(),
            ]);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    protected function transformProduct(array $data): PlatformProduct
    {
        $summary = $data['summaries'][0] ?? [];
        $attributes = $data['attributes'] ?? [];
        $images = $data['images'][0]['images'] ?? [];

        return new PlatformProduct(
            externalId: $data['asin'] ?? '',
            title: $summary['itemName'] ?? '',
            description: $this->extractAttribute($attributes, 'product_description'),
            sku: $this->extractAttribute($attributes, 'merchant_sku') ?? $data['asin'] ?? null,
            barcode: $this->extractAttribute($attributes, 'externally_assigned_product_identifier'),
            price: 0.0, // Price comes from listings API
            brand: $summary['brand'] ?? null,
            category: $summary['browseClassification']['displayName'] ?? null,
            categoryId: $summary['browseClassification']['classificationId'] ?? null,
            images: array_map(fn ($img) => $img['link'] ?? '', $images),
            attributes: $attributes,
            condition: $this->extractAttribute($attributes, 'condition_type') ?? 'new',
            status: 'active',
            metadata: [
                'asin' => $data['asin'] ?? null,
                'product_types' => $summary['productType'] ?? null,
                'sales_rank' => $data['salesRanks'] ?? [],
            ],
        );
    }

    protected function extractAttribute(array $attributes, string $key): ?string
    {
        return $attributes[$key][0]['value'] ?? null;
    }

    protected function buildProductFeed(PlatformProduct $product): string
    {
        // Build XML feed for Amazon - simplified version
        return '<?xml version="1.0" encoding="UTF-8"?>
        <AmazonEnvelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
            <Header>
                <DocumentVersion>1.01</DocumentVersion>
                <MerchantIdentifier>'.$this->getSellerId().'</MerchantIdentifier>
            </Header>
            <MessageType>Product</MessageType>
            <Message>
                <MessageID>1</MessageID>
                <Product>
                    <SKU>'.htmlspecialchars($product->sku ?? '').'</SKU>
                    <StandardProductID>
                        <Type>UPC</Type>
                        <Value>'.htmlspecialchars($product->barcode ?? '').'</Value>
                    </StandardProductID>
                    <ProductTaxCode>A_GEN_NOTAX</ProductTaxCode>
                    <DescriptionData>
                        <Title>'.htmlspecialchars($product->title).'</Title>
                        <Brand>'.htmlspecialchars($product->brand ?? '').'</Brand>
                        <Description>'.htmlspecialchars($product->description).'</Description>
                    </DescriptionData>
                </Product>
            </Message>
        </AmazonEnvelope>';
    }

    protected function uploadFeedDocument(string $content): string
    {
        // This would upload to S3 and return document ID
        // Simplified - actual implementation requires S3 upload
        return 'feed-document-id';
    }

    // ========================================
    // Order Operations
    // ========================================

    public function getOrders(?\DateTimeInterface $since = null, int $limit = 250): array
    {
        $params = [
            'MarketplaceIds' => $this->getMarketplaceId(),
            'MaxResultsPerPage' => min($limit, 100),
        ];

        if ($since) {
            $params['CreatedAfter'] = $since->format('c');
        }

        try {
            $response = $this->request('GET', '/orders/v0/orders', $params);
            $data = $response->json('payload.Orders', []);

            return array_map(fn ($order) => $this->transformOrder($order), $data);
        } catch (\Throwable) {
            return [];
        }
    }

    public function getOrder(string $externalId): ?PlatformOrder
    {
        try {
            $response = $this->request('GET', "/orders/v0/orders/{$externalId}");
            $data = $response->json('payload');

            return $data ? $this->transformOrder($data) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function fulfillOrder(string $externalId, array $fulfillmentData): bool
    {
        // Amazon fulfillment is handled via feeds or MFN shipment confirmation
        try {
            $feed = $this->buildShipmentFeed($externalId, $fulfillmentData);

            $this->request('POST', '/feeds/2021-06-30/feeds', [
                'feedType' => 'POST_ORDER_FULFILLMENT_DATA',
                'marketplaceIds' => [$this->getMarketplaceId()],
                'inputFeedDocumentId' => $this->uploadFeedDocument($feed),
            ]);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    protected function transformOrder(array $data): PlatformOrder
    {
        return new PlatformOrder(
            externalId: $data['AmazonOrderId'] ?? '',
            orderNumber: $data['AmazonOrderId'] ?? null,
            status: strtolower($data['OrderStatus'] ?? 'pending'),
            fulfillmentStatus: $this->mapFulfillmentStatus($data['FulfillmentChannel'] ?? ''),
            paymentStatus: $data['PaymentMethod'] ? 'paid' : 'pending',
            total: (float) ($data['OrderTotal']['Amount'] ?? 0),
            subtotal: (float) ($data['OrderTotal']['Amount'] ?? 0),
            shippingCost: 0.0,
            tax: 0.0,
            discount: 0.0,
            currency: $data['OrderTotal']['CurrencyCode'] ?? 'USD',
            customer: [
                'name' => $data['BuyerInfo']['BuyerName'] ?? null,
                'email' => $data['BuyerInfo']['BuyerEmail'] ?? null,
            ],
            shippingAddress: $this->transformAddress($data['ShippingAddress'] ?? []),
            lineItems: [], // Need separate API call
            orderedAt: isset($data['PurchaseDate']) ? Carbon::parse($data['PurchaseDate']) : null,
            metadata: [
                'fulfillment_channel' => $data['FulfillmentChannel'] ?? null,
                'ship_service_level' => $data['ShipServiceLevel'] ?? null,
                'is_prime' => $data['IsPrime'] ?? false,
            ],
        );
    }

    protected function mapFulfillmentStatus(string $channel): string
    {
        return match ($channel) {
            'AFN' => 'amazon_fulfilled',
            'MFN' => 'merchant_fulfilled',
            default => 'unfulfilled',
        };
    }

    protected function transformAddress(array $address): array
    {
        return [
            'name' => $address['Name'] ?? null,
            'address1' => $address['AddressLine1'] ?? null,
            'address2' => $address['AddressLine2'] ?? null,
            'city' => $address['City'] ?? null,
            'state' => $address['StateOrRegion'] ?? null,
            'postal_code' => $address['PostalCode'] ?? null,
            'country' => $address['CountryCode'] ?? null,
            'phone' => $address['Phone'] ?? null,
        ];
    }

    protected function buildShipmentFeed(string $orderId, array $data): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>
        <AmazonEnvelope>
            <Header>
                <DocumentVersion>1.01</DocumentVersion>
                <MerchantIdentifier>'.$this->getSellerId().'</MerchantIdentifier>
            </Header>
            <MessageType>OrderFulfillment</MessageType>
            <Message>
                <MessageID>1</MessageID>
                <OrderFulfillment>
                    <AmazonOrderID>'.$orderId.'</AmazonOrderID>
                    <FulfillmentDate>'.now()->format('c').'</FulfillmentDate>
                    <FulfillmentData>
                        <CarrierName>'.htmlspecialchars($data['carrier'] ?? '').'</CarrierName>
                        <ShippingMethod>'.htmlspecialchars($data['method'] ?? 'Standard').'</ShippingMethod>
                        <ShipperTrackingNumber>'.htmlspecialchars($data['tracking_number'] ?? '').'</ShipperTrackingNumber>
                    </FulfillmentData>
                </OrderFulfillment>
            </Message>
        </AmazonEnvelope>';
    }

    // ========================================
    // Inventory Operations
    // ========================================

    public function updateInventory(InventoryUpdate $update): bool
    {
        try {
            $feed = $this->buildInventoryFeed($update);

            $this->request('POST', '/feeds/2021-06-30/feeds', [
                'feedType' => 'POST_INVENTORY_AVAILABILITY_DATA',
                'marketplaceIds' => [$this->getMarketplaceId()],
                'inputFeedDocumentId' => $this->uploadFeedDocument($feed),
            ]);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function bulkUpdateInventory(array $updates): array
    {
        // Amazon prefers bulk feeds, but for simplicity process individually
        $results = [];
        foreach ($updates as $update) {
            $results[$update->sku] = $this->updateInventory($update);
        }

        return $results;
    }

    protected function buildInventoryFeed(InventoryUpdate $update): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>
        <AmazonEnvelope>
            <Header>
                <DocumentVersion>1.01</DocumentVersion>
                <MerchantIdentifier>'.$this->getSellerId().'</MerchantIdentifier>
            </Header>
            <MessageType>Inventory</MessageType>
            <Message>
                <MessageID>1</MessageID>
                <Inventory>
                    <SKU>'.htmlspecialchars($update->sku).'</SKU>
                    <Quantity>'.$update->quantity.'</Quantity>
                </Inventory>
            </Message>
        </AmazonEnvelope>';
    }

    // ========================================
    // Category Operations
    // ========================================

    public function getCategories(): array
    {
        // Amazon uses Browse Nodes for categories
        // This would need the Product Type Definitions API
        return [
            ['id' => 'jewelry', 'name' => 'Jewelry'],
            ['id' => 'watches', 'name' => 'Watches'],
            ['id' => 'clothing', 'name' => 'Clothing'],
            ['id' => 'electronics', 'name' => 'Electronics'],
        ];
    }

    public function getCategoryAttributes(string $categoryId): array
    {
        // Would use Product Type Definitions API
        // Returns required and optional attributes for a product type
        try {
            $response = $this->request('GET', "/definitions/2020-09-01/productTypes/{$categoryId}", [
                'marketplaceIds' => $this->getMarketplaceId(),
                'requirements' => 'LISTING',
            ]);

            return $response->json('propertyGroups', []);
        } catch (\Throwable) {
            return [];
        }
    }

    // ========================================
    // Helper Methods
    // ========================================

    protected function getMarketplaceId(): string
    {
        return $this->marketplace->settings['marketplace_id'] ?? 'ATVPDKIKX0DER'; // US default
    }

    protected function getSellerId(): string
    {
        return $this->marketplace->external_store_id ?? '';
    }
}
