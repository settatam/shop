<?php

namespace App\Http\Controllers\Webhooks;

use App\Enums\Platform;
use App\Models\StoreMarketplace;
use Illuminate\Http\Request;

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
}
