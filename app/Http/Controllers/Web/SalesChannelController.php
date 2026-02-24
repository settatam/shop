<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\PlatformListing;
use App\Models\SalesChannel;
use App\Models\StoreMarketplace;
use App\Models\Warehouse;
use App\Services\StoreContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SalesChannelController extends Controller
{
    public function __construct(
        protected StoreContext $storeContext,
    ) {}

    public function index(): Response
    {
        $storeId = $this->storeContext->getCurrentStoreId();

        $channels = SalesChannel::where('store_id', $storeId)
            ->with(['warehouse', 'storeMarketplace'])
            ->ordered()
            ->get()
            ->map(fn (SalesChannel $channel) => [
                'id' => $channel->id,
                'name' => $channel->name,
                'code' => $channel->code,
                'type' => $channel->type,
                'type_label' => $channel->type_label,
                'is_local' => $channel->is_local,
                'is_active' => $channel->is_active,
                'is_default' => $channel->is_default,
                'color' => $channel->color,
                'sort_order' => $channel->sort_order,
                'active_listing_count' => $channel->getActiveListingCount(),
                'warehouse' => $channel->warehouse ? [
                    'id' => $channel->warehouse->id,
                    'name' => $channel->warehouse->name,
                ] : null,
                'store_marketplace' => $channel->storeMarketplace ? [
                    'id' => $channel->storeMarketplace->id,
                    'platform' => $channel->storeMarketplace->platform?->value,
                    'status' => $channel->storeMarketplace->status,
                    'connected_successfully' => $channel->storeMarketplace->connected_successfully,
                ] : null,
            ]);

        $warehouses = Warehouse::where('store_id', $storeId)
            ->orderBy('name')
            ->get(['id', 'name']);

        $marketplaces = StoreMarketplace::where('store_id', $storeId)
            ->where('is_app', false)
            ->get()
            ->map(fn (StoreMarketplace $mp) => [
                'id' => $mp->id,
                'name' => $mp->name,
                'platform' => $mp->platform?->value,
                'platform_label' => $mp->platform?->label(),
                'connected_successfully' => $mp->connected_successfully,
                'status' => $mp->status,
            ]);

        return Inertia::render('settings/SalesChannels', [
            'channels' => $channels,
            'warehouses' => $warehouses,
            'marketplaces' => $marketplaces,
            'channelTypes' => collect(SalesChannel::TYPES)->map(fn ($type) => [
                'value' => $type,
                'label' => SalesChannel::getTypeLabel($type),
                'is_local' => $type === SalesChannel::TYPE_LOCAL,
            ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $storeId = $this->storeContext->getCurrentStoreId();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'in:'.implode(',', SalesChannel::TYPES)],
            'warehouse_id' => ['nullable', 'exists:warehouses,id'],
            'store_marketplace_id' => ['nullable', 'exists:store_marketplaces,id'],
            'color' => ['nullable', 'string', 'max:20'],
            'is_default' => ['nullable', 'boolean'],
        ]);

        // Verify warehouse belongs to store
        if ($validated['warehouse_id'] ?? null) {
            $warehouse = Warehouse::where('id', $validated['warehouse_id'])
                ->where('store_id', $storeId)
                ->firstOrFail();
        }

        // Verify marketplace belongs to store
        if ($validated['store_marketplace_id'] ?? null) {
            $marketplace = StoreMarketplace::where('id', $validated['store_marketplace_id'])
                ->where('store_id', $storeId)
                ->firstOrFail();
        }

        SalesChannel::create([
            'store_id' => $storeId,
            'name' => $validated['name'],
            'type' => $validated['type'],
            'is_local' => $validated['type'] === SalesChannel::TYPE_LOCAL,
            'warehouse_id' => $validated['warehouse_id'] ?? null,
            'store_marketplace_id' => $validated['store_marketplace_id'] ?? null,
            'color' => $validated['color'] ?? null,
            'is_active' => true,
            'is_default' => $validated['is_default'] ?? false,
        ]);

        return back()->with('success', 'Sales channel created successfully.');
    }

    public function update(Request $request, SalesChannel $salesChannel): RedirectResponse
    {
        $storeId = $this->storeContext->getCurrentStoreId();

        if ($salesChannel->store_id !== $storeId) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'warehouse_id' => ['nullable', 'exists:warehouses,id'],
            'store_marketplace_id' => ['nullable', 'exists:store_marketplaces,id'],
            'color' => ['nullable', 'string', 'max:20'],
            'is_active' => ['nullable', 'boolean'],
            'is_default' => ['nullable', 'boolean'],
        ]);

        // Verify warehouse belongs to store
        if ($validated['warehouse_id'] ?? null) {
            Warehouse::where('id', $validated['warehouse_id'])
                ->where('store_id', $storeId)
                ->firstOrFail();
        }

        // Verify marketplace belongs to store
        if ($validated['store_marketplace_id'] ?? null) {
            StoreMarketplace::where('id', $validated['store_marketplace_id'])
                ->where('store_id', $storeId)
                ->firstOrFail();
        }

        $salesChannel->update([
            'name' => $validated['name'],
            'warehouse_id' => $validated['warehouse_id'] ?? $salesChannel->warehouse_id,
            'store_marketplace_id' => $validated['store_marketplace_id'] ?? $salesChannel->store_marketplace_id,
            'color' => $validated['color'] ?? $salesChannel->color,
            'is_active' => $validated['is_active'] ?? $salesChannel->is_active,
            'is_default' => $validated['is_default'] ?? $salesChannel->is_default,
        ]);

        return back()->with('success', 'Sales channel updated successfully.');
    }

    public function destroy(SalesChannel $salesChannel): RedirectResponse
    {
        $storeId = $this->storeContext->getCurrentStoreId();

        if ($salesChannel->store_id !== $storeId) {
            abort(403);
        }

        // Check if channel has orders
        if ($salesChannel->orders()->exists()) {
            return back()->with('error', 'Cannot delete a channel that has orders. Deactivate it instead.');
        }

        // Soft delete all platform listings for this channel
        $deletedListingsCount = PlatformListing::where('sales_channel_id', $salesChannel->id)->delete();

        $salesChannel->delete();

        $message = 'Sales channel deleted successfully.';
        if ($deletedListingsCount > 0) {
            $message .= " {$deletedListingsCount} listing(s) were also removed.";
        }

        return back()->with('success', $message);
    }

    public function reorder(Request $request): RedirectResponse
    {
        $storeId = $this->storeContext->getCurrentStoreId();

        $validated = $request->validate([
            'channels' => ['required', 'array'],
            'channels.*.id' => ['required', 'exists:sales_channels,id'],
            'channels.*.sort_order' => ['required', 'integer', 'min:0'],
        ]);

        foreach ($validated['channels'] as $channelData) {
            SalesChannel::where('id', $channelData['id'])
                ->where('store_id', $storeId)
                ->update(['sort_order' => $channelData['sort_order']]);
        }

        return back()->with('success', 'Channel order updated.');
    }

    /**
     * Get deactivation preflight info (listing count that will be affected).
     */
    public function deactivatePreflight(SalesChannel $salesChannel): JsonResponse
    {
        $storeId = $this->storeContext->getCurrentStoreId();

        if ($salesChannel->store_id !== $storeId) {
            abort(403);
        }

        $activeListingCount = $salesChannel->getActiveListingCount();

        return response()->json([
            'channel_id' => $salesChannel->id,
            'channel_name' => $salesChannel->name,
            'active_listing_count' => $activeListingCount,
            'is_external' => $salesChannel->isExternal(),
            'warning' => $activeListingCount > 0
                ? "This will end {$activeListingCount} active listing(s) on this channel. For external platforms, items will be delisted which may take some time."
                : null,
        ]);
    }
}
