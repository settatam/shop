<?php

namespace App\Services\Voice;

class SynthesisResult
{
    public function __construct(
        public readonly bool $success,
        public readonly ?string $url = null,
        public readonly ?string $path = null,
        public readonly ?string $error = null
    ) {}

    public static function success(string $url, string $path): self
    {
        return new self(true, $url, $path);
    }

    public static function failure(string $error): self
    {
        return new self(false, null, null, $error);
    }
}
