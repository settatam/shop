<?php

namespace App\Http\Controllers\Webhooks;

use App\Enums\Platform;
use App\Jobs\ProcessWebhookJob;
use App\Models\StorefrontApiToken;
use App\Models\StoreMarketplace;
use App\Models\WebhookLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ShopifyWebhookController extends BaseWebhookController
{
    protected function getPlatform(): Platform
    {
        return Platform::Shopify;
    }

    protected function getEventType(Request $request): string
    {
        return $request->header('x-shopify-topic', 'unknown');
    }

    protected function verifySignature(Request $request, StoreMarketplace $connection): bool
    {
        $hmacHeader = $request->header('x-shopify-hmac-sha256');

        if (! $hmacHeader) {
            return false;
        }

        $secret = $connection->credentials['webhook_secret']
            ?? $connection->credentials['api_secret']
            ?? config('services.shopify.webhook_secret');

        if (! $secret) {
            return true;
        }

        $calculatedHmac = base64_encode(
            hash_hmac('sha256', $request->getContent(), $secret, true)
        );

        return hash_equals($calculatedHmac, $hmacHeader);
    }

    protected function extractExternalId(Request $request): ?string
    {
        $payload = $request->all();

        return isset($payload['id']) ? (string) $payload['id'] : null;
    }

    /**
     * Handle order created webhook.
     */
    public function orderCreated(Request $request, string $connectionId): JsonResponse
    {
        return $this->handleWithEventType($request, $connectionId, 'orders/create');
    }

    /**
     * Handle order paid webhook.
     */
    public function orderPaid(Request $request, string $connectionId): JsonResponse
    {
        return $this->handleWithEventType($request, $connectionId, 'orders/paid');
    }

    /**
     * Handle order cancelled webhook.
     */
    public function orderCancelled(Request $request, string $connectionId): JsonResponse
    {
        return $this->handleWithEventType($request, $connectionId, 'orders/cancelled');
    }

    /**
     * Handle order updated webhook.
     */
    public function orderUpdated(Request $request, string $connectionId): JsonResponse
    {
        return $this->handleWithEventType($request, $connectionId, 'orders/updated');
    }

    /**
     * Handle order fulfilled webhook.
     */
    public function orderFulfilled(Request $request, string $connectionId): JsonResponse
    {
        return $this->handleWithEventType($request, $connectionId, 'orders/fulfilled');
    }

    /**
     * Handle refund created webhook.
     */
    public function refundCreated(Request $request, string $connectionId): JsonResponse
    {
        return $this->handleWithEventType($request, $connectionId, 'refunds/create');
    }

    /**
     * Handle app/uninstalled webhook.
     *
     * Deactivates the connection and disables all storefront API tokens.
     */
    public function appUninstalled(Request $request, string $connectionId): JsonResponse
    {
        $connection = StoreMarketplace::find($connectionId);

        if (! $connection) {
            return response()->json(['error' => 'Connection not found'], 404);
        }

        if (! $this->verifySignature($request, $connection)) {
            Log::warning('Shopify app uninstall webhook failed signature verification', [
                'connection_id' => $connectionId,
            ]);

            return response()->json(['error' => 'Invalid signature'], 401);
        }

        $connection->update(['status' => 'inactive']);

        StorefrontApiToken::where('store_marketplace_id', $connection->id)
            ->update(['is_active' => false]);

        Log::info('Shopify app uninstalled', [
            'connection_id' => $connection->id,
            'store_id' => $connection->store_id,
            'shop_domain' => $connection->shop_domain,
        ]);

        return response()->json(['status' => 'ok']);
    }

    /**
     * Handle webhook with a specific event type override.
     */
    protected function handleWithEventType(Request $request, string $connectionId, string $eventType): JsonResponse
    {
        $connection = StoreMarketplace::find($connectionId);

        if (! $connection) {
            return response()->json(['error' => 'Connection not found'], 404);
        }

        if (! $connection->isActive()) {
            return response()->json(['error' => 'Connection is inactive'], 400);
        }

        $webhookLog = $this->logWebhookWithEventType($request, $connection, $eventType);

        if (! $this->verifySignature($request, $connection)) {
            $webhookLog->markAsFailed('Invalid signature');

            return response()->json(['error' => 'Invalid signature'], 401);
        }

        ProcessWebhookJob::dispatch($webhookLog);

        return response()->json(['status' => 'queued']);
    }

    /**
     * Log webhook with a specific event type.
     */
    protected function logWebhookWithEventType(Request $request, StoreMarketplace $connection, string $eventType): WebhookLog
    {
        return WebhookLog::create([
            'store_marketplace_id' => $connection->id,
            'store_id' => $connection->store_id,
            'platform' => $this->getPlatform(),
            'event_type' => $eventType,
            'external_id' => $this->extractExternalId($request),
            'status' => WebhookLog::STATUS_PENDING,
            'headers' => $this->getRelevantHeaders($request),
            'payload' => $request->all(),
            'ip_address' => $request->ip(),
            'signature' => $this->extractSignature($request),
        ]);
    }
}
