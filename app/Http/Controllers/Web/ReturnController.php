<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\ProductReturn;
use App\Models\ReturnItem;
use App\Models\ReturnPolicy;
use App\Services\ActivityLogFormatter;
use App\Services\StoreContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ReturnController extends Controller
{
    public function __construct(
        protected StoreContext $storeContext,
    ) {}

    /**
     * Display returns list page.
     */
    public function index(Request $request): Response|RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return redirect()->route('dashboard')
                ->with('error', 'Please select a store first.');
        }

        return Inertia::render('returns/Index', [
            'statuses' => $this->getStatuses(),
            'types' => $this->getTypes(),
        ]);
    }

    /**
     * Display return detail page.
     */
    public function show(ProductReturn $return): Response|RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store || $return->store_id !== $store->id) {
            abort(404);
        }

        $return->load([
            'order.customer.leadSource',
            'customer.leadSource',
            'returnPolicy',
            'processedByUser',
            'items.productVariant.product.images',
            'items.orderItem',
        ]);

        return Inertia::render('returns/Show', [
            'productReturn' => $this->formatReturn($return),
            'statuses' => $this->getStatuses(),
            'refundMethods' => $this->getRefundMethods(),
            'activityLogs' => Inertia::defer(fn () => app(ActivityLogFormatter::class)->formatForSubject($return)),
        ]);
    }

    /**
     * Display create return page.
     */
    public function create(Request $request): Response|RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return redirect()->route('dashboard')
                ->with('error', 'Please select a store first.');
        }

        // Get active return policies
        $policies = ReturnPolicy::where('store_id', $store->id)
            ->active()
            ->orderBy('is_default', 'desc')
            ->orderBy('name')
            ->get()
            ->map(fn ($policy) => [
                'id' => $policy->id,
                'name' => $policy->name,
                'description' => $policy->description,
                'return_window_days' => $policy->return_window_days,
                'restocking_fee_percent' => $policy->restocking_fee_percent,
                'allow_refund' => $policy->allow_refund,
                'allow_store_credit' => $policy->allow_store_credit,
                'allow_exchange' => $policy->allow_exchange,
                'is_default' => $policy->is_default,
            ]);

        // Pre-select order if provided
        $selectedOrder = null;
        if ($orderId = $request->get('order_id')) {
            $order = Order::where('store_id', $store->id)
                ->with(['customer', 'items.product.images', 'items.variant'])
                ->find($orderId);

            if ($order) {
                $selectedOrder = $this->formatOrderForReturn($order);
            }
        }

        return Inertia::render('returns/Create', [
            'policies' => $policies,
            'selectedOrder' => $selectedOrder,
            'types' => $this->getTypes(),
            'conditions' => $this->getConditions(),
            'reasons' => $this->getReasons(),
        ]);
    }

    /**
     * Search orders for return creation.
     */
    public function searchOrders(Request $request): JsonResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return response()->json(['orders' => []], 200);
        }

        $query = $request->get('query', '');

        $orders = Order::where('store_id', $store->id)
            ->whereIn('status', [
                Order::STATUS_COMPLETED,
                Order::STATUS_DELIVERED,
                Order::STATUS_CONFIRMED,
            ])
            ->when($query, function ($q) use ($query) {
                $q->where(function ($sub) use ($query) {
                    $sub->where('invoice_number', 'like', "%{$query}%")
                        ->orWhereHas('customer', function ($cq) use ($query) {
                            $cq->where('first_name', 'like', "%{$query}%")
                                ->orWhere('last_name', 'like', "%{$query}%")
                                ->orWhere('email', 'like', "%{$query}%");
                        });
                });
            })
            ->with(['customer', 'items.product.images', 'items.variant'])
            ->orderBy('created_at', 'desc')
            ->limit(20)
            ->get()
            ->map(fn ($order) => $this->formatOrderForReturn($order));

        return response()->json(['orders' => $orders]);
    }

    /**
     * Store a new return.
     */
    public function store(Request $request): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return redirect()->route('dashboard')
                ->with('error', 'Please select a store first.');
        }

        $validated = $request->validate([
            'order_id' => 'required|exists:orders,id',
            'return_policy_id' => 'nullable|exists:return_policies,id',
            'type' => 'required|in:return,exchange',
            'reason' => 'nullable|string|max:1000',
            'customer_notes' => 'nullable|string|max:1000',
            'internal_notes' => 'nullable|string|max:1000',
            'items' => 'required|array|min:1',
            'items.*.order_item_id' => 'required|exists:order_items,id',
            'items.*.product_variant_id' => 'nullable|exists:product_variants,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.condition' => 'nullable|string|max:50',
            'items.*.reason' => 'nullable|string|max:255',
            'items.*.notes' => 'nullable|string|max:500',
            'items.*.restock' => 'boolean',
        ]);

        // Verify order belongs to store
        $order = Order::where('store_id', $store->id)->findOrFail($validated['order_id']);

        // Create return
        $return = ProductReturn::create([
            'store_id' => $store->id,
            'order_id' => $order->id,
            'customer_id' => $order->customer_id,
            'return_policy_id' => $validated['return_policy_id'] ?? null,
            'type' => $validated['type'],
            'reason' => $validated['reason'] ?? null,
            'customer_notes' => $validated['customer_notes'] ?? null,
            'internal_notes' => $validated['internal_notes'] ?? null,
            'status' => ProductReturn::STATUS_PENDING,
        ]);

        // Create return items
        foreach ($validated['items'] as $itemData) {
            ReturnItem::create([
                'return_id' => $return->id,
                'order_item_id' => $itemData['order_item_id'],
                'product_variant_id' => $itemData['product_variant_id'] ?? null,
                'quantity' => $itemData['quantity'],
                'unit_price' => $itemData['unit_price'],
                'line_total' => $itemData['quantity'] * $itemData['unit_price'],
                'condition' => $itemData['condition'] ?? null,
                'reason' => $itemData['reason'] ?? null,
                'notes' => $itemData['notes'] ?? null,
                'restock' => $itemData['restock'] ?? true,
            ]);
        }

        // Calculate totals
        $return->calculateTotals();

        return redirect()->route('web.returns.show', $return)
            ->with('success', 'Return created successfully.');
    }

    /**
     * Approve a return.
     */
    public function approve(ProductReturn $return): RedirectResponse
    {
        $this->authorizeReturn($return);

        if (! $return->canBeApproved()) {
            return back()->with('error', 'This return cannot be approved in its current state.');
        }

        $return->approve(auth()->id());

        return back()->with('success', 'Return approved successfully.');
    }

    /**
     * Reject a return.
     */
    public function reject(Request $request, ProductReturn $return): RedirectResponse
    {
        $this->authorizeReturn($return);

        if (! $return->canBeRejected()) {
            return back()->with('error', 'This return cannot be rejected in its current state.');
        }

        $validated = $request->validate([
            'reason' => 'required|string|max:1000',
        ]);

        $return->reject($validated['reason'], auth()->id());

        return back()->with('success', 'Return rejected.');
    }

    /**
     * Mark return as processing.
     */
    public function process(ProductReturn $return): RedirectResponse
    {
        $this->authorizeReturn($return);

        if (! $return->canBeProcessed()) {
            return back()->with('error', 'This return cannot be processed in its current state.');
        }

        $return->markAsProcessing();

        return back()->with('success', 'Return is now being processed.');
    }

    /**
     * Mark items as received.
     */
    public function receive(ProductReturn $return): RedirectResponse
    {
        $this->authorizeReturn($return);

        if (! $return->isProcessing()) {
            return back()->with('error', 'Return must be in processing state to mark items received.');
        }

        $return->markAsReceived();

        return back()->with('success', 'Items marked as received.');
    }

    /**
     * Complete a return and process refund.
     */
    public function complete(Request $request, ProductReturn $return): RedirectResponse
    {
        $this->authorizeReturn($return);

        if (! $return->isProcessing()) {
            return back()->with('error', 'Return must be in processing state to complete.');
        }

        $validated = $request->validate([
            'refund_method' => 'required|in:original_payment,store_credit,cash,card',
        ]);

        $return->complete($validated['refund_method'], $return->refund_amount);

        return back()->with('success', 'Return completed. Refund of $'.number_format($return->refund_amount, 2).' processed.');
    }

    /**
     * Restock a return item.
     */
    public function restockItem(ProductReturn $return, ReturnItem $item): RedirectResponse
    {
        $this->authorizeReturn($return);

        if ($item->return_id !== $return->id) {
            abort(404);
        }

        if ($item->wasRestocked()) {
            return back()->with('error', 'This item has already been restocked.');
        }

        if (! $item->shouldRestock()) {
            return back()->with('error', 'This item is not marked for restocking.');
        }

        // Restock the item
        if ($item->product_variant_id) {
            $inventory = \App\Models\Inventory::where('product_variant_id', $item->product_variant_id)->first();
            if ($inventory) {
                $inventory->increment('quantity', $item->quantity);
            }
        }

        $item->markAsRestocked();

        return back()->with('success', 'Item restocked successfully.');
    }

    /**
     * Create a return shipping label.
     */
    public function createLabel(Request $request, ProductReturn $return): RedirectResponse
    {
        $this->authorizeReturn($return);

        // This would integrate with shipping carriers like FedEx, UPS, etc.
        // For now, we'll just return a message
        return back()->with('info', 'Return label creation is not yet implemented. Please create the label manually through your shipping provider.');
    }

    /**
     * Cancel a return.
     */
    public function cancel(ProductReturn $return): RedirectResponse
    {
        $this->authorizeReturn($return);

        if (! $return->canBeCancelled()) {
            return back()->with('error', 'This return cannot be cancelled in its current state.');
        }

        $return->cancel();

        return back()->with('success', 'Return cancelled.');
    }

    /**
     * Handle bulk actions.
     */
    public function bulkAction(Request $request): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return redirect()->route('dashboard')
                ->with('error', 'Please select a store first.');
        }

        $validated = $request->validate([
            'action' => 'required|string|in:approve,reject,cancel',
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|exists:returns,id',
        ]);

        $returns = ProductReturn::where('store_id', $store->id)
            ->whereIn('id', $validated['ids'])
            ->get();

        $count = 0;

        foreach ($returns as $return) {
            match ($validated['action']) {
                'approve' => $return->canBeApproved() && $return->approve(auth()->id()) && $count++,
                'reject' => $return->canBeRejected() && $return->reject('Bulk rejected', auth()->id()) && $count++,
                'cancel' => $return->canBeCancelled() && $return->cancel() && $count++,
                default => null,
            };
        }

        $actionLabel = match ($validated['action']) {
            'approve' => 'approved',
            'reject' => 'rejected',
            'cancel' => 'cancelled',
        };

        return redirect()->route('web.returns.index')
            ->with('success', "{$count} return(s) {$actionLabel} successfully.");
    }

    /**
     * Authorize access to a return.
     */
    protected function authorizeReturn(ProductReturn $return): void
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store || $return->store_id !== $store->id) {
            abort(404);
        }
    }

    /**
     * Format a return for the frontend.
     *
     * @return array<string, mixed>
     */
    protected function formatReturn(ProductReturn $return): array
    {
        return [
            'id' => $return->id,
            'return_number' => $return->return_number,
            'status' => $return->status,
            'type' => $return->type,
            'subtotal' => $return->subtotal,
            'restocking_fee' => $return->restocking_fee,
            'refund_amount' => $return->refund_amount,
            'refund_method' => $return->refund_method,
            'reason' => $return->reason,
            'customer_notes' => $return->customer_notes,
            'internal_notes' => $return->internal_notes,
            'requested_at' => $return->requested_at?->toISOString(),
            'approved_at' => $return->approved_at?->toISOString(),
            'received_at' => $return->received_at?->toISOString(),
            'completed_at' => $return->completed_at?->toISOString(),
            'created_at' => $return->created_at->toISOString(),
            'updated_at' => $return->updated_at->toISOString(),

            // Status helpers
            'is_pending' => $return->isPending(),
            'is_approved' => $return->isApproved(),
            'is_processing' => $return->isProcessing(),
            'is_completed' => $return->isCompleted(),
            'is_rejected' => $return->isRejected(),
            'is_cancelled' => $return->isCancelled(),

            // Action helpers
            'can_be_approved' => $return->canBeApproved(),
            'can_be_processed' => $return->canBeProcessed(),
            'can_be_rejected' => $return->canBeRejected(),
            'can_be_cancelled' => $return->canBeCancelled(),

            // Relationships
            'order' => $return->order ? [
                'id' => $return->order->id,
                'invoice_number' => $return->order->invoice_number,
                'status' => $return->order->status,
                'total' => $return->order->total,
                'created_at' => $return->order->created_at->toISOString(),
            ] : null,
            'customer' => $return->customer ? [
                'id' => $return->customer->id,
                'first_name' => $return->customer->first_name,
                'last_name' => $return->customer->last_name,
                'full_name' => $return->customer->full_name,
                'email' => $return->customer->email,
                'phone' => $return->customer->phone_number,
            ] : null,
            'return_policy' => $return->returnPolicy ? [
                'id' => $return->returnPolicy->id,
                'name' => $return->returnPolicy->name,
                'restocking_fee_percent' => $return->returnPolicy->restocking_fee_percent,
            ] : null,
            'processed_by' => $return->processedByUser ? [
                'id' => $return->processedByUser->id,
                'name' => $return->processedByUser->name,
            ] : null,
            'items' => $return->items->map(fn ($item) => [
                'id' => $item->id,
                'order_item_id' => $item->order_item_id,
                'product_variant_id' => $item->product_variant_id,
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_price,
                'line_total' => $item->line_total,
                'condition' => $item->condition,
                'reason' => $item->reason,
                'notes' => $item->notes,
                'restock' => $item->restock,
                'restocked' => $item->restocked,
                'restocked_at' => $item->restocked_at?->toISOString(),
                'product' => $item->productVariant?->product ? [
                    'id' => $item->productVariant->product->id,
                    'title' => $item->productVariant->product->title,
                    'image' => $item->productVariant->product->images->first()?->url,
                    'sku' => $item->productVariant->sku,
                ] : null,
            ]),
            'item_count' => $return->item_count,
        ];
    }

    /**
     * Format an order for return creation.
     *
     * @return array<string, mixed>
     */
    protected function formatOrderForReturn(Order $order): array
    {
        return [
            'id' => $order->id,
            'invoice_number' => $order->invoice_number,
            'status' => $order->status,
            'total' => $order->total,
            'created_at' => $order->created_at->toISOString(),
            'customer' => $order->customer ? [
                'id' => $order->customer->id,
                'full_name' => $order->customer->full_name,
                'email' => $order->customer->email,
            ] : null,
            'items' => $order->items->map(fn ($item) => [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'product_variant_id' => $item->product_variant_id,
                'sku' => $item->sku,
                'title' => $item->title,
                'quantity' => $item->quantity,
                'price' => $item->price,
                'line_total' => $item->line_total,
                'product' => $item->product ? [
                    'id' => $item->product->id,
                    'title' => $item->product->title,
                    'image' => $item->product->images->first()?->url,
                ] : null,
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
            ['value' => ProductReturn::STATUS_PENDING, 'label' => 'Pending'],
            ['value' => ProductReturn::STATUS_APPROVED, 'label' => 'Approved'],
            ['value' => ProductReturn::STATUS_PROCESSING, 'label' => 'Processing'],
            ['value' => ProductReturn::STATUS_COMPLETED, 'label' => 'Completed'],
            ['value' => ProductReturn::STATUS_REJECTED, 'label' => 'Rejected'],
            ['value' => ProductReturn::STATUS_CANCELLED, 'label' => 'Cancelled'],
        ];
    }

    /**
     * Get available types.
     *
     * @return array<array<string, string>>
     */
    protected function getTypes(): array
    {
        return [
            ['value' => ProductReturn::TYPE_RETURN, 'label' => 'Return'],
            ['value' => ProductReturn::TYPE_EXCHANGE, 'label' => 'Exchange'],
        ];
    }

    /**
     * Get available refund methods.
     *
     * @return array<array<string, string>>
     */
    protected function getRefundMethods(): array
    {
        return [
            ['value' => ProductReturn::REFUND_ORIGINAL, 'label' => 'Original Payment Method'],
            ['value' => ProductReturn::REFUND_STORE_CREDIT, 'label' => 'Store Credit'],
            ['value' => ProductReturn::REFUND_CASH, 'label' => 'Cash'],
            ['value' => ProductReturn::REFUND_CARD, 'label' => 'Card'],
        ];
    }

    /**
     * Get available conditions for return items.
     *
     * @return array<array<string, string>>
     */
    protected function getConditions(): array
    {
        return [
            ['value' => 'new', 'label' => 'New/Unused'],
            ['value' => 'like_new', 'label' => 'Like New'],
            ['value' => 'good', 'label' => 'Good'],
            ['value' => 'fair', 'label' => 'Fair'],
            ['value' => 'poor', 'label' => 'Poor'],
            ['value' => 'damaged', 'label' => 'Damaged'],
        ];
    }

    /**
     * Get common return reasons.
     *
     * @return array<array<string, string>>
     */
    protected function getReasons(): array
    {
        return [
            ['value' => 'changed_mind', 'label' => 'Changed Mind'],
            ['value' => 'wrong_size', 'label' => 'Wrong Size'],
            ['value' => 'wrong_item', 'label' => 'Wrong Item Received'],
            ['value' => 'defective', 'label' => 'Defective/Damaged'],
            ['value' => 'not_as_described', 'label' => 'Not as Described'],
            ['value' => 'arrived_late', 'label' => 'Arrived Too Late'],
            ['value' => 'better_price', 'label' => 'Found Better Price'],
            ['value' => 'other', 'label' => 'Other'],
        ];
    }
}
