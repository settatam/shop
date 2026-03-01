<?php

namespace App\Services\Platforms;

use App\Models\PlatformListing;
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

    /**
     * Sync platform product data back to the local Product and ProductVariant models.
     *
     * @param  array{title?: string, description?: string, is_published?: bool, variants?: array<int, array{external_id?: string, price?: float|string, quantity?: int, sku?: string, barcode?: string}>}  $productData
     */
    protected function syncListingToProduct(PlatformListing $listing, array $productData): void
    {
        $product = $listing->product;

        if (! $product) {
            return;
        }

        $product->update(array_filter([
            'title' => $productData['title'] ?? null,
            'description' => $productData['description'] ?? null,
            'is_published' => $productData['is_published'] ?? null,
        ], fn ($v) => $v !== null));

        foreach ($productData['variants'] ?? [] as $variantData) {
            $externalId = (string) ($variantData['external_id'] ?? '');

            if (! $externalId) {
                continue;
            }

            $listingVariant = $listing->listingVariants()
                ->where('external_variant_id', $externalId)
                ->first();

            if ($listingVariant && $listingVariant->productVariant) {
                $listingVariant->productVariant->update(array_filter([
                    'price' => $variantData['price'] ?? null,
                    'quantity' => $variantData['quantity'] ?? null,
                    'sku' => $variantData['sku'] ?? null,
                    'barcode' => $variantData['barcode'] ?? null,
                ], fn ($v) => $v !== null));
            }
        }
    }
}
