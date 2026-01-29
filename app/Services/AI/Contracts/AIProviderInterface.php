<?php

namespace App\Services\AI\Contracts;

interface AIProviderInterface
{
    public function getName(): string;

    public function getDefaultModel(): string;

    public function chat(string $prompt, array $options = []): AIResponse;

    public function chatWithSystem(string $systemPrompt, string $userPrompt, array $options = []): AIResponse;

    public function generateJson(string $prompt, array $schema, array $options = []): AIResponse;

    public function isAvailable(): bool;
}
