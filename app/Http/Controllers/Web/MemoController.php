<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateMemoFromWizardRequest;
use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Memo;
use App\Models\MemoItem;
use App\Models\Product;
use App\Models\Vendor;
use App\Models\Warehouse;
use App\Services\ActivityLogFormatter;
use App\Services\Memos\MemoService;
use App\Services\StoreContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class MemoController extends Controller
{
    public function __construct(
        protected StoreContext $storeContext,
        protected MemoService $memoService,
    ) {}

    public function index(Request $request): Response|RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return redirect()->route('dashboard')
                ->with('error', 'Please select a store first.');
        }

        $vendors = Vendor::where('store_id', $store->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(fn ($vendor) => [
                'value' => $vendor->id,
                'label' => $vendor->display_name,
            ]);

        return Inertia::render('memos/Index', [
            'statuses' => $this->getStatuses(),
            'paymentTerms' => $this->getPaymentTerms(),
            'vendors' => $vendors,
        ]);
    }

    public function show(Memo $memo): Response|RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store || $memo->store_id !== $store->id) {
            abort(404);
        }

        $memo->load([
            'vendor',
            'user',
            'items.product.images',
            'items.category',
            'order',
            'invoice',
            'payments.user',
            'notes.user',
        ]);

        // Get categories for add item modal
        $categories = Category::where('store_id', $store->id)
            ->orderBy('name')
            ->get()
            ->map(fn ($category) => [
                'value' => $category->id,
                'label' => $category->name,
            ]);

        return Inertia::render('memos/Show', [
            'memo' => $this->formatMemo($memo),
            'statuses' => $this->getStatuses(),
            'paymentTerms' => $this->getPaymentTerms(),
            'paymentMethods' => $this->getPaymentMethods(),
            'categories' => $categories,
            'activityLogs' => Inertia::defer(fn () => app(ActivityLogFormatter::class)->formatForSubject($memo)),
        ]);
    }

    public function createWizard(): Response|RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return redirect()->route('dashboard')
                ->with('error', 'Please select a store first.');
        }

        // Get store users for the employee dropdown (only assignable users with memo permission)
        $storeUsers = $store->storeUsers()
            ->with(['user', 'role'])
            ->whereNotNull('user_id')
            ->where('can_be_assigned', true)
            ->get()
            ->filter(fn ($storeUser) => $storeUser->is_owner || $storeUser->hasPermission('memos.create'))
            ->map(fn ($storeUser) => [
                'id' => $storeUser->id,
                'name' => $storeUser->user?->name ?? $storeUser->name ?? 'Unknown',
            ])
            ->sortBy('name')
            ->values();

        // Get the current user's store user ID
        $currentStoreUserId = auth()->user()?->currentStoreUser()?->id;

        // Get categories for filtering products
        $categories = Category::where('store_id', $store->id)
            ->orderBy('name')
            ->get()
            ->map(fn ($category) => [
                'value' => $category->id,
                'label' => $category->name,
            ]);

        // Get warehouses for the warehouse dropdown
        $warehouses = Warehouse::where('store_id', $store->id)
            ->orderBy('name')
            ->get()
            ->map(fn ($warehouse) => [
                'value' => $warehouse->id,
                'label' => $warehouse->name,
                'tax_rate' => $warehouse->tax_rate,
            ]);

        // Get the current user's default warehouse ID
        $currentStoreUser = auth()->user()?->currentStoreUser();
        $defaultWarehouseId = $currentStoreUser?->default_warehouse_id;

        return Inertia::render('memos/CreateWizard', [
            'storeUsers' => $storeUsers,
            'currentStoreUserId' => $currentStoreUserId,
            'categories' => $categories,
            'paymentTerms' => $this->getPaymentTerms(),
            'warehouses' => $warehouses,
            'defaultWarehouseId' => $defaultWarehouseId,
            'defaultTaxRate' => $store->default_tax_rate ?? 0,
        ]);
    }

    public function storeFromWizard(CreateMemoFromWizardRequest $request): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return redirect()->route('dashboard')
                ->with('error', 'Please select a store first.');
        }

        $data = $request->validated();
        $data['store_id'] = $store->id;

        $memo = $this->memoService->createFromWizard($data);

        return redirect()->route('web.memos.show', $memo)
            ->with('success', 'Memo created successfully.');
    }

    public function update(Request $request, Memo $memo): RedirectResponse
    {
        $this->authorizeMemo($memo);

        $validated = $request->validate([
            'description' => 'nullable|string|max:5000',
            'tenure' => 'nullable|integer|in:7,14,30,60',
            'charge_taxes' => 'nullable|boolean',
            'tax_rate' => 'nullable|numeric|min:0|max:1',
        ]);

        $memo->update($validated);

        if (isset($validated['charge_taxes']) || isset($validated['tax_rate'])) {
            $memo->calculateTotals();
        }

        return back()->with('success', 'Memo updated successfully.');
    }

    public function destroy(Memo $memo): RedirectResponse
    {
        $this->authorizeMemo($memo);

        if (! $memo->isPending()) {
            return back()->with('error', 'Only pending memos can be deleted.');
        }

        // Return all items to stock before deleting
        $memo->items()->where('is_returned', false)->each(function (MemoItem $item) {
            $item->returnToStock();
        });

        $memo->delete();

        return redirect()->route('web.memos.index')
            ->with('success', 'Memo deleted successfully.');
    }

    // Status Transition Methods

    public function sendToVendor(Memo $memo): RedirectResponse
    {
        $this->authorizeMemo($memo);

        if (! $memo->canBeSentToVendor()) {
            return back()->with('error', 'Memo cannot be sent to vendor in its current state.');
        }

        $this->memoService->sendToVendor($memo);

        return back()->with('success', 'Memo sent to vendor.');
    }

    public function markReceived(Memo $memo): RedirectResponse
    {
        $this->authorizeMemo($memo);

        if (! $memo->canBeMarkedAsReceived()) {
            return back()->with('error', 'Memo cannot be marked as received in its current state.');
        }

        $this->memoService->markVendorReceived($memo);

        return back()->with('success', 'Memo marked as received by vendor.');
    }

    public function markReturned(Memo $memo): RedirectResponse
    {
        $this->authorizeMemo($memo);

        if (! $memo->canBeMarkedAsReturned()) {
            return back()->with('error', 'Memo cannot be marked as returned in its current state.');
        }

        $this->memoService->markVendorReturned($memo);

        return back()->with('success', 'Memo marked as returned by vendor. All items returned to stock.');
    }

    public function receivePayment(Request $request, Memo $memo): RedirectResponse
    {
        $this->authorizeMemo($memo);

        if (! $memo->canReceivePayment()) {
            return back()->with('error', 'Memo is not ready to receive payment.');
        }

        $validated = $request->validate([
            'payment_method' => 'required|string|in:cash,check,wire,ach,store_credit',
            'payment_details' => 'nullable|array',
        ]);

        // Create a sale order and mark payment received
        $this->memoService->createSaleOrder($memo, $validated);

        return back()->with('success', 'Payment received. Invoice created.');
    }

    public function returnItem(Memo $memo, MemoItem $item): RedirectResponse
    {
        $this->authorizeMemo($memo);

        if ($item->memo_id !== $memo->id) {
            return back()->with('error', 'Item does not belong to this memo.');
        }

        if (! $item->canBeReturned()) {
            return back()->with('error', 'Item has already been returned.');
        }

        $this->memoService->returnItem($item);

        return back()->with('success', 'Item returned to stock.');
    }

    public function addItem(Request $request, Memo $memo): RedirectResponse
    {
        $this->authorizeMemo($memo);

        if (! $memo->isPending()) {
            return back()->with('error', 'Items can only be added to pending memos.');
        }

        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'price' => 'nullable|numeric|min:0',
            'cost' => 'nullable|numeric|min:0',
            'tenor' => 'nullable|integer|min:1',
        ]);

        $product = Product::with(['variants', 'category', 'images'])->findOrFail($validated['product_id']);
        $variant = $product->variants->first();

        // Check if product is available
        if ($product->quantity <= 0) {
            return back()->with('error', 'Product is out of stock.');
        }

        // Create the memo item
        // Cost priority: provided cost > wholesale_price > cost
        $effectiveCost = $validated['cost'] ?? $variant?->effective_cost ?? 0;

        $memo->items()->create([
            'product_id' => $product->id,
            'category_id' => $product->category_id,
            'sku' => $variant?->sku,
            'title' => $product->title,
            'description' => $product->description,
            'price' => $validated['price'] ?? $variant?->price ?? 0,
            'cost' => $effectiveCost,
            'tenor' => $validated['tenor'] ?? $memo->tenure,
            'is_returned' => false,
        ]);

        // Mark product as on memo (out of stock)
        MemoItem::markProductOnMemo($product);

        // Recalculate totals
        $memo->calculateTotals();

        return back()->with('success', 'Item added to memo.');
    }

    public function updateItem(Request $request, Memo $memo, MemoItem $item): RedirectResponse
    {
        $this->authorizeMemo($memo);

        if ($item->memo_id !== $memo->id) {
            return back()->with('error', 'Item does not belong to this memo.');
        }

        // Only allow updates until payment is received
        if ($memo->isPaymentReceived() || $memo->isArchived()) {
            return back()->with('error', 'Cannot update items after payment has been received.');
        }

        $validated = $request->validate([
            'cost' => 'nullable|numeric|min:0',
            'price' => 'nullable|numeric|min:0',
            'tenor' => 'nullable|integer|min:1',
        ]);

        $changes = [];

        // Track cost change
        if (isset($validated['cost']) && (float) $validated['cost'] !== (float) $item->cost) {
            $changes[] = "Cost changed from \${$item->cost} to \${$validated['cost']}";
        }

        // Track price change
        if (isset($validated['price']) && (float) $validated['price'] !== (float) $item->price) {
            $changes[] = "Price changed from \${$item->price} to \${$validated['price']}";
        }

        // Track tenor change
        if (isset($validated['tenor']) && (int) $validated['tenor'] !== (int) $item->tenor) {
            $changes[] = "Terms changed from {$item->tenor} days to {$validated['tenor']} days";
        }

        if (empty($changes)) {
            return back();
        }

        // Update the item
        $item->update([
            'cost' => $validated['cost'] ?? $item->cost,
            'price' => $validated['price'] ?? $item->price,
            'tenor' => $validated['tenor'] ?? $item->tenor,
        ]);

        // Log activity
        ActivityLog::log(
            'memos.update',
            $memo,
            auth()->user(),
            [
                'item_id' => $item->id,
                'item_title' => $item->title,
                'changes' => $changes,
            ],
            'Memo item updated: '.implode(', ', $changes)
        );

        // Recalculate totals
        $memo->calculateTotals();

        return back()->with('success', 'Item updated.');
    }

    public function changeStatus(Request $request, Memo $memo): RedirectResponse
    {
        $this->authorizeMemo($memo);

        $validated = $request->validate([
            'status' => 'required|string|in:'.implode(',', Memo::STATUSES),
        ]);

        $oldStatus = $memo->status;
        $newStatus = $validated['status'];

        if ($oldStatus === $newStatus) {
            return back();
        }

        // Handle special cases for status transitions
        $updates = ['status' => $newStatus];

        // Set date fields based on status
        if ($newStatus === Memo::STATUS_SENT_TO_VENDOR && ! $memo->date_sent_to_vendor) {
            $updates['date_sent_to_vendor'] = now();
        }

        if ($newStatus === Memo::STATUS_VENDOR_RECEIVED && ! $memo->date_vendor_received) {
            $updates['date_vendor_received'] = now();
        }

        // Clear date fields if moving backward
        if ($newStatus === Memo::STATUS_PENDING) {
            $updates['date_sent_to_vendor'] = null;
            $updates['date_vendor_received'] = null;
        }

        if ($newStatus === Memo::STATUS_SENT_TO_VENDOR) {
            $updates['date_vendor_received'] = null;
        }

        $memo->update($updates);

        // Log the status change
        ActivityLog::log(
            'memos.update',
            $memo,
            auth()->user(),
            [
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
            ],
            "Status changed from {$oldStatus} to {$newStatus}"
        );

        $statusLabels = [
            Memo::STATUS_PENDING => 'Pending',
            Memo::STATUS_SENT_TO_VENDOR => 'Sent to Vendor',
            Memo::STATUS_VENDOR_RECEIVED => 'Vendor Received',
            Memo::STATUS_VENDOR_RETURNED => 'Vendor Returned',
            Memo::STATUS_PAYMENT_RECEIVED => 'Payment Received',
            Memo::STATUS_ARCHIVED => 'Archived',
            Memo::STATUS_CANCELLED => 'Cancelled',
        ];

        return back()->with('success', "Status changed to {$statusLabels[$newStatus]}.");
    }

    public function cancel(Memo $memo): RedirectResponse
    {
        $this->authorizeMemo($memo);

        if (! $memo->canBeCancelled()) {
            return back()->with('error', 'Memo cannot be cancelled in its current state.');
        }

        $this->memoService->cancel($memo);

        return back()->with('success', 'Memo cancelled. All items returned to stock.');
    }

    public function bulkAction(Request $request): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return redirect()->route('dashboard')
                ->with('error', 'Please select a store first.');
        }

        $validated = $request->validate([
            'action' => 'required|string|in:delete,cancel',
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|exists:memos,id',
        ]);

        $memos = Memo::where('store_id', $store->id)
            ->whereIn('id', $validated['ids'])
            ->get();

        $count = $memos->count();

        match ($validated['action']) {
            'delete' => $memos->each(function ($memo) {
                if ($memo->isPending()) {
                    $memo->items()->where('is_returned', false)->each(function (MemoItem $item) {
                        $item->returnToStock();
                    });
                    $memo->delete();
                }
            }),
            'cancel' => $memos->each(function ($memo) {
                if ($memo->canBeCancelled()) {
                    $memo->cancel();
                }
            }),
        };

        $actionLabel = match ($validated['action']) {
            'delete' => 'deleted',
            'cancel' => 'cancelled',
        };

        return redirect()->route('web.memos.index')
            ->with('success', "{$count} memo(s) {$actionLabel} successfully.");
    }

    // Search Products API for Wizard

    public function searchProducts(Request $request): JsonResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return response()->json(['products' => []], 200);
        }

        $query = $request->get('query', '');
        $categoryId = $request->get('category_id');

        $products = Product::where('store_id', $store->id)
            ->where('quantity', '>', 0)
            ->when($query, function ($q) use ($query) {
                $q->where(function ($sub) use ($query) {
                    $sub->where('title', 'like', "%{$query}%")
                        ->orWhere('description', 'like', "%{$query}%")
                        ->orWhereHas('variants', function ($variantQuery) use ($query) {
                            $variantQuery->where('sku', 'like', "%{$query}%");
                        });
                });
            })
            ->when($categoryId, fn ($q) => $q->where('category_id', $categoryId))
            ->with(['category', 'images', 'variants'])
            ->limit(20)
            ->get()
            ->map(function ($product) {
                $variant = $product->variants->first();

                return [
                    'id' => $product->id,
                    'title' => $product->title,
                    'sku' => $variant?->sku,
                    'description' => $product->description,
                    'price' => $variant?->price ?? 0,
                    'cost' => $variant?->cost ?? 0,
                    'quantity' => $product->quantity,
                    'category' => $product->category?->name,
                    'image' => $product->images->first()?->url,
                ];
            });

        return response()->json(['products' => $products]);
    }

    // Search Vendors API for Wizard

    public function searchVendors(Request $request)
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return response()->json(['vendors' => []], 200);
        }

        $query = $request->get('query', '');

        $vendors = Vendor::where('store_id', $store->id)
            ->where('is_active', true)
            ->when($query, function ($q) use ($query) {
                $q->where(function ($sub) use ($query) {
                    $sub->where('name', 'like', "%{$query}%")
                        ->orWhere('company_name', 'like', "%{$query}%")
                        ->orWhere('email', 'like', "%{$query}%")
                        ->orWhere('code', 'like', "%{$query}%");
                });
            })
            ->limit(20)
            ->get()
            ->map(fn ($vendor) => [
                'id' => $vendor->id,
                'name' => $vendor->name,
                'company_name' => $vendor->company_name,
                'display_name' => $vendor->display_name,
                'email' => $vendor->email,
                'phone' => $vendor->phone,
            ]);

        return response()->json(['vendors' => $vendors]);
    }

    public function storeQuickProduct(Request $request): JsonResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return response()->json(['error' => 'No store selected'], 400);
        }

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'sku' => 'nullable|string|max:255',
            'price' => 'required|numeric|min:0',
            'cost' => 'nullable|numeric|min:0',
            'category_id' => 'nullable|exists:categories,id',
        ]);

        $product = Product::create([
            'store_id' => $store->id,
            'title' => $validated['title'],
            'handle' => $this->generateUniqueHandle($store->id, $validated['title']),
            'category_id' => $validated['category_id'] ?? null,
            'quantity' => 1,
            'is_published' => false,
            'is_draft' => true,
            'has_variants' => false,
            'track_quantity' => true,
        ]);

        $variant = $product->variants()->create([
            'sku' => ! empty($validated['sku']) ? $validated['sku'] : $this->generateSku(),
            'price' => $validated['price'],
            'cost' => $validated['cost'] ?? null,
            'quantity' => 1,
        ]);

        return response()->json([
            'product' => [
                'id' => $product->id,
                'title' => $product->title,
                'sku' => $variant->sku,
                'price' => $variant->price,
                'cost' => $variant->cost,
                'quantity' => $product->quantity,
                'category' => $product->category?->name,
                'image' => null,
            ],
        ], 201);
    }

    protected function generateSku(): string
    {
        return 'SKU-'.strtoupper(Str::random(8));
    }

    protected function generateUniqueHandle(int $storeId, string $title): string
    {
        $baseHandle = Str::slug($title);
        $handle = $baseHandle;
        $counter = 1;

        while (Product::where('store_id', $storeId)->where('handle', $handle)->exists()) {
            $handle = $baseHandle.'-'.$counter;
            $counter++;
        }

        return $handle;
    }

    protected function authorizeMemo(Memo $memo): void
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store || $memo->store_id !== $store->id) {
            abort(404);
        }
    }

    /**
     * Format memo for frontend.
     *
     * @return array<string, mixed>
     */
    protected function formatMemo(Memo $memo): array
    {
        return [
            'id' => $memo->id,
            'memo_number' => $memo->memo_number,
            'status' => $memo->status,
            'tenure' => $memo->tenure,
            'subtotal' => $memo->subtotal,
            'tax' => $memo->tax,
            'tax_rate' => $memo->tax_rate,
            'charge_taxes' => $memo->charge_taxes,
            'shipping_cost' => $memo->shipping_cost,
            'total' => $memo->total,

            // Payment adjustments
            'discount_value' => $memo->discount_value ?? 0,
            'discount_unit' => $memo->discount_unit ?? 'fixed',
            'discount_reason' => $memo->discount_reason,
            'discount_amount' => $memo->discount_amount ?? 0,
            'service_fee_value' => $memo->service_fee_value ?? 0,
            'service_fee_unit' => $memo->service_fee_unit ?? 'fixed',
            'service_fee_reason' => $memo->service_fee_reason,
            'service_fee_amount' => $memo->service_fee_amount ?? 0,
            'tax_type' => $memo->tax_type ?? 'percent',
            'tax_amount' => $memo->tax_amount ?? 0,
            'grand_total' => $memo->grand_total ?? $memo->total,
            'total_paid' => $memo->total_paid ?? 0,
            'balance_due' => $memo->balance_due ?? $memo->total,

            'description' => $memo->description,
            'duration' => $memo->duration,
            'days_with_vendor' => $memo->days_with_vendor,
            'due_date' => $memo->due_date?->toISOString(),
            'is_overdue' => $memo->isOverdue(),
            'date_sent_to_vendor' => $memo->date_sent_to_vendor?->toISOString(),
            'date_vendor_received' => $memo->date_vendor_received?->toISOString(),
            'created_at' => $memo->created_at->toISOString(),
            'updated_at' => $memo->updated_at->toISOString(),

            // Status helpers
            'is_pending' => $memo->isPending(),
            'is_sent_to_vendor' => $memo->isSentToVendor(),
            'is_vendor_received' => $memo->isVendorReceived(),
            'is_vendor_returned' => $memo->isVendorReturned(),
            'is_payment_received' => $memo->isPaymentReceived(),
            'is_archived' => $memo->isArchived(),
            'is_cancelled' => $memo->isCancelled(),

            // Action helpers
            'can_be_sent_to_vendor' => $memo->canBeSentToVendor(),
            'can_be_marked_as_received' => $memo->canBeMarkedAsReceived(),
            'can_be_marked_as_returned' => $memo->canBeMarkedAsReturned(),
            'can_receive_payment' => $memo->canReceivePayment(),
            'can_be_cancelled' => $memo->canBeCancelled(),

            // Relationships
            'vendor' => $memo->vendor ? [
                'id' => $memo->vendor->id,
                'name' => $memo->vendor->name,
                'company_name' => $memo->vendor->company_name,
                'display_name' => $memo->vendor->display_name,
                'email' => $memo->vendor->email,
                'phone' => $memo->vendor->phone,
            ] : null,
            'user' => $memo->user ? [
                'id' => $memo->user->id,
                'name' => $memo->user->name,
            ] : null,
            'items' => $memo->items->map(fn ($item) => [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'sku' => $item->sku,
                'title' => $item->title,
                'description' => $item->description,
                'price' => $item->price,
                'cost' => $item->cost,
                'tenor' => $item->tenor,
                'due_date' => $item->due_date?->toISOString(),
                'effective_due_date' => $item->effective_due_date?->toISOString(),
                'is_returned' => $item->is_returned,
                'can_be_returned' => $item->canBeReturned(),
                'quantity' => $item->quantity,
                'profit' => $item->profit,
                'product' => $item->product ? [
                    'id' => $item->product->id,
                    'title' => $item->product->title,
                    'sku' => $item->product->sku,
                    'image' => $item->product->images->first()?->url ?? null,
                ] : null,
            ]),
            'active_items_count' => $memo->active_items->count(),
            'returned_items_count' => $memo->returned_items->count(),
            'order' => $memo->order ? [
                'id' => $memo->order->id,
                'order_number' => $memo->order->order_number,
            ] : null,
            'invoice' => $memo->invoice ? [
                'id' => $memo->invoice->id,
                'invoice_number' => $memo->invoice->invoice_number,
                'status' => $memo->invoice->status,
                'total' => $memo->invoice->total,
                'balance_due' => $memo->invoice->balance_due,
            ] : null,
            'payments' => $memo->payments->map(fn ($payment) => [
                'id' => $payment->id,
                'amount' => $payment->amount,
                'payment_method' => $payment->payment_method,
                'status' => $payment->status,
                'reference' => $payment->reference,
                'notes' => $payment->notes,
                'paid_at' => $payment->paid_at?->toISOString(),
                'user' => $payment->user ? [
                    'id' => $payment->user->id,
                    'name' => $payment->user->name,
                ] : null,
            ]),
            'note_entries' => $memo->notes->map(fn ($note) => [
                'id' => $note->id,
                'content' => $note->content,
                'user' => $note->user ? [
                    'id' => $note->user->id,
                    'name' => $note->user->name,
                ] : null,
                'created_at' => $note->created_at->toISOString(),
                'updated_at' => $note->updated_at->toISOString(),
            ]),
        ];
    }

    /**
     * Get available statuses.
     *
     * @return array<array<string, string>>
     */
    protected function getStatuses(): array
    {
        return [
            ['value' => Memo::STATUS_PENDING, 'label' => 'Pending'],
            ['value' => Memo::STATUS_SENT_TO_VENDOR, 'label' => 'Sent to Vendor'],
            ['value' => Memo::STATUS_VENDOR_RECEIVED, 'label' => 'Vendor Received'],
            ['value' => Memo::STATUS_VENDOR_RETURNED, 'label' => 'Vendor Returned'],
            ['value' => Memo::STATUS_PAYMENT_RECEIVED, 'label' => 'Payment Received'],
            ['value' => Memo::STATUS_ARCHIVED, 'label' => 'Archived'],
            ['value' => Memo::STATUS_CANCELLED, 'label' => 'Cancelled'],
        ];
    }

    /**
     * Get available payment terms.
     *
     * @return array<array<string, mixed>>
     */
    protected function getPaymentTerms(): array
    {
        return [
            ['value' => Memo::TENURE_7_DAYS, 'label' => '7 Days'],
            ['value' => Memo::TENURE_14_DAYS, 'label' => '14 Days'],
            ['value' => Memo::TENURE_30_DAYS, 'label' => '30 Days'],
            ['value' => Memo::TENURE_60_DAYS, 'label' => '60 Days'],
        ];
    }

    /**
     * Get available payment methods.
     *
     * @return array<array<string, string>>
     */
    protected function getPaymentMethods(): array
    {
        return [
            ['value' => 'cash', 'label' => 'Cash'],
            ['value' => 'check', 'label' => 'Check'],
            ['value' => 'wire', 'label' => 'Wire Transfer'],
            ['value' => 'ach', 'label' => 'ACH Transfer'],
            ['value' => 'store_credit', 'label' => 'Store Credit'],
        ];
    }
}
