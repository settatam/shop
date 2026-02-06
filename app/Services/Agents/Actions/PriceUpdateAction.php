<?php

namespace App\Services\Agents\Actions;

use App\Models\AgentAction;
use App\Models\Product;
use App\Models\StoreAgent;
use App\Services\Agents\Contracts\AgentActionInterface;
use App\Services\Agents\Results\ActionResult;

class PriceUpdateAction implements AgentActionInterface
{
    public function getType(): string
    {
        return 'price_update';
    }

    public function getDescription(): string
    {
        return 'Update the price of a product based on market data';
    }

    public function requiresApproval(StoreAgent $storeAgent, array $payload): bool
    {
        // Always require approval if store agent requires it
        if ($storeAgent->requiresApproval()) {
            return true;
        }

        $config = $storeAgent->getMergedConfig();

        // Require approval if price change exceeds threshold
        $approvalThreshold = $config['require_approval_above'] ?? 100;
        $newPrice = $payload['after']['price'] ?? 0;

        return $newPrice > $approvalThreshold;
    }

    public function execute(AgentAction $action): ActionResult
    {
        $product = $action->actionable;

        if (! $product instanceof Product) {
            return ActionResult::failure('Action target is not a product');
        }

        $payload = $action->payload;
        $newPrice = $payload['after']['price'] ?? null;

        if ($newPrice === null) {
            return ActionResult::failure('No price specified in payload');
        }

        $oldPrice = $product->price;

        $product->update([
            'price' => $newPrice,
        ]);

        return ActionResult::success(
            "Price updated from \${$oldPrice} to \${$newPrice}",
            [
                'product_id' => $product->id,
                'old_price' => $oldPrice,
                'new_price' => $newPrice,
            ]
        );
    }

    public function rollback(AgentAction $action): bool
    {
        $product = $action->actionable;

        if (! $product instanceof Product) {
            return false;
        }

        $payload = $action->payload;
        $oldPrice = $payload['before']['price'] ?? null;

        if ($oldPrice === null) {
            return false;
        }

        $product->update([
            'price' => $oldPrice,
        ]);

        return true;
    }

    public function validatePayload(array $payload): bool
    {
        return isset($payload['after']['price'])
            && is_numeric($payload['after']['price'])
            && $payload['after']['price'] >= 0;
    }
}
