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

class UspsTrackingProvider implements TrackingProviderInterface
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
                StoreIntegration::PROVIDER_USPS ?? 'usps'
            );
        }

        if ($this->integration) {
            $this->clientId = $this->integration->getClientId() ?? '';
            $this->clientSecret = $this->integration->getClientSecret() ?? '';
            $this->baseUrl = $this->integration->getApiBaseUrl();
        } else {
            $this->clientId = config('logistics.usps.client_id')
                ?? config('services.usps.client_id')
                ?? '';
            $this->clientSecret = config('logistics.usps.client_secret')
                ?? config('services.usps.client_secret')
                ?? '';

            $mode = config('logistics.usps.mode') ?? 'production';
            $this->baseUrl = $mode === 'production'
                ? 'https://api.usps.com'
                : 'https://api-cat.usps.com';
        }
    }

    public function getCarrierCode(): string
    {
        return 'usps';
    }

    public function getCarrierName(): string
    {
        return 'USPS';
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
            ->timeout(30)
            ->get("{$this->baseUrl}/tracking/v3/tracking/{$trackingNumber}", [
                'expand' => 'DETAIL',
            ]);

        if ($response->successful()) {
            $this->integration?->recordUsage();

            return $this->parseUspsResponse($response->json(), $trackingNumber);
        }

        Log::error('USPS tracking failed', [
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

        // USPS API supports one tracking number at a time in v3
        foreach ($trackingNumbers as $trackingNumber) {
            $results[$trackingNumber] = $this->track($trackingNumber);
        }

        return $results;
    }

    public function canHandleTrackingNumber(string $trackingNumber): bool
    {
        // USPS tracking number patterns:
        // - 20-22 digits (most common)
        // - 13 characters starting with 2 letters, ending with US (international)
        // - 30 digits (S10 format for Priority Mail Express)

        $cleaned = preg_replace('/[^0-9A-Za-z]/', '', $trackingNumber);
        $length = strlen($cleaned);

        // Standard domestic (20-22 digits starting with specific prefixes)
        if ($length >= 20 && $length <= 22 && preg_match('/^\d+$/', $cleaned)) {
            // Common USPS prefixes
            $prefixes = ['94', '93', '92', '91', '70', '82', '13'];
            foreach ($prefixes as $prefix) {
                if (str_starts_with($cleaned, $prefix)) {
                    return true;
                }
            }
        }

        // International format (13 chars, letters + numbers + US)
        if ($length === 13 && preg_match('/^[A-Z]{2}\d{9}US$/i', $cleaned)) {
            return true;
        }

        // Priority Mail Express (30 digits)
        if ($length === 30 && preg_match('/^\d+$/', $cleaned)) {
            return true;
        }

        return false;
    }

    public static function forStore(Store $store): static
    {
        return new static($store);
    }

    /**
     * Parse USPS tracking response into TrackingResult.
     *
     * @param  array<string, mixed>  $response
     */
    protected function parseUspsResponse(array $response, string $trackingNumber): TrackingResult
    {
        $tracking = $response['tracking'] ?? null;

        if (! $tracking) {
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

        $statusCategory = $tracking['statusCategory'] ?? 'Unknown';
        $statusDescription = $tracking['statusSummary'] ?? 'Unknown';

        $status = $this->mapUspsStatus($statusCategory);

        // Get delivery dates
        $estimatedDelivery = null;
        $actualDelivery = null;

        if (isset($tracking['expectedDelivery'])) {
            $estimatedDelivery = Carbon::parse($tracking['expectedDelivery']);
        }

        if ($status === TrackingResult::STATUS_DELIVERED && isset($tracking['trackingEvents'][0]['eventDate'])) {
            $actualDelivery = Carbon::parse($tracking['trackingEvents'][0]['eventDate']);
        }

        $signedBy = $tracking['signedForName'] ?? null;

        // Get current location
        $currentLocation = null;
        if (isset($tracking['trackingEvents'][0]['eventCity'])) {
            $event = $tracking['trackingEvents'][0];
            $parts = array_filter([
                $event['eventCity'] ?? null,
                $event['eventState'] ?? null,
            ]);
            $currentLocation = implode(', ', $parts);
        }

        // Parse events
        $events = [];
        foreach ($tracking['trackingEvents'] ?? [] as $event) {
            $eventDate = null;
            if (isset($event['eventDate'])) {
                $time = $event['eventTime'] ?? '00:00:00';
                $eventDate = Carbon::parse($event['eventDate'].' '.$time);
            }

            $eventLocation = null;
            $parts = array_filter([
                $event['eventCity'] ?? null,
                $event['eventState'] ?? null,
            ]);
            $eventLocation = ! empty($parts) ? implode(', ', $parts) : null;

            $events[] = [
                'date' => $eventDate?->format('Y-m-d H:i:s'),
                'description' => $event['eventDescription'] ?? 'Unknown event',
                'location' => $eventLocation,
                'code' => $event['eventCode'] ?? null,
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
     * Map USPS status categories to our internal statuses.
     */
    protected function mapUspsStatus(string $statusCategory): string
    {
        return match (strtoupper($statusCategory)) {
            'ACCEPTED' => TrackingResult::STATUS_PICKED_UP,
            'IN_TRANSIT', 'IN TRANSIT' => TrackingResult::STATUS_IN_TRANSIT,
            'OUT_FOR_DELIVERY', 'OUT FOR DELIVERY' => TrackingResult::STATUS_OUT_FOR_DELIVERY,
            'DELIVERED' => TrackingResult::STATUS_DELIVERED,
            'ALERT', 'EXCEPTION' => TrackingResult::STATUS_EXCEPTION,
            'RETURNED', 'RETURN_TO_SENDER' => TrackingResult::STATUS_RETURNED,
            'PRE_SHIPMENT', 'PRE-SHIPMENT' => TrackingResult::STATUS_LABEL_CREATED,
            default => TrackingResult::STATUS_UNKNOWN,
        };
    }

    /**
     * Get OAuth access token.
     */
    protected function getAccessToken(): ?string
    {
        $cacheKey = $this->store
            ? "usps_tracking_token_{$this->store->id}"
            : 'usps_tracking_token';

        return Cache::remember($cacheKey, 3500, function () {
            $response = Http::asForm()
                ->post("{$this->baseUrl}/oauth2/v3/token", [
                    'grant_type' => 'client_credentials',
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                ]);

            if ($response->successful()) {
                $this->integration?->recordUsage();

                return $response->json('access_token');
            }

            Log::error('USPS OAuth failed', [
                'status' => $response->status(),
                'body' => $response->body(),
                'store_id' => $this->store?->id,
            ]);

            return null;
        });
    }
}
