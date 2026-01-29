<?php

namespace App\Services\Gateways\Results;

use Carbon\Carbon;

readonly class CheckoutResult
{
    public function __construct(
        public bool $success,
        public ?string $checkoutId = null,
        public ?string $status = null,
        public ?Carbon $expiresAt = null,
        public ?string $errorMessage = null,
        public ?string $errorCode = null,
        public array $gatewayResponse = [],
    ) {}

    public static function success(string $checkoutId, string $status = 'pending', ?Carbon $expiresAt = null, array $gatewayResponse = []): self
    {
        return new self(
            success: true,
            checkoutId: $checkoutId,
            status: $status,
            expiresAt: $expiresAt,
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
