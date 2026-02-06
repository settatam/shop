<?php

namespace App\Services\Agents\Results;

class AgentRunResult
{
    public function __construct(
        public readonly bool $success,
        public readonly array $summary,
        public readonly int $actionsCreated = 0,
        public readonly int $actionsExecuted = 0,
        public readonly ?string $errorMessage = null,
    ) {}

    public static function success(array $summary, int $actionsCreated = 0, int $actionsExecuted = 0): self
    {
        return new self(
            success: true,
            summary: $summary,
            actionsCreated: $actionsCreated,
            actionsExecuted: $actionsExecuted,
        );
    }

    public static function failure(string $errorMessage, array $summary = []): self
    {
        return new self(
            success: false,
            summary: $summary,
            errorMessage: $errorMessage,
        );
    }
}
