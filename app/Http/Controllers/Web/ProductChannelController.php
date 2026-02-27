<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\PlatformListing;
use App\Models\Product;
use App\Models\SalesChannel;
use App\Services\StoreContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProductChannelController extends Controller
{
    public function __construct(
        protected StoreContext $storeContext,
    ) {}

    /**
     * Show the channel listing page for a product (e.g., In Store).
     */
    public function show(Product $product, SalesChannel $channel): Response
    {
        $this->authorize('view', $product);
        $this->authorizeChannel($channel);

        $product->load([
            'images',
            'legacyImages',
            'variants',
            'category',
            'brand',
        ]);

        // Get or create listing (also auto-lists active products on auto_list channels)
        $listing = $product->ensureListingExists($channel);

        // Get default variant for pricing
        $defaultVariant = $product->variants()->first();

        // Get all active channels for this store (for "List on All" feature)
        $allChannels = SalesChannel::where('store_id', $this->storeContext->getCurrentStoreId())
            ->where('is_active', true)
            ->ordered()
            ->get()
            ->map(fn (SalesChannel $ch) => [
                'id' => $ch->id,
                'name' => $ch->name,
                'type' => $ch->type,
                'is_local' => $ch->is_local,
                'is_listed' => $product->platformListings()
                    ->where('sales_channel_id', $ch->id)
                    ->where('status', PlatformListing::STATUS_LISTED)
                    ->exists(),
            ]);

        return Inertia::render('products/channels/Show', [
            'product' => [
                'id' => $product->id,
                'title' => $product->title,
                'description' => $product->description,
                'handle' => $product->handle,
                'status' => $product->status,
                'category' => $product->category?->name,
                'brand' => $product->brand?->name,
                'default_price' => $defaultVariant?->price,
                'default_quantity' => $defaultVariant?->quantity,
                'images' => $product->images->map(fn ($img) => [
                    'id' => $img->id,
                    'url' => $img->url,
                    'alt' => $img->alt,
                    'is_primary' => $img->is_primary,
                ])->merge($product->legacyImages->map(fn ($img) => [
                    'id' => $img->id,
                    'url' => $img->url,
                    'alt' => $img->alt ?? $product->title,
                    'is_primary' => $img->is_primary ?? false,
                ]))->toArray(),
            ],
            'channel' => [
                'id' => $channel->id,
                'name' => $channel->name,
                'code' => $channel->code,
                'type' => $channel->type,
                'type_label' => $channel->type_label,
                'is_local' => $channel->is_local,
                'color' => $channel->color,
            ],
            'listing' => $listing ? [
                'id' => $listing->id,
                'status' => $listing->status,
                'should_list' => $listing->should_list,
                'platform_price' => $listing->platform_price,
                'platform_quantity' => $listing->platform_quantity,
                'platform_data' => $listing->platform_data,
                'published_at' => $listing->published_at?->toIso8601String(),
                'last_synced_at' => $listing->last_synced_at?->toIso8601String(),
            ] : null,
            'allChannels' => $allChannels,
        ]);
    }

    /**
     * Update the channel listing for a product.
     */
    public function update(Request $request, Product $product, SalesChannel $channel): JsonResponse
    {
        $this->authorize('update', $product);
        $this->authorizeChannel($channel);

        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'string'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'quantity' => ['nullable', 'integer', 'min:0'],
        ]);

        // Get or create listing
        $listing = PlatformListing::firstOrNew([
            'product_id' => $product->id,
            'sales_channel_id' => $channel->id,
        ]);

        // Update listing data
        $listing->platform_price = $validated['price'] ?? $listing->platform_price;
        $listing->platform_quantity = $validated['quantity'] ?? $listing->platform_quantity;
        $listing->platform_data = array_merge($listing->platform_data ?? [], [
            'title' => $validated['title'] ?? $product->title,
            'description' => $validated['description'] ?? $product->description,
        ]);
        $listing->store_marketplace_id = $channel->store_marketplace_id;
        $listing->save();

        return response()->json([
            'success' => true,
            'message' => 'Channel listing updated',
            'listing' => [
                'id' => $listing->id,
                'status' => $listing->status,
                'platform_price' => $listing->platform_price,
                'platform_quantity' => $listing->platform_quantity,
                'platform_data' => $listing->platform_data,
            ],
        ]);
    }

    /**
     * Publish/list the product on this channel.
     */
    public function publish(Request $request, Product $product, SalesChannel $channel): JsonResponse
    {
        $this->authorize('update', $product);
        $this->authorizeChannel($channel);

        // Check if the product is excluded from this channel
        $existingListing = $product->platformListings()
            ->where('sales_channel_id', $channel->id)
            ->first();

        if ($existingListing && ! $existingListing->should_list) {
            return response()->json([
                'success' => false,
                'message' => "This product is excluded from {$channel->name}. Toggle 'Should List' to enable publishing.",
            ], 422);
        }

        $listing = $product->listOnChannel($channel, PlatformListing::STATUS_LISTED);

        return response()->json([
            'success' => true,
            'message' => "Product listed on {$channel->name}",
            'listing' => [
                'id' => $listing->id,
                'status' => $listing->status,
                'platform_price' => $listing->platform_price,
            ],
        ]);
    }

    /**
     * Unpublish/unlist the product from this channel.
     */
    public function unpublish(Product $product, SalesChannel $channel): JsonResponse
    {
        $this->authorize('update', $product);
        $this->authorizeChannel($channel);

        $result = $product->unlistFromChannel($channel);

        if (! $result) {
            return response()->json([
                'success' => false,
                'message' => 'Product is not listed on this channel',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => "Product unlisted from {$channel->name}",
        ]);
    }

    /**
     * List the product on all enabled platforms at once.
     */
    public function listOnAllPlatforms(Product $product): JsonResponse
    {
        $this->authorize('update', $product);

        $listings = $product->listOnAllPlatforms(respectShouldList: true);

        // Also list on In Store if not already
        $product->listOnInStore();

        $channelNames = collect($listings)->map(fn ($l) => $l->salesChannel?->name)->filter()->implode(', ');

        return response()->json([
            'success' => true,
            'message' => 'Product listed on all platforms',
            'channels' => $channelNames,
            'count' => count($listings),
        ]);
    }

    /**
     * Toggle whether a product should be listed on this channel.
     */
    public function toggleShouldList(Product $product, SalesChannel $channel): JsonResponse
    {
        $this->authorize('update', $product);
        $this->authorizeChannel($channel);

        $listing = $product->ensureListingExists($channel);
        $listing->update(['should_list' => ! $listing->should_list]);

        return response()->json([
            'success' => true,
            'should_list' => $listing->should_list,
            'message' => $listing->should_list
                ? "Product included for listing on {$channel->name}"
                : "Product excluded from listing on {$channel->name}",
        ]);
    }

    /**
     * Toggle "not for sale" status for a product on this channel.
     */
    public function toggleNotForSale(Product $product, SalesChannel $channel): JsonResponse
    {
        $this->authorize('update', $product);
        $this->authorizeChannel($channel);

        $listing = PlatformListing::where('product_id', $product->id)
            ->where('sales_channel_id', $channel->id)
            ->first();

        if (! $listing) {
            // Create a listing marked as not for sale
            $defaultVariant = $product->variants()->first();
            $listing = PlatformListing::create([
                'sales_channel_id' => $channel->id,
                'store_marketplace_id' => $channel->store_marketplace_id,
                'product_id' => $product->id,
                'status' => PlatformListing::STATUS_NOT_FOR_SALE,
                'platform_price' => $defaultVariant?->price ?? 0,
                'platform_quantity' => $defaultVariant?->quantity ?? 0,
                'platform_data' => [
                    'title' => $product->title,
                    'description' => $product->description,
                ],
            ]);

            return response()->json([
                'success' => true,
                'message' => "Product marked as not for sale on {$channel->name}",
                'is_not_for_sale' => true,
            ]);
        }

        // Toggle status
        if ($listing->isNotForSale()) {
            $listing->enableForSale();
            $message = "Product is now for sale on {$channel->name}";
            $isNotForSale = false;
        } else {
            $listing->markAsNotForSale();
            $message = "Product marked as not for sale on {$channel->name}";
            $isNotForSale = true;
        }

        return response()->json([
            'success' => true,
            'message' => $message,
            'is_not_for_sale' => $isNotForSale,
        ]);
    }

    /**
     * Get all channel listings for a product (overview).
     */
    public function listings(Product $product): JsonResponse
    {
        $this->authorize('view', $product);

        $channels = SalesChannel::where('store_id', $this->storeContext->getCurrentStoreId())
            ->where('is_active', true)
            ->ordered()
            ->get();

        $listings = $channels->map(function (SalesChannel $channel) use ($product) {
            $listing = $product->platformListings()
                ->where('sales_channel_id', $channel->id)
                ->first();

            return [
                'channel' => [
                    'id' => $channel->id,
                    'name' => $channel->name,
                    'type' => $channel->type,
                    'type_label' => $channel->type_label,
                    'is_local' => $channel->is_local,
                    'color' => $channel->color,
                ],
                'listing' => $listing ? [
                    'id' => $listing->id,
                    'status' => $listing->status,
                    'status_label' => $listing->status_label,
                    'is_for_sale' => $listing->isForSale(),
                    'is_not_for_sale' => $listing->isNotForSale(),
                    'platform_price' => $listing->platform_price,
                    'platform_quantity' => $listing->platform_quantity,
                    'listing_url' => $listing->listing_url,
                    'published_at' => $listing->published_at?->toIso8601String(),
                    'last_synced_at' => $listing->last_synced_at?->toIso8601String(),
                    'last_error' => $listing->last_error,
                ] : null,
            ];
        });

        return response()->json([
            'product_id' => $product->id,
            'listings' => $listings,
        ]);
    }

    /**
     * Sync a single channel listing with latest product data.
     */
    public function sync(Product $product, SalesChannel $channel): JsonResponse
    {
        $this->authorize('update', $product);
        $this->authorizeChannel($channel);

        $listing = PlatformListing::where('product_id', $product->id)
            ->where('sales_channel_id', $channel->id)
            ->first();

        if (! $listing) {
            return response()->json([
                'success' => false,
                'message' => 'Product is not listed on this channel',
            ], 404);
        }

        // Get latest variant data
        $defaultVariant = $product->variants()->first();

        // Update listing with latest product data
        $listing->update([
            'platform_price' => $defaultVariant?->price ?? $listing->platform_price,
            'platform_quantity' => $defaultVariant?->quantity ?? $listing->platform_quantity,
            'platform_data' => array_merge($listing->platform_data ?? [], [
                'title' => $product->title,
                'description' => $product->description,
            ]),
            'last_synced_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Listing synced with latest product data',
            'listing' => [
                'id' => $listing->id,
                'status' => $listing->status,
                'platform_price' => $listing->platform_price,
                'platform_quantity' => $listing->platform_quantity,
                'last_synced_at' => $listing->last_synced_at->toIso8601String(),
            ],
        ]);
    }

    /**
     * Sync all channel listings for a product.
     */
    public function syncAll(Product $product): JsonResponse
    {
        $this->authorize('update', $product);

        $listings = PlatformListing::where('product_id', $product->id)
            ->whereIn('status', [
                PlatformListing::STATUS_LISTED,
                PlatformListing::STATUS_PENDING,
            ])
            ->get();

        if ($listings->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'No active listings to sync',
            ], 404);
        }

        $defaultVariant = $product->variants()->first();
        $syncedCount = 0;

        foreach ($listings as $listing) {
            $listing->update([
                'platform_price' => $defaultVariant?->price ?? $listing->platform_price,
                'platform_quantity' => $defaultVariant?->quantity ?? $listing->platform_quantity,
                'platform_data' => array_merge($listing->platform_data ?? [], [
                    'title' => $product->title,
                    'description' => $product->description,
                ]),
                'last_synced_at' => now(),
            ]);
            $syncedCount++;
        }

        return response()->json([
            'success' => true,
            'message' => "Synced {$syncedCount} listing(s) with latest product data",
            'synced_count' => $syncedCount,
        ]);
    }

    /**
     * Authorize that the channel belongs to the current store.
     */
    protected function authorizeChannel(SalesChannel $channel): void
    {
        $store = $this->storeContext->getCurrentStore();

        if ($channel->store_id !== $store->id) {
            abort(403, 'Unauthorized');
        }
    }
}
