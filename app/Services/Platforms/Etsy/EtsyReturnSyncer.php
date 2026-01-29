<?php

namespace App\Services\Platforms\Etsy;

use App\Models\ProductReturn;
use App\Models\StoreMarketplace;
use App\Services\Returns\Contracts\MarketplaceReturnSyncerInterface;

class EtsyReturnSyncer implements MarketplaceReturnSyncerInterface
{
    public function syncReturn(ProductReturn $return, StoreMarketplace $marketplace): void
    {
        // Etsy does not have a formal returns API
        // Returns are handled through cases/disputes
    }

    public function normalizePayload(array $payload): array
    {
        // Etsy uses cases for disputes/returns
        $caseData = $payload;

        return [
            'external_return_id' => (string) ($caseData['case_id'] ?? ''),
            'external_order_id' => (string) ($caseData['receipt_id'] ?? ''),
            'status' => $this->mapStatus($caseData['case_state'] ?? 'Open'),
            'type' => ProductReturn::TYPE_RETURN,
            'subtotal' => (float) ($caseData['total_price']['amount'] ?? 0) / 100,
            'refund_amount' => (float) ($caseData['refund_amount']['amount'] ?? 0) / 100,
            'reason' => $caseData['reason'] ?? null,
            'customer_notes' => $caseData['buyer_message'] ?? null,
            'requested_at' => $caseData['open_timestamp'] ?? now(),
            'items' => [],
        ];
    }

    protected function mapStatus(string $etsyStatus): string
    {
        return match ($etsyStatus) {
            'Open', 'Opened' => 'pending',
            'InProgress', 'EscalatedToEtsy' => 'processing',
            'Resolved', 'Closed' => 'completed',
            default => 'pending',
        };
    }

    public function supportsReturns(): bool
    {
        // Etsy has limited return support through cases
        return true;
    }
}
