<?php

namespace App\Widget\Products;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductTemplateField;
use App\Models\StoreMarketplace;
use App\Models\Tag;
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
                'minWidth' => '12rem',
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
            ->with(['category', 'brand', 'images', 'variants', 'tags'])
            ->where('store_id', $storeId);

        // Apply search filter
        if ($term = data_get($filter, 'term')) {
            $query->where(function ($q) use ($term) {
                $q->where('title', 'like', "%{$term}%")
                    ->orWhere('handle', 'like', "%{$term}%")
                    ->orWhere('description', 'like', "%{$term}%")
                    ->orWhere('upc', 'like', "%{$term}%")
                    ->orWhereHas('variants', fn ($vq) => $vq->where('sku', 'like', "%{$term}%"));
            });
        }

        // Apply date range filter
        if ($fromDate = data_get($filter, 'from_date')) {
            $query->whereDate('created_at', '>=', $fromDate);
        }
        if ($toDate = data_get($filter, 'to_date')) {
            $query->whereDate('created_at', '<=', $toDate);
        }

        // Apply category filter
        if ($categoryId = data_get($filter, 'category_id')) {
            $query->where('category_id', $categoryId);
        }

        // Apply brand filter
        if ($brandId = data_get($filter, 'brand_id')) {
            $query->where('brand_id', $brandId);
        }

        // Apply type filter (jewelry_type)
        if ($type = data_get($filter, 'type')) {
            // $query->where('jewelry_type', $type);
        }

        // Apply status filter
        if ($status = data_get($filter, 'status')) {
            if ($status === 'published') {
                $query->where('is_published', true);
            } elseif ($status === 'draft') {
                $query->where('is_draft', true);
            } elseif ($status === 'inactive') {
                $query->where('is_published', false)->where(function ($q) {
                    $q->where('is_draft', false)->orWhereNull('is_draft');
                });
            }
        }

        // Apply stock filter
        if ($stock = data_get($filter, 'stock')) {
            if ($stock === 'in_stock') {
                $query->whereHas('variants', fn ($q) => $q->where('quantity', '>', 0));
            } elseif ($stock === 'out_of_stock') {
                $query->where(function ($q) {
                    $q->whereDoesntHave('variants')
                        ->orWhereHas('variants', fn ($vq) => $vq->havingRaw('SUM(quantity) = 0'));
                });
            }
        }

        // Apply tags filter
        if ($tagIds = data_get($filter, 'tag_ids')) {
            $tagArray = is_array($tagIds) ? $tagIds : explode(',', $tagIds);
            $query->whereHas('tags', fn ($q) => $q->whereIn('tags.id', $tagArray));
        }

        // Apply listed in (marketplace) filter
        if ($marketplaceId = data_get($filter, 'marketplace_id')) {
            $query->whereHas('platformListings', fn ($q) => $q->where('store_marketplace_id', $marketplaceId));
        }

        // Apply price range filter (min/max from variants)
        if ($minPrice = data_get($filter, 'min_price')) {
            $query->whereHas('variants', fn ($q) => $q->where('price', '>=', $minPrice));
        }
        if ($maxPrice = data_get($filter, 'max_price')) {
            $query->whereHas('variants', fn ($q) => $q->where('price', '<=', $maxPrice));
        }

        // Apply cost range filter (min/max from variants)
        if ($minCost = data_get($filter, 'min_cost')) {
            $query->whereHas('variants', fn ($q) => $q->where('cost', '>=', $minCost));
        }
        if ($maxCost = data_get($filter, 'max_cost')) {
            $query->whereHas('variants', fn ($q) => $q->where('cost', '<=', $maxCost));
        }

        // Apply stone shape filter (from product column or attribute value)
        if ($stoneShape = data_get($filter, 'stone_shape')) {
            $query->where(function ($q) use ($stoneShape) {
                $q->where('main_stone_type', $stoneShape)
                    ->orWhereHas('attributeValues', function ($avq) use ($stoneShape) {
                        $avq->where('value', $stoneShape)
                            ->whereHas('field', fn ($fq) => $fq->where('name', 'like', '%stone_shape%')
                                ->orWhere('name', 'like', '%shape%'));
                    });
            });
        }

        // Apply stone weight filter
        if ($minStoneWeight = data_get($filter, 'min_stone_weight')) {
            $query->where(function ($q) use ($minStoneWeight) {
                $q->where('total_carat_weight', '>=', $minStoneWeight)
                    ->orWhereHas('attributeValues', function ($avq) use ($minStoneWeight) {
                        $avq->whereRaw('CAST(value AS DECIMAL(10,2)) >= ?', [$minStoneWeight])
                            ->whereHas('field', fn ($fq) => $fq->where('name', 'like', '%stone_weight%')
                                ->orWhere('name', 'like', '%carat%')
                                ->orWhere('name', 'like', '%total_stone_wt%'));
                    });
            });
        }
        if ($maxStoneWeight = data_get($filter, 'max_stone_weight')) {
            $query->where(function ($q) use ($maxStoneWeight) {
                $q->where('total_carat_weight', '<=', $maxStoneWeight)
                    ->orWhereHas('attributeValues', function ($avq) use ($maxStoneWeight) {
                        $avq->whereRaw('CAST(value AS DECIMAL(10,2)) <= ?', [$maxStoneWeight])
                            ->whereHas('field', fn ($fq) => $fq->where('name', 'like', '%stone_weight%')
                                ->orWhere('name', 'like', '%carat%')
                                ->orWhere('name', 'like', '%total_stone_wt%'));
                    });
            });
        }

        // Apply ring size filter
        if ($ringSize = data_get($filter, 'ring_size')) {
            $query->where(function ($q) use ($ringSize) {
                $q->where('ring_size', $ringSize)
                    ->orWhereHas('attributeValues', function ($avq) use ($ringSize) {
                        $avq->where('value', $ringSize)
                            ->whereHas('field', fn ($fq) => $fq->where('name', 'like', '%ring_size%')
                                ->orWhere('name', 'like', '%size%'));
                    });
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
                'data' => $product->variants->first()?->sku ?? "SKU-{$product->id}",
                'class' => 'font-mono text-sm',
            ],
            'title' => [
                'type' => 'link',
                'href' => "/products/{$product->id}",
                'data' => $product->title,
                'class' => 'font-medium',
                'tags' => $product->tags->map(fn ($tag) => [
                    'id' => $tag->id,
                    'name' => $tag->name,
                    'color' => $tag->color,
                ])->toArray(),
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

    /**
     * Build available filters for the table.
     *
     * @param  array<string, mixed>|null  $filter
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    public function buildFilters(?array $filter, array $data): ?array
    {
        $storeId = data_get($filter, 'store_id') ?: app(StoreContext::class)->getCurrentStoreId();

        // Get categories
        $categories = Category::where('store_id', $storeId)
            ->orderBy('name')
            ->get(['id', 'name', 'parent_id'])
            ->map(fn ($category) => [
                'value' => (string) $category->id,
                'label' => $category->name,
            ])
            ->toArray();

        // Get brands
        $brands = Brand::where('store_id', $storeId)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($brand) => [
                'value' => (string) $brand->id,
                'label' => $brand->name,
            ])
            ->toArray();

        // Get tags
        $tags = Tag::where('store_id', $storeId)
            ->orderBy('name')
            ->get(['id', 'name', 'color'])
            ->map(fn ($tag) => [
                'value' => (string) $tag->id,
                'label' => $tag->name,
                'color' => $tag->color,
            ])
            ->toArray();

        // Get marketplaces (Listed In)
        $marketplaces = StoreMarketplace::where('store_id', $storeId)
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name', 'platform'])
            ->map(fn ($marketplace) => [
                'value' => (string) $marketplace->id,
                'label' => $marketplace->name ?: ucfirst($marketplace->platform),
            ])
            ->toArray();

        // Get distinct jewelry types for the Type filter
        $jewelryTypes = Product::where('store_id', $storeId)
            ->whereNotNull('jewelry_type')
            ->distinct()
            ->pluck('jewelry_type')
            ->filter()
            ->map(fn ($type) => [
                'value' => $type,
                'label' => ucfirst(str_replace('_', ' ', $type)),
            ])
            ->values()
            ->toArray();

        // Get stone shapes from attribute values
        $stoneShapeFieldIds = ProductTemplateField::whereHas('template', fn ($q) => $q->where('store_id', $storeId))
            ->where(fn ($q) => $q->where('name', 'like', '%stone_shape%')
                ->orWhere('name', 'like', '%shape%'))
            ->pluck('id');

        $stoneShapes = collect();
        if ($stoneShapeFieldIds->isNotEmpty()) {
            $stoneShapes = \App\Models\ProductAttributeValue::whereIn('product_template_field_id', $stoneShapeFieldIds)
                ->whereHas('product', fn ($q) => $q->where('store_id', $storeId))
                ->distinct()
                ->pluck('value')
                ->filter()
                ->map(fn ($shape) => [
                    'value' => $shape,
                    'label' => ucfirst($shape),
                ])
                ->values();
        }

        // Also include main_stone_type values
        $mainStoneTypes = Product::where('store_id', $storeId)
            ->whereNotNull('main_stone_type')
            ->distinct()
            ->pluck('main_stone_type')
            ->filter()
            ->map(fn ($type) => [
                'value' => $type,
                'label' => ucfirst(str_replace('_', ' ', $type)),
            ]);

        $allStoneShapes = $stoneShapes->merge($mainStoneTypes)->unique('value')->values()->toArray();

        // Get ring sizes from attribute values and products
        $ringSizeFieldIds = ProductTemplateField::whereHas('template', fn ($q) => $q->where('store_id', $storeId))
            ->where(fn ($q) => $q->where('name', 'like', '%ring_size%')
                ->orWhere('name', 'like', '%size%'))
            ->pluck('id');

        $ringSizes = collect();
        if ($ringSizeFieldIds->isNotEmpty()) {
            $ringSizes = \App\Models\ProductAttributeValue::whereIn('product_template_field_id', $ringSizeFieldIds)
                ->whereHas('product', fn ($q) => $q->where('store_id', $storeId))
                ->distinct()
                ->pluck('value')
                ->filter()
                ->map(fn ($size) => [
                    'value' => $size,
                    'label' => $size,
                ]);
        }

        // Also include ring_size column values
        $productRingSizes = Product::where('store_id', $storeId)
            ->whereNotNull('ring_size')
            ->distinct()
            ->pluck('ring_size')
            ->filter()
            ->map(fn ($size) => [
                'value' => (string) $size,
                'label' => (string) $size,
            ]);

        $allRingSizes = $ringSizes->merge($productRingSizes)
            ->unique('value')
            ->sortBy(fn ($item) => is_numeric($item['value']) ? (float) $item['value'] : $item['value'])
            ->values()
            ->toArray();

        return [
            'current' => $filter,
            'available' => [
                'categories' => $categories,
                'brands' => $brands,
                'tags' => $tags,
                'marketplaces' => $marketplaces,
                'types' => $jewelryTypes,
                'stone_shapes' => $allStoneShapes,
                'ring_sizes' => $allRingSizes,
                'statuses' => [
                    ['value' => 'published', 'label' => 'Published'],
                    ['value' => 'draft', 'label' => 'Draft'],
                    ['value' => 'inactive', 'label' => 'Inactive'],
                ],
                'stock_options' => [
                    ['value' => 'in_stock', 'label' => 'In Stock'],
                    ['value' => 'out_of_stock', 'label' => 'Out of Stock'],
                ],
            ],
        ];
    }
}
