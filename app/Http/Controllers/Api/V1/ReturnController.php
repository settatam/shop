<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProcessReturnRequest;
use App\Http\Requests\StoreReturnRequest;
use App\Http\Resources\ProductReturnResource;
use App\Models\Order;
use App\Models\ProductReturn;
use App\Services\Returns\ReturnService;
use App\Services\Returns\ReturnSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ReturnController extends Controller
{
    public function __construct(
        protected ReturnService $returnService,
        protected ReturnSyncService $returnSyncService,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = ProductReturn::query()
            ->with(['order', 'customer', 'items'])
            ->latest();

        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->has('type')) {
            $query->where('type', $request->input('type'));
        }

        if ($request->has('customer_id')) {
            $query->where('customer_id', $request->input('customer_id'));
        }

        if ($request->has('order_id')) {
            $query->where('order_id', $request->input('order_id'));
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
                $q->where('return_number', 'like', "%{$search}%")
                    ->orWhere('external_return_id', 'like', "%{$search}%")
                    ->orWhereHas('customer', function ($customerQuery) use ($search) {
                        $customerQuery->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
            });
        }

        $returns = $query->paginate($request->input('per_page', 15));

        return ProductReturnResource::collection($returns);
    }

    public function store(StoreReturnRequest $request): JsonResponse
    {
        $order = Order::findOrFail($request->input('order_id'));

        $return = $this->returnService->createReturn(
            $order,
            $request->input('items'),
            $request->only(['return_policy_id', 'type', 'reason', 'customer_notes'])
        );

        return (new ProductReturnResource($return))
            ->response()
            ->setStatusCode(201);
    }

    public function show(ProductReturn $return): ProductReturnResource
    {
        $return->load(['order', 'customer', 'items.productVariant', 'items.orderItem', 'returnPolicy', 'processedByUser']);

        return new ProductReturnResource($return);
    }

    public function approve(ProductReturn $return): ProductReturnResource
    {
        $return = $this->returnService->approveReturn($return, auth()->user());

        return new ProductReturnResource($return->load(['order', 'customer', 'items']));
    }

    public function reject(Request $request, ProductReturn $return): ProductReturnResource
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $return = $this->returnService->rejectReturn($return, $validated['reason'], auth()->user());

        return new ProductReturnResource($return->load(['order', 'customer', 'items']));
    }

    public function process(ProcessReturnRequest $request, ProductReturn $return): ProductReturnResource
    {
        $return = $this->returnService->processReturn(
            $return,
            $request->input('refund_method')
        );

        if ($return->store_marketplace_id) {
            try {
                $this->returnSyncService->syncToMarketplace($return);
            } catch (\Exception $e) {
                // Log error but don't fail the request
                report($e);
            }
        }

        return new ProductReturnResource($return->load(['order', 'customer', 'items']));
    }

    public function cancel(ProductReturn $return): ProductReturnResource
    {
        $return = $this->returnService->cancelReturn($return);

        return new ProductReturnResource($return->load(['order', 'customer', 'items']));
    }

    public function exchange(Request $request, ProductReturn $return): JsonResponse
    {
        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_variant_id' => ['required', 'integer', 'exists:product_variants,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.price' => ['nullable', 'numeric', 'min:0'],
        ]);

        $order = $this->returnService->createExchange($return, $validated['items']);

        return response()->json([
            'message' => 'Exchange order created successfully.',
            'return' => new ProductReturnResource($return->fresh(['order', 'customer', 'items'])),
            'exchange_order_id' => $order->id,
        ]);
    }

    public function receive(ProductReturn $return): ProductReturnResource
    {
        $return = $this->returnService->markAsReceived($return);

        return new ProductReturnResource($return->load(['order', 'customer', 'items']));
    }
}
