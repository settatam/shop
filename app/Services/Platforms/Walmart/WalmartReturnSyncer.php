<?php

namespace App\Services\Platforms\Walmart;

use App\Models\ProductReturn;
use App\Models\StoreMarketplace;
use App\Services\Returns\Contracts\MarketplaceReturnSyncerInterface;

class WalmartReturnSyncer implements MarketplaceReturnSyncerInterface
{
    public function syncReturn(ProductReturn $return, StoreMarketplace $marketplace): void
    {
        // Walmart return sync implementation
        // Uses Walmart Marketplace API
    }

    public function normalizePayload(array $payload): array
    {
        $returnData = $payload;

        $items = [];
        foreach ($returnData['returnLines'] ?? [] as $line) {
            $items[] = [
                'external_line_item_id' => $line['orderLineNumber'] ?? '',
                'quantity' => $line['returnQuantity'] ?? 1,
                'unit_price' => (float) ($line['unitPrice']['amount'] ?? 0),
                'reason' => $line['returnReason']['returnReasonCode'] ?? null,
                'condition' => $this->mapCondition($line['itemCondition'] ?? null),
                'restock' => ($line['isResellable'] ?? false),
            ];
        }

        return [
            'external_return_id' => $returnData['returnOrderId'] ?? '',
            'external_order_id' => $returnData['customerOrderId'] ?? '',
            'status' => $this->mapStatus($returnData['status'] ?? 'INITIATED'),
            'type' => ProductReturn::TYPE_RETURN,
            'subtotal' => (float) ($returnData['totalRefundAmount']['amount'] ?? 0),
            'refund_amount' => (float) ($returnData['totalRefundAmount']['amount'] ?? 0),
            'reason' => $returnData['returnReason']['returnReasonCode'] ?? null,
            'customer_notes' => $returnData['buyerComments'] ?? null,
            'requested_at' => $returnData['returnCreatedDate'] ?? now(),
            'items' => $items,
        ];
    }

    protected function mapStatus(string $walmartStatus): string
    {
        return match ($walmartStatus) {
            'INITIATED', 'AWAITING_RECEIPT' => 'pending',
            'RECEIVED' => 'processing',
            'REFUND_ISSUED', 'COMPLETED', 'CLOSED' => 'completed',
            'DENIED' => 'rejected',
            'CANCELLED' => 'cancelled',
            default => 'pending',
        };
    }

    protected function mapCondition(?string $walmartCondition): ?string
    {
        if (! $walmartCondition) {
            return null;
        }

        return match ($walmartCondition) {
            'NEW', 'SELLABLE' => 'new',
            'LIKE_NEW' => 'like_new',
            'USED' => 'used',
            'DAMAGED', 'DEFECTIVE' => 'damaged',
            default => null,
        };
    }

    public function supportsReturns(): bool
    {
        return true;
    }
}
