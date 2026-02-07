<?php

namespace App\Services\Agents\Actions;

use App\Models\AgentAction;
use App\Models\PlatformListing;
use App\Models\StoreAgent;
use App\Services\Agents\Contracts\AgentActionInterface;
use App\Services\Agents\Results\ActionResult;
use App\Services\Marketplace\DTOs\PlatformProduct;
use App\Services\Marketplace\PlatformConnectorManager;

class ChannelRepriceAction implements AgentActionInterface
{
    public function __construct(
        protected PlatformConnectorManager $connectorManager
    ) {}

    public function getType(): string
    {
        return 'channel_reprice';
    }

    public function getDescription(): string
    {
        return 'Update the price of a listing on an external marketplace';
    }

    public function requiresApproval(StoreAgent $storeAgent, array $payload): bool
    {
        $config = $storeAgent->getMergedConfig();
        $changePercent = $payload['change_percent'] ?? 0;
        $threshold = $config['major_change_threshold_percent'] ?? 15;

        return $changePercent >= $threshold;
    }

    public function execute(AgentAction $action): ActionResult
    {
        $listing = $action->actionable;

        if (! $listing instanceof PlatformListing) {
            return ActionResult::failure('Action target is not a platform listing');
        }

        $payload = $action->payload;
        $newPrice = $payload['new_price'] ?? null;

        if ($newPrice === null) {
            return ActionResult::failure('No new price specified in payload');
        }

        $marketplace = $listing->storeMarketplace;

        if (! $marketplace) {
            return ActionResult::failure('Marketplace not found for listing');
        }

        $oldPrice = $listing->platform_price;

        try {
            $connector = $this->connectorManager->getConnectorForMarketplace($marketplace);

            // Get current listing data and update price
            $platformData = $listing->platform_data ?? [];
            $platformData['price'] = $newPrice;

            $platformProduct = new PlatformProduct(
                externalId: $listing->external_listing_id,
                title: $platformData['title'] ?? $listing->product?->title ?? '',
                description: $platformData['description'] ?? '',
                sku: $platformData['sku'] ?? $listing->product?->sku ?? null,
                barcode: $platformData['barcode'] ?? null,
                price: $newPrice,
                compareAtPrice: $platformData['compareAtPrice'] ?? null,
                quantity: $listing->platform_quantity,
                weight: $platformData['weight'] ?? null,
                weightUnit: $platformData['weightUnit'] ?? 'lb',
                brand: $platformData['brand'] ?? null,
                category: $platformData['category'] ?? null,
                categoryId: $platformData['categoryId'] ?? null,
                images: $platformData['images'] ?? [],
                attributes: $platformData['attributes'] ?? [],
                variants: $platformData['variants'] ?? [],
                condition: $platformData['condition'] ?? 'new',
                status: $platformData['status'] ?? 'active',
                metadata: $platformData['metadata'] ?? [],
            );

            $success = $connector->updateProduct($listing->external_listing_id, $platformProduct);

            if (! $success) {
                return ActionResult::failure('Failed to update price on platform');
            }

            // Update local listing record
            $listing->update([
                'platform_price' => $newPrice,
                'platform_data' => $platformData,
                'last_synced_at' => now(),
            ]);

            $changePercent = round((($newPrice - $oldPrice) / $oldPrice) * 100, 2);
            $direction = $newPrice > $oldPrice ? 'increased' : 'decreased';

            return ActionResult::success(
                "Price {$direction} from \${$oldPrice} to \${$newPrice} ({$changePercent}%) on {$marketplace->platform->label()}",
                [
                    'listing_id' => $listing->id,
                    'platform' => $marketplace->platform->value,
                    'old_price' => $oldPrice,
                    'new_price' => $newPrice,
                    'change_percent' => $changePercent,
                    'reason' => $payload['reason'] ?? null,
                ]
            );
        } catch (\Throwable $e) {
            return ActionResult::failure("Failed to reprice listing: {$e->getMessage()}");
        }
    }

    public function rollback(AgentAction $action): bool
    {
        $result = $action->result ?? [];
        $listingId = $result['listing_id'] ?? null;
        $oldPrice = $result['old_price'] ?? null;

        if (! $listingId || ! $oldPrice) {
            return false;
        }

        try {
            $listing = PlatformListing::find($listingId);

            if (! $listing) {
                return false;
            }

            $marketplace = $listing->storeMarketplace;

            if (! $marketplace) {
                return false;
            }

            $connector = $this->connectorManager->getConnectorForMarketplace($marketplace);

            $platformData = $listing->platform_data ?? [];
            $platformData['price'] = $oldPrice;

            $platformProduct = new PlatformProduct(
                externalId: $listing->external_listing_id,
                title: $platformData['title'] ?? '',
                description: $platformData['description'] ?? '',
                sku: $platformData['sku'] ?? null,
                barcode: $platformData['barcode'] ?? null,
                price: $oldPrice,
                compareAtPrice: $platformData['compareAtPrice'] ?? null,
                quantity: $listing->platform_quantity,
                weight: $platformData['weight'] ?? null,
                weightUnit: $platformData['weightUnit'] ?? 'lb',
                brand: $platformData['brand'] ?? null,
                category: $platformData['category'] ?? null,
                categoryId: $platformData['categoryId'] ?? null,
                images: $platformData['images'] ?? [],
                attributes: $platformData['attributes'] ?? [],
                variants: $platformData['variants'] ?? [],
                condition: $platformData['condition'] ?? 'new',
                status: $platformData['status'] ?? 'active',
                metadata: $platformData['metadata'] ?? [],
            );

            $connector->updateProduct($listing->external_listing_id, $platformProduct);

            $listing->update([
                'platform_price' => $oldPrice,
                'platform_data' => $platformData,
            ]);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function validatePayload(array $payload): bool
    {
        return isset($payload['new_price'])
            && is_numeric($payload['new_price'])
            && $payload['new_price'] > 0;
    }
}
