<?php

namespace App\Services\Agents\Actions;

use App\Models\AgentAction;
use App\Models\PlatformListing;
use App\Models\Product;
use App\Models\StoreAgent;
use App\Models\StoreMarketplace;
use App\Services\Agents\Contracts\AgentActionInterface;
use App\Services\Agents\Results\ActionResult;
use App\Services\Marketplace\PlatformConnectorManager;

class UpdateListingAction implements AgentActionInterface
{
    public function __construct(
        protected PlatformConnectorManager $connectorManager
    ) {}

    public function getType(): string
    {
        return 'update_listing';
    }

    public function getDescription(): string
    {
        return 'Update an existing product listing on an external marketplace';
    }

    public function requiresApproval(StoreAgent $storeAgent, array $payload): bool
    {
        $config = $storeAgent->getMergedConfig();

        return $config['require_approval_for_publish'] ?? true;
    }

    public function execute(AgentAction $action): ActionResult
    {
        $product = $action->actionable;

        if (! $product instanceof Product) {
            return ActionResult::failure('Action target is not a product');
        }

        $payload = $action->payload;
        $marketplaceId = $payload['marketplace_id'] ?? null;
        $existingListingId = $payload['existing_listing_id'] ?? null;
        $transformedProduct = $payload['transformed_product'] ?? null;

        if (! $marketplaceId || ! $transformedProduct) {
            return ActionResult::failure('Missing marketplace_id or transformed_product in payload');
        }

        $marketplace = StoreMarketplace::find($marketplaceId);

        if (! $marketplace) {
            return ActionResult::failure("Marketplace #{$marketplaceId} not found");
        }

        $listing = $existingListingId
            ? PlatformListing::find($existingListingId)
            : PlatformListing::where('store_marketplace_id', $marketplace->id)
                ->where('product_id', $product->id)
                ->first();

        if (! $listing || ! $listing->external_listing_id) {
            return ActionResult::failure('No existing listing found to update');
        }

        // Store previous data for rollback
        $previousData = $listing->platform_data;

        try {
            $connector = $this->connectorManager->getConnectorForMarketplace($marketplace);

            $platformProduct = new \App\Services\Marketplace\DTOs\PlatformProduct(
                externalId: $listing->external_listing_id,
                title: $transformedProduct['title'] ?? '',
                description: $transformedProduct['description'] ?? '',
                sku: $transformedProduct['sku'] ?? null,
                barcode: $transformedProduct['barcode'] ?? null,
                price: (float) ($transformedProduct['price'] ?? 0),
                compareAtPrice: $transformedProduct['compareAtPrice'] ?? null,
                quantity: (int) ($transformedProduct['quantity'] ?? 0),
                weight: $transformedProduct['weight'] ?? null,
                weightUnit: $transformedProduct['weightUnit'] ?? 'lb',
                brand: $transformedProduct['brand'] ?? null,
                category: $transformedProduct['category'] ?? null,
                categoryId: $transformedProduct['categoryId'] ?? null,
                images: $transformedProduct['images'] ?? [],
                attributes: $transformedProduct['attributes'] ?? [],
                variants: $transformedProduct['variants'] ?? [],
                condition: $transformedProduct['condition'] ?? 'new',
                status: $transformedProduct['status'] ?? 'active',
                metadata: $transformedProduct['metadata'] ?? [],
            );

            $success = $connector->updateProduct($listing->external_listing_id, $platformProduct);

            if (! $success) {
                return ActionResult::failure('Failed to update listing on platform');
            }

            $listing->update([
                'platform_price' => $platformProduct->price,
                'platform_quantity' => $platformProduct->quantity,
                'platform_data' => $transformedProduct,
                'last_synced_at' => now(),
            ]);

            return ActionResult::success(
                "Listing updated on {$marketplace->platform->label()}",
                [
                    'listing_id' => $listing->id,
                    'external_id' => $listing->external_listing_id,
                    'platform' => $marketplace->platform->value,
                    'previous_data' => $previousData,
                ]
            );
        } catch (\Throwable $e) {
            return ActionResult::failure("Failed to update listing: {$e->getMessage()}");
        }
    }

    public function rollback(AgentAction $action): bool
    {
        $result = $action->result ?? [];
        $listingId = $result['listing_id'] ?? null;
        $externalId = $result['external_id'] ?? null;
        $previousData = $result['previous_data'] ?? null;
        $marketplaceId = $action->payload['marketplace_id'] ?? null;

        if (! $listingId || ! $externalId || ! $previousData || ! $marketplaceId) {
            return false;
        }

        try {
            $marketplace = StoreMarketplace::find($marketplaceId);
            $listing = PlatformListing::find($listingId);

            if (! $marketplace || ! $listing) {
                return false;
            }

            $connector = $this->connectorManager->getConnectorForMarketplace($marketplace);

            $platformProduct = new \App\Services\Marketplace\DTOs\PlatformProduct(
                externalId: $externalId,
                title: $previousData['title'] ?? '',
                description: $previousData['description'] ?? '',
                sku: $previousData['sku'] ?? null,
                barcode: $previousData['barcode'] ?? null,
                price: (float) ($previousData['price'] ?? 0),
                compareAtPrice: $previousData['compareAtPrice'] ?? null,
                quantity: (int) ($previousData['quantity'] ?? 0),
                weight: $previousData['weight'] ?? null,
                weightUnit: $previousData['weightUnit'] ?? 'lb',
                brand: $previousData['brand'] ?? null,
                category: $previousData['category'] ?? null,
                categoryId: $previousData['categoryId'] ?? null,
                images: $previousData['images'] ?? [],
                attributes: $previousData['attributes'] ?? [],
                variants: $previousData['variants'] ?? [],
                condition: $previousData['condition'] ?? 'new',
                status: $previousData['status'] ?? 'active',
                metadata: $previousData['metadata'] ?? [],
            );

            $connector->updateProduct($externalId, $platformProduct);

            $listing->update([
                'platform_price' => $platformProduct->price,
                'platform_quantity' => $platformProduct->quantity,
                'platform_data' => $previousData,
            ]);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function validatePayload(array $payload): bool
    {
        return isset($payload['marketplace_id'])
            && isset($payload['transformed_product'])
            && isset($payload['transformed_product']['title'])
            && isset($payload['transformed_product']['price']);
    }
}
