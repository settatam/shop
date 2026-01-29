<?php

namespace App\Http\Controllers\Webhooks;

use App\Enums\Platform;
use App\Models\StoreMarketplace;
use Illuminate\Http\Request;

class WooCommerceWebhookController extends BaseWebhookController
{
    protected function getPlatform(): Platform
    {
        return Platform::WooCommerce;
    }

    protected function getEventType(Request $request): string
    {
        return $request->header('x-wc-webhook-topic', 'order.created');
    }

    protected function verifySignature(Request $request, StoreMarketplace $connection): bool
    {
        $signature = $request->header('x-wc-webhook-signature');

        if (! $signature) {
            return true;
        }

        $secret = $connection->credentials['webhook_secret']
            ?? config('services.woocommerce.webhook_secret');

        if (! $secret) {
            return true;
        }

        $payload = $request->getContent();
        $expectedSignature = base64_encode(
            hash_hmac('sha256', $payload, $secret, true)
        );

        return hash_equals($expectedSignature, $signature);
    }

    protected function extractExternalId(Request $request): ?string
    {
        $payload = $request->all();

        return isset($payload['id']) ? (string) $payload['id'] : null;
    }
}
