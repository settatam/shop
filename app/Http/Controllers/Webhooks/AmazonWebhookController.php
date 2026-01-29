<?php

namespace App\Http\Controllers\Webhooks;

use App\Enums\Platform;
use App\Models\StoreMarketplace;
use Illuminate\Http\Request;

class AmazonWebhookController extends BaseWebhookController
{
    protected function getPlatform(): Platform
    {
        return Platform::Amazon;
    }

    protected function getEventType(Request $request): string
    {
        $payload = $request->all();

        return $payload['notificationType'] ?? $payload['NotificationType'] ?? 'ORDER_CHANGE';
    }

    protected function verifySignature(Request $request, StoreMarketplace $connection): bool
    {
        return true;
    }

    protected function extractExternalId(Request $request): ?string
    {
        $payload = $request->all();

        return $payload['payload']['AmazonOrderId']
            ?? $payload['AmazonOrderId']
            ?? $payload['orderId']
            ?? null;
    }
}
