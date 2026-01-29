<?php

namespace App\Services\Platforms\WooCommerce;

use App\Models\ProductReturn;
use App\Models\StoreMarketplace;
use App\Services\Returns\Contracts\MarketplaceReturnSyncerInterface;

class WooCommerceReturnSyncer implements MarketplaceReturnSyncerInterface
{
    public function syncReturn(ProductReturn $return, StoreMarketplace $marketplace): void
    {
        // WooCommerce refund sync implementation
        // Uses WooCommerce REST API
    }

    public function normalizePayload(array $payload): array
    {
        $refund = $payload;

        $items = [];
        foreach ($refund['line_items'] ?? [] as $lineItem) {
            $items[] = [
                'external_line_item_id' => (string) ($lineItem['id'] ?? ''),
                'quantity' => abs($lineItem['quantity'] ?? 1),
                'unit_price' => abs((float) ($lineItem['total'] ?? 0)),
                'reason' => $lineItem['reason'] ?? null,
                'restock' => ($lineItem['refund_total'] ?? 0) > 0,
            ];
        }

        return [
            'external_return_id' => (string) ($refund['id'] ?? ''),
            'external_order_id' => (string) ($refund['parent_id'] ?? ''),
            'status' => 'completed',
            'type' => ProductReturn::TYPE_RETURN,
            'subtotal' => abs((float) ($refund['amount'] ?? 0)),
            'refund_amount' => abs((float) ($refund['amount'] ?? 0)),
            'reason' => $refund['reason'] ?? null,
            'requested_at' => $refund['date_created'] ?? now(),
            'items' => $items,
        ];
    }

    public function supportsReturns(): bool
    {
        return true;
    }
}
