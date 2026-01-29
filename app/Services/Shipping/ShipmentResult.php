<?php

namespace App\Services\Shipping;

class ShipmentResult
{
    public function __construct(
        public bool $success,
        public ?string $trackingNumber = null,
        public ?string $shipmentId = null,
        public ?string $labelPdf = null,
        public ?string $labelZpl = null,
        public ?float $shippingCost = null,
        public ?string $errorMessage = null,
        public ?string $errorCode = null,
        public ?array $rawResponse = null,
    ) {}

    public static function success(
        string $trackingNumber,
        string $shipmentId,
        string $labelPdf,
        ?string $labelZpl = null,
        ?float $shippingCost = null,
        ?array $rawResponse = null,
    ): self {
        return new self(
            success: true,
            trackingNumber: $trackingNumber,
            shipmentId: $shipmentId,
            labelPdf: $labelPdf,
            labelZpl: $labelZpl,
            shippingCost: $shippingCost,
            rawResponse: $rawResponse,
        );
    }

    public static function failure(
        string $errorMessage,
        ?string $errorCode = null,
        ?array $rawResponse = null,
    ): self {
        return new self(
            success: false,
            errorMessage: $errorMessage,
            errorCode: $errorCode,
            rawResponse: $rawResponse,
        );
    }
}
