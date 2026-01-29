<?php

namespace App\Services\Gateways\Results;

readonly class CancelResult
{
    public function __construct(
        public bool $success,
        public ?string $checkoutId = null,
        public ?string $status = null,
        public ?string $errorMessage = null,
        public ?string $errorCode = null,
        public array $gatewayResponse = [],
    ) {}

    public static function success(string $checkoutId, string $status = 'cancelled', array $gatewayResponse = []): self
    {
        return new self(
            success: true,
            checkoutId: $checkoutId,
            status: $status,
            gatewayResponse: $gatewayResponse,
        );
    }

    public static function failure(string $errorMessage, ?string $errorCode = null, array $gatewayResponse = []): self
    {
        return new self(
            success: false,
            errorMessage: $errorMessage,
            errorCode: $errorCode,
            gatewayResponse: $gatewayResponse,
        );
    }
}
