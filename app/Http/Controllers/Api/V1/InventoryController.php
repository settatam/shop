<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Inventory;
use App\Models\InventoryAdjustment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\StoreContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class InventoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Inventory::query()
            ->with(['variant.product', 'warehouse']);

        if ($request->has('warehouse_id')) {
            $query->where('warehouse_id', $request->input('warehouse_id'));
        }

        if ($request->has('search')) {
            $search = $request->input('search');
            $query->whereHas('variant', function ($q) use ($search) {
                $q->where('sku', 'like', "%{$search}%")
                    ->orWhereHas('product', function ($q2) use ($search) {
                        $q2->where('title', 'like', "%{$search}%");
                    });
            });
        }

        if ($request->boolean('low_stock')) {
            $query->lowStock();
        }

        if ($request->boolean('needs_reorder')) {
            $query->needsReorder();
        }

        $sortField = $request->input('sort', 'quantity');
        $sortDirection = $request->input('direction', 'asc');
        $query->orderBy($sortField, $sortDirection);

        $inventory = $query->paginate($request->input('per_page', 50));

        return response()->json($inventory);
    }

    public function adjust(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'product_variant_id' => ['required', 'integer', 'exists:product_variants,id'],
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'adjustment' => ['required', 'integer'],
            'type' => ['required', 'string', 'in:'.implode(',', InventoryAdjustment::TYPES)],
            'reason' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $validated = $validator->validated();
        $storeId = app(StoreContext::class)->getCurrentStoreId();

        $inventory = Inventory::getOrCreate(
            $storeId,
            $validated['product_variant_id'],
            $validated['warehouse_id']
        );

        $adjustment = $inventory->adjustQuantity(
            $validated['adjustment'],
            $validated['type'],
            $request->user()?->id,
            $validated['reason'] ?? null,
            $validated['notes'] ?? null
        );

        $inventory->load(['variant.product', 'warehouse']);

        return response()->json([
            'inventory' => $inventory,
            'adjustment' => $adjustment,
        ]);
    }

    public function lowStock(Request $request): JsonResponse
    {
        $inventory = Inventory::query()
            ->with(['variant.product', 'warehouse'])
            ->lowStock()
            ->paginate($request->input('per_page', 50));

        return response()->json($inventory);
    }

    public function needsReorder(Request $request): JsonResponse
    {
        $inventory = Inventory::query()
            ->with(['variant.product', 'warehouse'])
            ->needsReorder()
            ->paginate($request->input('per_page', 50));

        return response()->json($inventory);
    }

    public function byVariant(ProductVariant $productVariant, Request $request): JsonResponse
    {
        $inventory = Inventory::query()
            ->with(['warehouse'])
            ->where('product_variant_id', $productVariant->id)
            ->get();

        $totalQuantity = $inventory->sum('quantity');
        $totalAvailable = $inventory->sum('available_quantity');
        $totalReserved = $inventory->sum('reserved_quantity');

        return response()->json([
            'data' => $inventory,
            'summary' => [
                'total_quantity' => $totalQuantity,
                'total_available' => $totalAvailable,
                'total_reserved' => $totalReserved,
            ],
        ]);
    }

    public function byProduct(Product $product, Request $request): JsonResponse
    {
        $variantIds = $product->variants()->pluck('id');

        $inventory = Inventory::query()
            ->with(['variant', 'warehouse'])
            ->whereIn('product_variant_id', $variantIds)
            ->get();

        $byWarehouse = $inventory->groupBy('warehouse_id')->map(function ($items) {
            return [
                'warehouse' => $items->first()->warehouse,
                'total_quantity' => $items->sum('quantity'),
                'total_available' => $items->sum('available_quantity'),
                'total_reserved' => $items->sum('reserved_quantity'),
            ];
        })->values();

        return response()->json([
            'data' => $inventory,
            'by_warehouse' => $byWarehouse,
            'summary' => [
                'total_quantity' => $inventory->sum('quantity'),
                'total_available' => $inventory->sum('available_quantity'),
                'total_reserved' => $inventory->sum('reserved_quantity'),
            ],
        ]);
    }
}
