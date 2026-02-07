<?php

namespace App\Services\Agents\Actions;

use App\Models\AgentAction;
use App\Models\PlatformListing;
use App\Models\StoreAgent;
use App\Models\StoreMarketplace;
use App\Services\Agents\Contracts\AgentActionInterface;
use App\Services\Agents\Results\ActionResult;
use App\Services\Marketplace\DTOs\InventoryUpdate;
use App\Services\Marketplace\PlatformConnectorManager;

class SyncInventoryAction implements AgentActionInterface
{
    public function __construct(
        protected PlatformConnectorManager $connectorManager
    ) {}

    public function getType(): string
    {
        return 'sync_inventory';
    }

    public function getDescription(): string
    {
        return 'Synchronize inventory levels to an external marketplace';
    }

    public function requiresApproval(StoreAgent $storeAgent, array $payload): bool
    {
        // Inventory syncs typically don't require approval
        return false;
    }

    public function execute(AgentAction $action): ActionResult
    {
        $marketplace = $action->actionable;

        if (! $marketplace instanceof StoreMarketplace) {
            return ActionResult::failure('Action target is not a marketplace');
        }

        $payload = $action->payload;
        $updates = $payload['updates'] ?? [];

        if (empty($updates)) {
            return ActionResult::success('No inventory updates to process', []);
        }

        try {
            $connector = $this->connectorManager->getConnectorForMarketplace($marketplace);

            $inventoryUpdates = [];
            foreach ($updates as $update) {
                $inventoryUpdates[] = new InventoryUpdate(
                    sku: $update['sku'],
                    quantity: $update['new_quantity'],
                    externalId: $update['external_id'] ?? null,
                );
            }

            $results = $connector->bulkUpdateInventory($inventoryUpdates);

            $successful = count(array_filter($results));
            $failed = count($results) - $successful;

            // Update local listings
            foreach ($updates as $update) {
                if ($results[$update['sku']] ?? false) {
                    PlatformListing::where('id', $update['listing_id'])
                        ->update([
                            'platform_quantity' => $update['new_quantity'],
                            'last_synced_at' => now(),
                        ]);
                }
            }

            return ActionResult::success(
                "Inventory synced: {$successful} successful, {$failed} failed",
                [
                    'platform' => $marketplace->platform->value,
                    'successful' => $successful,
                    'failed' => $failed,
                    'updates' => $updates,
                ]
            );
        } catch (\Throwable $e) {
            return ActionResult::failure("Failed to sync inventory: {$e->getMessage()}");
        }
    }

    public function rollback(AgentAction $action): bool
    {
        // Inventory syncs are difficult to rollback without storing previous state
        // We would need to sync back to old quantities
        $result = $action->result ?? [];
        $updates = $result['updates'] ?? [];
        $marketplaceId = $action->actionable_id;

        if (empty($updates)) {
            return false;
        }

        try {
            $marketplace = StoreMarketplace::find($marketplaceId);

            if (! $marketplace) {
                return false;
            }

            $connector = $this->connectorManager->getConnectorForMarketplace($marketplace);

            $rollbackUpdates = [];
            foreach ($updates as $update) {
                $rollbackUpdates[] = new InventoryUpdate(
                    sku: $update['sku'],
                    quantity: $update['old_quantity'],
                    externalId: $update['external_id'] ?? null,
                );
            }

            $connector->bulkUpdateInventory($rollbackUpdates);

            // Revert local listings
            foreach ($updates as $update) {
                PlatformListing::where('id', $update['listing_id'])
                    ->update([
                        'platform_quantity' => $update['old_quantity'],
                    ]);
            }

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function validatePayload(array $payload): bool
    {
        if (! isset($payload['updates']) || ! is_array($payload['updates'])) {
            return false;
        }

        foreach ($payload['updates'] as $update) {
            if (! isset($update['listing_id']) || ! isset($update['new_quantity'])) {
                return false;
            }
        }

        return true;
    }
}
