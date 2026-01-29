<?php

namespace App\Services\AI\Providers;

use App\Services\AI\Contracts\AIProviderInterface;
use App\Services\AI\Contracts\AIResponse;
use Illuminate\Support\Facades\Http;

class OpenAIProvider implements AIProviderInterface
{
    protected string $baseUrl = 'https://api.openai.com/v1';

    protected string $apiKey;

    protected string $defaultModel;

    public function __construct()
    {
        $this->apiKey = config('services.openai.api_key', '');
        $this->defaultModel = config('services.openai.model', 'gpt-4o-mini');
    }

    public function getName(): string
    {
        return 'openai';
    }

    public function getDefaultModel(): string
    {
        return $this->defaultModel;
    }

    public function chat(string $prompt, array $options = []): AIResponse
    {
        return $this->chatWithSystem('You are a helpful assistant.', $prompt, $options);
    }

    public function chatWithSystem(string $systemPrompt, string $userPrompt, array $options = []): AIResponse
    {
        $model = $options['model'] ?? $this->defaultModel;
        $startTime = microtime(true);

        $response = Http::withHeaders([
            'Authorization' => "Bearer {$this->apiKey}",
            'Content-Type' => 'application/json',
        ])->timeout($options['timeout'] ?? 60)->post("{$this->baseUrl}/chat/completions", [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt],
            ],
            'temperature' => $options['temperature'] ?? 0.7,
            'max_tokens' => $options['max_tokens'] ?? 2048,
        ]);

        $durationMs = (int) ((microtime(true) - $startTime) * 1000);

        if ($response->failed()) {
            throw new \Exception('OpenAI API error: '.$response->body());
        }

        $data = $response->json();

        return new AIResponse(
            content: $data['choices'][0]['message']['content'] ?? '',
            provider: $this->getName(),
            model: $model,
            inputTokens: $data['usage']['prompt_tokens'] ?? 0,
            outputTokens: $data['usage']['completion_tokens'] ?? 0,
            durationMs: $durationMs,
            rawResponse: $data
        );
    }

    public function generateJson(string $prompt, array $schema, array $options = []): AIResponse
    {
        $model = $options['model'] ?? $this->defaultModel;
        $startTime = microtime(true);

        $response = Http::withHeaders([
            'Authorization' => "Bearer {$this->apiKey}",
            'Content-Type' => 'application/json',
        ])->timeout($options['timeout'] ?? 60)->post("{$this->baseUrl}/chat/completions", [
            'model' => $model,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'You are a helpful assistant that responds only with valid JSON matching the provided schema.',
                ],
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => $options['temperature'] ?? 0.3,
            'max_tokens' => $options['max_tokens'] ?? 2048,
            'response_format' => ['type' => 'json_object'],
        ]);

        $durationMs = (int) ((microtime(true) - $startTime) * 1000);

        if ($response->failed()) {
            throw new \Exception('OpenAI API error: '.$response->body());
        }

        $data = $response->json();

        return new AIResponse(
            content: $data['choices'][0]['message']['content'] ?? '{}',
            provider: $this->getName(),
            model: $model,
            inputTokens: $data['usage']['prompt_tokens'] ?? 0,
            outputTokens: $data['usage']['completion_tokens'] ?? 0,
            durationMs: $durationMs,
            rawResponse: $data
        );
    }

    public function isAvailable(): bool
    {
        return ! empty($this->apiKey);
    }
}
