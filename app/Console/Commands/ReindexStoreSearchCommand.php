<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Memo;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductTemplate;
use App\Models\Repair;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\TransactionItem;
use Illuminate\Console\Command;

class ReindexStoreSearchCommand extends Command
{
    protected $signature = 'scout:reindex-store
                            {store : The store ID to reindex}
                            {--model=* : Specific model(s) to reindex}';

    protected $description = 'Reindex search indexes for a specific store';

    /**
     * @var array<string, class-string>
     */
    protected array $models = [
        'categories' => Category::class,
        'customers' => Customer::class,
        'memos' => Memo::class,
        'orders' => Order::class,
        'order_items' => OrderItem::class,
        'products' => Product::class,
        'product_templates' => ProductTemplate::class,
        'repairs' => Repair::class,
        'transactions' => Transaction::class,
        'transaction_items' => TransactionItem::class,
    ];

    public function handle(): int
    {
        $storeId = $this->argument('store');
        $store = Store::find($storeId);

        if (! $store) {
            $this->error("Store with ID {$storeId} not found.");

            return self::FAILURE;
        }

        $modelsToReindex = $this->option('model');

        if (empty($modelsToReindex)) {
            $modelsToReindex = array_keys($this->models);
        }

        $this->info("Reindexing search indexes for store: {$store->name} (ID: {$store->id})");
        $this->newLine();

        foreach ($modelsToReindex as $modelKey) {
            if (! isset($this->models[$modelKey])) {
                $this->warn("Unknown model: {$modelKey}, skipping...");

                continue;
            }

            $modelClass = $this->models[$modelKey];
            $this->reindexModel($modelClass, $storeId);
        }

        $this->newLine();
        $this->info('Store reindex complete!');

        return self::SUCCESS;
    }

    protected function reindexModel(string $modelClass, int $storeId): void
    {
        $modelName = class_basename($modelClass);

        $this->components->task("Reindexing {$modelName}", function () use ($modelClass, $storeId) {
            $query = $modelClass::query();

            // Handle models that use store_id directly
            if ($modelClass === OrderItem::class) {
                // OrderItem gets store_id through order relationship
                $query->whereHas('order', fn ($q) => $q->where('store_id', $storeId));
            } elseif ($modelClass === TransactionItem::class) {
                // TransactionItem gets store_id through transaction relationship
                $query->whereHas('transaction', fn ($q) => $q->where('store_id', $storeId));
            } else {
                // Most models have store_id directly
                $query->where('store_id', $storeId);
            }

            // Make records searchable in chunks
            $query->searchable();
        });
    }
}
