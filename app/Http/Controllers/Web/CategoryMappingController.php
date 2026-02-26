<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\CategoryPlatformMapping;
use App\Models\StoreMarketplace;
use App\Services\Platforms\CategoryMappingService;
use App\Services\StoreContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CategoryMappingController extends Controller
{
    public function __construct(
        protected CategoryMappingService $categoryMappingService,
        protected StoreContext $storeContext
    ) {}

    /**
     * Get platform mappings for a category.
     */
    public function index(Category $category): JsonResponse
    {
        $store = $this->storeContext->getCurrentStore();

        abort_unless($category->store_id === $store->id, 403);

        $mappings = $category->platformMappings()
            ->with('storeMarketplace')
            ->get()
            ->map(fn (CategoryPlatformMapping $mapping) => [
                'id' => $mapping->id,
                'store_marketplace_id' => $mapping->store_marketplace_id,
                'platform' => $mapping->platform->value,
                'platform_label' => $mapping->platform->label(),
                'marketplace_name' => $mapping->storeMarketplace->name,
                'primary_category_id' => $mapping->primary_category_id,
                'primary_category_name' => $mapping->primary_category_name,
                'secondary_category_id' => $mapping->secondary_category_id,
                'secondary_category_name' => $mapping->secondary_category_name,
                'item_specifics_synced_at' => $mapping->item_specifics_synced_at?->toIso8601String(),
                'field_mappings' => $mapping->field_mappings,
                'default_values' => $mapping->default_values,
            ]);

        return response()->json($mappings);
    }

    /**
     * Create or update a platform mapping for a category.
     */
    public function store(Request $request, Category $category, StoreMarketplace $marketplace): JsonResponse
    {
        $store = $this->storeContext->getCurrentStore();

        abort_unless($category->store_id === $store->id, 403);
        abort_unless($marketplace->store_id === $store->id, 403);

        $validated = $request->validate([
            'primary_category_id' => ['required', 'string', 'max:100'],
            'primary_category_name' => ['required', 'string', 'max:500'],
            'secondary_category_id' => ['nullable', 'string', 'max:100'],
            'secondary_category_name' => ['nullable', 'string', 'max:500'],
            'field_mappings' => ['nullable', 'array'],
            'default_values' => ['nullable', 'array'],
        ]);

        $mapping = $this->categoryMappingService->saveMapping($category, $marketplace, $validated);

        return response()->json([
            'id' => $mapping->id,
            'primary_category_id' => $mapping->primary_category_id,
            'primary_category_name' => $mapping->primary_category_name,
            'secondary_category_id' => $mapping->secondary_category_id,
            'secondary_category_name' => $mapping->secondary_category_name,
        ], 201);
    }

    /**
     * Delete a platform mapping.
     */
    public function destroy(Category $category, CategoryPlatformMapping $mapping): JsonResponse
    {
        $store = $this->storeContext->getCurrentStore();

        abort_unless($category->store_id === $store->id, 403);
        abort_unless($mapping->category_id === $category->id, 403);

        $this->categoryMappingService->deleteMapping($mapping);

        return response()->json(['message' => 'Mapping deleted.']);
    }

    /**
     * Trigger item specifics sync for a mapping.
     */
    public function syncItemSpecifics(Category $category, CategoryPlatformMapping $mapping): JsonResponse
    {
        $store = $this->storeContext->getCurrentStore();

        abort_unless($category->store_id === $store->id, 403);
        abort_unless($mapping->category_id === $category->id, 403);

        $this->categoryMappingService->syncItemSpecifics($mapping);

        return response()->json(['message' => 'Item specifics sync started.']);
    }
}
