<?php

namespace App\Http\Controllers\Webhooks;

use App\Enums\Platform;
use App\Models\StoreMarketplace;
use Illuminate\Http\Request;

class WalmartWebhookController extends BaseWebhookController
{
    protected function getPlatform(): Platform
    {
        return Platform::Walmart;
    }

    protected function getEventType(Request $request): string
    {
        $payload = $request->all();

        return $payload['eventType'] ?? $payload['resourceName'] ?? 'order.created';
    }

    protected function verifySignature(Request $request, StoreMarketplace $connection): bool
    {
        return true;
    }

    protected function shouldProcessEvent(string $eventType): bool
    {
        return in_array($eventType, ['PO_CREATED', 'PO_LINE_UPDATED', 'ITEM_UPDATED'], true);
    }

    protected function extractExternalId(Request $request): ?string
    {
        $payload = $request->all();

        return $payload['purchaseOrderId']
            ?? $payload['order']['purchaseOrderId']
            ?? null;
    }
}
