<?php

namespace App\Http\Controllers\Webhooks;

use App\Enums\Platform;
use App\Models\StoreMarketplace;
use Illuminate\Http\Request;

class EbayWebhookController extends BaseWebhookController
{
    protected function getPlatform(): Platform
    {
        return Platform::Ebay;
    }

    protected function getEventType(Request $request): string
    {
        $payload = $request->all();

        return $payload['metadata']['topic'] ?? $payload['topic'] ?? 'order.created';
    }

    protected function verifySignature(Request $request, StoreMarketplace $connection): bool
    {
        $signature = $request->header('x-ebay-signature');

        if (! $signature) {
            return true;
        }

        $verificationToken = $connection->credentials['verification_token']
            ?? config('services.ebay.verification_token');

        if (! $verificationToken) {
            return true;
        }

        $payload = $request->getContent();
        $expectedSignature = base64_encode(
            hash_hmac('sha256', $payload, $verificationToken, true)
        );

        return hash_equals($expectedSignature, $signature);
    }

    protected function shouldProcessEvent(string $eventType): bool
    {
        $ebayEvents = [
            'MARKETPLACE_ACCOUNT_DELETION',
            'ITEM_SOLD',
            'ITEM_CLOSED',
            'ITEM_SUSPENDED',
        ];

        return in_array($eventType, $ebayEvents) || parent::shouldProcessEvent($eventType);
    }

    protected function extractExternalId(Request $request): ?string
    {
        $payload = $request->all();

        return $payload['resource']['orderId']
            ?? $payload['resource']['listingId']
            ?? $payload['resource']['itemId']
            ?? $payload['orderId']
            ?? $payload['OrderID']
            ?? $payload['listingId']
            ?? $payload['itemId']
            ?? null;
    }
}
