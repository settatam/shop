<?php

namespace App\Http\Controllers\Webhooks;

use App\Enums\Platform;
use App\Models\StoreMarketplace;
use Illuminate\Http\Request;

class EtsyWebhookController extends BaseWebhookController
{
    protected function getPlatform(): Platform
    {
        return Platform::Etsy;
    }

    protected function getEventType(Request $request): string
    {
        $payload = $request->all();

        return $payload['type'] ?? 'receipt.created';
    }

    protected function verifySignature(Request $request, StoreMarketplace $connection): bool
    {
        $signature = $request->header('x-etsy-signature');

        if (! $signature) {
            return true;
        }

        $secret = $connection->credentials['webhook_secret']
            ?? config('services.etsy.webhook_secret');

        if (! $secret) {
            return true;
        }

        $payload = $request->getContent();
        $expectedSignature = hash_hmac('sha256', $payload, $secret);

        return hash_equals($expectedSignature, $signature);
    }

    protected function shouldProcessEvent(string $eventType): bool
    {
        return in_array($eventType, ['receipt.created', 'receipt.updated', 'listing.updated'], true);
    }

    protected function extractExternalId(Request $request): ?string
    {
        $payload = $request->all();

        return isset($payload['receipt_id']) ? (string) $payload['receipt_id'] : null;
    }
}
