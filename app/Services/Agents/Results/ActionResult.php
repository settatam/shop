<?php

namespace App\Services\Agents\Results;

class ActionResult
{
    public function __construct(
        public readonly bool $success,
        public readonly ?string $message = null,
        public readonly array $data = [],
    ) {}

    public static function success(?string $message = null, array $data = []): self
    {
        return new self(
            success: true,
            message: $message,
            data: $data,
        );
    }

    public static function failure(string $message, array $data = []): self
    {
        return new self(
            success: false,
            message: $message,
            data: $data,
        );
    }
}
