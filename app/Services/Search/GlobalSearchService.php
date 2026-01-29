<?php

namespace App\Services\Search;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Memo;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductTemplate;
use App\Models\Repair;
use App\Models\Transaction;
use Illuminate\Support\Collection;

class GlobalSearchService
{
    protected array $searchableModels = [
        'products' => Product::class,
        'orders' => Order::class,
        'customers' => Customer::class,
        'repairs' => Repair::class,
        'memos' => Memo::class,
        'transactions' => Transaction::class,
        'categories' => Category::class,
        'templates' => ProductTemplate::class,
    ];

    /**
     * Search across all models for the given query.
     *
     * @return array<string, Collection>
     */
    public function search(string $query, int $storeId, int $limitPerType = 5): array
    {
        $results = [];

        foreach ($this->searchableModels as $type => $modelClass) {
            $results[$type] = $this->searchModel($modelClass, $query, $storeId, $limitPerType);
        }

        return $results;
    }

    /**
     * Search a specific model type.
     */
    protected function searchModel(string $modelClass, string $query, int $storeId, int $limit): Collection
    {
        return $modelClass::search($query)
            ->where('store_id', $storeId)
            ->take($limit)
            ->get()
            ->map(fn ($model) => $this->formatResult($model));
    }

    /**
     * Format a model result for the API response.
     */
    protected function formatResult($model): array
    {
        $type = $this->getModelType($model);

        return match ($type) {
            'products' => [
                'id' => $model->id,
                'title' => $model->title,
                'subtitle' => $model->brand?->name ?? $model->category?->name,
                'url' => route('products.edit', $model),
            ],
            'orders' => [
                'id' => $model->id,
                'title' => $model->invoice_number ?? "Order #{$model->id}",
                'subtitle' => $model->customer?->full_name ?? 'No customer',
                'url' => "/orders?highlight={$model->id}",
            ],
            'customers' => [
                'id' => $model->id,
                'title' => $model->full_name ?: $model->email,
                'subtitle' => $model->email,
                'url' => "/customers?highlight={$model->id}",
            ],
            'repairs' => [
                'id' => $model->id,
                'title' => $model->repair_number,
                'subtitle' => $model->customer?->full_name ?? 'No customer',
                'url' => "/repairs?highlight={$model->id}",
            ],
            'memos' => [
                'id' => $model->id,
                'title' => $model->memo_number,
                'subtitle' => $model->vendor?->full_name ?? 'No vendor',
                'url' => "/memos?highlight={$model->id}",
            ],
            'transactions' => [
                'id' => $model->id,
                'title' => $model->transaction_number,
                'subtitle' => $model->customer?->full_name ?? 'No customer',
                'url' => route('web.transactions.show', $model),
            ],
            'categories' => [
                'id' => $model->id,
                'title' => $model->name,
                'subtitle' => $model->parent?->name ?? 'Root category',
                'url' => route('categories.index', ['highlight' => $model->id]),
            ],
            'templates' => [
                'id' => $model->id,
                'title' => $model->name,
                'subtitle' => $model->description ? str()->limit($model->description, 50) : 'Product template',
                'url' => route('templates.edit', $model),
            ],
            default => [
                'id' => $model->id,
                'title' => $model->id,
                'subtitle' => null,
                'url' => '#',
            ],
        };
    }

    /**
     * Get the type key for a model.
     */
    protected function getModelType($model): string
    {
        $class = get_class($model);

        return array_search($class, $this->searchableModels, true) ?: 'unknown';
    }

    /**
     * Get total count of results across all types.
     */
    public function getTotalCount(array $results): int
    {
        return collect($results)->sum(fn ($items) => $items->count());
    }
}
