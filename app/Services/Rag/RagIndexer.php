<?php

namespace App\Services\Rag;

use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use App\Models\StoreKnowledgeBaseEntry;

class RagIndexer
{
    public function __construct(
        protected EmbeddingService $embedding,
        protected QdrantService $qdrant,
        protected ContentChunker $chunker
    ) {}

    /**
     * Index a single product into Qdrant.
     */
    public function indexProduct(Product $product): void
    {
        $chunk = $this->chunker->chunkProduct($product);
        $this->indexChunk($chunk);
    }

    /**
     * Index a single knowledge base entry into Qdrant.
     */
    public function indexKnowledgeBaseEntry(StoreKnowledgeBaseEntry $entry): void
    {
        $chunk = $this->chunker->chunkKnowledgeBaseEntry($entry);
        $this->indexChunk($chunk);
    }

    /**
     * Index a single category into Qdrant.
     */
    public function indexCategory(Category $category): void
    {
        $chunk = $this->chunker->chunkCategory($category);
        $this->indexChunk($chunk);
    }

    /**
     * Index store info into Qdrant.
     */
    public function indexStore(Store $store): void
    {
        $chunk = $this->chunker->chunkStore($store);
        $this->indexChunk($chunk);
    }

    /**
     * Remove a content item from Qdrant by its point ID.
     */
    public function remove(string $pointId): void
    {
        $this->qdrant->delete([$pointId]);
    }

    /**
     * Remove all content for a store from Qdrant.
     */
    public function removeStore(int $storeId): void
    {
        $this->qdrant->deleteByFilter([
            'must' => [
                ['key' => 'store_id', 'match' => ['value' => $storeId]],
            ],
        ]);
    }

    /**
     * Reindex all content for a store.
     */
    public function reindexStore(Store $store, ?\Closure $onProgress = null): int
    {
        $this->qdrant->ensureCollection($this->embedding->dimensions());

        // Remove existing content for this store
        $this->removeStore($store->id);

        $indexed = 0;

        // Index store info
        $this->indexStore($store);
        $indexed++;
        $this->reportProgress($onProgress, 'store_info', $indexed);

        // Index products in batches
        Product::where('store_id', $store->id)
            ->where('status', Product::STATUS_ACTIVE)
            ->where('is_published', true)
            ->with(['brand', 'category', 'variants', 'attributeValues.field'])
            ->chunkById(50, function ($products) use (&$indexed, $onProgress) {
                $chunks = $products->map(fn (Product $p) => $this->chunker->chunkProduct($p))->toArray();
                $this->indexChunkBatch($chunks);
                $indexed += count($chunks);
                $this->reportProgress($onProgress, 'products', $indexed);
            });

        // Index knowledge base entries
        $kbEntries = StoreKnowledgeBaseEntry::where('store_id', $store->id)
            ->where('is_active', true)
            ->get();

        foreach ($kbEntries as $entry) {
            $this->indexKnowledgeBaseEntry($entry);
            $indexed++;
        }
        $this->reportProgress($onProgress, 'knowledge_base', $indexed);

        // Index categories
        Category::withoutGlobalScopes()
            ->where('store_id', $store->id)
            ->whereNull('deleted_at')
            ->chunkById(50, function ($categories) use (&$indexed, $onProgress) {
                $chunks = $categories->map(fn (Category $c) => $this->chunker->chunkCategory($c))->toArray();
                $this->indexChunkBatch($chunks);
                $indexed += count($chunks);
                $this->reportProgress($onProgress, 'categories', $indexed);
            });

        return $indexed;
    }

    /**
     * Index a single chunk (embed + upsert).
     *
     * @param  array{point_id: string, text: string, payload: array<string, mixed>}  $chunk
     */
    protected function indexChunk(array $chunk): void
    {
        $vector = $this->embedding->embed($chunk['text']);

        if (! $vector) {
            return;
        }

        $this->qdrant->upsert($chunk['point_id'], $vector, $chunk['payload']);
    }

    /**
     * Index multiple chunks in a batch (embed all + upsert all).
     *
     * @param  array<int, array{point_id: string, text: string, payload: array<string, mixed>}>  $chunks
     */
    protected function indexChunkBatch(array $chunks): void
    {
        if (empty($chunks)) {
            return;
        }

        $texts = array_map(fn ($c) => $c['text'], $chunks);
        $vectors = $this->embedding->embedBatch($texts);

        $points = [];
        foreach ($chunks as $i => $chunk) {
            if (isset($vectors[$i])) {
                $points[] = [
                    'id' => $chunk['point_id'],
                    'vector' => $vectors[$i],
                    'payload' => $chunk['payload'],
                ];
            }
        }

        $this->qdrant->upsertBatch($points);
    }

    protected function reportProgress(?\Closure $onProgress, string $type, int $count): void
    {
        if ($onProgress) {
            $onProgress($type, $count);
        }
    }
}
