<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreVendorRequest;
use App\Http\Requests\Api\UpdateVendorRequest;
use App\Models\ProductVariant;
use App\Models\Vendor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VendorController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Vendor::query();

        if ($request->has('search')) {
            $query->search($request->input('search'));
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $sortField = $request->input('sort', 'name');
        $sortDirection = $request->input('direction', 'asc');
        $query->orderBy($sortField, $sortDirection);

        if ($request->boolean('all')) {
            return response()->json(['data' => $query->get()]);
        }

        $vendors = $query->paginate($request->input('per_page', 15));

        return response()->json($vendors);
    }

    public function store(StoreVendorRequest $request): JsonResponse
    {
        $vendor = Vendor::create($request->validated());

        return response()->json($vendor, 201);
    }

    public function show(Vendor $vendor): JsonResponse
    {
        $vendor->loadCount('purchaseOrders', 'productVariants');

        return response()->json($vendor);
    }

    public function update(UpdateVendorRequest $request, Vendor $vendor): JsonResponse
    {
        $vendor->update($request->validated());

        return response()->json($vendor);
    }

    public function destroy(Vendor $vendor): JsonResponse
    {
        // Check for open purchase orders
        $hasOpenPOs = $vendor->purchaseOrders()
            ->whereNotIn('status', ['closed', 'cancelled'])
            ->exists();

        if ($hasOpenPOs) {
            return response()->json([
                'message' => 'Cannot delete vendor with open purchase orders',
            ], 422);
        }

        $vendor->delete();

        return response()->json(null, 204);
    }

    public function attachProduct(Request $request, Vendor $vendor): JsonResponse
    {
        $request->validate([
            'product_variant_id' => ['required', 'exists:product_variants,id'],
            'vendor_sku' => ['nullable', 'string', 'max:191'],
            'cost' => ['nullable', 'numeric', 'min:0'],
            'lead_time_days' => ['nullable', 'integer', 'min:0'],
            'minimum_order_qty' => ['nullable', 'integer', 'min:1'],
            'is_preferred' => ['boolean'],
            'notes' => ['nullable', 'string'],
        ]);

        $pivotData = [
            'vendor_sku' => $request->input('vendor_sku'),
            'cost' => $request->input('cost'),
            'lead_time_days' => $request->input('lead_time_days'),
            'minimum_order_qty' => $request->input('minimum_order_qty'),
            'is_preferred' => $request->boolean('is_preferred'),
            'notes' => $request->input('notes'),
        ];

        // If this is the preferred vendor, remove preferred from all other vendors for this variant
        if ($request->boolean('is_preferred')) {
            \DB::table('product_vendor')
                ->where('product_variant_id', $request->input('product_variant_id'))
                ->where('vendor_id', '!=', $vendor->id)
                ->update(['is_preferred' => false]);
        }

        $vendor->productVariants()->syncWithoutDetaching([
            $request->input('product_variant_id') => $pivotData,
        ]);

        return response()->json([
            'message' => 'Product linked to vendor successfully',
        ]);
    }

    public function detachProduct(Vendor $vendor, ProductVariant $variant): JsonResponse
    {
        $vendor->productVariants()->detach($variant->id);

        return response()->json([
            'message' => 'Product unlinked from vendor successfully',
        ]);
    }

    public function products(Vendor $vendor, Request $request): JsonResponse
    {
        $query = $vendor->productVariants()
            ->with(['product:id,title,handle']);

        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('sku', 'like', "%{$search}%")
                    ->orWhere('product_vendor.vendor_sku', 'like', "%{$search}%")
                    ->orWhereHas('product', function ($q) use ($search) {
                        $q->where('title', 'like', "%{$search}%");
                    });
            });
        }

        $products = $query->paginate($request->input('per_page', 15));

        return response()->json($products);
    }
}
