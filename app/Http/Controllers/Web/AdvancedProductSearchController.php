<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\TransactionItem;
use App\Services\StoreContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AdvancedProductSearchController extends Controller
{
    public function __construct(protected StoreContext $storeContext) {}

    /**
     * Display the advanced search page.
     */
    public function index(): Response
    {
        $storeId = $this->storeContext->getCurrentStoreId();

        $brands = Brand::where('store_id', $storeId)
            ->orderBy('name')
            ->get(['id', 'name']);

        return Inertia::render('products/AdvancedSearch', [
            'brands' => $brands,
        ]);
    }

    /**
     * Search with pagination for the full page.
     */
    public function searchPaginated(Request $request): JsonResponse
    {
        $request->validate([
            'query' => ['nullable', 'string', 'max:255'],
            'tab' => ['sometimes', 'string', 'in:active,bought,sold'],
            'brand_id' => ['sometimes', 'nullable', 'integer'],
            'per_page' => ['sometimes', 'integer', 'min:10', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $storeId = $this->storeContext->getCurrentStoreId();
        $query = $request->input('query', '');
        $tab = $request->input('tab', 'active');
        $brandId = $request->input('brand_id');
        $perPage = $request->input('per_page', 25);

        $results = match ($tab) {
            'active' => $this->searchProductsPaginated($query, $storeId, $brandId, $perPage),
            'bought' => $this->searchTransactionItemsPaginated($query, $storeId, $brandId, $perPage),
            'sold' => $this->searchOrderItemsPaginated($query, $storeId, $brandId, $perPage),
            default => $this->searchProductsPaginated($query, $storeId, $brandId, $perPage),
        };

        // Get counts for all tabs
        $counts = [
            'active' => $this->getProductsCount($query, $storeId, $brandId),
            'bought' => $this->getTransactionItemsCount($query, $storeId, $brandId),
            'sold' => $this->getOrderItemsCount($query, $storeId, $brandId),
        ];

        return response()->json([
            'results' => $results,
            'counts' => $counts,
        ]);
    }

    /**
     * Search products with pagination.
     */
    protected function searchProductsPaginated(string $query, int $storeId, ?int $brandId, int $perPage): array
    {
        $builder = Product::where('store_id', $storeId)
            ->with(['brand', 'category', 'variants', 'images', 'attributeValues.field']);

        if ($brandId) {
            $builder->where('brand_id', $brandId);
        }

        if ($query) {
            $builder->where(function ($q) use ($query) {
                $q->where('title', 'like', "%{$query}%")
                    ->orWhereHas('brand', fn ($b) => $b->where('name', 'like', "%{$query}%"))
                    ->orWhereHas('variants', fn ($v) => $v->where('sku', 'like', "%{$query}%"))
                    // Search product template values
                    ->orWhereHas('attributeValues', fn ($av) => $av->where('value', 'like', "%{$query}%"));
            });
        }

        $paginator = $builder->orderBy('created_at', 'desc')->paginate($perPage);

        return [
            'data' => $paginator->getCollection()->map(function (Product $product) {
                return [
                    'id' => $product->id,
                    'title' => $product->title,
                    'sku' => $product->variants->first()?->sku,
                    'brand' => $product->brand?->name,
                    'category' => $product->category?->name,
                    'price' => $product->variants->first()?->price,
                    'status' => $product->status,
                    'image' => $product->images->first()?->url,
                    'url' => "/products/{$product->id}",
                    'template_values' => $product->attributeValues->map(fn ($av) => [
                        'field' => $av->field?->name,
                        'value' => $av->value,
                    ])->filter(fn ($v) => $v['value'])->values()->toArray(),
                ];
            })->toArray(),
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ];
    }

    /**
     * Get products count for tab badge.
     */
    protected function getProductsCount(string $query, int $storeId, ?int $brandId): int
    {
        $builder = Product::where('store_id', $storeId);

        if ($brandId) {
            $builder->where('brand_id', $brandId);
        }

        if ($query) {
            $builder->where(function ($q) use ($query) {
                $q->where('title', 'like', "%{$query}%")
                    ->orWhereHas('brand', fn ($b) => $b->where('name', 'like', "%{$query}%"))
                    ->orWhereHas('variants', fn ($v) => $v->where('sku', 'like', "%{$query}%"))
                    ->orWhereHas('attributeValues', fn ($av) => $av->where('value', 'like', "%{$query}%"));
            });
        }

        return $builder->count();
    }

    /**
     * Search transaction items with pagination.
     */
    protected function searchTransactionItemsPaginated(string $query, int $storeId, ?int $brandId, int $perPage): array
    {
        $builder = TransactionItem::whereHas('transaction', fn ($t) => $t->where('store_id', $storeId))
            ->with(['transaction.customer', 'category', 'images', 'product.brand']);

        // Filter by brand through the linked product
        if ($brandId) {
            $builder->whereHas('product', fn ($p) => $p->where('brand_id', $brandId));
        }

        if ($query) {
            $builder->where(function ($q) use ($query) {
                $q->where('title', 'like', "%{$query}%")
                    ->orWhere('sku', 'like', "%{$query}%")
                    ->orWhere('description', 'like', "%{$query}%");
            });
        }

        $paginator = $builder->orderBy('created_at', 'desc')->paginate($perPage);

        return [
            'data' => $paginator->getCollection()->map(function (TransactionItem $item) {
                return [
                    'id' => $item->id,
                    'title' => $item->title,
                    'sku' => $item->sku,
                    'brand' => $item->product?->brand?->name,
                    'category' => $item->category?->name,
                    'price' => $item->buy_price,
                    'transaction_id' => $item->transaction_id,
                    'transaction_number' => $item->transaction?->transaction_number,
                    'customer_name' => $item->transaction?->customer?->full_name,
                    'date' => $item->created_at?->format('M d, Y'),
                    'image' => $item->images->first()?->url,
                    'url' => "/transactions/{$item->transaction_id}",
                ];
            })->toArray(),
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ];
    }

    /**
     * Get transaction items count for tab badge.
     */
    protected function getTransactionItemsCount(string $query, int $storeId, ?int $brandId): int
    {
        $builder = TransactionItem::whereHas('transaction', fn ($t) => $t->where('store_id', $storeId));

        if ($brandId) {
            $builder->whereHas('product', fn ($p) => $p->where('brand_id', $brandId));
        }

        if ($query) {
            $builder->where(function ($q) use ($query) {
                $q->where('title', 'like', "%{$query}%")
                    ->orWhere('sku', 'like', "%{$query}%")
                    ->orWhere('description', 'like', "%{$query}%");
            });
        }

        return $builder->count();
    }

    /**
     * Search order items with pagination.
     */
    protected function searchOrderItemsPaginated(string $query, int $storeId, ?int $brandId, int $perPage): array
    {
        $builder = OrderItem::whereHas('order', fn ($o) => $o->where('store_id', $storeId))
            ->with(['order.customer']);

        // Note: OrderItem may not have brand_id directly, but we can filter through product if linked
        if ($brandId) {
            $builder->whereHas('product', fn ($p) => $p->where('brand_id', $brandId));
        }

        if ($query) {
            $builder->where(function ($q) use ($query) {
                $q->where('title', 'like', "%{$query}%")
                    ->orWhere('sku', 'like', "%{$query}%");
            });
        }

        $paginator = $builder->orderBy('created_at', 'desc')->paginate($perPage);

        return [
            'data' => $paginator->getCollection()->map(function (OrderItem $item) {
                return [
                    'id' => $item->id,
                    'title' => $item->title,
                    'sku' => $item->sku,
                    'brand' => $item->product?->brand?->name,
                    'price' => $item->price,
                    'order_id' => $item->order_id,
                    'invoice_number' => $item->order?->invoice_number,
                    'customer_name' => $item->order?->customer?->full_name,
                    'date' => $item->created_at?->format('M d, Y'),
                    'url' => "/orders/{$item->order_id}",
                ];
            })->toArray(),
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ];
    }

    /**
     * Get order items count for tab badge.
     */
    protected function getOrderItemsCount(string $query, int $storeId, ?int $brandId): int
    {
        $builder = OrderItem::whereHas('order', fn ($o) => $o->where('store_id', $storeId));

        if ($brandId) {
            $builder->whereHas('product', fn ($p) => $p->where('brand_id', $brandId));
        }

        if ($query) {
            $builder->where(function ($q) use ($query) {
                $q->where('title', 'like', "%{$query}%")
                    ->orWhere('sku', 'like', "%{$query}%");
            });
        }

        return $builder->count();
    }

    /**
     * Search across products, transaction items (bought), and order items (sold).
     * Used by the modal for quick search.
     */
    public function search(Request $request): JsonResponse
    {
        $request->validate([
            'query' => ['required', 'string', 'min:2', 'max:255'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        $storeId = $this->storeContext->getCurrentStoreId();
        $query = $request->input('query');
        $limit = $request->input('limit', 10);

        // Search active products (inventory)
        $activeProducts = $this->searchProducts($query, $storeId, $limit);

        // Search transaction items (bought from customers)
        $boughtItems = $this->searchTransactionItems($query, $storeId, $limit);

        // Search order items (sold to customers)
        $soldItems = $this->searchOrderItems($query, $storeId, $limit);

        return response()->json([
            'active' => $activeProducts,
            'bought' => $boughtItems,
            'sold' => $soldItems,
        ]);
    }

    /**
     * Search active products in inventory.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function searchProducts(string $query, int $storeId, int $limit): array
    {
        try {
            $results = Product::search($query)
                ->where('store_id', $storeId)
                ->take($limit)
                ->get();

            return $results->map(function (Product $product) {
                return [
                    'id' => $product->id,
                    'title' => $product->title,
                    'sku' => $product->variants->first()?->sku,
                    'brand' => $product->brand?->name,
                    'category' => $product->category?->name,
                    'price' => $product->variants->first()?->price,
                    'status' => $product->status,
                    'image' => $product->images->first()?->url,
                    'url' => "/products/{$product->id}",
                ];
            })->toArray();
        } catch (\Exception $e) {
            // Fallback to SQL search if Scout fails
            return $this->fallbackProductSearch($query, $storeId, $limit);
        }
    }

    /**
     * Fallback SQL search for products.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function fallbackProductSearch(string $query, int $storeId, int $limit): array
    {
        $results = Product::where('store_id', $storeId)
            ->where(function ($q) use ($query) {
                $q->where('title', 'like', "%{$query}%")
                    ->orWhereHas('brand', fn ($b) => $b->where('name', 'like', "%{$query}%"))
                    ->orWhereHas('variants', fn ($v) => $v->where('sku', 'like', "%{$query}%"));
            })
            ->with(['brand', 'category', 'variants', 'images'])
            ->limit($limit)
            ->get();

        return $results->map(function (Product $product) {
            return [
                'id' => $product->id,
                'title' => $product->title,
                'sku' => $product->variants->first()?->sku,
                'brand' => $product->brand?->name,
                'category' => $product->category?->name,
                'price' => $product->variants->first()?->price,
                'status' => $product->status,
                'image' => $product->images->first()?->url,
                'url' => "/products/{$product->id}",
            ];
        })->toArray();
    }

    /**
     * Search transaction items (bought from customers).
     *
     * @return array<int, array<string, mixed>>
     */
    protected function searchTransactionItems(string $query, int $storeId, int $limit): array
    {
        try {
            $results = TransactionItem::search($query)
                ->where('store_id', $storeId)
                ->take($limit)
                ->get();

            return $results->map(function (TransactionItem $item) {
                return [
                    'id' => $item->id,
                    'title' => $item->title,
                    'sku' => $item->sku,
                    'category' => $item->category?->name,
                    'price' => $item->buy_price,
                    'transaction_id' => $item->transaction_id,
                    'transaction_number' => $item->transaction?->transaction_number,
                    'customer_name' => $item->transaction?->customer?->full_name,
                    'date' => $item->created_at?->format('M d, Y'),
                    'image' => $item->images->first()?->url,
                    'url' => "/transactions/{$item->transaction_id}",
                ];
            })->toArray();
        } catch (\Exception $e) {
            // Fallback to SQL search
            return $this->fallbackTransactionItemSearch($query, $storeId, $limit);
        }
    }

    /**
     * Fallback SQL search for transaction items.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function fallbackTransactionItemSearch(string $query, int $storeId, int $limit): array
    {
        $results = TransactionItem::whereHas('transaction', fn ($t) => $t->where('store_id', $storeId))
            ->where(function ($q) use ($query) {
                $q->where('title', 'like', "%{$query}%")
                    ->orWhere('sku', 'like', "%{$query}%")
                    ->orWhere('description', 'like', "%{$query}%");
            })
            ->with(['transaction.customer', 'category', 'images'])
            ->limit($limit)
            ->get();

        return $results->map(function (TransactionItem $item) {
            return [
                'id' => $item->id,
                'title' => $item->title,
                'sku' => $item->sku,
                'category' => $item->category?->name,
                'price' => $item->buy_price,
                'transaction_id' => $item->transaction_id,
                'transaction_number' => $item->transaction?->transaction_number,
                'customer_name' => $item->transaction?->customer?->full_name,
                'date' => $item->created_at?->format('M d, Y'),
                'image' => $item->images->first()?->url,
                'url' => "/transactions/{$item->transaction_id}",
            ];
        })->toArray();
    }

    /**
     * Search order items (sold to customers).
     *
     * @return array<int, array<string, mixed>>
     */
    protected function searchOrderItems(string $query, int $storeId, int $limit): array
    {
        try {
            $results = OrderItem::search($query)
                ->where('store_id', $storeId)
                ->take($limit)
                ->get();

            return $results->map(function (OrderItem $item) {
                return [
                    'id' => $item->id,
                    'title' => $item->title,
                    'sku' => $item->sku,
                    'price' => $item->price,
                    'order_id' => $item->order_id,
                    'invoice_number' => $item->order?->invoice_number,
                    'customer_name' => $item->order?->customer?->full_name,
                    'date' => $item->created_at?->format('M d, Y'),
                    'url' => "/orders/{$item->order_id}",
                ];
            })->toArray();
        } catch (\Exception $e) {
            // Fallback to SQL search
            return $this->fallbackOrderItemSearch($query, $storeId, $limit);
        }
    }

    /**
     * Fallback SQL search for order items.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function fallbackOrderItemSearch(string $query, int $storeId, int $limit): array
    {
        $results = OrderItem::whereHas('order', fn ($o) => $o->where('store_id', $storeId))
            ->where(function ($q) use ($query) {
                $q->where('title', 'like', "%{$query}%")
                    ->orWhere('sku', 'like', "%{$query}%");
            })
            ->with(['order.customer'])
            ->limit($limit)
            ->get();

        return $results->map(function (OrderItem $item) {
            return [
                'id' => $item->id,
                'title' => $item->title,
                'sku' => $item->sku,
                'price' => $item->price,
                'order_id' => $item->order_id,
                'invoice_number' => $item->order?->invoice_number,
                'customer_name' => $item->order?->customer?->full_name,
                'date' => $item->created_at?->format('M d, Y'),
                'url' => "/orders/{$item->order_id}",
            ];
        })->toArray();
    }
}
