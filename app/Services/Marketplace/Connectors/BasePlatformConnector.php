<?php

namespace App\Services\Marketplace\Connectors;

use App\Enums\Platform;
use App\Models\StoreMarketplace;
use App\Services\Marketplace\Contracts\PlatformConnectorInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

abstract class BasePlatformConnector implements PlatformConnectorInterface
{
    protected ?StoreMarketplace $marketplace = null;

    protected ?string $lastError = null;

    protected int $rateLimitRemaining = 0;

    protected int $rateLimitTotal = 0;

    protected ?\DateTimeInterface $rateLimitResetAt = null;

    abstract public function getPlatform(): Platform;

    abstract protected function getBaseUrl(): string;

    public function initialize(StoreMarketplace $marketplace): self
    {
        $this->marketplace = $marketplace;

        return $this;
    }

    public function testConnection(): bool
    {
        try {
            // Default implementation - try to fetch products with limit 1
            $products = $this->getProducts(1);

            return true;
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            return false;
        }
    }

    public function refreshTokensIfNeeded(): bool
    {
        if (! $this->marketplace) {
            return false;
        }

        if (! $this->marketplace->isTokenExpired()) {
            return true;
        }

        return $this->refreshTokens();
    }

    /**
     * Refresh OAuth tokens. Override in platform-specific connectors.
     */
    protected function refreshTokens(): bool
    {
        return false;
    }

    /**
     * Get a configured HTTP client for API requests.
     */
    protected function getHttpClient(): PendingRequest
    {
        return Http::baseUrl($this->getBaseUrl())
            ->timeout(30)
            ->retry(3, 100, fn ($exception) => $this->shouldRetry($exception));
    }

    /**
     * Determine if a failed request should be retried.
     */
    protected function shouldRetry(\Throwable $exception): bool
    {
        // Retry on rate limits or temporary failures
        if ($exception instanceof \Illuminate\Http\Client\RequestException) {
            $status = $exception->response?->status();

            return in_array($status, [429, 500, 502, 503, 504]);
        }

        return false;
    }

    /**
     * Make an authenticated API request.
     */
    protected function request(string $method, string $endpoint, array $data = []): Response
    {
        $this->refreshTokensIfNeeded();

        $client = $this->getHttpClient()
            ->withHeaders($this->getAuthHeaders());

        $response = match (strtoupper($method)) {
            'GET' => $client->get($endpoint, $data),
            'POST' => $client->post($endpoint, $data),
            'PUT' => $client->put($endpoint, $data),
            'PATCH' => $client->patch($endpoint, $data),
            'DELETE' => $client->delete($endpoint, $data),
            default => throw new \InvalidArgumentException("Unsupported HTTP method: {$method}"),
        };

        $this->parseRateLimitHeaders($response);
        $this->logRequest($method, $endpoint, $response);

        if ($response->failed()) {
            $this->lastError = $response->json('message') ?? $response->json('error') ?? $response->body();
            $response->throw();
        }

        return $response;
    }

    /**
     * Get authentication headers for API requests.
     */
    protected function getAuthHeaders(): array
    {
        if (! $this->marketplace) {
            return [];
        }

        return [
            'Authorization' => 'Bearer '.$this->marketplace->access_token,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
    }

    /**
     * Parse rate limit headers from response.
     */
    protected function parseRateLimitHeaders(Response $response): void
    {
        // Default implementation - override in platform-specific connectors
        $this->rateLimitRemaining = (int) $response->header('X-RateLimit-Remaining', 0);
        $this->rateLimitTotal = (int) $response->header('X-RateLimit-Limit', 0);

        $resetHeader = $response->header('X-RateLimit-Reset');
        $this->rateLimitResetAt = $resetHeader ? new \DateTimeImmutable("@{$resetHeader}") : null;
    }

    /**
     * Log API request for debugging.
     */
    protected function logRequest(string $method, string $endpoint, Response $response): void
    {
        if (! config('app.debug')) {
            return;
        }

        Log::debug('Marketplace API Request', [
            'platform' => $this->getPlatform()->value,
            'method' => $method,
            'endpoint' => $endpoint,
            'status' => $response->status(),
            'rate_limit_remaining' => $this->rateLimitRemaining,
        ]);
    }

    public function getRateLimitStatus(): array
    {
        return [
            'remaining' => $this->rateLimitRemaining,
            'limit' => $this->rateLimitTotal,
            'reset_at' => $this->rateLimitResetAt,
        ];
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * Ensure the connector is initialized.
     */
    protected function ensureInitialized(): void
    {
        if (! $this->marketplace) {
            throw new \RuntimeException('Connector not initialized. Call initialize() first.');
        }
    }
}
