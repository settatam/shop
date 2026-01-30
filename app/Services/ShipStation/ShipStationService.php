<?php

namespace App\Services\ShipStation;

use App\Models\Order;
use App\Models\StoreIntegration;
use App\Services\StoreContext;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ShipStationService
{
    protected ?StoreIntegration $integration = null;

    public function __construct(
        protected StoreContext $storeContext,
    ) {}

    /**
     * Get ShipStation integration for current store.
     */
    protected function getIntegration(): ?StoreIntegration
    {
        if ($this->integration) {
            return $this->integration;
        }

        $storeId = $this->storeContext->getCurrentStoreId();
        if (! $storeId) {
            return null;
        }

        $this->integration = StoreIntegration::findActiveForStore($storeId, StoreIntegration::PROVIDER_SHIPSTATION);

        return $this->integration;
    }

    /**
     * Set integration manually (useful for specific store context).
     */
    public function setIntegration(StoreIntegration $integration): self
    {
        $this->integration = $integration;

        return $this;
    }

    /**
     * Check if ShipStation integration is configured for the current store.
     */
    public function isConfigured(): bool
    {
        return $this->getIntegration() !== null;
    }

    /**
     * Check if auto-sync is enabled.
     */
    public function isAutoSyncEnabled(): bool
    {
        $integration = $this->getIntegration();

        return $integration?->isShipStationAutoSyncEnabled() ?? false;
    }

    /**
     * Create an order in ShipStation.
     *
     * @return array{success: bool, order_id: int|null, order_key: string|null, error: string|null}
     */
    public function createOrder(Order $order): array
    {
        $integration = $this->getIntegration();

        if (! $integration) {
            return [
                'success' => false,
                'order_id' => null,
                'order_key' => null,
                'error' => 'ShipStation integration is not configured',
            ];
        }

        try {
            $orderData = $this->buildOrderPayload($order, $integration);

            $response = $this->makeRequest('POST', '/orders/createorder', $orderData);

            if (isset($response['orderId'])) {
                $integration->recordUsage();

                Log::info('Order created in ShipStation', [
                    'order_id' => $order->id,
                    'shipstation_order_id' => $response['orderId'],
                ]);

                return [
                    'success' => true,
                    'order_id' => $response['orderId'],
                    'order_key' => $response['orderKey'] ?? null,
                    'error' => null,
                ];
            }

            $error = $response['Message'] ?? $response['ExceptionMessage'] ?? 'Unknown error';
            Log::warning('ShipStation order creation failed', [
                'order_id' => $order->id,
                'response' => $response,
            ]);

            return [
                'success' => false,
                'order_id' => null,
                'order_key' => null,
                'error' => $error,
            ];
        } catch (\Exception $e) {
            Log::error('ShipStation API error', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            if (str_contains($e->getMessage(), '401') || str_contains($e->getMessage(), 'Unauthorized')) {
                $integration->markAsError('Invalid API credentials');
            }

            return [
                'success' => false,
                'order_id' => null,
                'order_key' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get list of stores from ShipStation account.
     *
     * @return array{success: bool, stores: array, error: string|null}
     */
    public function getStores(): array
    {
        try {
            $response = $this->makeRequest('GET', '/stores');

            return [
                'success' => true,
                'stores' => $response ?? [],
                'error' => null,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'stores' => [],
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get list of carriers from ShipStation.
     *
     * @return array{success: bool, carriers: array, error: string|null}
     */
    public function getCarriers(): array
    {
        try {
            $response = $this->makeRequest('GET', '/carriers');

            return [
                'success' => true,
                'carriers' => $response ?? [],
                'error' => null,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'carriers' => [],
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get order status from ShipStation.
     *
     * @return array{success: bool, status: string|null, tracking_number: string|null, error: string|null}
     */
    public function getOrderStatus(int $shipstationOrderId): array
    {
        try {
            $response = $this->makeRequest('GET', "/orders/{$shipstationOrderId}");

            return [
                'success' => true,
                'status' => $response['orderStatus'] ?? null,
                'tracking_number' => $response['shipments'][0]['trackingNumber'] ?? null,
                'error' => null,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'status' => null,
                'tracking_number' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Test the API connection.
     *
     * @return array{success: bool, error: string|null}
     */
    public function testConnection(): array
    {
        try {
            // Try to get stores as a simple API test
            $response = $this->makeRequest('GET', '/stores');

            return [
                'success' => is_array($response),
                'error' => null,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Build the order payload for ShipStation API.
     */
    protected function buildOrderPayload(Order $order, StoreIntegration $integration): array
    {
        $order->load(['customer', 'items.productVariant.product', 'store']);

        $customer = $order->customer;
        $shippingAddress = $order->shipping_address ?? [];
        $billingAddress = $order->billing_address ?? $shippingAddress;

        $items = $order->items->map(function ($item) {
            $variant = $item->productVariant;
            $product = $variant?->product;

            return [
                'lineItemKey' => (string) $item->id,
                'sku' => $variant?->sku ?? '',
                'name' => $item->title ?? $product?->title ?? 'Item',
                'imageUrl' => $product?->primaryImage?->url ?? null,
                'weight' => [
                    'value' => (float) ($item->weight ?? $variant?->weight ?? 0),
                    'units' => 'ounces',
                ],
                'quantity' => (int) $item->quantity,
                'unitPrice' => (float) $item->unit_price,
                'taxAmount' => 0, // Tax calculated at order level
                'options' => [],
            ];
        })->toArray();

        $payload = [
            'orderNumber' => $order->order_id ?? $order->invoice_number ?? "ORD-{$order->id}",
            'orderKey' => "order-{$order->id}",
            'orderDate' => $order->created_at->toIso8601String(),
            'paymentDate' => $order->created_at->toIso8601String(),
            'orderStatus' => 'awaiting_shipment',
            'customerUsername' => $customer?->email ?? '',
            'customerEmail' => $customer?->email ?? '',
            'billTo' => [
                'name' => $billingAddress['name'] ?? $customer?->full_name ?? '',
                'company' => $billingAddress['company'] ?? '',
                'street1' => $billingAddress['street1'] ?? $billingAddress['address_line1'] ?? '',
                'street2' => $billingAddress['street2'] ?? $billingAddress['address_line2'] ?? '',
                'city' => $billingAddress['city'] ?? '',
                'state' => $billingAddress['state'] ?? '',
                'postalCode' => $billingAddress['postal_code'] ?? $billingAddress['zip'] ?? '',
                'country' => $billingAddress['country'] ?? 'US',
                'phone' => $billingAddress['phone'] ?? $customer?->phone ?? '',
            ],
            'shipTo' => [
                'name' => $shippingAddress['name'] ?? $customer?->full_name ?? '',
                'company' => $shippingAddress['company'] ?? '',
                'street1' => $shippingAddress['street1'] ?? $shippingAddress['address_line1'] ?? '',
                'street2' => $shippingAddress['street2'] ?? $shippingAddress['address_line2'] ?? '',
                'city' => $shippingAddress['city'] ?? '',
                'state' => $shippingAddress['state'] ?? '',
                'postalCode' => $shippingAddress['postal_code'] ?? $shippingAddress['zip'] ?? '',
                'country' => $shippingAddress['country'] ?? 'US',
                'phone' => $shippingAddress['phone'] ?? $customer?->phone ?? '',
            ],
            'items' => $items,
            'amountPaid' => (float) $order->total,
            'taxAmount' => (float) ($order->sales_tax ?? 0),
            'shippingAmount' => (float) ($order->shipping_cost ?? 0),
            'internalNotes' => $order->notes ?? '',
        ];

        // Add store ID if configured
        $storeId = $integration->getShipStationStoreId();
        if ($storeId) {
            $payload['advancedOptions'] = [
                'storeId' => $storeId,
            ];
        }

        return $payload;
    }

    /**
     * Make a request to the ShipStation API.
     *
     * @throws \RuntimeException
     */
    protected function makeRequest(string $method, string $endpoint, array $data = []): array
    {
        $integration = $this->getIntegration();

        if (! $integration) {
            throw new \RuntimeException('ShipStation integration is not configured');
        }

        $apiKey = $integration->getShipStationApiKey();
        $apiSecret = $integration->getShipStationApiSecret();

        if (! $apiKey || ! $apiSecret) {
            throw new \RuntimeException('ShipStation API credentials are not configured');
        }

        $url = $integration->getShipStationApiUrl().$endpoint;

        $response = Http::withBasicAuth($apiKey, $apiSecret)
            ->timeout(30)
            ->retry(2, 1000)
            ->when(
                $method === 'GET',
                fn ($http) => $http->get($url, $data),
                fn ($http) => $http->post($url, $data)
            );

        if (! $response->successful()) {
            $body = $response->json();
            $message = $body['Message'] ?? $body['ExceptionMessage'] ?? "HTTP {$response->status()}";
            throw new \RuntimeException("ShipStation API error: {$message}");
        }

        return $response->json() ?? [];
    }

    /**
     * Create ShipStation service for a specific store.
     */
    public static function forStore(int $storeId): self
    {
        $integration = StoreIntegration::findActiveForStore($storeId, StoreIntegration::PROVIDER_SHIPSTATION);

        $service = app(self::class);

        if ($integration) {
            $service->setIntegration($integration);
        }

        return $service;
    }
}
