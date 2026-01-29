<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBucketItemRequest;
use App\Http\Requests\StoreBucketRequest;
use App\Http\Requests\UpdateBucketRequest;
use App\Models\Activity;
use App\Models\ActivityLog;
use App\Models\Bucket;
use App\Models\BucketItem;
use App\Models\Customer;
use App\Services\ActivityLogFormatter;
use App\Services\BucketService;
use App\Services\Orders\OrderCreationService;
use App\Services\StoreContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BucketsController extends Controller
{
    public function __construct(
        protected StoreContext $storeContext,
        protected BucketService $bucketService,
        protected OrderCreationService $orderCreationService,
        protected ActivityLogFormatter $activityLogFormatter,
    ) {}

    public function index(): Response|RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return redirect()->route('dashboard')
                ->with('error', 'Please select a store first.');
        }

        $buckets = Bucket::where('store_id', $store->id)
            ->withCount(['items', 'activeItems', 'soldItems'])
            ->orderBy('name')
            ->get()
            ->map(fn ($bucket) => [
                'id' => $bucket->id,
                'name' => $bucket->name,
                'description' => $bucket->description,
                'total_value' => (float) $bucket->total_value,
                'items_count' => $bucket->items_count,
                'active_items_count' => $bucket->active_items_count,
                'sold_items_count' => $bucket->sold_items_count,
                'created_at' => $bucket->created_at->toISOString(),
            ]);

        return Inertia::render('buckets/Index', [
            'buckets' => $buckets,
        ]);
    }

    public function store(StoreBucketRequest $request): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return redirect()->route('dashboard')
                ->with('error', 'Please select a store first.');
        }

        $bucket = Bucket::create([
            'store_id' => $store->id,
            'name' => $request->validated('name'),
            'description' => $request->validated('description'),
            'total_value' => 0,
        ]);

        ActivityLog::log(
            Activity::BUCKETS_CREATE,
            $bucket,
            null,
            ['name' => $bucket->name],
            "Created bucket \"{$bucket->name}\""
        );

        return redirect()->route('buckets.show', $bucket)
            ->with('success', 'Bucket created successfully.');
    }

    public function show(Bucket $bucket): Response|RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store || $bucket->store_id !== $store->id) {
            abort(404);
        }

        $bucket->load(['items' => fn ($query) => $query->orderByDesc('created_at')]);

        // Get store users for the employee dropdown
        $storeUsers = $store->storeUsers()
            ->with(['user', 'role'])
            ->get()
            ->filter(fn ($storeUser) => $storeUser->is_owner || $storeUser->hasPermission('orders.create'))
            ->map(fn ($storeUser) => [
                'id' => $storeUser->id,
                'name' => $storeUser->user?->name ?? $storeUser->name ?? 'Unknown',
            ])
            ->values();

        // Get the current user's store user ID
        $currentStoreUserId = auth()->user()?->currentStoreUser()?->id;

        // Get activity logs for this bucket
        $activityLogs = $this->activityLogFormatter->formatForSubject($bucket);

        return Inertia::render('buckets/Show', [
            'bucket' => [
                'id' => $bucket->id,
                'name' => $bucket->name,
                'description' => $bucket->description,
                'total_value' => (float) $bucket->total_value,
                'items' => $bucket->items->map(fn ($item) => [
                    'id' => $item->id,
                    'title' => $item->title,
                    'description' => $item->description,
                    'value' => (float) $item->value,
                    'sold_at' => $item->sold_at?->toISOString(),
                    'is_sold' => $item->isSold(),
                    'transaction_item_id' => $item->transaction_item_id,
                    'created_at' => $item->created_at->toISOString(),
                ]),
                'created_at' => $bucket->created_at->toISOString(),
            ],
            'storeUsers' => $storeUsers,
            'currentStoreUserId' => $currentStoreUserId,
            'activityLogs' => $activityLogs,
        ]);
    }

    public function update(UpdateBucketRequest $request, Bucket $bucket): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store || $bucket->store_id !== $store->id) {
            abort(404);
        }

        $oldValues = [
            'name' => $bucket->name,
            'description' => $bucket->description,
        ];

        $bucket->update([
            'name' => $request->validated('name'),
            'description' => $request->validated('description'),
        ]);

        ActivityLog::logWithChanges(
            Activity::BUCKETS_UPDATE,
            $bucket,
            $oldValues,
            [
                'name' => $bucket->name,
                'description' => $bucket->description,
            ],
            'Updated bucket details'
        );

        return redirect()->route('buckets.show', $bucket)
            ->with('success', 'Bucket updated successfully.');
    }

    public function destroy(Bucket $bucket): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store || $bucket->store_id !== $store->id) {
            abort(404);
        }

        if ($bucket->hasActiveItems()) {
            return redirect()->back()
                ->with('error', 'Cannot delete bucket with active items. Remove all items first.');
        }

        $bucketName = $bucket->name;

        ActivityLog::log(
            Activity::BUCKETS_DELETE,
            $bucket,
            null,
            ['name' => $bucketName],
            "Deleted bucket \"{$bucketName}\""
        );

        $bucket->delete();

        return redirect()->route('buckets.index')
            ->with('success', 'Bucket deleted successfully.');
    }

    public function addItem(StoreBucketItemRequest $request, Bucket $bucket): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store || $bucket->store_id !== $store->id) {
            abort(404);
        }

        $bucketItem = $this->bucketService->addItem($bucket, $request->validated());

        ActivityLog::log(
            Activity::BUCKETS_ITEM_ADDED,
            $bucket,
            null,
            [
                'item_title' => $bucketItem->title,
                'item_value' => $bucketItem->value,
            ],
            'Added item "'.$bucketItem->title.'" ($'.$bucketItem->value.')'
        );

        return redirect()->route('buckets.show', $bucket)
            ->with('success', 'Item added to bucket.');
    }

    public function removeItem(BucketItem $bucketItem): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store || $bucketItem->bucket->store_id !== $store->id) {
            abort(404);
        }

        if ($bucketItem->isSold()) {
            return redirect()->back()
                ->with('error', 'Cannot remove a sold item.');
        }

        $bucket = $bucketItem->bucket;
        $itemTitle = $bucketItem->title;
        $itemValue = $bucketItem->value;

        $this->bucketService->removeItem($bucketItem);

        ActivityLog::log(
            Activity::BUCKETS_ITEM_REMOVED,
            $bucket,
            null,
            [
                'item_title' => $itemTitle,
                'item_value' => $itemValue,
            ],
            'Removed item "'.$itemTitle.'" ($'.$itemValue.')'
        );

        return redirect()->route('buckets.show', $bucket)
            ->with('success', 'Item removed from bucket.');
    }

    /**
     * Search for buckets (used in dropdowns/selects).
     */
    public function search(): \Illuminate\Http\JsonResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return response()->json(['buckets' => []]);
        }

        $buckets = Bucket::where('store_id', $store->id)
            ->orderBy('name')
            ->get()
            ->map(fn ($bucket) => [
                'id' => $bucket->id,
                'name' => $bucket->name,
                'total_value' => (float) $bucket->total_value,
            ]);

        return response()->json(['buckets' => $buckets]);
    }

    /**
     * Create a sale (order) from bucket items.
     */
    public function createSale(Request $request, Bucket $bucket): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store || $bucket->store_id !== $store->id) {
            abort(404);
        }

        $validated = $request->validate([
            'store_user_id' => ['required', 'integer', 'exists:store_users,id'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'item_ids' => ['required', 'array', 'min:1'],
            'item_ids.*' => ['integer', 'exists:bucket_items,id'],
            'prices' => ['required', 'array'],
            'prices.*' => ['numeric', 'min:0'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:1'],
        ]);

        // Verify all items belong to this bucket and are not sold
        $bucketItems = BucketItem::whereIn('id', $validated['item_ids'])
            ->where('bucket_id', $bucket->id)
            ->whereNull('sold_at')
            ->get();

        if ($bucketItems->count() !== count($validated['item_ids'])) {
            return redirect()->back()
                ->with('error', 'Some items are no longer available for sale.');
        }

        // Build bucket_items array for order creation
        $orderBucketItems = [];
        foreach ($validated['item_ids'] as $itemId) {
            $orderBucketItems[] = [
                'id' => $itemId,
                'price' => $validated['prices'][$itemId] ?? $bucketItems->firstWhere('id', $itemId)?->value,
            ];
        }

        // Create the order using the existing service
        $order = $this->orderCreationService->createFromWizard([
            'store_user_id' => $validated['store_user_id'],
            'customer_id' => $validated['customer_id'] ?? null,
            'items' => [], // No regular products
            'bucket_items' => $orderBucketItems,
            'tax_rate' => $validated['tax_rate'] ?? 0,
        ], $store);

        // Log activity for each sold item
        $totalSaleAmount = collect($orderBucketItems)->sum('price');
        $itemTitles = $bucketItems->pluck('title')->implode(', ');

        ActivityLog::log(
            Activity::BUCKETS_ITEM_SOLD,
            $bucket,
            null,
            [
                'order_id' => $order->id,
                'items_count' => count($orderBucketItems),
                'item_titles' => $itemTitles,
                'total_amount' => $totalSaleAmount,
            ],
            'Sold '.count($orderBucketItems)." item(s) for \${$totalSaleAmount}"
        );

        return redirect()->route('web.orders.show', $order)
            ->with('success', 'Sale created successfully from bucket items.');
    }

    /**
     * Search customers (for bucket sale modal).
     */
    public function searchCustomers(Request $request): \Illuminate\Http\JsonResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return response()->json(['customers' => []]);
        }

        $query = $request->get('query', '');

        $customers = Customer::where('store_id', $store->id)
            ->when($query, function ($q) use ($query) {
                $q->where(function ($sub) use ($query) {
                    $sub->where('first_name', 'like', "%{$query}%")
                        ->orWhere('last_name', 'like', "%{$query}%")
                        ->orWhere('email', 'like', "%{$query}%")
                        ->orWhere('phone_number', 'like', "%{$query}%");
                });
            })
            ->limit(20)
            ->get()
            ->map(fn ($customer) => [
                'id' => $customer->id,
                'first_name' => $customer->first_name,
                'last_name' => $customer->last_name,
                'full_name' => $customer->full_name,
                'email' => $customer->email,
                'phone' => $customer->phone_number,
            ]);

        return response()->json(['customers' => $customers]);
    }
}
