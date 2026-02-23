<?php

namespace App\Http\Controllers\Web;

use App\Facades\Channel;
use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\ActivityLog;
use App\Models\PlatformListing;
use App\Services\StoreContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ListingController extends Controller
{
    public function __construct(
        protected StoreContext $storeContext,
    ) {}

    /**
     * Show the listing detail page.
     */
    public function show(PlatformListing $listing): Response
    {
        $this->authorizeListing($listing);

        $listing->load([
            'product.images',
            'product.legacyImages',
            'product.variants',
            'product.category',
            'product.brand',
            'salesChannel',
        ]);

        $product = $listing->product;
        $channel = $listing->salesChannel;

        // Get recent activities for this listing
        $activities = ActivityLog::forSubject($listing)
            ->with('user')
            ->orderBy('created_at', 'desc')
            ->limit(20)
            ->get()
            ->map(fn (ActivityLog $log) => [
                'id' => $log->id,
                'activity_slug' => $log->activity_slug,
                'activity' => $log->activity,
                'description' => $log->description,
                'properties' => $log->properties,
                'user' => $log->user ? [
                    'id' => $log->user->id,
                    'name' => $log->user->name,
                ] : null,
                'created_at' => $log->created_at->toIso8601String(),
            ]);

        // Get status options for dropdown
        $statusOptions = collect(PlatformListing::getStatusOptions())
            ->map(fn ($label, $value) => [
                'value' => $value,
                'label' => $label,
                'disabled' => ! $listing->canTransitionTo($value),
            ])
            ->values();

        return Inertia::render('listings/Show', [
            'listing' => [
                'id' => $listing->id,
                'status' => $listing->normalized_status,
                'status_label' => $listing->status_label,
                'external_listing_id' => $listing->external_listing_id,
                'listing_url' => $listing->listing_url,
                'platform_price' => $listing->platform_price,
                'platform_quantity' => $listing->platform_quantity,
                'platform_data' => $listing->platform_data,
                'published_at' => $listing->published_at?->toIso8601String(),
                'last_synced_at' => $listing->last_synced_at?->toIso8601String(),
                'last_error' => $listing->last_error,
                'created_at' => $listing->created_at->toIso8601String(),
                'updated_at' => $listing->updated_at->toIso8601String(),
            ],
            'product' => [
                'id' => $product->id,
                'title' => $product->title,
                'description' => $product->description,
                'handle' => $product->handle,
                'status' => $product->status,
                'category' => $product->category?->name,
                'brand' => $product->brand?->name,
                'default_price' => $product->variants()->first()?->price,
                'default_quantity' => $product->total_quantity,
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
            'statusOptions' => $statusOptions,
            'activities' => $activities,
        ]);
    }

    /**
     * Update the listing status.
     */
    public function updateStatus(Request $request, PlatformListing $listing): JsonResponse
    {
        $this->authorizeListing($listing);

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:'.implode(',', PlatformListing::VALID_STATUSES)],
        ]);

        $newStatus = $validated['status'];
        $oldStatus = $listing->normalized_status;

        // Validate transition
        if (! $listing->canTransitionTo($newStatus)) {
            return response()->json([
                'success' => false,
                'message' => "Cannot transition from '{$oldStatus}' to '{$newStatus}'",
            ], 422);
        }

        // No change
        if ($oldStatus === $newStatus) {
            return response()->json([
                'success' => true,
                'message' => 'Status unchanged',
                'listing' => $this->formatListingResponse($listing),
            ]);
        }

        // Platform actions based on status change
        $result = null;
        $channel = $listing->salesChannel;

        try {
            // Only trigger platform actions for external channels
            if ($channel && ! $channel->is_local) {
                if ($newStatus === PlatformListing::STATUS_LISTED) {
                    // Publish to platform
                    $result = Channel::listing($listing)->publish();
                    if (! $result->success) {
                        return response()->json([
                            'success' => false,
                            'message' => $result->message ?? 'Failed to publish to platform',
                        ], 500);
                    }
                } elseif ($newStatus === PlatformListing::STATUS_ENDED) {
                    // Only end if currently listed
                    if ($oldStatus === PlatformListing::STATUS_LISTED) {
                        $result = Channel::listing($listing)->end();
                        if (! $result->success) {
                            return response()->json([
                                'success' => false,
                                'message' => $result->message ?? 'Failed to end listing on platform',
                            ], 500);
                        }
                    }
                }
            }

            // For local channels or non-platform-action statuses, just update directly
            if ($result === null) {
                $listing->update([
                    'status' => $newStatus,
                    'published_at' => $newStatus === PlatformListing::STATUS_LISTED ? now() : $listing->published_at,
                    'last_synced_at' => now(),
                ]);
            }

            // Log the status change
            ActivityLog::log(
                Activity::LISTINGS_STATUS_CHANGE,
                $listing,
                null,
                [
                    'old_status' => $oldStatus,
                    'new_status' => $newStatus,
                    'channel' => $channel?->name,
                ],
                "Status changed from {$oldStatus} to {$newStatus}"
            );

            return response()->json([
                'success' => true,
                'message' => "Listing status changed to {$newStatus}",
                'listing' => $this->formatListingResponse($listing->fresh()),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get activities for a listing.
     */
    public function activities(Request $request, PlatformListing $listing): JsonResponse
    {
        $this->authorizeListing($listing);

        $limit = min($request->integer('limit', 20), 100);
        $offset = $request->integer('offset', 0);

        $query = ActivityLog::forSubject($listing)
            ->with('user')
            ->orderBy('created_at', 'desc');

        $total = $query->count();

        $activities = $query
            ->skip($offset)
            ->take($limit)
            ->get()
            ->map(fn (ActivityLog $log) => [
                'id' => $log->id,
                'activity_slug' => $log->activity_slug,
                'activity' => $log->activity,
                'description' => $log->description,
                'properties' => $log->properties,
                'user' => $log->user ? [
                    'id' => $log->user->id,
                    'name' => $log->user->name,
                ] : null,
                'created_at' => $log->created_at->toIso8601String(),
            ]);

        return response()->json([
            'activities' => $activities,
            'total' => $total,
            'has_more' => ($offset + $limit) < $total,
        ]);
    }

    /**
     * Format listing for JSON response.
     *
     * @return array<string, mixed>
     */
    protected function formatListingResponse(PlatformListing $listing): array
    {
        return [
            'id' => $listing->id,
            'status' => $listing->normalized_status,
            'status_label' => $listing->status_label,
            'external_listing_id' => $listing->external_listing_id,
            'listing_url' => $listing->listing_url,
            'platform_price' => $listing->platform_price,
            'platform_quantity' => $listing->platform_quantity,
            'published_at' => $listing->published_at?->toIso8601String(),
            'last_synced_at' => $listing->last_synced_at?->toIso8601String(),
            'last_error' => $listing->last_error,
        ];
    }

    /**
     * Authorize that the listing belongs to the current store.
     */
    protected function authorizeListing(PlatformListing $listing): void
    {
        $store = $this->storeContext->getCurrentStore();
        $product = $listing->product;

        if (! $product || $product->store_id !== $store->id) {
            abort(403, 'Unauthorized');
        }
    }
}
