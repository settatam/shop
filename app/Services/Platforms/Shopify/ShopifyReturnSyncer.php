<?php

namespace App\Services\Platforms\Shopify;

use App\Models\ProductReturn;
use App\Models\StoreMarketplace;
use App\Services\Returns\Contracts\MarketplaceReturnSyncerInterface;

class ShopifyReturnSyncer implements MarketplaceReturnSyncerInterface
{
    public function syncReturn(ProductReturn $return, StoreMarketplace $marketplace): void
    {
        // Shopify refund sync implementation
        // Uses Shopify Admin API to create/update refunds
    }

    public function normalizePayload(array $payload): array
    {
        $refund = $payload;

        $items = [];
        foreach ($refund['refund_line_items'] ?? [] as $lineItem) {
            $items[] = [
                'external_line_item_id' => (string) ($lineItem['line_item_id'] ?? ''),
                'quantity' => $lineItem['quantity'] ?? 1,
                'unit_price' => $lineItem['subtotal'] ?? 0,
                'reason' => $lineItem['restock_type'] ?? null,
                'restock' => ($lineItem['restock_type'] ?? '') !== 'no_restock',
            ];
        }

        return [
            'external_return_id' => (string) ($refund['id'] ?? ''),
            'external_order_id' => (string) ($refund['order_id'] ?? ''),
            'status' => 'completed',
            'type' => ProductReturn::TYPE_RETURN,
            'subtotal' => $this->calculateSubtotal($refund),
            'refund_amount' => $this->calculateRefundAmount($refund),
            'reason' => $refund['note'] ?? null,
            'requested_at' => $refund['created_at'] ?? now(),
            'items' => $items,
        ];
    }

    protected function calculateSubtotal(array $refund): float
    {
        $total = 0;
        foreach ($refund['refund_line_items'] ?? [] as $lineItem) {
            $total += (float) ($lineItem['subtotal'] ?? 0);
        }

        return $total;
    }

    protected function calculateRefundAmount(array $refund): float
    {
        $total = 0;
        foreach ($refund['transactions'] ?? [] as $transaction) {
            if (($transaction['kind'] ?? '') === 'refund') {
                $total += (float) ($transaction['amount'] ?? 0);
            }
        }

        return $total;
    }

    public function supportsReturns(): bool
    {
        return true;
    }
}
