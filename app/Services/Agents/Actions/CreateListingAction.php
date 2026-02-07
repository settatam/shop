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

class CreateListingAction implements AgentActionInterface
{
    public function __construct(
        protected PlatformConnectorManager $connectorManager
    ) {}

    public function getType(): string
    {
        return 'create_listing';
    }

    public function getDescription(): string
    {
        return 'Create a new product listing on an external marketplace';
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
        $transformedProduct = $payload['transformed_product'] ?? null;

        if (! $marketplaceId || ! $transformedProduct) {
            return ActionResult::failure('Missing marketplace_id or transformed_product in payload');
        }

        $marketplace = StoreMarketplace::find($marketplaceId);

        if (! $marketplace) {
            return ActionResult::failure("Marketplace #{$marketplaceId} not found");
        }

        try {
            $connector = $this->connectorManager->getConnectorForMarketplace($marketplace);

            $platformProduct = new \App\Services\Marketplace\DTOs\PlatformProduct(
                externalId: $transformedProduct['externalId'] ?? '',
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

            $externalId = $connector->createProduct($platformProduct);

            if (! $externalId) {
                return ActionResult::failure('Failed to create listing on platform');
            }

            $listing = PlatformListing::create([
                'store_marketplace_id' => $marketplace->id,
                'product_id' => $product->id,
                'external_listing_id' => $externalId,
                'status' => 'pending',
                'platform_price' => $platformProduct->price,
                'platform_quantity' => $platformProduct->quantity,
                'platform_data' => $transformedProduct,
                'last_synced_at' => now(),
            ]);

            return ActionResult::success(
                "Listing created on {$marketplace->platform->label()} with ID: {$externalId}",
                [
                    'listing_id' => $listing->id,
                    'external_id' => $externalId,
                    'platform' => $marketplace->platform->value,
                ]
            );
        } catch (\Throwable $e) {
            return ActionResult::failure("Failed to create listing: {$e->getMessage()}");
        }
    }

    public function rollback(AgentAction $action): bool
    {
        $result = $action->result ?? [];
        $listingId = $result['listing_id'] ?? null;
        $externalId = $result['external_id'] ?? null;
        $marketplaceId = $action->payload['marketplace_id'] ?? null;

        if (! $listingId || ! $externalId || ! $marketplaceId) {
            return false;
        }

        try {
            $marketplace = StoreMarketplace::find($marketplaceId);

            if (! $marketplace) {
                return false;
            }

            $connector = $this->connectorManager->getConnectorForMarketplace($marketplace);
            $connector->deleteProduct($externalId);

            PlatformListing::destroy($listingId);

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
