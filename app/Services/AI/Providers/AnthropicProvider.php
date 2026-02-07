<?php

namespace App\Services\AI\Providers;

use App\Services\AI\Contracts\AIProviderInterface;
use App\Services\AI\Contracts\AIResponse;
use Illuminate\Support\Facades\Http;

class AnthropicProvider implements AIProviderInterface
{
    protected string $baseUrl = 'https://api.anthropic.com/v1';

    protected string $apiKey;

    protected string $defaultModel;

    public function __construct()
    {
        $this->apiKey = (string) config('services.anthropic.api_key', '');
        $this->defaultModel = (string) config('services.anthropic.model', 'claude-3-5-sonnet-20241022');
    }

    public function getName(): string
    {
        return 'anthropic';
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
            'x-api-key' => $this->apiKey,
            'anthropic-version' => '2023-06-01',
            'Content-Type' => 'application/json',
        ])->timeout($options['timeout'] ?? 60)->post("{$this->baseUrl}/messages", [
            'model' => $model,
            'system' => $systemPrompt,
            'messages' => [
                ['role' => 'user', 'content' => $userPrompt],
            ],
            'max_tokens' => $options['max_tokens'] ?? 2048,
            'temperature' => $options['temperature'] ?? 0.7,
        ]);

        $durationMs = (int) ((microtime(true) - $startTime) * 1000);

        if ($response->failed()) {
            throw new \Exception('Anthropic API error: '.$response->body());
        }

        $data = $response->json();

        $content = '';
        foreach ($data['content'] ?? [] as $block) {
            if ($block['type'] === 'text') {
                $content .= $block['text'];
            }
        }

        return new AIResponse(
            content: $content,
            provider: $this->getName(),
            model: $model,
            inputTokens: $data['usage']['input_tokens'] ?? 0,
            outputTokens: $data['usage']['output_tokens'] ?? 0,
            durationMs: $durationMs,
            rawResponse: $data
        );
    }

    public function generateJson(string $prompt, array $schema, array $options = []): AIResponse
    {
        $systemPrompt = 'You are a helpful assistant that responds only with valid JSON matching the provided schema. Do not include any text outside the JSON object. The schema is: '.json_encode($schema);

        $response = $this->chatWithSystem($systemPrompt, $prompt, array_merge($options, ['temperature' => 0.3]));

        // Extract JSON from response if wrapped in markdown code blocks
        $content = $response->content;
        if (preg_match('/```(?:json)?\s*([\s\S]*?)\s*```/', $content, $matches)) {
            $content = $matches[1];
        }

        return new AIResponse(
            content: trim($content),
            provider: $response->provider,
            model: $response->model,
            inputTokens: $response->inputTokens,
            outputTokens: $response->outputTokens,
            durationMs: $response->durationMs,
            rawResponse: $response->rawResponse
        );
    }

    public function isAvailable(): bool
    {
        return ! empty($this->apiKey);
    }
}
