<?php

namespace App\Widget\Products;

use App\Models\Product;
use App\Services\StoreContext;
use App\Widget\Table;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

class ProductsTable extends Table
{
    protected string $title = 'Products';

    protected string $component = 'DataTable';

    protected bool $hasCheckBox = true;

    protected bool $isSearchable = true;

    protected string $noDataMessage = 'No products found. Create your first product to get started.';

    /**
     * Define the table fields/columns.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fields(): array
    {
        return [
            [
                'key' => 'image',
                'label' => 'Image',
                'sortable' => false,
            ],
            [
                'key' => 'sku',
                'label' => 'SKU',
                'sortable' => true,
            ],
            [
                'key' => 'title',
                'label' => 'Product Title',
                'sortable' => true,
            ],
            [
                'key' => 'category',
                'label' => 'Category',
                'sortable' => false,
            ],
            [
                'key' => 'price',
                'label' => 'Price',
                'sortable' => true,
            ],
            [
                'key' => 'quantity',
                'label' => 'Qty',
                'sortable' => true,
            ],
            [
                'key' => 'status',
                'label' => 'Status',
                'sortable' => true,
            ],
            [
                'key' => 'created_at',
                'label' => 'Created',
                'sortable' => true,
            ],
        ];
    }

    /**
     * Build the query for fetching products.
     *
     * @param  array<string, mixed>|null  $filter
     */
    protected function query(?array $filter): Builder
    {
        $storeId = data_get($filter, 'store_id') ?? app(StoreContext::class)->getCurrentStoreId();

        $query = Product::query()
            ->with(['category', 'brand', 'images', 'variants'])
            ->where('store_id', $storeId);

        // Apply search filter
        if ($term = data_get($filter, 'term')) {
            $query->where(function ($q) use ($term) {
                $q->where('title', 'like', "%{$term}%")
                    ->orWhere('handle', 'like', "%{$term}%")
                    ->orWhere('description', 'like', "%{$term}%")
                    ->orWhere('upc', 'like', "%{$term}%")
                    ->orWhere('sku', 'like', "%{$term}%");
            });
        }

        // Apply category filter
        if ($categoryId = data_get($filter, 'category_id')) {
            $query->where('category_id', $categoryId);
        }

        // Apply brand filter
        if ($brandId = data_get($filter, 'brand_id')) {
            $query->where('brand_id', $brandId);
        }

        // Apply status filter
        if ($status = data_get($filter, 'status')) {
            if ($status === 'published') {
                $query->where('is_published', true);
            } elseif ($status === 'draft') {
                $query->where('is_draft', true);
            }
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
        $sortBy = data_get($filter, 'sortBy', 'id');
        $sortDirection = data_get($filter, 'sortDirection', 'desc');

        // Handle special sort columns
        if ($sortBy === 'category') {
            $query->leftJoin('categories', 'products.category_id', '=', 'categories.id')
                ->orderBy('categories.name', $sortDirection)
                ->select('products.*');
        } elseif ($sortBy === 'price') {
            $query->orderByRaw('(SELECT MIN(price) FROM product_variants WHERE product_variants.product_id = products.id) '.$sortDirection);
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
            'items' => $this->paginatedData->map(fn (Product $product) => $this->formatProduct($product))->toArray(),
        ];
    }

    /**
     * Format a product for display.
     *
     * @return array<string, mixed>
     */
    protected function formatProduct(Product $product): array
    {
        $primaryImage = $product->images->firstWhere('is_primary', true) ?? $product->images->first();
        $price = $product->has_variants
            ? $product->variants->min('price')
            : ($product->variants->first()?->price ?? 0);

        return [
            'id' => [
                'data' => $product->id,
            ],
            'image' => [
                'type' => 'image',
                'data' => $primaryImage?->url ?? null,
                'alt' => $product->title,
                'class' => 'w-12 h-12 object-cover rounded',
            ],
            'sku' => [
                'type' => 'link',
                'href' => "/products/{$product->id}",
                'data' => $product->handle ?? "PRD-{$product->id}",
                'class' => 'font-mono text-sm',
            ],
            'title' => [
                'type' => 'link',
                'href' => "/products/{$product->id}",
                'data' => $product->title,
                'class' => 'font-medium',
            ],
            'category' => [
                'data' => $product->category?->name ?? '-',
            ],
            'price' => [
                'type' => 'currency',
                'data' => $price,
                'currency' => $product->currency_code ?? 'USD',
            ],
            'quantity' => [
                'data' => $product->total_quantity,
                'class' => 'text-center',
            ],
            'status' => [
                'type' => 'badge',
                'data' => $product->is_published ? 'Published' : ($product->is_draft ? 'Draft' : 'Inactive'),
                'variant' => $product->is_published ? 'success' : ($product->is_draft ? 'warning' : 'secondary'),
            ],
            'created_at' => [
                'data' => Carbon::parse($product->created_at)->format('M d, Y'),
                'class' => 'text-sm text-gray-500',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $filter
     */
    public function title(?array $filter): string
    {
        return 'Products';
    }

    /**
     * @param  array<string, mixed>|null  $filter
     * @param  array<string, mixed>  $filteredData
     */
    public function exportable(?array $filter, array $filteredData): bool
    {
        return true;
    }

    /**
     * Get bulk actions available for the table.
     *
     * @param  array<string, mixed>|null  $filter
     * @return array<mixed>
     */
    public function actions(?array $filter): array
    {
        return [
            [
                'key' => 'mass_edit',
                'label' => 'Edit Selected',
                'icon' => 'PencilSquareIcon',
                'variant' => 'default',
                'type' => 'modal',
            ],
            [
                'key' => 'delete',
                'label' => 'Delete Selected',
                'icon' => 'TrashIcon',
                'variant' => 'danger',
                'confirm' => 'Are you sure you want to delete the selected products?',
            ],
            [
                'key' => 'publish',
                'label' => 'Publish Selected',
                'icon' => 'CheckCircleIcon',
                'variant' => 'success',
            ],
            [
                'key' => 'unpublish',
                'label' => 'Unpublish Selected',
                'icon' => 'XCircleIcon',
                'variant' => 'warning',
            ],
        ];
    }
}
