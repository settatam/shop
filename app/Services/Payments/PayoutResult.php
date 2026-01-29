<?php

namespace App\Services\Payments;

readonly class PayoutResult
{
    public function __construct(
        public bool $success,
        public ?string $batchId = null,
        public ?string $payoutItemId = null,
        public ?float $amount = null,
        public ?string $status = null,
        public ?string $errorMessage = null,
        public ?string $errorCode = null,
        public array $gatewayResponse = [],
    ) {}

    public static function success(
        string $batchId,
        string $payoutItemId,
        float $amount,
        string $status = 'pending',
        array $gatewayResponse = []
    ): self {
        return new self(
            success: true,
            batchId: $batchId,
            payoutItemId: $payoutItemId,
            amount: $amount,
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
