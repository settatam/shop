<?php

namespace App\Services\Agents\Agents;

use App\Enums\AgentType;
use App\Enums\Platform;
use App\Models\AgentAction;
use App\Models\AgentRun;
use App\Models\PlatformListing;
use App\Models\Product;
use App\Models\StoreAgent;
use App\Models\StoreMarketplace;
use App\Services\Agents\Contracts\AgentInterface;
use App\Services\Agents\Results\AgentRunResult;
use App\Services\Marketplace\Listing\ListingTransformer;
use App\Services\Marketplace\PlatformConnectorManager;

class ProductListingAgent implements AgentInterface
{
    public function __construct(
        protected ListingTransformer $transformer,
        protected PlatformConnectorManager $connectorManager
    ) {}

    public function getName(): string
    {
        return 'Product Listing Agent';
    }

    public function getSlug(): string
    {
        return 'product-listing';
    }

    public function getType(): AgentType
    {
        return AgentType::GoalOriented;
    }

    public function getDescription(): string
    {
        return 'Transforms products for multi-channel listing on Amazon, Walmart, Shopify, BigCommerce, and other platforms. Uses AI to optimize titles, descriptions, and attributes for each marketplace.';
    }

    public function getDefaultConfig(): array
    {
        return [
            'auto_optimize' => true,
            'platforms' => ['shopify', 'amazon', 'walmart', 'bigcommerce'],
            'batch_size' => 50,
            'require_approval_for_publish' => true,
            'auto_sync_inventory' => true,
        ];
    }

    public function getConfigSchema(): array
    {
        return [
            'auto_optimize' => [
                'type' => 'boolean',
                'label' => 'Auto-Optimize Listings',
                'description' => 'Use AI to automatically optimize titles and descriptions',
                'default' => true,
            ],
            'platforms' => [
                'type' => 'multiselect',
                'label' => 'Target Platforms',
                'description' => 'Platforms to prepare listings for',
                'options' => [
                    'shopify' => 'Shopify',
                    'amazon' => 'Amazon',
                    'walmart' => 'Walmart',
                    'bigcommerce' => 'BigCommerce',
                    'ebay' => 'eBay',
                    'etsy' => 'Etsy',
                ],
                'default' => ['shopify', 'amazon'],
            ],
            'batch_size' => [
                'type' => 'number',
                'label' => 'Batch Size',
                'description' => 'Number of products to process per run',
                'default' => 50,
            ],
            'require_approval_for_publish' => [
                'type' => 'boolean',
                'label' => 'Require Approval',
                'description' => 'Require manual approval before publishing listings',
                'default' => true,
            ],
        ];
    }

    public function run(AgentRun $run, StoreAgent $storeAgent): AgentRunResult
    {
        $config = $storeAgent->getMergedConfig();
        $storeId = $storeAgent->store_id;

        $platforms = $config['platforms'] ?? ['shopify'];
        $batchSize = $config['batch_size'] ?? 50;
        $requireApproval = $config['require_approval_for_publish'] ?? true;

        // Get products that need listing
        $products = $this->getProductsToList($storeId, $batchSize);

        if ($products->isEmpty()) {
            return AgentRunResult::success([
                'message' => 'No products found that need listing',
                'products_processed' => 0,
            ], 0);
        }

        $actionsCreated = 0;
        $results = [
            'products_processed' => 0,
            'listings_prepared' => 0,
            'by_platform' => [],
        ];

        foreach ($products as $product) {
            foreach ($platforms as $platformSlug) {
                try {
                    $platform = Platform::from($platformSlug);

                    // Check if we have a marketplace connection for this platform
                    $marketplace = StoreMarketplace::where('store_id', $storeId)
                        ->where('platform', $platform)
                        ->where('status', 'active')
                        ->first();

                    if (! $marketplace) {
                        continue;
                    }

                    // Check if listing already exists
                    $existingListing = PlatformListing::where('store_marketplace_id', $marketplace->id)
                        ->where('product_id', $product->id)
                        ->first();

                    // Transform the product for this platform
                    $transformedProduct = $this->transformer->transform($product, $platform);

                    // Create action for listing
                    AgentAction::create([
                        'agent_run_id' => $run->id,
                        'store_id' => $storeId,
                        'action_type' => $existingListing ? 'update_listing' : 'create_listing',
                        'actionable_type' => Product::class,
                        'actionable_id' => $product->id,
                        'status' => 'pending',
                        'requires_approval' => $requireApproval,
                        'payload' => [
                            'platform' => $platform->value,
                            'marketplace_id' => $marketplace->id,
                            'existing_listing_id' => $existingListing?->id,
                            'transformed_product' => $transformedProduct->toArray(),
                            'original_title' => $product->title,
                            'optimized_title' => $transformedProduct->title,
                            'reasoning' => $this->generateReasoning($product, $transformedProduct, $platform),
                        ],
                    ]);

                    $actionsCreated++;
                    $results['listings_prepared']++;
                    $results['by_platform'][$platformSlug] = ($results['by_platform'][$platformSlug] ?? 0) + 1;
                } catch (\Throwable $e) {
                    // Log error but continue with other products/platforms
                    continue;
                }
            }

            $results['products_processed']++;
        }

        return AgentRunResult::success($results, $actionsCreated);
    }

    /**
     * Get products that need to be listed on platforms.
     *
     * @return \Illuminate\Database\Eloquent\Collection<Product>
     */
    protected function getProductsToList(int $storeId, int $limit): \Illuminate\Database\Eloquent\Collection
    {
        return Product::where('store_id', $storeId)
            ->where('status', 'active')
            ->where('is_published', true)
            ->where('quantity', '>', 0)
            ->whereDoesntHave('platformListings', function ($query) {
                $query->where('status', 'active');
            })
            ->with(['brand', 'category', 'images', 'variants'])
            ->limit($limit)
            ->get();
    }

    /**
     * Generate reasoning for the listing transformation.
     */
    protected function generateReasoning(Product $product, \App\Services\Marketplace\DTOs\PlatformProduct $transformed, Platform $platform): string
    {
        $changes = [];

        if ($product->title !== $transformed->title) {
            $changes[] = "Title optimized from \"{$product->title}\" to \"{$transformed->title}\" for better {$platform->label()} search visibility";
        }

        if (! empty($transformed->metadata['tags'] ?? [])) {
            $changes[] = 'Generated '.count($transformed->metadata['tags']).' tags for discoverability';
        }

        if (! empty($transformed->attributes['bullet_points'] ?? [])) {
            $changes[] = 'Created '.count($transformed->attributes['bullet_points']).' bullet points highlighting key features';
        }

        if (empty($changes)) {
            $changes[] = "Product prepared for {$platform->label()} with platform-specific formatting";
        }

        return implode('. ', $changes).'.';
    }

    public function canRun(StoreAgent $storeAgent): bool
    {
        if (! $storeAgent->canRun()) {
            return false;
        }

        // Check if store has any marketplace connections
        return StoreMarketplace::where('store_id', $storeAgent->store_id)
            ->where('status', 'active')
            ->exists();
    }

    public function getSubscribedEvents(): array
    {
        return [
            'product.created',
            'product.updated',
            'product.published',
        ];
    }

    public function handleEvent(string $event, array $payload, StoreAgent $storeAgent): void
    {
        // Could trigger immediate listing for new/updated products
        // For now, let the scheduled runs handle it
    }

    /**
     * List a single product on a specific platform.
     */
    public function listProduct(Product $product, Platform $platform, StoreMarketplace $marketplace): ?PlatformListing
    {
        try {
            $transformed = $this->transformer->transform($product, $platform);
            $connector = $this->connectorManager->getConnectorForMarketplace($marketplace);

            // Check if listing exists
            $existingListing = PlatformListing::where('store_marketplace_id', $marketplace->id)
                ->where('product_id', $product->id)
                ->first();

            if ($existingListing && $existingListing->external_listing_id) {
                // Update existing listing
                $success = $connector->updateProduct($existingListing->external_listing_id, $transformed);

                if ($success) {
                    $existingListing->update([
                        'platform_price' => $transformed->price,
                        'platform_quantity' => $transformed->quantity,
                        'platform_data' => $transformed->toArray(),
                        'last_synced_at' => now(),
                    ]);
                }

                return $existingListing;
            }

            // Create new listing
            $externalId = $connector->createProduct($transformed);

            if (! $externalId) {
                return null;
            }

            return PlatformListing::create([
                'store_marketplace_id' => $marketplace->id,
                'product_id' => $product->id,
                'external_listing_id' => $externalId,
                'status' => 'pending',
                'platform_price' => $transformed->price,
                'platform_quantity' => $transformed->quantity,
                'platform_data' => $transformed->toArray(),
                'last_synced_at' => now(),
            ]);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Bulk list products on a platform.
     *
     * @param  Product[]  $products
     * @return array{success: int, failed: int, listings: PlatformListing[]}
     */
    public function bulkListProducts(array $products, Platform $platform, StoreMarketplace $marketplace): array
    {
        $success = 0;
        $failed = 0;
        $listings = [];

        foreach ($products as $product) {
            $listing = $this->listProduct($product, $platform, $marketplace);

            if ($listing) {
                $success++;
                $listings[] = $listing;
            } else {
                $failed++;
            }
        }

        return [
            'success' => $success,
            'failed' => $failed,
            'listings' => $listings,
        ];
    }
}
