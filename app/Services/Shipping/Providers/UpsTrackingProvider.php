<?php

namespace App\Services\Shipping\Providers;

use App\Models\Store;
use App\Models\StoreIntegration;
use App\Services\Shipping\Contracts\TrackingProviderInterface;
use App\Services\Shipping\TrackingResult;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class UpsTrackingProvider implements TrackingProviderInterface
{
    protected ?Store $store;

    protected ?StoreIntegration $integration;

    protected string $clientId;

    protected string $clientSecret;

    protected string $baseUrl;

    public function __construct(?Store $store = null)
    {
        $this->store = $store;
        $this->integration = null;

        if ($store) {
            $this->integration = StoreIntegration::findActiveForStore(
                $store->id,
                StoreIntegration::PROVIDER_UPS ?? 'ups'
            );
        }

        if ($this->integration) {
            $this->clientId = $this->integration->getClientId() ?? '';
            $this->clientSecret = $this->integration->getClientSecret() ?? '';
            $this->baseUrl = $this->integration->getApiBaseUrl();
        } else {
            $this->clientId = config('logistics.ups.client_id')
                ?? config('services.ups.client_id')
                ?? '';
            $this->clientSecret = config('logistics.ups.client_secret')
                ?? config('services.ups.client_secret')
                ?? '';

            $mode = config('logistics.ups.mode') ?? 'production';
            $this->baseUrl = $mode === 'production'
                ? 'https://onlinetools.ups.com'
                : 'https://wwwcie.ups.com';
        }
    }

    public function getCarrierCode(): string
    {
        return 'ups';
    }

    public function getCarrierName(): string
    {
        return 'UPS';
    }

    public function isConfigured(): bool
    {
        return ! empty($this->clientId) && ! empty($this->clientSecret);
    }

    public function track(string $trackingNumber): ?TrackingResult
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $token = $this->getAccessToken();

        if (! $token) {
            return null;
        }

        $response = Http::withToken($token)
            ->withHeaders([
                'transId' => uniqid('track_'),
                'transactionSrc' => 'shopmata',
            ])
            ->timeout(30)
            ->get("{$this->baseUrl}/api/track/v1/details/{$trackingNumber}");

        if ($response->successful()) {
            $this->integration?->recordUsage();

            return $this->parseUpsResponse($response->json(), $trackingNumber);
        }

        Log::error('UPS tracking failed', [
            'tracking_number' => $trackingNumber,
            'status' => $response->status(),
            'body' => $response->json(),
            'store_id' => $this->store?->id,
        ]);

        return null;
    }

    public function trackMultiple(array $trackingNumbers): array
    {
        $results = [];

        // UPS API supports one tracking number at a time
        foreach ($trackingNumbers as $trackingNumber) {
            $results[$trackingNumber] = $this->track($trackingNumber);
        }

        return $results;
    }

    public function canHandleTrackingNumber(string $trackingNumber): bool
    {
        // UPS tracking number patterns:
        // - 1Z followed by 16 alphanumeric characters (most common)
        // - T followed by 10 digits (Mail Innovations)
        // - 9 digits (Ground Freight)

        $cleaned = preg_replace('/[^0-9A-Za-z]/', '', $trackingNumber);

        // 1Z pattern (most common UPS tracking)
        if (preg_match('/^1Z[A-Z0-9]{16}$/i', $cleaned)) {
            return true;
        }

        // Mail Innovations pattern
        if (preg_match('/^T\d{10}$/', $cleaned)) {
            return true;
        }

        // Ground Freight (9 digits)
        if (preg_match('/^\d{9}$/', $cleaned)) {
            return true;
        }

        return false;
    }

    public static function forStore(Store $store): static
    {
        return new static($store);
    }

    /**
     * Parse UPS tracking response into TrackingResult.
     *
     * @param  array<string, mixed>  $response
     */
    protected function parseUpsResponse(array $response, string $trackingNumber): TrackingResult
    {
        $package = $response['trackResponse']['shipment'][0]['package'][0] ?? null;

        if (! $package) {
            return new TrackingResult(
                trackingNumber: $trackingNumber,
                status: TrackingResult::STATUS_UNKNOWN,
                statusDescription: 'No tracking information available',
                estimatedDelivery: null,
                actualDelivery: null,
                signedBy: null,
                currentLocation: null,
                events: [],
                rawResponse: $response,
            );
        }

        $currentStatus = $package['currentStatus'] ?? [];
        $statusCode = $currentStatus['code'] ?? 'UN';
        $statusDescription = $currentStatus['description'] ?? 'Unknown';

        $status = $this->mapUpsStatus($statusCode);

        // Get delivery dates
        $estimatedDelivery = null;
        $actualDelivery = null;

        if (isset($package['deliveryDate'])) {
            foreach ($package['deliveryDate'] as $date) {
                if ($date['type'] === 'DEL') {
                    $actualDelivery = Carbon::parse($date['date']);
                } elseif ($date['type'] === 'EST') {
                    $estimatedDelivery = Carbon::parse($date['date']);
                }
            }
        }

        $signedBy = $package['deliveryInformation']['receivedBy'] ?? null;

        // Get current location
        $currentLocation = null;
        if (isset($currentStatus['location'])) {
            $loc = $currentStatus['location']['address'] ?? [];
            $parts = array_filter([
                $loc['city'] ?? null,
                $loc['stateProvince'] ?? null,
                $loc['country'] ?? null,
            ]);
            $currentLocation = implode(', ', $parts);
        }

        // Parse activity/events
        $events = [];
        foreach ($package['activity'] ?? [] as $activity) {
            $eventDate = null;
            if (isset($activity['date']) && isset($activity['time'])) {
                $eventDate = Carbon::parse($activity['date'].' '.$activity['time']);
            }

            $eventLocation = null;
            if (isset($activity['location']['address'])) {
                $loc = $activity['location']['address'];
                $parts = array_filter([
                    $loc['city'] ?? null,
                    $loc['stateProvince'] ?? null,
                ]);
                $eventLocation = implode(', ', $parts);
            }

            $events[] = [
                'date' => $eventDate?->format('Y-m-d H:i:s'),
                'description' => $activity['status']['description'] ?? 'Unknown event',
                'location' => $eventLocation,
                'code' => $activity['status']['code'] ?? null,
            ];
        }

        return new TrackingResult(
            trackingNumber: $trackingNumber,
            status: $status,
            statusDescription: $statusDescription,
            estimatedDelivery: $estimatedDelivery,
            actualDelivery: $actualDelivery,
            signedBy: $signedBy,
            currentLocation: $currentLocation,
            events: $events,
            rawResponse: $response,
        );
    }

    /**
     * Map UPS status codes to our internal statuses.
     */
    protected function mapUpsStatus(string $statusCode): string
    {
        return match ($statusCode) {
            'P' => TrackingResult::STATUS_PICKED_UP,
            'I' => TrackingResult::STATUS_IN_TRANSIT,
            'O' => TrackingResult::STATUS_OUT_FOR_DELIVERY,
            'D' => TrackingResult::STATUS_DELIVERED,
            'X' => TrackingResult::STATUS_EXCEPTION,
            'RS' => TrackingResult::STATUS_RETURNED,
            'M' => TrackingResult::STATUS_LABEL_CREATED,
            default => TrackingResult::STATUS_UNKNOWN,
        };
    }

    /**
     * Get OAuth access token.
     */
    protected function getAccessToken(): ?string
    {
        $cacheKey = $this->store
            ? "ups_tracking_token_{$this->store->id}"
            : 'ups_tracking_token';

        return Cache::remember($cacheKey, 3500, function () {
            $response = Http::withBasicAuth($this->clientId, $this->clientSecret)
                ->asForm()
                ->post("{$this->baseUrl}/security/v1/oauth/token", [
                    'grant_type' => 'client_credentials',
                ]);

            if ($response->successful()) {
                $this->integration?->recordUsage();

                return $response->json('access_token');
            }

            Log::error('UPS OAuth failed', [
                'status' => $response->status(),
                'body' => $response->body(),
                'store_id' => $this->store?->id,
            ]);

            return null;
        });
    }
}
