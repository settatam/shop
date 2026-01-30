<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateOrderFromWizardRequest;
use App\Models\Bucket;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\TransactionItem;
use App\Models\Warehouse;
use App\Services\ActivityLogFormatter;
use App\Services\Orders\OrderCreationService;
use App\Services\StoreContext;
use App\Services\TaxService;
use App\Services\TradeIn\TradeInService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class OrderController extends Controller
{
    public function __construct(
        protected StoreContext $storeContext,
        protected OrderCreationService $orderCreationService,
        protected TaxService $taxService,
        protected TradeInService $tradeInService,
    ) {}

    public function index(Request $request): Response|RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return redirect()->route('dashboard')
                ->with('error', 'Please select a store first.');
        }

        // Get distinct marketplaces from orders
        $marketplaces = Order::where('store_id', $store->id)
            ->whereNotNull('source_platform')
            ->distinct()
            ->pluck('source_platform')
            ->map(fn ($platform) => [
                'value' => $platform,
                'label' => $this->formatMarketplaceLabel($platform),
            ])
            ->values()
            ->toArray();

        // Get vendors that have products sold in orders
        $vendors = \App\Models\Vendor::where('store_id', $store->id)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($vendor) => [
                'value' => $vendor->id,
                'label' => $vendor->name,
            ])
            ->toArray();

        return Inertia::render('orders/Index', [
            'statuses' => $this->getStatuses(),
            'marketplaces' => $marketplaces,
            'paymentMethods' => $this->getPaymentMethods(),
            'vendors' => $vendors,
        ]);
    }

    /**
     * Format marketplace label for display.
     */
    protected function formatMarketplaceLabel(string $platform): string
    {
        $labels = [
            'pos' => 'In Store',
            'in_store' => 'In Store',
            'shopify' => 'Shopify',
            'reb' => 'REB',
            'memo' => 'Memo',
            'repair' => 'Repair',
            'website' => 'Website',
            'online' => 'Online',
        ];

        return $labels[$platform] ?? ucfirst(str_replace('_', ' ', $platform));
    }

    public function show(Order $order): Response|RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store || $order->store_id !== $store->id) {
            abort(404);
        }

        $order->load([
            'customer',
            'user',
            'warehouse',
            'items.product.images',
            'items.variant',
            'invoice',
            'payments.user',
            'returns',
            'tradeInTransaction.items',
            'notes.user',
        ]);

        return Inertia::render('orders/Show', [
            'order' => $this->formatOrder($order),
            'statuses' => $this->getStatuses(),
            'paymentMethods' => $this->getPaymentMethods(),
            'activityLogs' => Inertia::defer(fn () => app(ActivityLogFormatter::class)->formatForSubject($order)),
        ]);
    }

    public function createWizard(): Response|RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return redirect()->route('dashboard')
                ->with('error', 'Please select a store first.');
        }

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

        return Inertia::render('orders/CreateWizard', [
            'storeUsers' => $storeUsers,
            'currentStoreUserId' => $currentStoreUserId,
            'categories' => $categories,
            'warehouses' => $warehouses,
            'defaultWarehouseId' => $defaultWarehouseId,
            'defaultTaxRate' => $store->default_tax_rate ?? 0,
            'preciousMetals' => $this->getPreciousMetals(),
            'itemConditions' => $this->getItemConditions(),
        ]);
    }

    public function storeFromWizard(CreateOrderFromWizardRequest $request): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return redirect()->route('dashboard')
                ->with('error', 'Please select a store first.');
        }

        $data = $request->validated();

        $order = $this->orderCreationService->createFromWizard($data, $store);

        return redirect()->route('web.orders.show', $order)
            ->with('success', 'Order created successfully.');
    }

    public function update(Request $request, Order $order): RedirectResponse
    {
        $this->authorizeOrder($order);

        $validated = $request->validate([
            'notes' => 'nullable|string|max:5000',
            'shipping_cost' => 'nullable|numeric|min:0',
            'discount_cost' => 'nullable|numeric|min:0',
        ]);

        $order->update($validated);
        $order->calculateTotals();

        return back()->with('success', 'Order updated successfully.');
    }

    public function destroy(Order $order): RedirectResponse
    {
        $this->authorizeOrder($order);

        if (! $order->isPending() && $order->status !== Order::STATUS_DRAFT) {
            return back()->with('error', 'Only pending or draft orders can be deleted.');
        }

        // Restore stock for all items before deleting
        foreach ($order->items as $item) {
            if ($item->product_variant_id) {
                $this->restoreStock($item->product_variant_id, $item->quantity);
            }
        }

        $order->delete();

        return redirect()->route('web.orders.index')
            ->with('success', 'Order deleted successfully.');
    }

    // Status Transition Methods

    public function confirm(Order $order): RedirectResponse
    {
        $this->authorizeOrder($order);

        if (! $order->isPending() && $order->status !== Order::STATUS_PARTIAL_PAYMENT) {
            return back()->with('error', 'Order cannot be confirmed in its current state.');
        }

        $order->confirm();

        return back()->with('success', 'Order confirmed.');
    }

    public function ship(Order $order): RedirectResponse
    {
        $this->authorizeOrder($order);

        if (! $order->isConfirmed() && $order->status !== Order::STATUS_PROCESSING) {
            return back()->with('error', 'Order cannot be shipped in its current state.');
        }

        $order->markAsShipped();

        return back()->with('success', 'Order marked as shipped.');
    }

    public function deliver(Order $order): RedirectResponse
    {
        $this->authorizeOrder($order);

        if ($order->status !== Order::STATUS_SHIPPED) {
            return back()->with('error', 'Order cannot be marked as delivered in its current state.');
        }

        $order->markAsDelivered();

        return back()->with('success', 'Order marked as delivered.');
    }

    public function complete(Order $order): RedirectResponse
    {
        $this->authorizeOrder($order);

        if (! in_array($order->status, [Order::STATUS_DELIVERED, Order::STATUS_CONFIRMED, Order::STATUS_SHIPPED])) {
            return back()->with('error', 'Order cannot be completed in its current state.');
        }

        $order->markAsCompleted();

        return back()->with('success', 'Order completed.');
    }

    public function receivePayment(Request $request, Order $order): RedirectResponse
    {
        $this->authorizeOrder($order);

        if ($order->isFullyPaid()) {
            return back()->with('error', 'Order is already fully paid.');
        }

        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'payment_method' => 'required|string|in:cash,credit_card,debit_card,check,wire,ach,store_credit',
            'reference' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:1000',
        ]);

        $this->orderCreationService->addPaymentToOrder($order, [
            'amount' => $validated['amount'],
            'payment_method' => $validated['payment_method'],
            'reference' => $validated['reference'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'user_id' => auth()->id(),
        ]);

        return back()->with('success', 'Payment received.');
    }

    public function cancel(Order $order): RedirectResponse
    {
        $this->authorizeOrder($order);

        if ($order->isCancelled()) {
            return back()->with('error', 'Order is already cancelled.');
        }

        if ($order->status === Order::STATUS_COMPLETED) {
            return back()->with('error', 'Completed orders cannot be cancelled.');
        }

        $this->orderCreationService->cancelOrder($order);

        return back()->with('success', 'Order cancelled. Stock has been restored.');
    }

    public function updateItem(Request $request, Order $order, OrderItem $item): RedirectResponse
    {
        $this->authorizeOrder($order);

        if ($item->order_id !== $order->id) {
            return back()->with('error', 'Item does not belong to this order.');
        }

        if (! $order->isPending() && $order->status !== Order::STATUS_DRAFT) {
            return back()->with('error', 'Items can only be modified on pending orders.');
        }

        $validated = $request->validate([
            'quantity' => 'required|integer|min:1',
            'price' => 'required|numeric|min:0',
            'discount' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:1000',
        ]);

        $item->update($validated);
        $order->calculateTotals();

        return back()->with('success', 'Item updated.');
    }

    public function removeItem(Order $order, OrderItem $item): RedirectResponse
    {
        $this->authorizeOrder($order);

        if ($item->order_id !== $order->id) {
            return back()->with('error', 'Item does not belong to this order.');
        }

        if (! $order->isPending() && $order->status !== Order::STATUS_DRAFT) {
            return back()->with('error', 'Items can only be removed from pending orders.');
        }

        // Restore stock if item has a variant
        if ($item->product_variant_id) {
            $this->restoreStock($item->product_variant_id, $item->quantity);
        }

        $item->delete();
        $order->calculateTotals();

        return back()->with('success', 'Item removed from order.');
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
            'ids.*' => 'integer|exists:orders,id',
        ]);

        $orders = Order::where('store_id', $store->id)
            ->whereIn('id', $validated['ids'])
            ->get();

        $count = $orders->count();

        match ($validated['action']) {
            'delete' => $orders->each(function ($order) {
                if ($order->isPending() || $order->status === Order::STATUS_DRAFT) {
                    foreach ($order->items as $item) {
                        if ($item->product_variant_id) {
                            $this->restoreStock($item->product_variant_id, $item->quantity);
                        }
                    }
                    $order->delete();
                }
            }),
            'cancel' => $orders->each(function ($order) {
                if (! $order->isCancelled() && $order->status !== Order::STATUS_COMPLETED) {
                    $this->orderCreationService->cancelOrder($order);
                }
            }),
        };

        $actionLabel = match ($validated['action']) {
            'delete' => 'deleted',
            'cancel' => 'cancelled',
        };

        return redirect()->route('web.orders.index')
            ->with('success', "{$count} order(s) {$actionLabel} successfully.");
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
            ->when($query, function ($q) use ($query) {
                $q->where(function ($sub) use ($query) {
                    $sub->where('title', 'like', "%{$query}%")
                        ->orWhere('description', 'like', "%{$query}%")
                        ->orWhereHas('variants', function ($variantQuery) use ($query) {
                            $variantQuery->where('sku', 'like', "%{$query}%")
                                ->orWhere('barcode', 'like', "%{$query}%");
                        });
                });
            })
            ->when($categoryId, fn ($q) => $q->where('category_id', $categoryId))
            ->with(['category', 'images', 'variants'])
            ->limit(20)
            ->get()
            ->map(function ($product) {
                $variant = $product->variants->first();
                $totalQuantity = $product->total_quantity;

                return [
                    'id' => $product->id,
                    'variant_id' => $variant?->id,
                    'title' => $product->title,
                    'sku' => $variant?->sku,
                    'description' => $product->description,
                    'price' => $variant?->price ?? 0,
                    'cost' => $variant?->cost ?? 0,
                    'quantity' => $totalQuantity,
                    'category' => $product->category?->name,
                    'image' => $product->images->first()?->url,
                ];
            });

        return response()->json(['products' => $products]);
    }

    /**
     * Lookup a product by exact barcode or SKU match.
     * Used for barcode scanner functionality.
     */
    public function lookupBarcode(Request $request): JsonResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return response()->json(['found' => false, 'product' => null], 200);
        }

        $barcode = $request->get('barcode', '');

        if (empty($barcode)) {
            return response()->json(['found' => false, 'product' => null, 'error' => 'No barcode provided'], 400);
        }

        // First try exact match on barcode
        $variant = ProductVariant::whereHas('product', function ($q) use ($store) {
            $q->where('store_id', $store->id);
        })
            ->where(function ($q) use ($barcode) {
                $q->where('barcode', $barcode)
                    ->orWhere('sku', $barcode);
            })
            ->with(['product.category', 'product.images'])
            ->first();

        if (! $variant) {
            return response()->json([
                'found' => false,
                'product' => null,
                'message' => 'Product not found',
            ]);
        }

        $product = $variant->product;
        $totalQuantity = $product->total_quantity;

        return response()->json([
            'found' => true,
            'product' => [
                'id' => $product->id,
                'variant_id' => $variant->id,
                'title' => $product->title,
                'sku' => $variant->sku,
                'barcode' => $variant->barcode,
                'description' => $product->description,
                'price' => $variant->price ?? 0,
                'cost' => $variant->cost ?? 0,
                'quantity' => $totalQuantity,
                'category' => $product->category?->name,
                'image' => $product->images->first()?->url,
            ],
        ]);
    }

    // Search Bucket Items API for Wizard

    public function searchBucketItems(Request $request): JsonResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return response()->json(['buckets' => []], 200);
        }

        // Get all buckets with their active (unsold) items
        $buckets = Bucket::where('store_id', $store->id)
            ->with(['activeItems' => fn ($q) => $q->orderBy('created_at', 'desc')])
            ->get()
            ->filter(fn ($bucket) => $bucket->activeItems->isNotEmpty())
            ->map(fn ($bucket) => [
                'id' => $bucket->id,
                'name' => $bucket->name,
                'total_value' => $bucket->total_value,
                'items' => $bucket->activeItems->map(fn ($item) => [
                    'id' => $item->id,
                    'title' => $item->title,
                    'description' => $item->description,
                    'value' => $item->value,
                    'created_at' => $item->created_at->toISOString(),
                ]),
            ])
            ->values();

        return response()->json(['buckets' => $buckets]);
    }

    // Search Customers API for Wizard

    public function searchCustomers(Request $request): JsonResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return response()->json(['customers' => []], 200);
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
                'variant_id' => $variant->id,
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

    protected function restoreStock(int $variantId, int $quantity): void
    {
        $inventory = \App\Models\Inventory::where('product_variant_id', $variantId)->first();

        if ($inventory) {
            $inventory->increment('quantity', $quantity);
        }
    }

    protected function authorizeOrder(Order $order): void
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store || $order->store_id !== $store->id) {
            abort(404);
        }
    }

    /**
     * Format order for frontend.
     *
     * @return array<string, mixed>
     */
    protected function formatOrder(Order $order): array
    {
        return [
            'id' => $order->id,
            'invoice_number' => $order->invoice_number,
            'status' => $order->status,
            'sub_total' => $order->sub_total,
            'sales_tax' => $order->sales_tax,
            'tax_rate' => $order->tax_rate,
            'shipping_cost' => $order->shipping_cost,
            'discount_cost' => $order->discount_cost,
            'trade_in_credit' => $order->trade_in_credit ?? 0,
            'total' => $order->total,
            'total_paid' => $order->total_paid,
            'balance_due' => $order->balance_due,
            'notes' => $order->notes,
            'billing_address' => $order->billing_address,
            'shipping_address' => $order->shipping_address,
            'date_of_purchase' => $order->date_of_purchase?->toISOString(),
            'source_platform' => $order->source_platform,
            'external_marketplace_id' => $order->external_marketplace_id,
            'created_at' => $order->created_at->toISOString(),
            'updated_at' => $order->updated_at->toISOString(),

            // Status helpers
            'is_draft' => $order->status === Order::STATUS_DRAFT,
            'is_pending' => $order->isPending(),
            'is_confirmed' => $order->isConfirmed(),
            'is_paid' => $order->isPaid(),
            'is_cancelled' => $order->isCancelled(),
            'is_fully_paid' => $order->isFullyPaid(),
            'is_from_external_platform' => $order->isFromExternalPlatform(),

            // Action helpers
            'can_be_confirmed' => $order->isPending() || $order->status === Order::STATUS_PARTIAL_PAYMENT,
            'can_be_shipped' => $order->isConfirmed() || $order->status === Order::STATUS_PROCESSING,
            'can_be_delivered' => $order->status === Order::STATUS_SHIPPED,
            'can_be_completed' => in_array($order->status, [Order::STATUS_DELIVERED, Order::STATUS_CONFIRMED, Order::STATUS_SHIPPED]),
            'can_be_cancelled' => ! $order->isCancelled() && $order->status !== Order::STATUS_COMPLETED,
            'can_receive_payment' => ! $order->isFullyPaid(),
            'can_be_deleted' => $order->isPending() || $order->status === Order::STATUS_DRAFT,
            'has_trade_in' => $order->hasTradeIn(),

            // Relationships
            'customer' => $order->customer ? [
                'id' => $order->customer->id,
                'first_name' => $order->customer->first_name,
                'last_name' => $order->customer->last_name,
                'full_name' => $order->customer->full_name,
                'email' => $order->customer->email,
                'phone' => $order->customer->phone_number,
            ] : null,
            'user' => $order->user ? [
                'id' => $order->user->id,
                'name' => $order->user->name,
            ] : null,
            'warehouse' => $order->warehouse ? [
                'id' => $order->warehouse->id,
                'name' => $order->warehouse->name,
            ] : null,
            'items' => $order->items->map(fn ($item) => [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'product_variant_id' => $item->product_variant_id,
                'sku' => $item->sku,
                'title' => $item->title,
                'quantity' => $item->quantity,
                'price' => $item->price,
                'cost' => $item->cost,
                'discount' => $item->discount,
                'tax' => $item->tax,
                'line_total' => $item->line_total,
                'line_profit' => $item->line_profit,
                'notes' => $item->notes,
                'product' => $item->product ? [
                    'id' => $item->product->id,
                    'title' => $item->product->title,
                    'image' => $item->product->images->first()?->url ?? null,
                ] : null,
            ]),
            'item_count' => $order->item_count,
            'invoice' => $order->invoice ? [
                'id' => $order->invoice->id,
                'invoice_number' => $order->invoice->invoice_number,
                'status' => $order->invoice->status,
                'total' => $order->invoice->total,
                'balance_due' => $order->invoice->balance_due,
            ] : null,
            'payments' => $order->payments->map(fn ($payment) => [
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
            'trade_in_transaction' => $order->tradeInTransaction ? [
                'id' => $order->tradeInTransaction->id,
                'transaction_number' => $order->tradeInTransaction->transaction_number,
                'final_offer' => $order->tradeInTransaction->final_offer,
                'status' => $order->tradeInTransaction->status,
                'items' => $order->tradeInTransaction->items->map(fn ($item) => [
                    'id' => $item->id,
                    'title' => $item->title,
                    'description' => $item->description,
                    'buy_price' => $item->buy_price,
                    'precious_metal' => $item->precious_metal,
                    'condition' => $item->condition,
                    'dwt' => $item->dwt,
                ]),
            ] : null,
            'note_entries' => ($order->getRelation('notes') ?? collect())->map(fn ($note) => [
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
            ['value' => Order::STATUS_DRAFT, 'label' => 'Draft'],
            ['value' => Order::STATUS_PENDING, 'label' => 'Pending'],
            ['value' => Order::STATUS_CONFIRMED, 'label' => 'Confirmed'],
            ['value' => Order::STATUS_PROCESSING, 'label' => 'Processing'],
            ['value' => Order::STATUS_SHIPPED, 'label' => 'Shipped'],
            ['value' => Order::STATUS_DELIVERED, 'label' => 'Delivered'],
            ['value' => Order::STATUS_COMPLETED, 'label' => 'Completed'],
            ['value' => Order::STATUS_CANCELLED, 'label' => 'Cancelled'],
            ['value' => Order::STATUS_REFUNDED, 'label' => 'Refunded'],
            ['value' => Order::STATUS_PARTIAL_PAYMENT, 'label' => 'Partial Payment'],
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
            ['value' => 'credit_card', 'label' => 'Credit Card'],
            ['value' => 'debit_card', 'label' => 'Debit Card'],
            ['value' => 'check', 'label' => 'Check'],
            ['value' => 'wire', 'label' => 'Wire Transfer'],
            ['value' => 'ach', 'label' => 'ACH Transfer'],
            ['value' => 'store_credit', 'label' => 'Store Credit'],
        ];
    }

    /**
     * Get available precious metals for trade-in items.
     *
     * @return array<array<string, string>>
     */
    protected function getPreciousMetals(): array
    {
        return [
            ['value' => TransactionItem::METAL_GOLD_10K, 'label' => 'Gold 10K'],
            ['value' => TransactionItem::METAL_GOLD_14K, 'label' => 'Gold 14K'],
            ['value' => TransactionItem::METAL_GOLD_18K, 'label' => 'Gold 18K'],
            ['value' => TransactionItem::METAL_GOLD_22K, 'label' => 'Gold 22K'],
            ['value' => TransactionItem::METAL_GOLD_24K, 'label' => 'Gold 24K'],
            ['value' => TransactionItem::METAL_SILVER, 'label' => 'Silver'],
            ['value' => TransactionItem::METAL_PLATINUM, 'label' => 'Platinum'],
            ['value' => TransactionItem::METAL_PALLADIUM, 'label' => 'Palladium'],
        ];
    }

    /**
     * Get available conditions for trade-in items.
     *
     * @return array<array<string, string>>
     */
    protected function getItemConditions(): array
    {
        return [
            ['value' => TransactionItem::CONDITION_NEW, 'label' => 'New'],
            ['value' => TransactionItem::CONDITION_LIKE_NEW, 'label' => 'Like New'],
            ['value' => TransactionItem::CONDITION_USED, 'label' => 'Used'],
            ['value' => TransactionItem::CONDITION_DAMAGED, 'label' => 'Damaged'],
        ];
    }
}
