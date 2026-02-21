<?php

namespace App\Http\Controllers\Webhooks;

use App\Enums\Platform;
use App\Models\StoreMarketplace;
use Illuminate\Http\Request;

class BigCommerceWebhookController extends BaseWebhookController
{
    protected function getPlatform(): Platform
    {
        return Platform::BigCommerce;
    }

    protected function getEventType(Request $request): string
    {
        // BigCommerce sends event type in payload as 'scope'
        // e.g., "store/order/created", "store/order/updated"
        return $request->input('scope', 'unknown');
    }

    protected function verifySignature(Request $request, StoreMarketplace $connection): bool
    {
        $hmacHeader = $request->header('x-bc-webhook-hmac');

        if (! $hmacHeader) {
            // If no signature header, check if webhook verification is required
            $secret = $connection->credentials['webhook_secret'] ?? null;

            // Allow if no secret is configured (webhook verification disabled)
            return ! $secret;
        }

        $secret = $connection->credentials['webhook_secret']
            ?? $connection->credentials['client_secret']
            ?? config('services.bigcommerce.webhook_secret');

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

        // BigCommerce sends order data in 'data' object
        $data = $payload['data'] ?? [];

        return isset($data['id']) ? (string) $data['id']
            : (isset($payload['id']) ? (string) $payload['id'] : null);
    }

    protected function extractSignature(Request $request): ?string
    {
        return $request->header('x-bc-webhook-hmac');
    }
}
