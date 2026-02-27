<?php

namespace App\Widget\Products;

use App\Models\Brand;
use App\Models\Category;
use App\Models\PlatformListing;
use App\Models\Product;
use App\Models\ProductTemplateField;
use App\Models\SalesChannel;
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
     * All active sales channels for the current store.
     *
     * @var \Illuminate\Support\Collection<int, \App\Models\SalesChannel>|null
     */
    protected ?\Illuminate\Support\Collection $activeSalesChannels = null;

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
                'key' => 'cost',
                'label' => 'Cost',
                'sortable' => true,
            ],
            [
                'key' => 'marketplaces',
                'label' => 'Marketplaces',
                'sortable' => false,
                'minWidth' => '10rem',
            ],
            [
                'key' => 'quantity',
                'label' => 'Qty',
                'sortable' => true,
            ],
            [
                'key' => 'product_type',
                'label' => 'Type',
                'sortable' => true,
            ],
            [
                'key' => 'vendor',
                'label' => 'Vendor',
                'sortable' => true,
            ],
            [
                'key' => 'date_of_purchase',
                'label' => 'Date of Purchase',
                'sortable' => true,
            ],
            [
                'key' => 'created_at',
                'label' => 'Date Entered',
                'sortable' => true,
            ],
        ];
    }

    /**
     * Product IDs from Scout search (if applicable).
     *
     * @var array<int>|null
     */
    protected ?array $scoutProductIds = null;

    /**
     * Build the query for fetching products.
     *
     * @param  array<string, mixed>|null  $filter
     */
    protected function query(?array $filter): Builder
    {
        $storeId = data_get($filter, 'store_id') ?? app(StoreContext::class)->getCurrentStoreId();

        // Use Scout search if a search term is provided
        if ($term = data_get($filter, 'term')) {
            $this->scoutProductIds = $this->searchWithScout($term, $storeId);

            // If Scout search returns no results, return empty query
            if (empty($this->scoutProductIds)) {
                return Product::query()
                    ->with(['category', 'brand', 'images', 'variants', 'tags', 'platformListings.marketplace', 'platformListings.salesChannel', 'platformOverrides', 'vendor'])
                    ->whereRaw('1 = 0'); // Return empty result set
            }

            $query = Product::query()
                ->with(['category', 'brand', 'images', 'variants', 'tags', 'platformListings.marketplace', 'platformListings.salesChannel', 'platformOverrides', 'vendor'])
                ->whereIn('id', $this->scoutProductIds)
                ->where('store_id', $storeId);
        } else {
            $query = Product::query()
                ->with(['category', 'brand', 'images', 'variants', 'tags', 'platformListings.marketplace', 'platformListings.salesChannel', 'platformOverrides', 'vendor'])
                ->where('store_id', $storeId);
        }

        // Apply date range filter
        if ($fromDate = data_get($filter, 'from_date')) {
            $query->whereDate('created_at', '>=', $fromDate);
        }
        if ($toDate = data_get($filter, 'to_date')) {
            $query->whereDate('created_at', '<=', $toDate);
        }

        // Apply category filter (includes children for hierarchical filtering)
        if ($categoryId = data_get($filter, 'category_id')) {
            // Get the category and all its descendants
            $categoryIds = [$categoryId];
            $childIds = Category::where('parent_id', $categoryId)->pluck('id')->toArray();
            if (! empty($childIds)) {
                $categoryIds = array_merge($categoryIds, $childIds);
                // Also get grandchildren
                $grandchildIds = Category::whereIn('parent_id', $childIds)->pluck('id')->toArray();
                $categoryIds = array_merge($categoryIds, $grandchildIds);
            }
            $query->whereIn('category_id', $categoryIds);
        }

        // Apply uncategorized filter (products with no category)
        if (data_get($filter, 'uncategorized')) {
            $query->whereNull('category_id');
        }

        // Apply brand filter (from attribute values)
        if ($brand = data_get($filter, 'brand')) {
            // Get brand-type field IDs for this store
            $brandFieldIds = ProductTemplateField::whereHas('template', fn ($q) => $q->where('store_id', $storeId))
                ->where('type', ProductTemplateField::TYPE_BRAND)
                ->pluck('id');

            if ($brandFieldIds->isNotEmpty()) {
                $query->whereHas('attributeValues', fn ($q) => $q->whereIn('product_template_field_id', $brandFieldIds)
                    ->where('value', $brand)
                );
            }
        }

        // Apply type filter (jewelry_type)
        if ($type = data_get($filter, 'type')) {
            // $query->where('jewelry_type', $type);
        }

        // Apply status filter
        if ($status = data_get($filter, 'status')) {
            $query->where('status', $status);
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
        $storeId = data_get($filter, 'store_id') ?? app(StoreContext::class)->getCurrentStoreId();

        // Load all active sales channels for the store
        $this->activeSalesChannels = SalesChannel::where('store_id', $storeId)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        $query = $this->query($filter);

        // Sorting
        $sortBy = data_get($filter, 'sortBy', 'id');
        $sortDirection = data_get($filter, 'sortDirection', 'desc');

        // If we have Scout search results and no explicit sort requested, preserve relevance order
        $hasSearchTerm = ! empty(data_get($filter, 'term'));
        $hasExplicitSort = $filter && array_key_exists('sortBy', $filter);

        if ($hasSearchTerm && ! $hasExplicitSort && ! empty($this->scoutProductIds)) {
            // Preserve Scout relevance order using FIELD()
            $ids = implode(',', $this->scoutProductIds);
            $query->orderByRaw("FIELD(id, {$ids})");
        } elseif ($sortBy === 'category') {
            // Handle special sort columns
            $query->leftJoin('categories', 'products.category_id', '=', 'categories.id')
                ->orderBy('categories.name', $sortDirection)
                ->select('products.*');
        } elseif ($sortBy === 'price') {
            $query->orderByRaw('(SELECT MIN(price) FROM product_variants WHERE product_variants.product_id = products.id) '.$sortDirection);
        } elseif ($sortBy === 'marketplaces') {
            // Sort by number of active platform listings
            $query->withCount(['platformListings' => fn ($q) => $q->where('status', 'active')])
                ->orderBy('platform_listings_count', $sortDirection);
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
        $defaultVariant = $product->variants->first();
        $cost = $defaultVariant?->cost ?? 0;

        return [
            'id' => [
                'data' => $product->id,
            ],
            'image' => [
                'type' => 'image',
                'data' => $primaryImage?->url ?? null,
                'alt' => $product->title,
                'class' => 'size-16 object-cover rounded',
            ],
            'sku' => [
                'type' => 'link',
                'href' => "/products/{$product->id}",
                'data' => $defaultVariant?->sku ?? "SKU-{$product->id}",
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
            'cost' => [
                'type' => 'currency',
                'data' => $cost,
                'currency' => $product->currency_code ?? 'USD',
            ],
            'marketplaces' => [
                'type' => 'marketplace_prices',
                'data' => $this->getChannelPricesForProduct($product, $defaultVariant),
                'product_id' => $product->id,
            ],
            'quantity' => [
                'data' => $product->total_quantity,
                'class' => 'text-center',
            ],
            'product_type' => [
                'data' => $product->category?->name ?? '-',
            ],
            'vendor' => [
                'data' => $product->vendor?->name ?? '-',
            ],
            'date_of_purchase' => [
                'data' => $product->date_of_purchase ? Carbon::parse($product->date_of_purchase)->format('M d, Y') : '-',
                'class' => 'text-sm text-gray-500',
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
                'key' => 'activate',
                'label' => 'Set Active',
                'icon' => 'CheckCircleIcon',
                'variant' => 'success',
            ],
            [
                'key' => 'archive',
                'label' => 'Archive Selected',
                'icon' => 'ArchiveBoxIcon',
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

        // Get brands from attribute values (cached)
        $brands = cache()->remember("store_{$storeId}_brand_options", now()->addHour(), function () use ($storeId) {
            $brandFieldIds = ProductTemplateField::whereHas('template', fn ($q) => $q->where('store_id', $storeId))
                ->where('type', ProductTemplateField::TYPE_BRAND)
                ->pluck('id');

            if ($brandFieldIds->isEmpty()) {
                return [];
            }

            return ProductAttributeValue::whereIn('product_template_field_id', $brandFieldIds)
                ->whereHas('product', fn ($q) => $q->where('store_id', $storeId))
                ->whereNotNull('value')
                ->where('value', '!=', '')
                ->distinct()
                ->pluck('value')
                ->filter()
                ->unique()
                ->sort()
                ->values()
                ->map(fn ($brand) => [
                    'value' => $brand,
                    'label' => $brand,
                ])
                ->toArray();
        });

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
                    ['value' => Product::STATUS_DRAFT, 'label' => 'Draft'],
                    ['value' => Product::STATUS_ACTIVE, 'label' => 'Active'],
                    ['value' => Product::STATUS_ARCHIVE, 'label' => 'Archive'],
                    ['value' => Product::STATUS_SOLD, 'label' => 'Sold'],
                    ['value' => Product::STATUS_IN_MEMO, 'label' => 'In Memo'],
                    ['value' => Product::STATUS_IN_REPAIR, 'label' => 'In Repair'],
                ],
                'stock_options' => [
                    ['value' => 'in_stock', 'label' => 'In Stock'],
                    ['value' => 'out_of_stock', 'label' => 'Out of Stock'],
                ],
            ],
        ];
    }

    /**
     * Get all active channels with prices for a product.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function getChannelPricesForProduct(Product $product, ?\App\Models\ProductVariant $defaultVariant): array
    {
        if (! $this->activeSalesChannels) {
            return [];
        }

        $defaultPrice = $defaultVariant?->price ?? 0;
        $listings = $product->platformListings->keyBy('sales_channel_id');
        $overrides = $product->platformOverrides->keyBy('store_marketplace_id');

        return $this->activeSalesChannels->map(function ($channel) use ($listings, $overrides, $defaultPrice) {
            $listing = $listings->get($channel->id);
            $override = $channel->store_marketplace_id ? $overrides->get($channel->store_marketplace_id) : null;

            // Priority: listing price > override price > default price
            $price = $listing?->platform_price ?? $override?->price ?? $defaultPrice;

            // Status: use listing status if exists, otherwise 'draft' (unlisted)
            $status = $listing?->status ?? 'draft';

            return [
                'id' => $listing?->id,
                'channel_id' => $channel->id,
                'platform' => $channel->is_local ? $channel->code : $channel->type,
                'name' => $channel->name,
                'price' => $price,
                'status' => $status,
                'is_local' => $channel->is_local,
                'is_listed' => $listing !== null && ($listing->isListed() || $listing->status === PlatformListing::STATUS_PENDING),
            ];
        })->values()->toArray();
    }

    /**
     * Search products using Scout (Meilisearch).
     *
     * @return array<int>
     */
    protected function searchWithScout(string $term, int $storeId): array
    {
        try {
            // Use Scout search with store_id filter
            $results = Product::search($term)
                ->where('store_id', $storeId)
                ->take(1000) // Reasonable limit for search results
                ->get();

            return $results->pluck('id')->toArray();
        } catch (\Exception $e) {
            // Fallback to SQL search if Scout fails
            \Illuminate\Support\Facades\Log::warning('Scout search failed, falling back to SQL', [
                'error' => $e->getMessage(),
                'term' => $term,
            ]);

            return Product::query()
                ->where('store_id', $storeId)
                ->where(function ($q) use ($term) {
                    $q->where('title', 'like', "%{$term}%")
                        ->orWhere('handle', 'like', "%{$term}%")
                        ->orWhere('description', 'like', "%{$term}%")
                        ->orWhere('upc', 'like', "%{$term}%")
                        ->orWhereHas('variants', fn ($vq) => $vq->where('sku', 'like', "%{$term}%"));
                })
                ->pluck('id')
                ->toArray();
        }
    }
}
