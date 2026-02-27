<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\ActivityLog;
use App\Models\PlatformListing;
use App\Models\Product;
use App\Models\StoreMarketplace;
use App\Services\Platforms\ListingBuilderService;
use App\Services\Platforms\PlatformManager;
use App\Services\StoreContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PlatformListingController extends Controller
{
    public function __construct(
        protected StoreContext $storeContext,
        protected ListingBuilderService $listingBuilder,
        protected PlatformManager $platformManager,
    ) {}

    /**
     * Get all platform listings for a product.
     */
    public function index(Product $product): JsonResponse
    {
        $this->authorize('view', $product);

        $product->load(['platformListings.marketplace', 'platformListings.listingVariants']);

        $store = $this->storeContext->getCurrentStore();
        $marketplaces = StoreMarketplace::where('store_id', $store->id)
            ->sellingPlatforms()
            ->connected()
            ->get();

        $listings = $product->platformListings->map(fn ($listing) => [
            'id' => $listing->id,
            'marketplace_id' => $listing->store_marketplace_id,
            'marketplace_name' => $listing->marketplace?->name ?: ucfirst($listing->marketplace?->platform?->value ?? 'unknown'),
            'platform' => $listing->marketplace?->platform?->value,
            'platform_label' => $listing->marketplace?->platform?->label(),
            'status' => $listing->status,
            'listing_url' => $listing->listing_url,
            'external_listing_id' => $listing->external_listing_id,
            'platform_price' => $listing->platform_price,
            'platform_quantity' => $listing->platform_quantity,
            'quantity_override' => $listing->quantity_override,
            'effective_quantity' => $listing->getEffectiveQuantity(),
            'last_synced_at' => $listing->last_synced_at?->toIso8601String(),
            'published_at' => $listing->published_at?->toIso8601String(),
            'last_error' => $listing->last_error,
            'variant_count' => $listing->listingVariants->count(),
        ]);

        // Overrides now come from PlatformListing directly
        $overrides = $product->platformListings
            ->filter(fn ($l) => $l->store_marketplace_id !== null)
            ->keyBy('store_marketplace_id')
            ->map(fn ($listing) => [
                'id' => $listing->id,
                'title' => $listing->title,
                'description' => $listing->description,
                'price' => $listing->platform_price,
                'quantity' => $listing->quantity_override,
                'attributes' => $listing->attributes,
                'platform_category_id' => $listing->platform_category_id,
            ]);

        $availableMarketplaces = $marketplaces->map(fn ($m) => [
            'id' => $m->id,
            'name' => $m->name ?: ucfirst($m->platform->value),
            'platform' => $m->platform->value,
            'platform_label' => $m->platform->label(),
            'has_listing' => $listings->contains('marketplace_id', $m->id),
        ]);

        return response()->json([
            'listings' => $listings,
            'overrides' => $overrides,
            'marketplaces' => $availableMarketplaces,
        ]);
    }

    /**
     * Toggle whether a product should be listed on this marketplace.
     */
    public function toggleShouldList(Product $product, StoreMarketplace $marketplace): JsonResponse
    {
        $this->authorize('update', $product);

        $listing = PlatformListing::firstOrCreate(
            [
                'product_id' => $product->id,
                'store_marketplace_id' => $marketplace->id,
            ],
            [
                'status' => PlatformListing::STATUS_NOT_LISTED,
                'platform_price' => $product->variants()->first()?->price ?? 0,
                'platform_quantity' => null,
            ]
        );

        $listing->update(['should_list' => ! $listing->should_list]);

        return response()->json([
            'success' => true,
            'should_list' => $listing->should_list,
            'message' => $listing->should_list
                ? "Product included for listing on {$marketplace->name}"
                : "Product excluded from listing on {$marketplace->name}",
        ]);
    }

    /**
     * Preview listing data for a marketplace.
     */
    public function preview(Product $product, StoreMarketplace $marketplace): JsonResponse
    {
        $this->authorize('view', $product);

        $preview = $this->listingBuilder->previewListing($product, $marketplace);

        return response()->json($preview);
    }

    /**
     * Publish product to a marketplace.
     */
    public function publish(Request $request, Product $product, StoreMarketplace $marketplace): JsonResponse
    {
        $this->authorize('update', $product);

        // Check if the product is excluded from this marketplace
        $existingListing = PlatformListing::where('product_id', $product->id)
            ->where('store_marketplace_id', $marketplace->id)
            ->first();

        if ($existingListing && ! $existingListing->should_list) {
            return response()->json([
                'success' => false,
                'message' => "This product is excluded from {$marketplace->name}. Toggle 'Should List' to enable publishing.",
            ], 422);
        }

        // Validate override data if provided
        $validated = $request->validate([
            'override' => ['nullable', 'array'],
            'override.title' => ['nullable', 'string', 'max:500'],
            'override.description' => ['nullable', 'string'],
            'override.price' => ['nullable', 'numeric', 'min:0'],
            'override.compare_at_price' => ['nullable', 'numeric', 'min:0'],
            'override.quantity' => ['nullable', 'integer', 'min:0'],
        ]);

        // Save override if provided before publishing
        if (! empty($validated['override'])) {
            $this->listingBuilder->saveOverride($product, $marketplace, $validated['override']);
        }

        // Validate the product can be published
        $validation = $this->listingBuilder->validateListing($product, $marketplace);

        if (! $validation['valid']) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot publish product',
                'errors' => $validation['errors'],
                'warnings' => $validation['warnings'],
            ], 422);
        }

        try {
            $platformService = $this->platformManager->driver($marketplace->platform);
            $listing = $platformService->pushProduct($product, $marketplace);

            return response()->json([
                'success' => true,
                'message' => 'Product published successfully',
                'listing' => [
                    'id' => $listing->id,
                    'status' => $listing->status,
                    'listing_url' => $listing->listing_url,
                    'external_listing_id' => $listing->external_listing_id,
                ],
                'warnings' => $validation['warnings'],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to publish product: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Publish product to all available marketplaces.
     */
    public function publishAll(Product $product): JsonResponse
    {
        $this->authorize('update', $product);

        $store = $this->storeContext->getCurrentStore();

        // Get all connected selling marketplaces
        $marketplaces = StoreMarketplace::where('store_id', $store->id)
            ->sellingPlatforms()
            ->connected()
            ->get();

        // Filter to marketplaces that don't already have an active listing
        $existingMarketplaceIds = $product->platformListings()
            ->whereIn('status', [PlatformListing::STATUS_LISTED, 'active', PlatformListing::STATUS_PENDING])
            ->pluck('store_marketplace_id')
            ->toArray();

        // Get marketplace IDs where should_list is false
        $excludedMarketplaceIds = $product->platformListings()
            ->where('should_list', false)
            ->pluck('store_marketplace_id')
            ->toArray();

        $targetMarketplaces = $marketplaces->reject(
            fn ($m) => in_array($m->id, $existingMarketplaceIds) || in_array($m->id, $excludedMarketplaceIds)
        );

        if ($targetMarketplaces->isEmpty()) {
            return response()->json([
                'success' => true,
                'message' => 'Product is already listed on all connected marketplaces.',
                'published' => [],
                'failed' => [],
            ]);
        }

        $published = [];
        $failed = [];

        foreach ($targetMarketplaces as $marketplace) {
            // Validate first
            $validation = $this->listingBuilder->validateListing($product, $marketplace);

            if (! $validation['valid']) {
                $failed[] = [
                    'marketplace_id' => $marketplace->id,
                    'marketplace_name' => $marketplace->name ?: ucfirst($marketplace->platform->value),
                    'platform' => $marketplace->platform->value,
                    'errors' => $validation['errors'],
                ];

                continue;
            }

            try {
                $platformService = $this->platformManager->driver($marketplace->platform);
                $listing = $platformService->pushProduct($product, $marketplace);

                $published[] = [
                    'marketplace_id' => $marketplace->id,
                    'marketplace_name' => $marketplace->name ?: ucfirst($marketplace->platform->value),
                    'platform' => $marketplace->platform->value,
                    'listing_id' => $listing->id,
                    'listing_url' => $listing->listing_url,
                    'warnings' => $validation['warnings'],
                ];
            } catch (\Throwable $e) {
                $failed[] = [
                    'marketplace_id' => $marketplace->id,
                    'marketplace_name' => $marketplace->name ?: ucfirst($marketplace->platform->value),
                    'platform' => $marketplace->platform->value,
                    'errors' => [$e->getMessage()],
                ];
            }
        }

        $message = count($published) > 0
            ? 'Published to '.count($published).' platform(s).'
            : 'No platforms were published to.';

        if (count($failed) > 0) {
            $message .= ' '.count($failed).' failed.';
        }

        return response()->json([
            'success' => count($published) > 0,
            'message' => $message,
            'published' => $published,
            'failed' => $failed,
        ]);
    }

    /**
     * Unpublish product from a marketplace (keeps the listing record for relisting later).
     */
    public function unpublish(Product $product, StoreMarketplace $marketplace): JsonResponse
    {
        $this->authorize('update', $product);

        $listing = $this->listingBuilder->getExistingListing($product, $marketplace);

        if (! $listing) {
            return response()->json([
                'success' => false,
                'message' => 'Product is not listed on this marketplace',
            ], 404);
        }

        try {
            $platformService = $this->platformManager->driver($marketplace->platform);
            $updatedListing = $platformService->unlistListing($listing);

            // Log the activity on the product
            ActivityLog::log(
                Activity::LISTINGS_UNLIST,
                $product,
                null,
                [
                    'platform' => $marketplace->platform->value,
                    'marketplace_name' => $marketplace->name,
                    'listing_id' => $listing->id,
                ],
                "Unlisted from {$marketplace->name}"
            );

            return response()->json([
                'success' => true,
                'message' => 'Product unlisted successfully. You can relist it at any time.',
                'listing' => [
                    'id' => $updatedListing->id,
                    'status' => $updatedListing->status,
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to unlist product: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Relist a previously unlisted product on a marketplace.
     */
    public function relist(Product $product, StoreMarketplace $marketplace): JsonResponse
    {
        $this->authorize('update', $product);

        $listing = $this->listingBuilder->getExistingListing($product, $marketplace);

        if (! $listing) {
            return response()->json([
                'success' => false,
                'message' => 'No listing found for this product on this marketplace',
            ], 404);
        }

        if ($listing->status !== PlatformListing::STATUS_ENDED) {
            return response()->json([
                'success' => false,
                'message' => 'This listing is not in unlisted status',
            ], 400);
        }

        try {
            $platformService = $this->platformManager->driver($marketplace->platform);
            $updatedListing = $platformService->relistListing($listing);

            // Log the activity on the product
            ActivityLog::log(
                Activity::LISTINGS_RELIST,
                $product,
                null,
                [
                    'platform' => $marketplace->platform->value,
                    'marketplace_name' => $marketplace->name,
                    'listing_id' => $listing->id,
                ],
                "Relisted on {$marketplace->name}"
            );

            return response()->json([
                'success' => true,
                'message' => 'Product relisted successfully',
                'listing' => [
                    'id' => $updatedListing->id,
                    'status' => $updatedListing->status,
                    'listing_url' => $updatedListing->listing_url,
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to relist product: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update platform override for a product.
     * Overrides are now stored directly on the PlatformListing.
     */
    public function updateOverride(Request $request, Product $product, StoreMarketplace $marketplace): JsonResponse
    {
        $this->authorize('update', $product);

        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'string'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'quantity' => ['nullable', 'integer', 'min:0'],
            'attributes' => ['nullable', 'array'],
            'platform_category_id' => ['nullable', 'string', 'max:255'],
            'platform_settings' => ['nullable', 'array'],
        ]);

        $listing = $this->listingBuilder->saveOverride($product, $marketplace, $validated);

        return response()->json([
            'success' => true,
            'message' => 'Override saved successfully',
            'override' => [
                'id' => $listing->id,
                'title' => $listing->title,
                'description' => $listing->description,
                'price' => $listing->platform_price,
                'quantity' => $listing->quantity_override,
                'attributes' => $listing->attributes,
                'platform_category_id' => $listing->platform_category_id,
            ],
        ]);
    }

    /**
     * Sync listing with platform (update price, quantity, etc.)
     */
    public function sync(Product $product, StoreMarketplace $marketplace): JsonResponse
    {
        $this->authorize('update', $product);

        $listing = $this->listingBuilder->getExistingListing($product, $marketplace);

        if (! $listing) {
            return response()->json([
                'success' => false,
                'message' => 'Product is not listed on this marketplace',
            ], 404);
        }

        try {
            $platformService = $this->platformManager->driver($marketplace->platform);
            $updatedListing = $platformService->updateListing($listing);

            return response()->json([
                'success' => true,
                'message' => 'Listing synced successfully',
                'listing' => [
                    'id' => $updatedListing->id,
                    'status' => $updatedListing->status,
                    'last_synced_at' => $updatedListing->last_synced_at?->toIso8601String(),
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to sync listing: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Overview of all platform listings (global view).
     */
    public function overview(Request $request): Response
    {
        $store = $this->storeContext->getCurrentStore();

        $marketplaces = StoreMarketplace::where('store_id', $store->id)
            ->sellingPlatforms()
            ->connected()
            ->get(['id', 'name', 'platform']);

        return Inertia::render('listings/Index', [
            'marketplaces' => $marketplaces->map(fn ($m) => [
                'id' => $m->id,
                'name' => $m->name ?: ucfirst($m->platform->value),
                'platform' => $m->platform->value,
                'platform_label' => $m->platform->label(),
            ]),
            'filters' => [
                'platform' => $request->input('platform'),
                'status' => $request->input('status'),
                'search' => $request->input('search'),
            ],
            'listings' => Inertia::defer(fn () => $this->getPaginatedListings($request, $store)),
        ]);
    }

    /**
     * Get paginated listings for overview, grouped by product.
     *
     * @return array<string, mixed>
     */
    protected function getPaginatedListings(Request $request, $store): array
    {
        // Build query for products that have listings via sales channels
        $productQuery = Product::where('store_id', $store->id)
            ->whereHas('platformListings', function ($q) use ($store, $request) {
                $q->whereHas('salesChannel', fn ($sc) => $sc->where('store_id', $store->id));

                if ($request->filled('platform')) {
                    $q->whereHas('salesChannel.storeMarketplace', fn ($sm) => $sm->where('platform', $request->input('platform')));
                }

                if ($request->filled('status')) {
                    $q->where('status', $request->input('status'));
                }
            })
            ->with(['platformListings' => function ($q) use ($store, $request) {
                $q->whereHas('salesChannel', fn ($sc) => $sc->where('store_id', $store->id))
                    ->with(['salesChannel', 'marketplace']);

                if ($request->filled('platform')) {
                    $q->whereHas('salesChannel.storeMarketplace', fn ($sm) => $sm->where('platform', $request->input('platform')));
                }

                if ($request->filled('status')) {
                    $q->where('status', $request->input('status'));
                }
            }, 'images', 'legacyImages']);

        // Apply search filter
        if ($request->filled('search')) {
            $search = $request->input('search');
            $productQuery->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('handle', 'like', "%{$search}%");
            });
        }

        $products = $productQuery->orderByDesc('updated_at')
            ->paginate(25);

        $data = $products->map(function ($product) {
            $primaryImage = $product->images->firstWhere('is_primary', true)
                ?? $product->images->first()
                ?? $product->legacyImages->first();

            return [
                'id' => $product->id,
                'title' => $product->title,
                'handle' => $product->handle,
                'image' => $primaryImage?->url,
                'listings' => $product->platformListings->map(function ($listing) {
                    $channel = $listing->salesChannel;
                    $marketplace = $listing->marketplace ?? $channel?->storeMarketplace;

                    return [
                        'id' => $listing->id,
                        'sales_channel_id' => $listing->sales_channel_id,
                        'marketplace_id' => $listing->store_marketplace_id,
                        'channel_name' => $channel?->name ?? 'Unknown',
                        'channel_type' => $channel?->type ?? 'unknown',
                        'channel_code' => $channel?->code,
                        'platform' => $marketplace?->platform?->value,
                        'status' => $listing->status,
                        'listing_url' => $listing->listing_url,
                        'external_listing_id' => $listing->external_listing_id,
                        'platform_price' => $listing->platform_price,
                        'platform_quantity' => $listing->getEffectiveQuantity(),
                        'quantity_override' => $listing->quantity_override,
                        'last_synced_at' => $listing->last_synced_at?->toIso8601String(),
                        'last_error' => $listing->last_error,
                    ];
                }),
            ];
        });

        return [
            'data' => $data,
            'meta' => [
                'current_page' => $products->currentPage(),
                'last_page' => $products->lastPage(),
                'per_page' => $products->perPage(),
                'total' => $products->total(),
            ],
        ];
    }

    /**
     * Update a listing's price directly (inline edit from products table).
     */
    public function updatePrice(Request $request, Product $product, PlatformListing $listing): JsonResponse
    {
        $this->authorize('update', $product);

        // Verify the listing belongs to this product
        if ($listing->product_id !== $product->id) {
            return response()->json([
                'success' => false,
                'message' => 'Listing does not belong to this product',
            ], 403);
        }

        $validated = $request->validate([
            'price' => ['required', 'numeric', 'min:0'],
        ]);

        $listing->update([
            'platform_price' => $validated['price'],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Price updated successfully',
            'price' => $listing->platform_price,
        ]);
    }

    /**
     * Set price for a channel, creating a draft listing if needed.
     */
    public function setChannelPrice(Request $request, Product $product, \App\Models\SalesChannel $channel): JsonResponse
    {
        $this->authorize('update', $product);

        $validated = $request->validate([
            'price' => ['required', 'numeric', 'min:0'],
        ]);

        // Check if listing already exists
        $listing = PlatformListing::where('product_id', $product->id)
            ->where('sales_channel_id', $channel->id)
            ->first();

        if ($listing) {
            // Update existing listing
            $listing->update([
                'platform_price' => $validated['price'],
            ]);
        } else {
            // Create new draft listing
            $listing = PlatformListing::create([
                'product_id' => $product->id,
                'sales_channel_id' => $channel->id,
                'store_marketplace_id' => $channel->store_marketplace_id,
                'status' => 'draft',
                'platform_price' => $validated['price'],
                'platform_quantity' => null,
                'platform_data' => [
                    'title' => $product->title,
                    'description' => $product->description,
                ],
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Price set successfully',
            'listing_id' => $listing->id,
            'price' => $listing->platform_price,
        ]);
    }

    /**
     * Bulk update listing statuses.
     */
    public function bulkUpdateStatus(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'listing_ids' => ['required', 'array', 'min:1'],
            'listing_ids.*' => ['required', 'integer'],
            'action' => ['required', 'string', 'in:list,end,archive'],
        ]);

        $store = $this->storeContext->getCurrentStore();
        $listingIds = $validated['listing_ids'];
        $action = $validated['action'];

        // Get listings that belong to this store
        $listings = PlatformListing::whereIn('id', $listingIds)
            ->whereHas('salesChannel', fn ($q) => $q->where('store_id', $store->id))
            ->get();

        if ($listings->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'No valid listings found',
            ], 404);
        }

        $updated = 0;
        $errors = [];

        foreach ($listings as $listing) {
            try {
                $newStatus = match ($action) {
                    'list' => PlatformListing::STATUS_LISTED,
                    'end' => PlatformListing::STATUS_ENDED,
                    'archive' => PlatformListing::STATUS_ARCHIVED,
                };

                if ($listing->canTransitionTo($newStatus)) {
                    $listing->transitionTo($newStatus);

                    // Update timestamps for realistic demo behavior
                    $updateData = ['last_synced_at' => now()];
                    if ($action === 'list') {
                        $updateData['published_at'] = now();
                    }
                    $listing->update($updateData);

                    $updated++;
                } else {
                    $errors[] = "Listing #{$listing->id} cannot transition to {$newStatus}";
                }
            } catch (\Exception $e) {
                $errors[] = "Listing #{$listing->id}: {$e->getMessage()}";
            }
        }

        $actionLabel = match ($action) {
            'list' => 'listed',
            'end' => 'ended',
            'archive' => 'archived',
        };

        return response()->json([
            'success' => true,
            'message' => "{$updated} listing(s) {$actionLabel} successfully",
            'updated' => $updated,
            'errors' => $errors,
        ]);
    }
}
