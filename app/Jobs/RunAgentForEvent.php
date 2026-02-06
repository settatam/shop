<?php

namespace App\Jobs;

use App\Models\Store;
use App\Services\Agents\AgentOrchestrator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class RunAgentForEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    /**
     * Create a new job instance.
     *
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $event,
        public array $payload,
        public int $storeId,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(AgentOrchestrator $orchestrator): void
    {
        $store = Store::find($this->storeId);

        if (! $store) {
            Log::warning('RunAgentForEvent: Store not found', [
                'store_id' => $this->storeId,
            ]);

            return;
        }

        Log::info('Dispatching event to agents', [
            'event' => $this->event,
            'store_id' => $this->storeId,
        ]);

        $orchestrator->dispatchEvent($this->event, $this->payload, $store);
    }
}
