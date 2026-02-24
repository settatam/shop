<?php

namespace App\Http\Controllers\Webhooks;

use App\Enums\Platform;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessWebhookJob;
use App\Models\StoreMarketplace;
use App\Models\WebhookLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

abstract class BaseWebhookController extends Controller
{
    abstract protected function getPlatform(): Platform;

    abstract protected function getEventType(Request $request): string;

    abstract protected function verifySignature(Request $request, StoreMarketplace $connection): bool;

    public function handle(Request $request, string $connectionId): JsonResponse
    {
        $connection = StoreMarketplace::find($connectionId);

        if (! $connection) {
            return response()->json(['error' => 'Connection not found'], 404);
        }

        if (! $connection->isActive()) {
            return response()->json(['error' => 'Connection is inactive'], 400);
        }

        $webhookLog = $this->logWebhook($request, $connection);

        if (! $this->verifySignature($request, $connection)) {
            $webhookLog->markAsFailed('Invalid signature');

            return response()->json(['error' => 'Invalid signature'], 401);
        }

        $eventType = $this->getEventType($request);

        if (! $this->shouldProcessEvent($eventType)) {
            $webhookLog->markAsSkipped("Event type '{$eventType}' not processed");

            return response()->json(['status' => 'skipped']);
        }

        $webhookLog->update(['event_type' => $eventType]);

        ProcessWebhookJob::dispatch($webhookLog);

        return response()->json(['status' => 'queued']);
    }

    protected function logWebhook(Request $request, StoreMarketplace $connection): WebhookLog
    {
        return WebhookLog::create([
            'store_marketplace_id' => $connection->id,
            'store_id' => $connection->store_id,
            'platform' => $this->getPlatform(),
            'event_type' => 'unknown',
            'external_id' => $this->extractExternalId($request),
            'status' => WebhookLog::STATUS_PENDING,
            'headers' => $this->getRelevantHeaders($request),
            'payload' => $request->all(),
            'ip_address' => $request->ip(),
            'signature' => $this->extractSignature($request),
        ]);
    }

    protected function getRelevantHeaders(Request $request): array
    {
        $headers = [];
        $relevantKeys = [
            'content-type',
            'x-shopify-topic',
            'x-shopify-hmac-sha256',
            'x-shopify-shop-domain',
            'x-ebay-signature',
            'x-wc-webhook-signature',
            'x-wc-webhook-topic',
            'x-etsy-signature',
            'x-bc-webhook-hmac',
            'x-bc-store-hash',
        ];

        foreach ($relevantKeys as $key) {
            if ($request->hasHeader($key)) {
                $headers[$key] = $request->header($key);
            }
        }

        return $headers;
    }

    protected function extractExternalId(Request $request): ?string
    {
        $payload = $request->all();

        return $payload['id']
            ?? $payload['order_id']
            ?? $payload['orderId']
            ?? $payload['AmazonOrderId']
            ?? $payload['receipt_id']
            ?? $payload['purchaseOrderId']
            ?? null;
    }

    protected function extractSignature(Request $request): ?string
    {
        return $request->header('x-shopify-hmac-sha256')
            ?? $request->header('x-wc-webhook-signature')
            ?? $request->header('x-ebay-signature')
            ?? $request->header('x-bc-webhook-hmac')
            ?? null;
    }

    protected function shouldProcessEvent(string $eventType): bool
    {
        $processableEvents = [
            // Order events
            'orders/create',
            'orders/updated',
            'orders/paid',
            'orders/fulfilled',
            'orders/cancelled',
            'order.created',
            'order.updated',
            'order.paid',
            'order.completed',
            'woocommerce_order_created',
            'woocommerce_order_updated',
            'store/order/created',
            'store/order/updated',
            'store/order/statusUpdated',
            // Refund/Return events
            'refunds/create',
            'refunds/updated',
            'refund.created',
            'refund.updated',
            'order.refunded',
        ];

        foreach ($processableEvents as $event) {
            if (stripos($eventType, $event) !== false || stripos($event, $eventType) !== false) {
                return true;
            }
        }

        return str_contains(strtolower($eventType), 'order') || str_contains(strtolower($eventType), 'refund');
    }
}
