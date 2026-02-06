<?php

namespace App\Services\Voice;

class TranscriptionResult
{
    public function __construct(
        public readonly bool $success,
        public readonly string $text,
        public readonly ?string $error = null
    ) {}

    public static function success(string $text): self
    {
        return new self(true, $text);
    }

    public static function failure(string $error): self
    {
        return new self(false, '', $error);
    }
}
