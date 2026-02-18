<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
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

        $product->load(['platformListings.marketplace', 'platformOverrides.marketplace']);

        $store = $this->storeContext->getCurrentStore();
        $marketplaces = StoreMarketplace::where('store_id', $store->id)
            ->sellingPlatforms()
            ->connected()
            ->get();

        $listings = $product->platformListings->map(fn ($listing) => [
            'id' => $listing->id,
            'marketplace_id' => $listing->store_marketplace_id,
            'marketplace_name' => $listing->marketplace->name ?: ucfirst($listing->marketplace->platform->value),
            'platform' => $listing->marketplace->platform->value,
            'platform_label' => $listing->marketplace->platform->label(),
            'status' => $listing->status,
            'listing_url' => $listing->listing_url,
            'external_listing_id' => $listing->external_listing_id,
            'platform_price' => $listing->platform_price,
            'platform_quantity' => $listing->platform_quantity,
            'last_synced_at' => $listing->last_synced_at?->toIso8601String(),
            'published_at' => $listing->published_at?->toIso8601String(),
            'last_error' => $listing->last_error,
        ]);

        $overrides = $product->platformOverrides->keyBy('store_marketplace_id')
            ->map(fn ($override) => [
                'id' => $override->id,
                'title' => $override->title,
                'description' => $override->description,
                'price' => $override->price,
                'compare_at_price' => $override->compare_at_price,
                'quantity' => $override->quantity,
                'attributes' => $override->attributes,
                'category_id' => $override->category_id,
                'is_active' => $override->is_active,
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
     * Unpublish product from a marketplace.
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
            $platformService->deleteListing($listing);

            return response()->json([
                'success' => true,
                'message' => 'Product unpublished successfully',
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to unpublish product: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update platform override for a product.
     */
    public function updateOverride(Request $request, Product $product, StoreMarketplace $marketplace): JsonResponse
    {
        $this->authorize('update', $product);

        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'string'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'compare_at_price' => ['nullable', 'numeric', 'min:0'],
            'quantity' => ['nullable', 'integer', 'min:0'],
            'attributes' => ['nullable', 'array'],
            'category_id' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $override = $this->listingBuilder->saveOverride($product, $marketplace, $validated);

        return response()->json([
            'success' => true,
            'message' => 'Override saved successfully',
            'override' => [
                'id' => $override->id,
                'title' => $override->title,
                'description' => $override->description,
                'price' => $override->price,
                'compare_at_price' => $override->compare_at_price,
                'quantity' => $override->quantity,
                'attributes' => $override->attributes,
                'category_id' => $override->category_id,
                'is_active' => $override->is_active,
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

        return Inertia::render('Listings/Index', [
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
     * Get paginated listings for overview.
     *
     * @return array<string, mixed>
     */
    protected function getPaginatedListings(Request $request, $store): array
    {
        $query = PlatformListing::whereHas('marketplace', fn ($q) => $q->where('store_id', $store->id))
            ->with(['product:id,title,handle', 'marketplace:id,name,platform']);

        // Apply filters
        if ($request->filled('platform')) {
            $query->whereHas('marketplace', fn ($q) => $q->where('platform', $request->input('platform')));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->whereHas('product', fn ($q) => $q->where('title', 'like', "%{$search}%")
                ->orWhere('handle', 'like', "%{$search}%"));
        }

        $listings = $query->orderByDesc('updated_at')
            ->paginate(25);

        return [
            'data' => $listings->items(),
            'meta' => [
                'current_page' => $listings->currentPage(),
                'last_page' => $listings->lastPage(),
                'per_page' => $listings->perPage(),
                'total' => $listings->total(),
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
                'platform_quantity' => $product->total_quantity,
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
}
