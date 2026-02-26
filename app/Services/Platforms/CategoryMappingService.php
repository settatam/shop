<?php

namespace App\Services\Platforms;

use App\Jobs\SyncPlatformCategoryItemSpecificsJob;
use App\Models\Category;
use App\Models\CategoryPlatformMapping;
use App\Models\Product;
use App\Models\StoreMarketplace;

class CategoryMappingService
{
    /**
     * Create or update a category-to-platform mapping.
     *
     * @param  array{primary_category_id: string, primary_category_name: string, secondary_category_id?: string|null, secondary_category_name?: string|null, field_mappings?: array<string, string>|null, default_values?: array<string, mixed>|null, metadata?: array<string, mixed>|null}  $data
     */
    public function saveMapping(Category $category, StoreMarketplace $marketplace, array $data): CategoryPlatformMapping
    {
        $mapping = CategoryPlatformMapping::updateOrCreate(
            [
                'category_id' => $category->id,
                'store_marketplace_id' => $marketplace->id,
            ],
            [
                'store_id' => $category->store_id,
                'platform' => $marketplace->platform,
                'primary_category_id' => $data['primary_category_id'],
                'primary_category_name' => $data['primary_category_name'],
                'secondary_category_id' => $data['secondary_category_id'] ?? null,
                'secondary_category_name' => $data['secondary_category_name'] ?? null,
                'field_mappings' => $data['field_mappings'] ?? null,
                'default_values' => $data['default_values'] ?? null,
                'metadata' => $data['metadata'] ?? null,
            ]
        );

        // Trigger item specifics sync for the mapped category
        if ($mapping->needsItemSpecificsSync()) {
            SyncPlatformCategoryItemSpecificsJob::dispatch($mapping);
        }

        return $mapping;
    }

    /**
     * Delete a category-to-platform mapping.
     */
    public function deleteMapping(CategoryPlatformMapping $mapping): void
    {
        $mapping->delete();
    }

    /**
     * Resolve the platform category for a product at publish time.
     * Checks: product listing override → category mapping → default.
     *
     * @return array{primary_category_id: string|null, secondary_category_id: string|null, mapping: CategoryPlatformMapping|null}
     */
    public function resolveCategory(Product $product, StoreMarketplace $marketplace): array
    {
        $result = [
            'primary_category_id' => null,
            'secondary_category_id' => null,
            'mapping' => null,
        ];

        if (! $product->category_id) {
            return $result;
        }

        // Look for a direct mapping for this product's category
        $mapping = CategoryPlatformMapping::where('category_id', $product->category_id)
            ->where('store_marketplace_id', $marketplace->id)
            ->first();

        if ($mapping) {
            $result['primary_category_id'] = $mapping->primary_category_id;
            $result['secondary_category_id'] = $mapping->secondary_category_id;
            $result['mapping'] = $mapping;

            return $result;
        }

        // Walk up the category tree to find a parent mapping
        $category = $product->category;
        while ($category && $category->parent_id) {
            $category = $category->parent;
            if (! $category) {
                break;
            }

            $mapping = CategoryPlatformMapping::where('category_id', $category->id)
                ->where('store_marketplace_id', $marketplace->id)
                ->first();

            if ($mapping) {
                $result['primary_category_id'] = $mapping->primary_category_id;
                $result['secondary_category_id'] = $mapping->secondary_category_id;
                $result['mapping'] = $mapping;

                return $result;
            }
        }

        return $result;
    }

    /**
     * Get all mappings for a store and marketplace.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, CategoryPlatformMapping>
     */
    public function getMappings(int $storeId, StoreMarketplace $marketplace): \Illuminate\Database\Eloquent\Collection
    {
        return CategoryPlatformMapping::where('store_id', $storeId)
            ->where('store_marketplace_id', $marketplace->id)
            ->with('category')
            ->orderBy('created_at')
            ->get();
    }

    /**
     * Trigger item specifics sync for a mapping.
     */
    public function syncItemSpecifics(CategoryPlatformMapping $mapping): void
    {
        SyncPlatformCategoryItemSpecificsJob::dispatch($mapping);
    }
}
