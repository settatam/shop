<?php

namespace App\Console\Commands;

use App\Models\Store;
use App\Services\Rag\RagIndexer;
use Illuminate\Console\Command;

class ReindexStoreContent extends Command
{
    protected $signature = 'rag:reindex {store_id? : The store ID to reindex (omit for all stores)}';

    protected $description = 'Reindex store content into Qdrant for RAG search';

    public function handle(RagIndexer $indexer): int
    {
        $storeId = $this->argument('store_id');

        if ($storeId) {
            $store = Store::find($storeId);

            if (! $store) {
                $this->error("Store {$storeId} not found.");

                return self::FAILURE;
            }

            $this->reindexStore($indexer, $store);
        } else {
            $stores = Store::all();
            $this->info("Reindexing {$stores->count()} store(s)...");

            foreach ($stores as $store) {
                $this->reindexStore($indexer, $store);
            }
        }

        $this->info('Done.');

        return self::SUCCESS;
    }

    protected function reindexStore(RagIndexer $indexer, Store $store): void
    {
        $this->info("Reindexing store: {$store->name} (ID: {$store->id})");

        $count = $indexer->reindexStore($store, function (string $type, int $total) {
            $this->line("  [{$type}] {$total} items indexed so far...");
        });

        $this->info("  Finished — {$count} items indexed.");
    }
}
