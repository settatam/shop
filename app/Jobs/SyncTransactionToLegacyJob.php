<?php

namespace App\Jobs;

use App\Models\Transaction;
use App\Services\Legacy\LegacyTransactionSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncTransactionToLegacyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     */
    public int $backoff = 30;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Transaction $transaction
    ) {
        $this->onQueue('legacy-sync');
    }

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
