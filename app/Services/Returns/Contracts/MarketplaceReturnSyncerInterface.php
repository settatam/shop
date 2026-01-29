<?php

namespace App\Services\Returns\Contracts;

use App\Models\ProductReturn;
use App\Models\StoreMarketplace;

interface MarketplaceReturnSyncerInterface
{
    /**
     * Sync a return to the marketplace.
     */
    public function syncReturn(ProductReturn $return, StoreMarketplace $marketplace): void;

    /**
     * Normalize a webhook payload to a standard format.
     *
     * @return array<string, mixed>
     */
    public function normalizePayload(array $payload): array;

    /**
     * Check if this syncer supports return events.
     */
    public function supportsReturns(): bool;
}
