<?php

namespace App\Jobs;

use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use App\Models\StoreKnowledgeBaseEntry;
use App\Services\Rag\RagIndexer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class IndexStoreContentJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public string $contentType,
        public int $contentId
    ) {}

    public function handle(RagIndexer $indexer): void
    {
        try {
            match ($this->contentType) {
                'product' => $this->indexProduct($indexer),
                'knowledge_base' => $this->indexKnowledgeBaseEntry($indexer),
                'category' => $this->indexCategory($indexer),
                'store' => $this->indexStore($indexer),
                default => null,
            };
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            // Qdrant or embedding service unavailable — retry later
            $this->release(60);
        }
    }

    protected function indexProduct(RagIndexer $indexer): void
    {
        $product = Product::with(['brand', 'category', 'variants', 'attributeValues.field'])
            ->find($this->contentId);

        if (! $product || $product->status !== Product::STATUS_ACTIVE || ! $product->is_published) {
            $indexer->remove("product_{$this->contentId}");

            return;
        }

        $indexer->indexProduct($product);
    }

    protected function indexKnowledgeBaseEntry(RagIndexer $indexer): void
    {
        $entry = StoreKnowledgeBaseEntry::find($this->contentId);

        if (! $entry || ! $entry->is_active) {
            $indexer->remove("kb_{$this->contentId}");

            return;
        }

        $indexer->indexKnowledgeBaseEntry($entry);
    }

    protected function indexCategory(RagIndexer $indexer): void
    {
        $category = Category::withoutGlobalScopes()->find($this->contentId);

        if (! $category || $category->trashed()) {
            $indexer->remove("category_{$this->contentId}");

            return;
        }

        $indexer->indexCategory($category);
    }

    protected function indexStore(RagIndexer $indexer): void
    {
        $store = Store::find($this->contentId);

        if (! $store) {
            $indexer->remove("store_{$this->contentId}");

            return;
        }

        $indexer->indexStore($store);
    }
}
