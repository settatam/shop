<?php

namespace App\Jobs;

use App\Models\Store;
use App\Services\Rag\RagIndexer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ReindexStoreJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(
        public int $storeId
    ) {}

    public function handle(RagIndexer $indexer): void
    {
        $store = Store::findOrFail($this->storeId);
        $indexer->reindexStore($store);
    }
}
