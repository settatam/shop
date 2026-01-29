<?php

namespace App\Services\Gateways\Results;

readonly class PairResult
{
    public function __construct(
        public bool $success,
        public ?string $deviceId = null,
        public ?string $deviceName = null,
        public ?string $status = null,
        public ?string $errorMessage = null,
        public ?string $errorCode = null,
        public array $capabilities = [],
        public array $gatewayResponse = [],
    ) {}

    public static function success(string $deviceId, ?string $deviceName = null, string $status = 'paired', array $capabilities = [], array $gatewayResponse = []): self
    {
        return new self(
            success: true,
            deviceId: $deviceId,
            deviceName: $deviceName,
            status: $status,
            capabilities: $capabilities,
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
