<?php

namespace App\Services\Platforms\Ebay;

use App\Models\ProductReturn;
use App\Models\StoreMarketplace;
use App\Services\Returns\Contracts\MarketplaceReturnSyncerInterface;

class EbayReturnSyncer implements MarketplaceReturnSyncerInterface
{
    public function syncReturn(ProductReturn $return, StoreMarketplace $marketplace): void
    {
        // eBay return sync implementation
        // Uses eBay Post-Order API to create/update returns
    }

    public function normalizePayload(array $payload): array
    {
        $returnData = $payload;

        $items = [];
        foreach ($returnData['returnLineItems'] ?? [] as $lineItem) {
            $items[] = [
                'external_line_item_id' => $lineItem['lineItemId'] ?? '',
                'quantity' => $lineItem['quantity'] ?? 1,
                'unit_price' => (float) ($lineItem['itemPrice']['value'] ?? 0),
                'reason' => $lineItem['returnReason'] ?? null,
                'condition' => $this->mapCondition($lineItem['itemCondition'] ?? null),
                'restock' => true,
            ];
        }

        return [
            'external_return_id' => $returnData['returnId'] ?? '',
            'external_order_id' => $returnData['orderId'] ?? '',
            'status' => $this->mapStatus($returnData['returnState'] ?? 'RETURN_REQUESTED'),
            'type' => ProductReturn::TYPE_RETURN,
            'subtotal' => (float) ($returnData['returnRefundAmount']['value'] ?? 0),
            'refund_amount' => (float) ($returnData['returnRefundAmount']['value'] ?? 0),
            'reason' => $returnData['returnReason'] ?? null,
            'customer_notes' => $returnData['buyerComments'] ?? null,
            'requested_at' => $returnData['creationDate'] ?? now(),
            'items' => $items,
        ];
    }

    protected function mapStatus(string $ebayStatus): string
    {
        return match ($ebayStatus) {
            'RETURN_REQUESTED', 'RETURN_WAITING_FOR_RMA' => 'pending',
            'RETURN_ACCEPTED' => 'approved',
            'RETURN_ITEM_RECEIVED' => 'processing',
            'RETURN_CLOSED', 'RETURN_COMPLETED' => 'completed',
            'RETURN_REJECTED', 'RETURN_DECLINED' => 'rejected',
            'RETURN_CANCELLED' => 'cancelled',
            default => 'pending',
        };
    }

    protected function mapCondition(?string $ebayCondition): ?string
    {
        if (! $ebayCondition) {
            return null;
        }

        return match ($ebayCondition) {
            'NEW', 'UNUSED' => 'new',
            'USED_LIKE_NEW' => 'like_new',
            'USED' => 'used',
            'DAMAGED' => 'damaged',
            default => null,
        };
    }

    public function supportsReturns(): bool
    {
        return true;
    }
}
