<?php

namespace App\Services\AI\Contracts;

class AIResponse
{
    public function __construct(
        public readonly string $content,
        public readonly string $provider,
        public readonly string $model,
        public readonly int $inputTokens,
        public readonly int $outputTokens,
        public readonly ?int $durationMs = null,
        public readonly ?array $rawResponse = null
    ) {}

    public function totalTokens(): int
    {
        return $this->inputTokens + $this->outputTokens;
    }

    public function toJson(): ?array
    {
        $decoded = json_decode($this->content, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    }
}
