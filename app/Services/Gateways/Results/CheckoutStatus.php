<?php

namespace App\Services\Gateways\Results;

readonly class CheckoutStatus
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_FAILED = 'failed';

    public const STATUS_TIMEOUT = 'timeout';

    public function __construct(
        public string $checkoutId,
        public string $status,
        public ?string $paymentId = null,
        public ?float $amount = null,
        public ?string $cardBrand = null,
        public ?string $cardLastFour = null,
        public ?string $errorMessage = null,
        public ?string $errorCode = null,
        public array $gatewayResponse = [],
    ) {}

    public function isPending(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_IN_PROGRESS]);
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isFailed(): bool
    {
        return in_array($this->status, [self::STATUS_FAILED, self::STATUS_TIMEOUT]);
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function isTerminal(): bool
    {
        return $this->isCompleted() || $this->isFailed() || $this->isCancelled();
    }
}
