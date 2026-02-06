<?php

namespace App\Services\Agents\Actions;

use App\Models\AgentAction;
use App\Models\Product;
use App\Models\StoreAgent;
use App\Services\Agents\Contracts\AgentActionInterface;
use App\Services\Agents\Results\ActionResult;

class MarkdownScheduleAction implements AgentActionInterface
{
    public function getType(): string
    {
        return 'markdown_schedule';
    }

    public function getDescription(): string
    {
        return 'Apply a markdown/discount to a slow-moving product';
    }

    public function requiresApproval(StoreAgent $storeAgent, array $payload): bool
    {
        // Always require approval if store agent requires it
        if ($storeAgent->requiresApproval()) {
            return true;
        }

        // Require approval for large markdowns (30%+)
        $discountPercent = $payload['discount_percent'] ?? 0;

        return $discountPercent >= 30;
    }

    public function execute(AgentAction $action): ActionResult
    {
        $product = $action->actionable;

        if (! $product instanceof Product) {
            return ActionResult::failure('Action target is not a product');
        }

        $payload = $action->payload;
        $discountPercent = $payload['discount_percent'] ?? 0;
        $newPrice = $payload['after']['price'] ?? null;
        $reason = $payload['reason'] ?? 'dead_stock';

        if ($newPrice === null) {
            return ActionResult::failure('No new price specified in payload');
        }

        $oldPrice = $product->price;

        $product->update([
            'price' => $newPrice,
            'compare_at_price' => $oldPrice, // Keep original price for reference
        ]);

        return ActionResult::success(
            "Applied {$discountPercent}% markdown: \${$oldPrice} -> \${$newPrice}",
            [
                'product_id' => $product->id,
                'old_price' => $oldPrice,
                'new_price' => $newPrice,
                'discount_percent' => $discountPercent,
                'reason' => $reason,
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
            'compare_at_price' => null,
        ]);

        return true;
    }

    public function validatePayload(array $payload): bool
    {
        return isset($payload['after']['price'])
            && is_numeric($payload['after']['price'])
            && $payload['after']['price'] >= 0
            && isset($payload['discount_percent'])
            && is_numeric($payload['discount_percent']);
    }
}
