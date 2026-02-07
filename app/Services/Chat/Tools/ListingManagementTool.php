<?php

namespace App\Services\Chat\Tools;

use App\Enums\Platform;
use App\Models\PlatformListing;
use App\Models\Product;
use App\Models\StoreMarketplace;
use App\Services\Marketplace\Listing\ListingTransformer;
use App\Services\Marketplace\PlatformConnectorManager;

class ListingManagementTool implements ChatToolInterface
{
    public function __construct(
        protected ListingTransformer $transformer,
        protected PlatformConnectorManager $connectorManager
    ) {}

    public function name(): string
    {
        return 'listing_management';
    }

    public function definition(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'Manage product listings across marketplaces. Use when users want to list products on Amazon, Walmart, etc., check listing status, or manage multi-channel listings.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'action' => [
                        'type' => 'string',
                        'enum' => ['status', 'unlisted', 'list_product', 'preview_listing', 'listing_issues'],
                        'description' => 'Action: status (check listing status), unlisted (products not on any platform), list_product (create listing), preview_listing (preview transformation), listing_issues (find problems)',
                    ],
                    'product_id' => [
                        'type' => 'integer',
                        'description' => 'Product ID for single product actions',
                    ],
                    'platform' => [
                        'type' => 'string',
                        'enum' => ['amazon', 'walmart', 'shopify', 'bigcommerce', 'ebay'],
                        'description' => 'Target platform for listing actions',
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'description' => 'Limit number of results (default 10)',
                    ],
                ],
                'required' => ['action'],
            ],
        ];
    }

    public function execute(array $params, int $storeId): array
    {
        $action = $params['action'] ?? 'status';
        $productId = $params['product_id'] ?? null;
        $platform = $params['platform'] ?? null;
        $limit = $params['limit'] ?? 10;

        return match ($action) {
            'status' => $this->getListingStatus($storeId, $productId, $limit),
            'unlisted' => $this->getUnlistedProducts($storeId, $limit),
            'list_product' => $this->listProduct($storeId, $productId, $platform),
            'preview_listing' => $this->previewListing($storeId, $productId, $platform),
            'listing_issues' => $this->getListingIssues($storeId, $platform),
            default => ['error' => 'Unknown action: '.$action],
        };
    }

    protected function getListingStatus(int $storeId, ?int $productId, int $limit): array
    {
        if ($productId) {
            return $this->getSingleProductListingStatus($storeId, $productId);
        }

        // Get marketplace connections
        $marketplaces = StoreMarketplace::where('store_id', $storeId)
            ->where('status', 'active')
            ->get();

        $summary = [];

        foreach ($marketplaces as $marketplace) {
            $total = PlatformListing::where('store_marketplace_id', $marketplace->id)->count();
            $active = PlatformListing::where('store_marketplace_id', $marketplace->id)
                ->where('status', 'active')
                ->count();
            $pending = PlatformListing::where('store_marketplace_id', $marketplace->id)
                ->where('status', 'pending')
                ->count();
            $error = PlatformListing::where('store_marketplace_id', $marketplace->id)
                ->where('status', 'error')
                ->count();

            $summary[] = [
                'platform' => $marketplace->platform->label(),
                'total_listings' => $total,
                'active' => $active,
                'pending' => $pending,
                'errors' => $error,
            ];
        }

        // Recent listings
        $recentListings = PlatformListing::whereHas('storeMarketplace', fn ($q) => $q->where('store_id', $storeId))
            ->with(['product', 'storeMarketplace'])
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn ($l) => [
                'product' => $l->product?->title,
                'platform' => $l->storeMarketplace?->platform->label(),
                'status' => $l->status,
                'price' => $l->platform_price,
                'created' => $l->created_at->diffForHumans(),
            ]);

        return [
            'summary_by_platform' => $summary,
            'recent_listings' => $recentListings,
        ];
    }

    protected function getSingleProductListingStatus(int $storeId, int $productId): array
    {
        $product = Product::where('store_id', $storeId)
            ->where('id', $productId)
            ->first();

        if (! $product) {
            return ['error' => 'Product not found'];
        }

        $listings = PlatformListing::where('product_id', $productId)
            ->with('storeMarketplace')
            ->get()
            ->map(fn ($l) => [
                'platform' => $l->storeMarketplace?->platform->label(),
                'status' => $l->status,
                'external_id' => $l->external_listing_id,
                'price' => $l->platform_price,
                'quantity' => $l->platform_quantity,
                'last_synced' => $l->last_synced_at?->diffForHumans(),
            ]);

        $marketplaces = StoreMarketplace::where('store_id', $storeId)
            ->where('status', 'active')
            ->get();

        $listedPlatforms = $listings->pluck('platform')->toArray();
        $availablePlatforms = $marketplaces->map(fn ($m) => $m->platform->label())->toArray();
        $notListedOn = array_diff($availablePlatforms, $listedPlatforms);

        return [
            'product' => [
                'id' => $product->id,
                'title' => $product->title,
                'sku' => $product->sku,
                'price' => $product->price,
                'quantity' => $product->quantity,
            ],
            'listings' => $listings,
            'not_listed_on' => array_values($notListedOn),
        ];
    }

    protected function getUnlistedProducts(int $storeId, int $limit): array
    {
        $marketplaces = StoreMarketplace::where('store_id', $storeId)
            ->where('status', 'active')
            ->get();

        if ($marketplaces->isEmpty()) {
            return [
                'message' => 'No active marketplace connections.',
                'products' => [],
            ];
        }

        // Find products not listed on ANY platform
        $unlistedProducts = Product::where('store_id', $storeId)
            ->where('status', 'active')
            ->where('quantity', '>', 0)
            ->whereDoesntHave('platformListings', function ($query) {
                $query->whereIn('status', ['active', 'pending']);
            })
            ->limit($limit)
            ->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'title' => $p->title,
                'sku' => $p->sku,
                'price' => $p->price,
                'quantity' => $p->quantity,
            ]);

        return [
            'message' => $unlistedProducts->count().' products not listed on any marketplace',
            'available_platforms' => $marketplaces->map(fn ($m) => $m->platform->label())->toArray(),
            'products' => $unlistedProducts,
        ];
    }

    protected function listProduct(int $storeId, ?int $productId, ?string $platform): array
    {
        if (! $productId) {
            return ['error' => 'product_id is required'];
        }

        if (! $platform) {
            return ['error' => 'platform is required'];
        }

        $product = Product::where('store_id', $storeId)
            ->where('id', $productId)
            ->first();

        if (! $product) {
            return ['error' => 'Product not found'];
        }

        try {
            $platformEnum = Platform::from($platform);
        } catch (\Throwable) {
            return ['error' => "Invalid platform: {$platform}"];
        }

        $marketplace = StoreMarketplace::where('store_id', $storeId)
            ->where('platform', $platformEnum)
            ->where('status', 'active')
            ->first();

        if (! $marketplace) {
            return ['error' => "No active {$platform} connection found"];
        }

        // Check if already listed
        $existingListing = PlatformListing::where('store_marketplace_id', $marketplace->id)
            ->where('product_id', $product->id)
            ->first();

        if ($existingListing) {
            return [
                'message' => 'Product is already listed on this platform',
                'listing' => [
                    'status' => $existingListing->status,
                    'external_id' => $existingListing->external_listing_id,
                    'price' => $existingListing->platform_price,
                ],
            ];
        }

        // Transform and create listing
        try {
            $transformed = $this->transformer->transform($product, $platformEnum);
            $connector = $this->connectorManager->getConnectorForMarketplace($marketplace);
            $externalId = $connector->createProduct($transformed);

            if (! $externalId) {
                return ['error' => 'Failed to create listing on platform'];
            }

            $listing = PlatformListing::create([
                'store_marketplace_id' => $marketplace->id,
                'product_id' => $product->id,
                'external_listing_id' => $externalId,
                'status' => 'pending',
                'platform_price' => $transformed->price,
                'platform_quantity' => $transformed->quantity,
                'platform_data' => $transformed->toArray(),
                'last_synced_at' => now(),
            ]);

            return [
                'message' => "Product successfully listed on {$platformEnum->label()}",
                'listing' => [
                    'id' => $listing->id,
                    'external_id' => $externalId,
                    'optimized_title' => $transformed->title,
                    'price' => $transformed->price,
                ],
            ];
        } catch (\Throwable $e) {
            return ['error' => "Failed to list product: {$e->getMessage()}"];
        }
    }

    protected function previewListing(int $storeId, ?int $productId, ?string $platform): array
    {
        if (! $productId) {
            return ['error' => 'product_id is required'];
        }

        if (! $platform) {
            return ['error' => 'platform is required'];
        }

        $product = Product::where('store_id', $storeId)
            ->where('id', $productId)
            ->with(['brand', 'category', 'images'])
            ->first();

        if (! $product) {
            return ['error' => 'Product not found'];
        }

        try {
            $platformEnum = Platform::from($platform);
            $transformed = $this->transformer->transform($product, $platformEnum);

            return [
                'original' => [
                    'title' => $product->title,
                    'description' => substr($product->description ?? '', 0, 200),
                    'price' => $product->price,
                ],
                'optimized_for_'.$platform => [
                    'title' => $transformed->title,
                    'description' => substr($transformed->description, 0, 200).'...',
                    'price' => $transformed->price,
                    'bullet_points' => $transformed->attributes['bullet_points'] ?? [],
                    'tags' => $transformed->metadata['tags'] ?? [],
                ],
            ];
        } catch (\Throwable $e) {
            return ['error' => "Preview failed: {$e->getMessage()}"];
        }
    }

    protected function getListingIssues(int $storeId, ?string $platform): array
    {
        $query = PlatformListing::whereHas('storeMarketplace', fn ($q) => $q->where('store_id', $storeId));

        if ($platform) {
            $query->whereHas('storeMarketplace', fn ($q) => $q->where('platform', $platform));
        }

        $issues = [];

        // Error status listings
        $errorListings = (clone $query)->where('status', 'error')
            ->with(['product', 'storeMarketplace'])
            ->limit(10)
            ->get();

        foreach ($errorListings as $listing) {
            $issues[] = [
                'type' => 'error',
                'product' => $listing->product?->title,
                'platform' => $listing->storeMarketplace?->platform->label(),
                'issue' => 'Listing in error state',
            ];
        }

        // Out of sync inventory
        $outOfSyncListings = (clone $query)->whereIn('status', ['active', 'pending'])
            ->with(['product', 'storeMarketplace'])
            ->get()
            ->filter(fn ($l) => $l->product && $l->platform_quantity !== $l->product->quantity);

        foreach ($outOfSyncListings->take(10) as $listing) {
            $issues[] = [
                'type' => 'inventory_sync',
                'product' => $listing->product?->title,
                'platform' => $listing->storeMarketplace?->platform->label(),
                'issue' => "Inventory mismatch: local={$listing->product->quantity}, platform={$listing->platform_quantity}",
            ];
        }

        // Price mismatches
        $priceMismatchListings = (clone $query)->whereIn('status', ['active', 'pending'])
            ->with(['product', 'storeMarketplace'])
            ->get()
            ->filter(fn ($l) => $l->product && abs($l->platform_price - $l->product->price) > 0.01);

        foreach ($priceMismatchListings->take(10) as $listing) {
            $issues[] = [
                'type' => 'price_sync',
                'product' => $listing->product?->title,
                'platform' => $listing->storeMarketplace?->platform->label(),
                'issue' => "Price mismatch: local=\${$listing->product->price}, platform=\${$listing->platform_price}",
            ];
        }

        return [
            'total_issues' => count($issues),
            'issues' => $issues,
        ];
    }
}
