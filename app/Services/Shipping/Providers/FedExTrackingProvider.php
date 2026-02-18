<?php

namespace App\Services\Shipping\Providers;

use App\Models\Store;
use App\Models\StoreIntegration;
use App\Services\Shipping\Contracts\TrackingProviderInterface;
use App\Services\Shipping\FedExService;
use App\Services\Shipping\TrackingResult;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FedExTrackingProvider implements TrackingProviderInterface
{
    protected ?Store $store;

    protected ?StoreIntegration $trackingIntegration;

    protected string $trackingApiKey;

    protected string $trackingApiSecret;

    protected string $baseUrl;

    protected bool $useShippingCredentials;

    public function __construct(?Store $store = null)
    {
        $this->store = $store;
        $this->trackingIntegration = null;
        $this->useShippingCredentials = false;

        // First, try to load dedicated tracking credentials
        if ($store) {
            $this->trackingIntegration = StoreIntegration::findActiveForStore(
                $store->id,
                StoreIntegration::PROVIDER_FEDEX_TRACKING ?? 'fedex_tracking'
            );
        }

        if ($this->trackingIntegration) {
            // Use dedicated tracking credentials
            $this->trackingApiKey = $this->trackingIntegration->getClientId() ?? '';
            $this->trackingApiSecret = $this->trackingIntegration->getClientSecret() ?? '';
            $this->baseUrl = $this->trackingIntegration->getApiBaseUrl();
        } else {
            // Fall back to config for dedicated tracking API
            $this->trackingApiKey = config('logistics.fedex.tracking_key')
                ?? config('services.fedex.tracking_api_key')
                ?? '';
            $this->trackingApiSecret = config('logistics.fedex.tracking_secret')
                ?? config('services.fedex.tracking_api_secret')
                ?? '';

            $mode = config('logistics.fedex.mode') ?? config('services.fedex.mode') ?? 'production';
            $this->baseUrl = $mode === 'production'
                ? 'https://apis.fedex.com'
                : 'https://apis-sandbox.fedex.com';

            // If no dedicated tracking credentials, fall back to shipping credentials
            if (empty($this->trackingApiKey) || empty($this->trackingApiSecret)) {
                $this->useShippingCredentials = true;
            }
        }
    }

    public function getCarrierCode(): string
    {
        return 'fedex';
    }

    public function getCarrierName(): string
    {
        return 'FedEx';
    }

    public function isConfigured(): bool
    {
        if ($this->useShippingCredentials) {
            // Check if FedExService is configured
            $fedExService = $this->store
                ? FedExService::forStore($this->store)
                : new FedExService;

            return $fedExService->isConfigured();
        }

        return ! empty($this->trackingApiKey) && ! empty($this->trackingApiSecret);
    }

    public function track(string $trackingNumber): ?TrackingResult
    {
        // Use FedExService if no dedicated tracking credentials
        if ($this->useShippingCredentials) {
            return $this->trackViaFedExService($trackingNumber);
        }

        return $this->trackViaDedicatedApi($trackingNumber);
    }

    public function trackMultiple(array $trackingNumbers): array
    {
        $results = [];

        // FedEx API supports up to 30 tracking numbers per request
        $chunks = array_chunk($trackingNumbers, 30);

        foreach ($chunks as $chunk) {
            if ($this->useShippingCredentials) {
                // Track one by one using FedExService
                foreach ($chunk as $trackingNumber) {
                    $results[$trackingNumber] = $this->trackViaFedExService($trackingNumber);
                }
            } else {
                // Use batch tracking API
                $batchResults = $this->trackBatchViaDedicatedApi($chunk);
                $results = array_merge($results, $batchResults);
            }
        }

        return $results;
    }

    public function canHandleTrackingNumber(string $trackingNumber): bool
    {
        // FedEx tracking number patterns:
        // - 12 digits (Express)
        // - 15 digits (Ground)
        // - 20-22 digits starting with 96 (Ground 96 / SmartPost)
        // - Door Tag: starts with DT followed by 12 digits

        $cleaned = preg_replace('/[^0-9A-Za-z]/', '', $trackingNumber);
        $length = strlen($cleaned);

        // Check Door Tag pattern
        if (preg_match('/^DT\d{12}$/', $cleaned)) {
            return true;
        }

        // FedEx Express (12 digits)
        if ($length === 12 && preg_match('/^\d{12}$/', $cleaned)) {
            return true;
        }

        // FedEx Ground (15 digits)
        if ($length === 15 && preg_match('/^\d{15}$/', $cleaned)) {
            return true;
        }

        // FedEx Ground 96 / SmartPost (20-22 digits starting with 96)
        // These start with 96 to distinguish from USPS
        if ($length >= 20 && $length <= 22 && str_starts_with($cleaned, '96')) {
            return true;
        }

        return false;
    }

    public static function forStore(Store $store): static
    {
        return new static($store);
    }

    /**
     * Track using the existing FedExService (uses shipping API credentials).
     */
    protected function trackViaFedExService(string $trackingNumber): ?TrackingResult
    {
        $fedExService = $this->store
            ? FedExService::forStore($this->store)
            : new FedExService;

        $response = $fedExService->trackShipment($trackingNumber);

        if (! $response) {
            return null;
        }

        return TrackingResult::fromFedExResponse($response, $trackingNumber);
    }

    /**
     * Track using dedicated tracking API credentials.
     */
    protected function trackViaDedicatedApi(string $trackingNumber): ?TrackingResult
    {
        $token = $this->getAccessToken();

        if (! $token) {
            Log::warning('FedEx tracking: Failed to get access token');

            return null;
        }

        $response = Http::withToken($token)
            ->timeout(30)
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
            $this->trackingIntegration?->recordUsage();

            return TrackingResult::fromFedExResponse($response->json(), $trackingNumber);
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
     * Track multiple numbers in a single API call.
     *
     * @param  array<string>  $trackingNumbers
     * @return array<string, TrackingResult|null>
     */
    protected function trackBatchViaDedicatedApi(array $trackingNumbers): array
    {
        $token = $this->getAccessToken();

        if (! $token) {
            return array_fill_keys($trackingNumbers, null);
        }

        $trackingInfo = array_map(fn ($num) => [
            'trackingNumberInfo' => [
                'trackingNumber' => $num,
            ],
        ], $trackingNumbers);

        $response = Http::withToken($token)
            ->timeout(60)
            ->post("{$this->baseUrl}/track/v1/trackingnumbers", [
                'trackingInfo' => $trackingInfo,
                'includeDetailedScans' => true,
            ]);

        $results = array_fill_keys($trackingNumbers, null);

        if ($response->successful()) {
            $this->trackingIntegration?->recordUsage();
            $data = $response->json();

            foreach ($data['output']['completeTrackResults'] ?? [] as $trackResult) {
                $trackingNumber = $trackResult['trackingNumber'] ?? null;
                if ($trackingNumber && isset($results[$trackingNumber])) {
                    $results[$trackingNumber] = TrackingResult::fromFedExResponse(
                        ['output' => ['completeTrackResults' => [$trackResult]]],
                        $trackingNumber
                    );
                }
            }
        }

        return $results;
    }

    /**
     * Get OAuth access token for tracking API.
     */
    protected function getAccessToken(): ?string
    {
        $cacheKey = $this->store
            ? "fedex_tracking_token_{$this->store->id}"
            : 'fedex_tracking_token';

        return Cache::remember($cacheKey, 3500, function () {
            $response = Http::asForm()->post("{$this->baseUrl}/oauth/token", [
                'grant_type' => 'client_credentials',
                'client_id' => $this->trackingApiKey,
                'client_secret' => $this->trackingApiSecret,
            ]);

            if ($response->successful()) {
                $this->trackingIntegration?->recordUsage();

                return $response->json('access_token');
            }

            Log::error('FedEx tracking OAuth failed', [
                'status' => $response->status(),
                'body' => $response->body(),
                'store_id' => $this->store?->id,
            ]);

            return null;
        });
    }
}
