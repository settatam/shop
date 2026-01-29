<?php

namespace App\Services\Gateways\Results;

readonly class PaymentResult
{
    public function __construct(
        public bool $success,
        public ?string $paymentId = null,
        public ?float $amount = null,
        public ?string $status = null,
        public ?string $errorMessage = null,
        public ?string $errorCode = null,
        public array $gatewayResponse = [],
    ) {}

    public static function success(string $paymentId, float $amount, string $status = 'completed', array $gatewayResponse = []): self
    {
        return new self(
            success: true,
            paymentId: $paymentId,
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
