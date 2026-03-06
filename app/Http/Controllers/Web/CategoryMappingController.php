<?php

namespace App\Http\Controllers\Web;

use App\Enums\Platform;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\CategoryPlatformMapping;
use App\Models\EbayCategory;
use App\Models\ShopifyMetafieldDefinition;
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

        // Resolve internal ebay_categories ID for the frontend
        $ebayInternalId = EbayCategory::where('ebay_category_id', $mapping->primary_category_id)->value('id');

        return response()->json([
            'id' => $mapping->id,
            'primary_category_id' => $mapping->primary_category_id,
            'primary_category_name' => $mapping->primary_category_name,
            'secondary_category_id' => $mapping->secondary_category_id,
            'secondary_category_name' => $mapping->secondary_category_name,
            'ebay_category_internal_id' => $ebayInternalId,
        ], 201);
    }

    /**
     * Update field mappings and default values for an existing mapping.
     */
    public function update(Request $request, Category $category, CategoryPlatformMapping $mapping): JsonResponse
    {
        $store = $this->storeContext->getCurrentStore();

        abort_unless($category->store_id === $store->id, 403);
        abort_unless($mapping->category_id === $category->id, 403);

        $validated = $request->validate([
            'field_mappings' => ['nullable', 'array'],
            'default_values' => ['nullable', 'array'],
            'metadata' => ['nullable', 'array'],
        ]);

        $updateData = [
            'field_mappings' => $validated['field_mappings'] ?? [],
            'default_values' => $validated['default_values'] ?? [],
        ];

        if (array_key_exists('metadata', $validated)) {
            $updateData['metadata'] = $validated['metadata'];
        }

        $mapping->update($updateData);

        return response()->json([
            'id' => $mapping->id,
            'field_mappings' => $mapping->field_mappings,
            'default_values' => $mapping->default_values,
            'metadata' => $mapping->metadata,
        ]);
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
     * Get Shopify metafield definitions for a category mapping.
     */
    public function shopifyMetafields(Category $category, CategoryPlatformMapping $mapping): JsonResponse
    {
        $store = $this->storeContext->getCurrentStore();

        abort_unless($category->store_id === $store->id, 403);
        abort_unless($mapping->category_id === $category->id, 403);
        abort_unless($mapping->platform === Platform::Shopify, 404);

        $definitions = ShopifyMetafieldDefinition::where('store_marketplace_id', $mapping->store_marketplace_id)
            ->orderBy('name')
            ->get();

        $enabledMetafields = $mapping->getEnabledMetafields();
        $metafieldMappings = $mapping->getMetafieldMappings();

        $definitionsData = $definitions->map(function (ShopifyMetafieldDefinition $def) use ($enabledMetafields, $metafieldMappings) {
            $fullKey = $def->namespace.'.'.$def->key;

            return [
                'id' => $def->id,
                'name' => $def->name,
                'key' => $def->key,
                'namespace' => $def->namespace,
                'type' => $def->type,
                'description' => $def->description,
                'enabled' => empty($enabledMetafields) || in_array($fullKey, $enabledMetafields),
                'mapped_template_field' => $metafieldMappings[$fullKey] ?? null,
            ];
        })->toArray();

        return response()->json([
            'definitions' => $definitionsData,
            'has_definitions' => $definitions->isNotEmpty(),
        ]);
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
