<?php

namespace App\Services\Platforms\Amazon;

use App\Models\ProductReturn;
use App\Models\StoreMarketplace;
use App\Services\Returns\Contracts\MarketplaceReturnSyncerInterface;

class AmazonReturnSyncer implements MarketplaceReturnSyncerInterface
{
    public function syncReturn(ProductReturn $return, StoreMarketplace $marketplace): void
    {
        // Amazon return sync implementation
        // Uses Amazon SP-API to manage returns
    }

    public function normalizePayload(array $payload): array
    {
        $returnData = $payload;

        $items = [];
        foreach ($returnData['returnItems'] ?? [] as $item) {
            $items[] = [
                'external_line_item_id' => $item['orderItemId'] ?? '',
                'quantity' => $item['quantityReturned'] ?? 1,
                'unit_price' => (float) ($item['itemPrice']['amount'] ?? 0),
                'reason' => $item['returnReasonCode'] ?? null,
                'condition' => $this->mapCondition($item['itemCondition'] ?? null),
                'restock' => ($item['disposition'] ?? '') === 'SELLABLE',
            ];
        }

        return [
            'external_return_id' => $returnData['returnAuthorizationId'] ?? $returnData['rmaId'] ?? '',
            'external_order_id' => $returnData['orderId'] ?? '',
            'status' => $this->mapStatus($returnData['status'] ?? 'Pending'),
            'type' => ProductReturn::TYPE_RETURN,
            'subtotal' => (float) ($returnData['refundAmount']['amount'] ?? 0),
            'refund_amount' => (float) ($returnData['refundAmount']['amount'] ?? 0),
            'reason' => $returnData['returnReasonCode'] ?? null,
            'customer_notes' => $returnData['customerComments'] ?? null,
            'requested_at' => $returnData['returnRequestDate'] ?? now(),
            'items' => $items,
        ];
    }

    protected function mapStatus(string $amazonStatus): string
    {
        return match ($amazonStatus) {
            'Pending', 'PendingAction' => 'pending',
            'Approved', 'Authorized' => 'approved',
            'InTransit', 'Received' => 'processing',
            'Completed', 'Refunded', 'Closed' => 'completed',
            'Rejected', 'Denied' => 'rejected',
            'Cancelled' => 'cancelled',
            default => 'pending',
        };
    }

    protected function mapCondition(?string $amazonCondition): ?string
    {
        if (! $amazonCondition) {
            return null;
        }

        return match ($amazonCondition) {
            'Sellable', 'NewItem' => 'new',
            'CustomerDamaged' => 'used',
            'Defective', 'CarrierDamaged' => 'damaged',
            default => null,
        };
    }

    public function supportsReturns(): bool
    {
        return true;
    }
}
