<?php

namespace App\Observers;

use App\Jobs\IndexStoreContentJob;
use App\Jobs\RemoveStoreContentJob;
use App\Models\StoreKnowledgeBaseEntry;

class KnowledgeBaseRagObserver
{
    public function created(StoreKnowledgeBaseEntry $entry): void
    {
        if ($entry->is_active) {
            IndexStoreContentJob::dispatch('knowledge_base', $entry->id);
        }
    }

    public function updated(StoreKnowledgeBaseEntry $entry): void
    {
        if ($entry->is_active) {
            IndexStoreContentJob::dispatch('knowledge_base', $entry->id);
        } else {
            RemoveStoreContentJob::dispatch("kb_{$entry->id}");
        }
    }

    public function deleted(StoreKnowledgeBaseEntry $entry): void
    {
        RemoveStoreContentJob::dispatch("kb_{$entry->id}");
    }
}
