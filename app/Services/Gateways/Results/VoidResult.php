<?php

namespace App\Services\Gateways\Results;

readonly class VoidResult
{
    public function __construct(
        public bool $success,
        public ?string $voidId = null,
        public ?string $status = null,
        public ?string $errorMessage = null,
        public ?string $errorCode = null,
        public array $gatewayResponse = [],
    ) {}

    public static function success(string $voidId, string $status = 'voided', array $gatewayResponse = []): self
    {
        return new self(
            success: true,
            voidId: $voidId,
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
