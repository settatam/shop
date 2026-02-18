<?php

namespace App\Services\Shipping;

use Carbon\Carbon;

class TrackingResult
{
    public const STATUS_UNKNOWN = 'unknown';

    public const STATUS_LABEL_CREATED = 'label_created';

    public const STATUS_PICKED_UP = 'picked_up';

    public const STATUS_IN_TRANSIT = 'in_transit';

    public const STATUS_OUT_FOR_DELIVERY = 'out_for_delivery';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_EXCEPTION = 'exception';

    public const STATUS_RETURNED = 'returned';

    public function __construct(
        public readonly string $trackingNumber,
        public readonly string $status,
        public readonly string $statusDescription,
        public readonly ?Carbon $estimatedDelivery,
        public readonly ?Carbon $actualDelivery,
        public readonly ?string $signedBy,
        public readonly ?string $currentLocation,
        public readonly array $events,
        public readonly array $rawResponse,
    ) {}

    /**
     * Parse a FedEx tracking API response into a TrackingResult.
     *
     * @param  array<string, mixed>  $response
     */
    public static function fromFedExResponse(array $response, string $trackingNumber): self
    {
        $trackResults = $response['output']['completeTrackResults'][0]['trackResults'][0] ?? null;

        if (! $trackResults) {
            return new self(
                trackingNumber: $trackingNumber,
                status: self::STATUS_UNKNOWN,
                statusDescription: 'No tracking information available',
                estimatedDelivery: null,
                actualDelivery: null,
                signedBy: null,
                currentLocation: null,
                events: [],
                rawResponse: $response,
            );
        }

        // Get the latest status
        $latestStatus = $trackResults['latestStatusDetail'] ?? [];
        $statusCode = $latestStatus['code'] ?? 'UN';
        $statusDescription = $latestStatus['description'] ?? 'Unknown';
        $derivedCode = $latestStatus['derivedCode'] ?? $statusCode;

        // Map FedEx status codes to our statuses
        $status = self::mapFedExStatus($statusCode, $derivedCode);

        // Get delivery dates
        $estimatedDelivery = null;
        $actualDelivery = null;

        if (isset($trackResults['dateAndTimes'])) {
            foreach ($trackResults['dateAndTimes'] as $dateTime) {
                $type = $dateTime['type'] ?? '';
                $dateStr = $dateTime['dateTime'] ?? null;

                if ($dateStr) {
                    if ($type === 'ESTIMATED_DELIVERY') {
                        $estimatedDelivery = Carbon::parse($dateStr);
                    } elseif ($type === 'ACTUAL_DELIVERY') {
                        $actualDelivery = Carbon::parse($dateStr);
                    }
                }
            }
        }

        // Get signed by info
        $signedBy = $trackResults['deliveryDetails']['signedBy'] ?? null;

        // Get current location
        $currentLocation = null;
        if (isset($latestStatus['scanLocation'])) {
            $loc = $latestStatus['scanLocation'];
            $parts = array_filter([
                $loc['city'] ?? null,
                $loc['stateOrProvinceCode'] ?? null,
                $loc['countryCode'] ?? null,
            ]);
            $currentLocation = implode(', ', $parts);
        }

        // Parse scan events
        $events = [];
        foreach ($trackResults['scanEvents'] ?? [] as $event) {
            $eventDate = isset($event['date']) ? Carbon::parse($event['date']) : null;
            $eventLocation = null;
            if (isset($event['scanLocation'])) {
                $loc = $event['scanLocation'];
                $parts = array_filter([
                    $loc['city'] ?? null,
                    $loc['stateOrProvinceCode'] ?? null,
                ]);
                $eventLocation = implode(', ', $parts);
            }

            $events[] = [
                'date' => $eventDate?->format('Y-m-d H:i:s'),
                'description' => $event['eventDescription'] ?? 'Unknown event',
                'location' => $eventLocation,
                'code' => $event['eventType'] ?? null,
            ];
        }

        return new self(
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
     * Map FedEx status codes to our internal statuses.
     */
    protected static function mapFedExStatus(string $statusCode, string $derivedCode): string
    {
        // FedEx status codes
        // https://developer.fedex.com/api/en-us/guides/api-reference.html#trackstatuscode

        return match ($statusCode) {
            'PU' => self::STATUS_PICKED_UP, // Picked Up
            'IT' => self::STATUS_IN_TRANSIT, // In Transit
            'OD' => self::STATUS_OUT_FOR_DELIVERY, // Out for Delivery
            'DL' => self::STATUS_DELIVERED, // Delivered
            'DE', 'CA', 'SE', 'HP' => self::STATUS_EXCEPTION, // Delivery Exception, Cancelled, Shipment Exception, Hold at Location
            'RS' => self::STATUS_RETURNED, // Return to Shipper
            'OC' => self::STATUS_LABEL_CREATED, // Order Created (label created)
            default => match (true) {
                str_contains($derivedCode, 'DELIVERED') => self::STATUS_DELIVERED,
                str_contains($derivedCode, 'TRANSIT') => self::STATUS_IN_TRANSIT,
                str_contains($derivedCode, 'EXCEPTION') => self::STATUS_EXCEPTION,
                default => self::STATUS_UNKNOWN,
            },
        };
    }

    /**
     * Check if the shipment has been delivered.
     */
    public function isDelivered(): bool
    {
        return $this->status === self::STATUS_DELIVERED;
    }

    /**
     * Check if the shipment is in transit (not yet delivered).
     */
    public function isInTransit(): bool
    {
        return in_array($this->status, [
            self::STATUS_PICKED_UP,
            self::STATUS_IN_TRANSIT,
            self::STATUS_OUT_FOR_DELIVERY,
        ]);
    }

    /**
     * Check if there's an exception/problem with the shipment.
     */
    public function hasException(): bool
    {
        return $this->status === self::STATUS_EXCEPTION;
    }

    /**
     * Get a human-readable status label.
     */
    public function getStatusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_LABEL_CREATED => 'Label Created',
            self::STATUS_PICKED_UP => 'Picked Up',
            self::STATUS_IN_TRANSIT => 'In Transit',
            self::STATUS_OUT_FOR_DELIVERY => 'Out for Delivery',
            self::STATUS_DELIVERED => 'Delivered',
            self::STATUS_EXCEPTION => 'Exception',
            self::STATUS_RETURNED => 'Returned to Sender',
            default => 'Unknown',
        };
    }
}
