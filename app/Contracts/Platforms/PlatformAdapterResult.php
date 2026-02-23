<?php

namespace App\Contracts\Platforms;

class PlatformAdapterResult
{
    public function __construct(
        public readonly bool $success,
        public readonly ?string $message = null,
        public readonly ?string $externalId = null,
        public readonly ?string $externalUrl = null,
        public readonly array $data = [],
        public readonly ?\Throwable $exception = null,
    ) {}

    public static function success(?string $message = null, array $data = []): self
    {
        return new self(
            success: true,
            message: $message,
            data: $data,
        );
    }

    public static function created(string $externalId, ?string $externalUrl = null, array $data = []): self
    {
        return new self(
            success: true,
            message: 'Listing created successfully',
            externalId: $externalId,
            externalUrl: $externalUrl,
            data: $data,
        );
    }

    public static function failure(string $message, ?\Throwable $exception = null): self
    {
        return new self(
            success: false,
            message: $message,
            exception: $exception,
        );
    }

    public function failed(): bool
    {
        return ! $this->success;
    }
}
