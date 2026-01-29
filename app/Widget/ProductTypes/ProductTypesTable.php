<?php

namespace App\Widget\ProductTypes;

use App\Models\Category;
use App\Services\StoreContext;
use App\Widget\Table;
use Illuminate\Database\Eloquent\Builder;

class ProductTypesTable extends Table
{
    protected string $title = 'Product Types';

    protected string $component = 'DataTable';

    protected bool $hasCheckBox = false;

    protected bool $isSearchable = true;

    protected string $noDataMessage = 'No product types found. Product types are leaf categories (categories without children).';

    /**
     * Define the table fields/columns.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fields(): array
    {
        return [
            [
                'key' => 'name',
                'label' => 'Name',
                'sortable' => true,
                'minWidth' => '10rem',
            ],
            [
                'key' => 'full_path',
                'label' => 'Category Path',
                'sortable' => false,
                'minWidth' => '14rem',
            ],
            [
                'key' => 'sku_config',
                'label' => 'SKU Config',
                'sortable' => false,
            ],
            [
                'key' => 'default_bucket',
                'label' => 'Default Bucket',
                'sortable' => false,
            ],
            [
                'key' => 'barcode_attributes',
                'label' => 'Barcode Attributes',
                'sortable' => false,
                'minWidth' => '12rem',
            ],
            [
                'key' => 'products_count',
                'label' => 'Products',
                'sortable' => true,
            ],
            [
                'key' => 'template',
                'label' => 'Template',
                'sortable' => false,
            ],
        ];
    }

    /**
     * Build the query for fetching leaf categories (product types).
     *
     * @param  array<string, mixed>|null  $filter
     */
    protected function query(?array $filter): Builder
    {
        $storeId = data_get($filter, 'store_id') ?? app(StoreContext::class)->getCurrentStoreId();

        $query = Category::query()
            ->with(['template:id,name', 'defaultBucket:id,name', 'parent'])
            ->withCount('products')
            ->where('store_id', $storeId)
            ->whereDoesntHave('children');

        // Apply search filter
        if ($term = data_get($filter, 'term')) {
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', "%{$term}%")
                    ->orWhere('sku_prefix', 'like', "%{$term}%")
                    ->orWhere('sku_suffix', 'like', "%{$term}%");
            });
        }

        return $query;
    }

    /**
     * Get the data for the table.
     *
     * @param  array<string, mixed>|null  $filter
     * @return array<string, mixed>
     */
    public function data(?array $filter): array
    {
        $query = $this->query($filter);

        // Sorting
        $sortBy = data_get($filter, 'sortBy', 'name');
        $sortDirection = data_get($filter, 'sortDirection', 'asc');

        if ($sortBy === 'products_count') {
            $query->orderBy('products_count', $sortDirection);
        } else {
            $query->orderBy($sortBy, $sortDirection);
        }

        // Pagination
        $page = (int) data_get($filter, 'page', 1);
        $perPage = (int) data_get($filter, 'per_page', 15);

        $this->paginatedData = $query->paginate($perPage, ['*'], 'page', $page);

        return [
            'count' => $this->paginatedData->count(),
            'total' => $this->paginatedData->total(),
            'items' => $this->paginatedData->map(fn (Category $category) => $this->formatCategory($category))->toArray(),
        ];
    }

    /**
     * Format a category for display.
     *
     * @return array<string, mixed>
     */
    protected function formatCategory(Category $category): array
    {
        $skuConfig = $this->formatSkuConfig($category);
        $barcodeAttrs = $category->getEffectiveBarcodeAttributes();

        return [
            'id' => [
                'data' => $category->id,
            ],
            'name' => [
                'type' => 'link',
                'href' => "/product-types/{$category->id}/settings",
                'data' => $category->name,
                'class' => 'font-medium',
            ],
            'full_path' => [
                'data' => $category->full_path,
                'class' => 'text-sm text-gray-500',
            ],
            'sku_config' => [
                'data' => $skuConfig ?: '-',
                'class' => 'font-mono text-sm',
            ],
            'default_bucket' => [
                'data' => $category->defaultBucket?->name ?? ($category->getEffectiveDefaultBucket()?->name ? $category->getEffectiveDefaultBucket()->name.' (inherited)' : '-'),
                'class' => 'text-sm',
            ],
            'barcode_attributes' => [
                'data' => implode(', ', array_map(fn ($attr) => ucfirst($attr), $barcodeAttrs)),
                'class' => 'text-sm text-gray-500',
            ],
            'products_count' => [
                'data' => $category->products_count,
                'class' => 'text-center',
            ],
            'template' => [
                'data' => $category->template?->name ?? ($category->getEffectiveTemplate()?->name ? $category->getEffectiveTemplate()->name.' (inherited)' : '-'),
                'class' => 'text-sm',
            ],
        ];
    }

    /**
     * Format the SKU configuration string.
     */
    protected function formatSkuConfig(Category $category): string
    {
        $parts = [];

        $prefix = $category->sku_prefix ?? $category->getEffectiveSkuPrefix();
        $suffix = $category->sku_suffix ?? $category->getEffectiveSkuSuffix();

        if ($prefix) {
            $parts[] = $prefix.'...';
        }

        if ($suffix) {
            $parts[] = '...'.$suffix;
        }

        if (empty($parts) && $category->sku_format) {
            return 'Custom format';
        }

        return implode(' / ', $parts);
    }

    /**
     * @param  array<string, mixed>|null  $filter
     */
    public function title(?array $filter): string
    {
        return 'Product Types';
    }
}
