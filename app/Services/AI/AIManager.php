<?php

namespace App\Services\AI;

use App\Models\AiUsageLog;
use App\Services\AI\Contracts\AIProviderInterface;
use App\Services\AI\Contracts\AIResponse;
use App\Services\AI\Providers\AnthropicProvider;
use App\Services\AI\Providers\OpenAIProvider;
use App\Services\StoreContext;
use InvalidArgumentException;

class AIManager
{
    /** @var array<string, AIProviderInterface> */
    protected array $providers = [];

    protected string $defaultProvider;

    protected StoreContext $storeContext;

    public function __construct(StoreContext $storeContext)
    {
        $this->storeContext = $storeContext;
        $this->defaultProvider = config('services.ai.default_provider', 'openai');
        $this->registerProviders();
    }

    protected function registerProviders(): void
    {
        $openai = new OpenAIProvider;
        $anthropic = new AnthropicProvider;

        if ($openai->isAvailable()) {
            $this->providers['openai'] = $openai;
        }

        if ($anthropic->isAvailable()) {
            $this->providers['anthropic'] = $anthropic;
        }
    }

    public function provider(?string $name = null): AIProviderInterface
    {
        $name = $name ?? $this->defaultProvider;

        if (! isset($this->providers[$name])) {
            throw new InvalidArgumentException("AI provider [{$name}] is not available.");
        }

        return $this->providers[$name];
    }

    public function chat(string $prompt, array $options = []): AIResponse
    {
        $provider = $this->provider($options['provider'] ?? null);

        return $this->executeWithLogging($provider, 'chat', function () use ($provider, $prompt, $options) {
            return $provider->chat($prompt, $options);
        }, $options['feature'] ?? 'chat');
    }

    public function chatWithSystem(string $systemPrompt, string $userPrompt, array $options = []): AIResponse
    {
        $provider = $this->provider($options['provider'] ?? null);

        return $this->executeWithLogging($provider, 'chat', function () use ($provider, $systemPrompt, $userPrompt, $options) {
            return $provider->chatWithSystem($systemPrompt, $userPrompt, $options);
        }, $options['feature'] ?? 'chat');
    }

    public function generateJson(string $prompt, array $schema, array $options = []): AIResponse
    {
        $provider = $this->provider($options['provider'] ?? null);

        return $this->executeWithLogging($provider, 'json', function () use ($provider, $prompt, $schema, $options) {
            return $provider->generateJson($prompt, $schema, $options);
        }, $options['feature'] ?? 'json');
    }

    protected function executeWithLogging(AIProviderInterface $provider, string $type, callable $callback, string $feature): AIResponse
    {
        $storeId = $this->storeContext->getCurrentStoreId();

        try {
            $response = $callback();

            if ($storeId) {
                AiUsageLog::logUsage(
                    storeId: $storeId,
                    provider: $response->provider,
                    model: $response->model,
                    feature: $feature,
                    inputTokens: $response->inputTokens,
                    outputTokens: $response->outputTokens,
                    durationMs: $response->durationMs,
                    userId: auth()->id()
                );
            }

            return $response;
        } catch (\Throwable $e) {
            if ($storeId) {
                AiUsageLog::logError(
                    storeId: $storeId,
                    provider: $provider->getName(),
                    model: $provider->getDefaultModel(),
                    feature: $feature,
                    errorMessage: $e->getMessage(),
                    userId: auth()->id()
                );
            }

            throw $e;
        }
    }

    public function getAvailableProviders(): array
    {
        return array_keys($this->providers);
    }

    public function isProviderAvailable(string $name): bool
    {
        return isset($this->providers[$name]);
    }

    public function hasAnyProvider(): bool
    {
        return ! empty($this->providers);
    }
}
