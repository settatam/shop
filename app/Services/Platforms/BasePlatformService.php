<?php

namespace App\Services\Platforms;

use App\Models\StoreMarketplace;
use App\Models\SyncLog;
use App\Services\Platforms\Contracts\PlatformInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

abstract class BasePlatformService implements PlatformInterface
{
    protected function logSync(StoreMarketplace $connection, string $type, string $direction): SyncLog
    {
        return SyncLog::start($connection->id, $type, $direction);
    }

    protected function handleApiError(StoreMarketplace $connection, \Throwable $e, string $context): void
    {
        $message = "[{$this->getPlatform()}] {$context}: {$e->getMessage()}";
        Log::error($message, [
            'connection_id' => $connection->id,
            'exception' => $e,
        ]);
        $connection->markAsError($e->getMessage());
    }

    protected function makeRequest(
        string $method,
        string $url,
        StoreMarketplace $connection,
        array $data = [],
        array $headers = []
    ): array {
        $defaultHeaders = array_merge([
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ], $headers);

        $response = Http::withHeaders($defaultHeaders)
            ->timeout(30)
            ->{$method}($url, $data);

        if ($response->failed()) {
            throw new \Exception("API request failed: {$response->body()}");
        }

        return $response->json() ?? [];
    }

    public function getWebhookUrl(StoreMarketplace $connection): string
    {
        return route('webhooks.'.$this->getPlatform(), [
            'connection' => $connection->id,
        ]);
    }
}
