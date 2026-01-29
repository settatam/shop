<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMemoRequest;
use App\Http\Requests\UpdateMemoRequest;
use App\Http\Resources\MemoResource;
use App\Models\Memo;
use App\Models\MemoItem;
use App\Services\Memos\MemoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MemoController extends Controller
{
    public function __construct(
        protected MemoService $memoService,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Memo::query()
            ->with(['vendor', 'user', 'items'])
            ->latest();

        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->has('vendor_id')) {
            $query->where('vendor_id', $request->input('vendor_id'));
        }

        if ($request->has('tenure')) {
            $query->where('tenure', $request->input('tenure'));
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
                $q->where('memo_number', 'like', "%{$search}%")
                    ->orWhereHas('vendor', function ($vendorQuery) use ($search) {
                        $vendorQuery->where('name', 'like', "%{$search}%")
                            ->orWhere('company_name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
            });
        }

        $memos = $query->paginate($request->input('per_page', 15));

        return MemoResource::collection($memos);
    }

    public function store(StoreMemoRequest $request): JsonResponse
    {
        $memo = $this->memoService->create($request->validated());

        return (new MemoResource($memo))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Memo $memo): MemoResource
    {
        $memo->load(['vendor', 'user', 'items.category', 'items.product']);

        return new MemoResource($memo);
    }

    public function update(UpdateMemoRequest $request, Memo $memo): MemoResource
    {
        $memo->update($request->validated());

        return new MemoResource($memo->fresh(['vendor', 'user', 'items']));
    }

    public function destroy(Memo $memo): JsonResponse
    {
        $memo->delete();

        return response()->json(['message' => 'Memo deleted successfully.']);
    }

    public function addItem(Request $request, Memo $memo): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'sku' => ['nullable', 'string', 'max:100'],
            'title' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'cost' => ['nullable', 'numeric', 'min:0'],
            'tenor' => ['nullable', 'integer', 'min:1'],
            'due_date' => ['nullable', 'date'],
            'charge_taxes' => ['nullable', 'boolean'],
        ]);

        $item = $this->memoService->addItem($memo, $validated);

        return response()->json([
            'message' => 'Item added successfully.',
            'item' => $item,
        ]);
    }

    public function removeItem(Memo $memo, MemoItem $item): JsonResponse
    {
        $this->memoService->removeItem($item);

        return response()->json(['message' => 'Item removed successfully.']);
    }

    public function sendToVendor(Memo $memo): MemoResource
    {
        $memo = $this->memoService->sendToVendor($memo);

        return new MemoResource($memo->load(['vendor', 'user', 'items']));
    }

    public function markVendorReceived(Memo $memo): MemoResource
    {
        $memo = $this->memoService->markVendorReceived($memo);

        return new MemoResource($memo->load(['vendor', 'user', 'items']));
    }

    public function returnItem(Memo $memo, MemoItem $item): JsonResponse
    {
        $item = $this->memoService->returnItem($item);

        return response()->json([
            'message' => 'Item returned successfully.',
            'item' => $item,
        ]);
    }

    public function createSale(Request $request, Memo $memo): JsonResponse
    {
        $validated = $request->validate([
            'item_ids' => ['nullable', 'array'],
            'item_ids.*' => ['integer', 'exists:memo_items,id'],
        ]);

        $order = $this->memoService->createSaleOrder($memo, $validated);

        return response()->json([
            'message' => 'Sale order created successfully.',
            'order_id' => $order->id,
        ]);
    }
}
