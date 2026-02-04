<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreProductRequest;
use App\Http\Requests\Api\UpdateProductRequest;
use App\Models\Product;
use App\Services\StoreContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Product::query()
            ->with(['category', 'brand', 'variants', 'primaryImage']);

        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('handle', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        if ($request->has('category_id')) {
            $query->where('category_id', $request->input('category_id'));
        }

        if ($request->has('brand_id')) {
            $query->where('brand_id', $request->input('brand_id'));
        }

        if ($request->has('is_published')) {
            $query->where('is_published', $request->boolean('is_published'));
        }

        $sortField = $request->input('sort', 'created_at');
        $sortDirection = $request->input('direction', 'desc');
        $query->orderBy($sortField, $sortDirection);

        $products = $query->paginate($request->input('per_page', 15));

        return response()->json($products);
    }

    public function store(StoreProductRequest $request): JsonResponse
    {
        $product = Product::create($request->validated());

        if ($request->has('variants')) {
            foreach ($request->input('variants') as $variantData) {
                $product->variants()->create($variantData);
            }
        }

        $product->load(['category', 'brand', 'variants']);

        return response()->json($product, 201);
    }

    public function show(Product $product): JsonResponse
    {
        $product->load(['category', 'brand', 'variants', 'images']);

        return response()->json($product);
    }

    public function update(UpdateProductRequest $request, Product $product): JsonResponse
    {
        $product->update($request->validated());

        $product->load(['category', 'brand', 'variants']);

        return response()->json($product);
    }

    public function destroy(Product $product): JsonResponse
    {
        $product->delete();

        return response()->json(null, 204);
    }

    /**
     * Get a lightweight preview of the product for quick view hover cards.
     */
    public function preview(Product $product, StoreContext $storeContext): JsonResponse
    {
        $store = $storeContext->getCurrentStore();

        if (! $store || $product->store_id !== $store->id) {
            abort(404);
        }

        $product->load(['category', 'brand', 'primaryImage', 'variants.inventories.warehouse']);

        // Aggregate inventory by warehouse across all variants
        $inventoryLevels = [];
        foreach ($product->variants as $variant) {
            foreach ($variant->inventories as $inventory) {
                $warehouseId = $inventory->warehouse_id;
                if (! isset($inventoryLevels[$warehouseId])) {
                    $inventoryLevels[$warehouseId] = [
                        'warehouse_name' => $inventory->warehouse?->name ?? 'Unknown',
                        'warehouse_code' => $inventory->warehouse?->code ?? '',
                        'quantity' => 0,
                        'available_quantity' => 0,
                    ];
                }
                $inventoryLevels[$warehouseId]['quantity'] += $inventory->quantity;
                $inventoryLevels[$warehouseId]['available_quantity'] += $inventory->available_quantity;
            }
        }

        // Get the minimum price across variants, or 0 if no variants
        $price = $product->variants->min('price') ?? 0;

        return response()->json([
            'id' => $product->id,
            'title' => $product->title,
            'image_url' => $product->primaryImage?->url,
            'price' => $price,
            'status' => $product->status_label,
            'category_name' => $product->category?->name,
            'brand_name' => $product->brand?->name,
            'total_quantity' => $product->total_quantity,
            'inventory_levels' => array_values($inventoryLevels),
        ]);
    }
}
