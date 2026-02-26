<?php

namespace App\Jobs;

use App\Models\Transaction;
use App\Services\Legacy\LegacyTransactionSyncService;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;

class SyncTransactionToLegacyJob
{
    use Dispatchable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Transaction $transaction
    ) {}

    /**
     * Execute the job.
     */
    public function handle(LegacyTransactionSyncService $service): void
    {
        try {
            $service->sync($this->transaction);
        } catch (\Throwable $e) {
            Log::error('Legacy sync failed', [
                'transaction_id' => $this->transaction->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
