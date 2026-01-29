<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOrderRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\Orders\OrderCreationService;
use App\Services\StoreContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class OrderController extends Controller
{
    public function __construct(
        protected OrderCreationService $orderService,
        protected StoreContext $storeContext,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Order::query()
            ->with(['customer', 'items'])
            ->latest();

        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->has('customer_id')) {
            $query->where('customer_id', $request->input('customer_id'));
        }

        if ($request->has('source_platform')) {
            $query->where('source_platform', $request->input('source_platform'));
        }

        if ($request->has('date_from')) {
            $query->whereDate('created_at', '>=', $request->input('date_from'));
        }

        if ($request->has('date_to')) {
            $query->whereDate('created_at', '<=', $request->input('date_to'));
        }

        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('invoice_number', 'like', "%{$search}%")
                    ->orWhere('external_marketplace_id', 'like', "%{$search}%")
                    ->orWhereHas('customer', function ($customerQuery) use ($search) {
                        $customerQuery->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
            });
        }

        $orders = $query->paginate($request->input('per_page', 15));

        return OrderResource::collection($orders);
    }

    public function store(StoreOrderRequest $request): OrderResource
    {
        $store = $this->storeContext->getCurrentStore();

        $order = $this->orderService->create($request->validated(), $store);

        return new OrderResource($order);
    }

    public function show(Order $order): OrderResource
    {
        $order->load(['customer', 'items.product', 'items.variant', 'payments']);

        return new OrderResource($order);
    }

    public function update(Request $request, Order $order): OrderResource
    {
        $validated = $request->validate([
            'status' => ['sometimes', 'string', 'in:pending,confirmed,processing,shipped,delivered,completed,cancelled'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'shipping_address' => ['sometimes', 'nullable', 'array'],
            'billing_address' => ['sometimes', 'nullable', 'array'],
            'shipping_cost' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'sales_tax' => ['sometimes', 'nullable', 'numeric', 'min:0'],
        ]);

        $order->update($validated);

        if (isset($validated['shipping_cost']) || isset($validated['sales_tax'])) {
            $order->calculateTotals();
        }

        return new OrderResource($order->fresh(['customer', 'items', 'payments']));
    }

    public function destroy(Order $order): JsonResponse
    {
        if ($order->isPaid()) {
            return response()->json([
                'message' => 'Cannot delete a paid order. Cancel it first.',
            ], 422);
        }

        $order->delete();

        return response()->json(['message' => 'Order deleted successfully.']);
    }

    public function cancel(Order $order): OrderResource
    {
        if ($order->isCancelled()) {
            return new OrderResource($order);
        }

        $order = $this->orderService->cancelOrder($order);

        return new OrderResource($order->load(['customer', 'items', 'payments']));
    }

    public function addPayment(Request $request, Order $order): OrderResource
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'payment_method' => ['nullable', 'string'],
            'reference' => ['nullable', 'string', 'max:100'],
            'transaction_id' => ['nullable', 'string', 'max:255'],
            'gateway' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $this->orderService->addPaymentToOrder($order, $validated);

        return new OrderResource($order->fresh(['customer', 'items', 'payments']));
    }

    public function confirm(Order $order): OrderResource
    {
        $order->confirm();

        return new OrderResource($order->fresh(['customer', 'items', 'payments']));
    }

    public function ship(Order $order): OrderResource
    {
        $order->markAsShipped();

        return new OrderResource($order->fresh(['customer', 'items', 'payments']));
    }

    public function deliver(Order $order): OrderResource
    {
        $order->markAsDelivered();

        return new OrderResource($order->fresh(['customer', 'items', 'payments']));
    }

    public function complete(Order $order): OrderResource
    {
        $order->markAsCompleted();

        return new OrderResource($order->fresh(['customer', 'items', 'payments']));
    }
}
