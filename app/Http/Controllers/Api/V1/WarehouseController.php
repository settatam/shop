<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreWarehouseRequest;
use App\Http\Requests\Api\UpdateWarehouseRequest;
use App\Models\Warehouse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WarehouseController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Warehouse::query();

        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('city', 'like', "%{$search}%");
            });
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        if ($request->has('fulfills_orders')) {
            $query->where('fulfills_orders', $request->boolean('fulfills_orders'));
        }

        if ($request->has('accepts_transfers')) {
            $query->where('accepts_transfers', $request->boolean('accepts_transfers'));
        }

        $sortField = $request->input('sort', 'name');
        $sortDirection = $request->input('direction', 'asc');
        $query->orderBy($sortField, $sortDirection);

        if ($request->boolean('all')) {
            return response()->json(['data' => $query->get()]);
        }

        $warehouses = $query->paginate($request->input('per_page', 15));

        return response()->json($warehouses);
    }

    public function store(StoreWarehouseRequest $request): JsonResponse
    {
        $warehouse = Warehouse::create($request->validated());

        if ($request->boolean('is_default')) {
            $warehouse->makeDefault();
        }

        return response()->json($warehouse, 201);
    }

    public function show(Warehouse $warehouse): JsonResponse
    {
        $warehouse->loadCount('inventories');

        return response()->json($warehouse);
    }

    public function update(UpdateWarehouseRequest $request, Warehouse $warehouse): JsonResponse
    {
        $warehouse->update($request->validated());

        if ($request->has('is_default') && $request->boolean('is_default')) {
            $warehouse->makeDefault();
        }

        return response()->json($warehouse);
    }

    public function destroy(Warehouse $warehouse): JsonResponse
    {
        // Check if warehouse has inventory
        if ($warehouse->inventories()->where('quantity', '>', 0)->exists()) {
            return response()->json([
                'message' => 'Cannot delete warehouse with existing inventory',
            ], 422);
        }

        // Check if warehouse has pending transfers
        $hasPendingTransfers = $warehouse->incomingTransfers()
            ->whereIn('status', ['draft', 'pending', 'in_transit'])
            ->exists() || $warehouse->outgoingTransfers()
            ->whereIn('status', ['draft', 'pending', 'in_transit'])
            ->exists();

        if ($hasPendingTransfers) {
            return response()->json([
                'message' => 'Cannot delete warehouse with pending transfers',
            ], 422);
        }

        $warehouse->delete();

        return response()->json(null, 204);
    }

    public function makeDefault(Warehouse $warehouse): JsonResponse
    {
        $warehouse->makeDefault();

        return response()->json($warehouse);
    }

    public function inventory(Warehouse $warehouse, Request $request): JsonResponse
    {
        $query = $warehouse->inventories()
            ->with(['variant.product']);

        if ($request->has('low_stock')) {
            $query->lowStock();
        }

        if ($request->has('needs_reorder')) {
            $query->needsReorder();
        }

        $inventory = $query->paginate($request->input('per_page', 50));

        return response()->json($inventory);
    }
}
