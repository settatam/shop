<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\TransactionItem;
use App\Services\StoreContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdvancedProductSearchController extends Controller
{
    public function __construct(protected StoreContext $storeContext) {}

    /**
     * Search across products, transaction items (bought), and order items (sold).
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
