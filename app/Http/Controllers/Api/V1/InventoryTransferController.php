<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\InventoryTransfer;
use App\Services\StoreContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class InventoryTransferController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = InventoryTransfer::query()
            ->with(['fromWarehouse', 'toWarehouse', 'createdByUser']);

        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->has('from_warehouse_id')) {
            $query->where('from_warehouse_id', $request->input('from_warehouse_id'));
        }

        if ($request->has('to_warehouse_id')) {
            $query->where('to_warehouse_id', $request->input('to_warehouse_id'));
        }

        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where('reference', 'like', "%{$search}%");
        }

        $sortField = $request->input('sort', 'created_at');
        $sortDirection = $request->input('direction', 'desc');
        $query->orderBy($sortField, $sortDirection);

        $transfers = $query->paginate($request->input('per_page', 15));

        return response()->json($transfers);
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'from_warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'to_warehouse_id' => ['required', 'integer', 'exists:warehouses,id', 'different:from_warehouse_id'],
            'notes' => ['nullable', 'string'],
            'expected_at' => ['nullable', 'date'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_variant_id' => ['required', 'integer', 'exists:product_variants,id'],
            'items.*.quantity_requested' => ['required', 'integer', 'min:1'],
            'items.*.notes' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $validated = $validator->validated();
        $storeId = app(StoreContext::class)->getCurrentStoreId();

        $transfer = DB::transaction(function () use ($validated, $storeId, $request) {
            $transfer = InventoryTransfer::create([
                'store_id' => $storeId,
                'from_warehouse_id' => $validated['from_warehouse_id'],
                'to_warehouse_id' => $validated['to_warehouse_id'],
                'created_by' => $request->user()?->id,
                'reference' => InventoryTransfer::generateReference($storeId),
                'status' => InventoryTransfer::STATUS_DRAFT,
                'notes' => $validated['notes'] ?? null,
                'expected_at' => $validated['expected_at'] ?? null,
            ]);

            foreach ($validated['items'] as $item) {
                $transfer->items()->create([
                    'product_variant_id' => $item['product_variant_id'],
                    'quantity_requested' => $item['quantity_requested'],
                    'notes' => $item['notes'] ?? null,
                ]);
            }

            return $transfer;
        });

        $transfer->load(['fromWarehouse', 'toWarehouse', 'items.variant.product']);

        return response()->json($transfer, 201);
    }

    public function show(InventoryTransfer $inventoryTransfer): JsonResponse
    {
        $inventoryTransfer->load([
            'fromWarehouse',
            'toWarehouse',
            'createdByUser',
            'receivedByUser',
            'items.variant.product',
        ]);

        return response()->json($inventoryTransfer);
    }

    public function update(Request $request, InventoryTransfer $inventoryTransfer): JsonResponse
    {
        if (! $inventoryTransfer->canBeEdited()) {
            return response()->json([
                'message' => 'Transfer cannot be edited in its current status',
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'from_warehouse_id' => ['sometimes', 'required', 'integer', 'exists:warehouses,id'],
            'to_warehouse_id' => ['sometimes', 'required', 'integer', 'exists:warehouses,id'],
            'notes' => ['nullable', 'string'],
            'expected_at' => ['nullable', 'date'],
            'items' => ['sometimes', 'array', 'min:1'],
            'items.*.id' => ['nullable', 'integer'],
            'items.*.product_variant_id' => ['required_without:items.*.id', 'integer', 'exists:product_variants,id'],
            'items.*.quantity_requested' => ['required', 'integer', 'min:1'],
            'items.*.notes' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $validated = $validator->validated();

        // Validate warehouses are different
        $fromWarehouse = $validated['from_warehouse_id'] ?? $inventoryTransfer->from_warehouse_id;
        $toWarehouse = $validated['to_warehouse_id'] ?? $inventoryTransfer->to_warehouse_id;

        if ($fromWarehouse === $toWarehouse) {
            return response()->json([
                'message' => 'Source and destination warehouses must be different',
            ], 422);
        }

        DB::transaction(function () use ($inventoryTransfer, $validated) {
            $inventoryTransfer->update([
                'from_warehouse_id' => $validated['from_warehouse_id'] ?? $inventoryTransfer->from_warehouse_id,
                'to_warehouse_id' => $validated['to_warehouse_id'] ?? $inventoryTransfer->to_warehouse_id,
                'notes' => $validated['notes'] ?? $inventoryTransfer->notes,
                'expected_at' => $validated['expected_at'] ?? $inventoryTransfer->expected_at,
            ]);

            if (isset($validated['items'])) {
                // Remove items not in the update
                $keepIds = collect($validated['items'])->pluck('id')->filter()->toArray();
                $inventoryTransfer->items()->whereNotIn('id', $keepIds)->delete();

                // Update or create items
                foreach ($validated['items'] as $item) {
                    if (isset($item['id'])) {
                        $inventoryTransfer->items()
                            ->where('id', $item['id'])
                            ->update([
                                'quantity_requested' => $item['quantity_requested'],
                                'notes' => $item['notes'] ?? null,
                            ]);
                    } else {
                        $inventoryTransfer->items()->create([
                            'product_variant_id' => $item['product_variant_id'],
                            'quantity_requested' => $item['quantity_requested'],
                            'notes' => $item['notes'] ?? null,
                        ]);
                    }
                }
            }
        });

        $inventoryTransfer->load(['fromWarehouse', 'toWarehouse', 'items.variant.product']);

        return response()->json($inventoryTransfer);
    }

    public function destroy(InventoryTransfer $inventoryTransfer): JsonResponse
    {
        if (! $inventoryTransfer->canBeCancelled()) {
            return response()->json([
                'message' => 'Transfer cannot be deleted in its current status',
            ], 422);
        }

        $inventoryTransfer->delete();

        return response()->json(null, 204);
    }

    public function submit(InventoryTransfer $inventoryTransfer): JsonResponse
    {
        try {
            $inventoryTransfer->submit();
            $inventoryTransfer->load(['fromWarehouse', 'toWarehouse', 'items.variant.product']);

            return response()->json($inventoryTransfer);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function ship(InventoryTransfer $inventoryTransfer): JsonResponse
    {
        try {
            $inventoryTransfer->ship();
            $inventoryTransfer->load(['fromWarehouse', 'toWarehouse', 'items.variant.product']);

            return response()->json($inventoryTransfer);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function receive(Request $request, InventoryTransfer $inventoryTransfer): JsonResponse
    {
        try {
            $inventoryTransfer->receive($request->user()?->id);
            $inventoryTransfer->load(['fromWarehouse', 'toWarehouse', 'items.variant.product']);

            return response()->json($inventoryTransfer);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function cancel(InventoryTransfer $inventoryTransfer): JsonResponse
    {
        try {
            $inventoryTransfer->cancel();
            $inventoryTransfer->load(['fromWarehouse', 'toWarehouse']);

            return response()->json($inventoryTransfer);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
