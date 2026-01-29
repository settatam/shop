<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRepairRequest;
use App\Http\Requests\UpdateRepairRequest;
use App\Http\Resources\RepairResource;
use App\Models\Repair;
use App\Models\RepairItem;
use App\Services\Repairs\RepairService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class RepairController extends Controller
{
    public function __construct(
        protected RepairService $repairService,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Repair::query()
            ->with(['customer', 'vendor', 'user', 'items'])
            ->latest();

        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->has('customer_id')) {
            $query->where('customer_id', $request->input('customer_id'));
        }

        if ($request->has('vendor_id')) {
            $query->where('vendor_id', $request->input('vendor_id'));
        }

        if ($request->has('is_appraisal')) {
            $query->where('is_appraisal', $request->boolean('is_appraisal'));
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
                $q->where('repair_number', 'like', "%{$search}%")
                    ->orWhereHas('customer', function ($customerQuery) use ($search) {
                        $customerQuery->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
            });
        }

        $repairs = $query->paginate($request->input('per_page', 15));

        return RepairResource::collection($repairs);
    }

    public function store(StoreRepairRequest $request): JsonResponse
    {
        $repair = $this->repairService->create($request->validated());

        return (new RepairResource($repair))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Repair $repair): RepairResource
    {
        $repair->load(['customer', 'vendor', 'user', 'items.category']);

        return new RepairResource($repair);
    }

    public function update(UpdateRepairRequest $request, Repair $repair): RepairResource
    {
        $repair->update($request->validated());

        return new RepairResource($repair->fresh(['customer', 'vendor', 'user', 'items']));
    }

    public function destroy(Repair $repair): JsonResponse
    {
        $repair->delete();

        return response()->json(['message' => 'Repair deleted successfully.']);
    }

    public function addItem(Request $request, Repair $repair): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'sku' => ['nullable', 'string', 'max:100'],
            'title' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'vendor_cost' => ['nullable', 'numeric', 'min:0'],
            'customer_cost' => ['nullable', 'numeric', 'min:0'],
            'dwt' => ['nullable', 'numeric', 'min:0'],
            'precious_metal' => ['nullable', 'string', 'max:50'],
        ]);

        $item = $this->repairService->addItem($repair, $validated);

        return response()->json([
            'message' => 'Item added successfully.',
            'item' => $item,
        ]);
    }

    public function updateItem(Request $request, Repair $repair, RepairItem $item): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'sku' => ['nullable', 'string', 'max:100'],
            'title' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'vendor_cost' => ['nullable', 'numeric', 'min:0'],
            'customer_cost' => ['nullable', 'numeric', 'min:0'],
            'dwt' => ['nullable', 'numeric', 'min:0'],
            'precious_metal' => ['nullable', 'string', 'max:50'],
            'status' => ['nullable', 'string', 'max:50'],
        ]);

        $item = $this->repairService->updateItem($item, $validated);

        return response()->json([
            'message' => 'Item updated successfully.',
            'item' => $item,
        ]);
    }

    public function removeItem(Repair $repair, RepairItem $item): JsonResponse
    {
        $this->repairService->removeItem($item);

        return response()->json(['message' => 'Item removed successfully.']);
    }

    public function sendToVendor(Repair $repair): RepairResource
    {
        $repair = $this->repairService->sendToVendor($repair);

        return new RepairResource($repair->load(['customer', 'vendor', 'user', 'items']));
    }

    public function markReceivedByVendor(Repair $repair): RepairResource
    {
        $repair = $this->repairService->markReceivedByVendor($repair);

        return new RepairResource($repair->load(['customer', 'vendor', 'user', 'items']));
    }

    public function markCompleted(Repair $repair): RepairResource
    {
        $repair = $this->repairService->markCompleted($repair);

        return new RepairResource($repair->load(['customer', 'vendor', 'user', 'items']));
    }

    public function createSale(Repair $repair): JsonResponse
    {
        $order = $this->repairService->createSaleOrder($repair);

        return response()->json([
            'message' => 'Sale order created successfully.',
            'order_id' => $order->id,
        ]);
    }
}
