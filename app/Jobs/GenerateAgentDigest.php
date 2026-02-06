<?php

namespace App\Jobs;

use App\Models\Store;
use App\Services\Agents\DigestGenerator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class GenerateAgentDigest implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public ?int $storeId = null,
        public string $period = 'daily',
    ) {}

    /**
     * Execute the job.
     */
    public function handle(DigestGenerator $generator): void
    {
        if ($this->storeId) {
            $this->processStore($generator, $this->storeId);

            return;
        }

        // Process all active stores
        $stores = Store::where('is_active', true)->get();

        Log::info('Generating agent digests', [
            'store_count' => $stores->count(),
            'period' => $this->period,
        ]);

        foreach ($stores as $store) {
            $this->processStore($generator, $store->id);
        }
    }

    protected function processStore(DigestGenerator $generator, int $storeId): void
    {
        $store = Store::find($storeId);

        if (! $store) {
            Log::warning('GenerateAgentDigest: Store not found', [
                'store_id' => $storeId,
            ]);

            return;
        }

        try {
            $sent = $generator->sendDigest($store, $this->period);

            Log::info('Agent digest processed', [
                'store_id' => $storeId,
                'sent' => $sent,
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to generate agent digest', [
                'store_id' => $storeId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
