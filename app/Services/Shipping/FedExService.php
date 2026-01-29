<?php

namespace App\Services\Shipping;

use App\Models\Store;
use App\Models\StoreIntegration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FedExService
{
    protected string $clientId;

    protected string $clientSecret;

    protected string $accountNumber;

    protected string $mode;

    protected string $baseUrl;

    protected ?Store $store;

    protected ?StoreIntegration $integration;

    public function __construct(?Store $store = null)
    {
        $this->store = $store;
        $this->integration = null;

        // Try to load credentials from store integration first
        if ($store) {
            $this->integration = StoreIntegration::findActiveForStore(
                $store->id,
                StoreIntegration::PROVIDER_FEDEX
            );
        }

        if ($this->integration) {
            // Use store-specific credentials
            $this->clientId = $this->integration->getClientId() ?? '';
            $this->clientSecret = $this->integration->getClientSecret() ?? '';
            $this->accountNumber = $this->integration->getAccountNumber() ?? '';
            $this->mode = $this->integration->isSandbox() ? 'sandbox' : 'production';
            $this->baseUrl = $this->integration->getApiBaseUrl();
        } else {
            // Fall back to global config/env (using logistics.fedex config keys)
            $this->clientId = config('logistics.fedex.key') ?? config('services.fedex.client_id') ?? '';
            $this->clientSecret = config('logistics.fedex.secret') ?? config('services.fedex.client_secret') ?? '';
            $this->accountNumber = config('logistics.fedex.account') ?? config('services.fedex.account_number') ?? '';
            $this->mode = config('logistics.fedex.mode') ?? config('services.fedex.mode') ?? 'production';
            $this->baseUrl = config('logistics.fedex.url') ?? ($this->mode === 'production'
                ? 'https://apis.fedex.com'
                : 'https://apis-sandbox.fedex.com');
        }
    }

    /**
     * Create a FedExService instance for a specific store.
     */
    public static function forStore(Store $store): self
    {
        return new self($store);
    }

    public function isConfigured(): bool
    {
        return ! empty($this->clientId)
            && ! empty($this->clientSecret)
            && ! empty($this->accountNumber);
    }

    /**
     * Get the store associated with this service instance.
     */
    public function getStore(): ?Store
    {
        return $this->store;
    }

    /**
     * Get OAuth 2.0 access token with caching.
     */
    public function getAccessToken(): ?string
    {
        if (! $this->isConfigured()) {
            return null;
        }

        // Include store ID in cache key if using store-specific credentials
        $cacheKey = $this->store
            ? "fedex_access_token_{$this->store->id}"
            : 'fedex_access_token';

        return Cache::remember($cacheKey, 3500, function () {
            $response = Http::asForm()->post("{$this->baseUrl}/oauth/token", [
                'grant_type' => 'client_credentials',
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
            ]);

            if ($response->successful()) {
                // Record usage if using store integration
                $this->integration?->recordUsage();

                return $response->json('access_token');
            }

            Log::error('FedEx OAuth failed', [
                'status' => $response->status(),
                'body' => $response->body(),
                'store_id' => $this->store?->id,
            ]);

            return null;
        });
    }

    /**
     * Create a shipment and get the shipping label.
     *
     * @param  array<string, mixed>  $senderAddress
     * @param  array<string, mixed>  $recipientAddress
     * @param  array<string, mixed>  $packageDetails
     */
    public function createShipment(
        array $senderAddress,
        array $recipientAddress,
        array $packageDetails,
        string $serviceType = 'FEDEX_2_DAY',
        string $labelFormat = 'PNG',
        string $packagingType = 'FEDEX_ENVELOPE'
    ): ShipmentResult {
        $token = $this->getAccessToken();

        if (! $token) {
            return ShipmentResult::failure('Failed to obtain FedEx access token');
        }

        $payload = $this->buildShipmentPayload(
            $senderAddress,
            $recipientAddress,
            $packageDetails,
            $serviceType,
            $labelFormat,
            $packagingType
        );

        $response = Http::withToken($token)
            ->timeout(30)
            ->post("{$this->baseUrl}/ship/v1/shipments", $payload);

        if ($response->successful()) {
            return $this->parseShipmentResponse($response->json());
        }

        Log::error('FedEx shipment creation failed', [
            'status' => $response->status(),
            'body' => $response->json(),
            'store_id' => $this->store?->id,
        ]);

        $errors = $response->json('errors', []);
        $errorMessage = ! empty($errors) ? $errors[0]['message'] ?? 'Shipment creation failed' : 'Shipment creation failed';
        $errorCode = ! empty($errors) ? $errors[0]['code'] ?? null : null;

        // Mark integration as error if using store-specific credentials
        if ($this->integration && $response->status() === 401) {
            $this->integration->markAsError($errorMessage);
        }

        return ShipmentResult::failure($errorMessage, $errorCode, $response->json());
    }

    /**
     * Create a return shipment label.
     *
     * @param  array<string, mixed>  $originalShipperAddress
     * @param  array<string, mixed>  $returnFromAddress
     * @param  array<string, mixed>  $packageDetails
     */
    public function createReturnShipment(
        array $originalShipperAddress,
        array $returnFromAddress,
        array $packageDetails,
        string $serviceType = 'FEDEX_2_DAY',
        string $packagingType = 'FEDEX_ENVELOPE'
    ): ShipmentResult {
        // For return shipments, swap sender and recipient
        return $this->createShipment(
            $returnFromAddress,
            $originalShipperAddress,
            $packageDetails,
            $serviceType,
            'PDF',
            $packagingType
        );
    }

    /**
     * Void a shipment.
     */
    public function voidShipment(string $trackingNumber): bool
    {
        $token = $this->getAccessToken();

        if (! $token) {
            return false;
        }

        $response = Http::withToken($token)
            ->put("{$this->baseUrl}/ship/v1/shipments/cancel", [
                'accountNumber' => [
                    'value' => $this->accountNumber,
                ],
                'trackingNumber' => $trackingNumber,
            ]);

        if ($response->successful()) {
            return true;
        }

        Log::error('FedEx void shipment failed', [
            'tracking_number' => $trackingNumber,
            'status' => $response->status(),
            'body' => $response->json(),
            'store_id' => $this->store?->id,
        ]);

        return false;
    }

    /**
     * Track a shipment.
     *
     * @return array<string, mixed>|null
     */
    public function trackShipment(string $trackingNumber): ?array
    {
        $token = $this->getAccessToken();

        if (! $token) {
            return null;
        }

        $response = Http::withToken($token)
            ->post("{$this->baseUrl}/track/v1/trackingnumbers", [
                'trackingInfo' => [
                    [
                        'trackingNumberInfo' => [
                            'trackingNumber' => $trackingNumber,
                        ],
                    ],
                ],
                'includeDetailedScans' => true,
            ]);

        if ($response->successful()) {
            return $response->json();
        }

        Log::error('FedEx tracking failed', [
            'tracking_number' => $trackingNumber,
            'status' => $response->status(),
            'body' => $response->json(),
            'store_id' => $this->store?->id,
        ]);

        return null;
    }

    /**
     * Get shipping rates for a package.
     *
     * @param  array<string, mixed>  $senderAddress
     * @param  array<string, mixed>  $recipientAddress
     * @param  array<string, mixed>  $packageDetails
     * @return array<mixed>
     */
    public function getRates(
        array $senderAddress,
        array $recipientAddress,
        array $packageDetails
    ): array {
        $token = $this->getAccessToken();

        if (! $token) {
            return [];
        }

        $response = Http::withToken($token)
            ->post("{$this->baseUrl}/rate/v1/rates/quotes", [
                'accountNumber' => [
                    'value' => $this->accountNumber,
                ],
                'requestedShipment' => [
                    'shipper' => [
                        'address' => $this->formatAddress($senderAddress),
                    ],
                    'recipient' => [
                        'address' => $this->formatAddress($recipientAddress),
                    ],
                    'pickupType' => 'DROPOFF_AT_FEDEX_LOCATION',
                    'requestedPackageLineItems' => [
                        $this->formatPackage($packageDetails),
                    ],
                ],
            ]);

        if ($response->successful()) {
            return $response->json('output.rateReplyDetails', []);
        }

        return [];
    }

    /**
     * Build the shipment request payload.
     *
     * @param  array<string, mixed>  $senderAddress
     * @param  array<string, mixed>  $recipientAddress
     * @param  array<string, mixed>  $packageDetails
     * @return array<string, mixed>
     */
    protected function buildShipmentPayload(
        array $senderAddress,
        array $recipientAddress,
        array $packageDetails,
        string $serviceType,
        string $labelFormat,
        string $packagingType = 'FEDEX_ENVELOPE'
    ): array {
        $payload = [
            'mergeLabelDocOption' => 'LABELS_ONLY',
            'labelResponseOptions' => 'LABEL',
            'requestedShipment' => [
                'shipper' => [
                    'contact' => [
                        'personName' => $senderAddress['name'] ?? $senderAddress['company'] ?? '',
                        'phoneNumber' => $senderAddress['phone'] ?? '2679809681',
                        'companyName' => $senderAddress['company'] ?? $senderAddress['name'] ?? '',
                    ],
                    'address' => $this->formatAddress($senderAddress),
                ],
                'recipients' => [
                    [
                        'contact' => [
                            'personName' => $recipientAddress['name'] ?? $recipientAddress['company'] ?? '',
                            'phoneNumber' => $recipientAddress['phone'] ?? '2679809681',
                            'companyName' => $recipientAddress['company'] ?? '',
                        ],
                        'address' => $this->formatAddress($recipientAddress),
                    ],
                ],
                'pickupType' => 'DROPOFF_AT_FEDEX_LOCATION',
                'serviceType' => $serviceType,
                'packagingType' => $packagingType,
                'totalPackageCount' => 1,
                'shippingChargesPayment' => [
                    'paymentType' => 'SENDER',
                    'payor' => [
                        'responsibleParty' => [
                            'accountNumber' => [
                                'value' => $this->accountNumber,
                            ],
                        ],
                    ],
                ],
                'labelSpecification' => [
                    'labelFormatType' => 'COMMON2D',
                    'imageType' => $labelFormat,
                    'labelStockType' => 'PAPER_4X6',
                ],
                'shipmentSpecialServices' => [
                    'specialServiceTypes' => ['FEDEX_ONE_RATE'],
                ],
                'requestedPackageLineItems' => [
                    $this->formatPackageWithSpecialServices($packageDetails),
                ],
            ],
            'accountNumber' => [
                'value' => $this->accountNumber,
            ],
        ];

        return $payload;
    }

    /**
     * Format package details with special services for FedEx One Rate.
     *
     * @param  array<string, mixed>  $packageDetails
     * @return array<string, mixed>
     */
    protected function formatPackageWithSpecialServices(array $packageDetails): array
    {
        return [
            'sequenceNumber' => 1,
            'groupPackageCount' => 1,
            'weight' => [
                'units' => 'LB',
                'value' => $packageDetails['weight'] ?? 1,
            ],
            'dimensions' => [
                'length' => $packageDetails['length'] ?? 12,
                'width' => $packageDetails['width'] ?? 12,
                'height' => $packageDetails['height'] ?? 6,
                'units' => 'IN',
            ],
            'packageSpecialServices' => [
                'signatureOptionType' => 'SERVICE_DEFAULT',
            ],
        ];
    }

    /**
     * Format an address for the FedEx API.
     *
     * @param  array<string, mixed>  $address
     * @return array<string, mixed>
     */
    protected function formatAddress(array $address): array
    {
        return [
            'streetLines' => array_filter([
                $address['street'] ?? $address['address'] ?? '',
                $address['street2'] ?? $address['address2'] ?? null,
            ]),
            'city' => $address['city'] ?? '',
            'stateOrProvinceCode' => $address['state'] ?? '',
            'postalCode' => $address['postal_code'] ?? $address['zip'] ?? '',
            'countryCode' => $address['country'] ?? 'US',
        ];
    }

    /**
     * Format package details for the FedEx API.
     *
     * @param  array<string, mixed>  $packageDetails
     * @return array<string, mixed>
     */
    protected function formatPackage(array $packageDetails): array
    {
        return [
            'weight' => [
                'units' => 'LB',
                'value' => $packageDetails['weight'] ?? 1,
            ],
            'dimensions' => [
                'length' => $packageDetails['length'] ?? 12,
                'width' => $packageDetails['width'] ?? 12,
                'height' => $packageDetails['height'] ?? 6,
                'units' => 'IN',
            ],
        ];
    }

    /**
     * Parse the shipment response from FedEx.
     *
     * @param  array<string, mixed>  $response
     */
    protected function parseShipmentResponse(array $response): ShipmentResult
    {
        $shipmentDetails = $response['output']['transactionShipments'][0] ?? null;

        if (! $shipmentDetails) {
            return ShipmentResult::failure('Invalid shipment response', null, $response);
        }

        $pieceResponses = $shipmentDetails['pieceResponses'][0] ?? null;
        $trackingNumber = $pieceResponses['trackingNumber'] ?? $shipmentDetails['masterTrackingNumber'] ?? null;
        $shipmentId = $shipmentDetails['shipmentId'] ?? null;

        $labelData = $pieceResponses['packageDocuments'][0] ?? null;
        $labelPdf = $labelData['encodedLabel'] ?? null;

        $totalCharge = $shipmentDetails['completedShipmentDetail']['shipmentRating']['actualRateType'] ?? null;
        $shippingCost = $shipmentDetails['completedShipmentDetail']['shipmentRating']['shipmentRateDetails'][0]['totalNetCharge'] ?? null;

        if (! $trackingNumber || ! $labelPdf) {
            return ShipmentResult::failure('Missing tracking number or label', null, $response);
        }

        return ShipmentResult::success(
            trackingNumber: $trackingNumber,
            shipmentId: $shipmentId ?? $trackingNumber,
            labelPdf: $labelPdf,
            shippingCost: $shippingCost,
            rawResponse: $response,
        );
    }
}
