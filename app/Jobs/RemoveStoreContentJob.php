<?php

namespace App\Jobs;

use App\Services\Rag\RagIndexer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RemoveStoreContentJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public string $pointId
    ) {}

    public function handle(RagIndexer $indexer): void
    {
        try {
            $indexer->remove($this->pointId);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            $this->release(60);
        }
    }
}
