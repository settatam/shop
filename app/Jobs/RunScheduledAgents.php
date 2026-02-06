<?php

namespace App\Jobs;

use App\Services\Agents\AgentOrchestrator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class RunScheduledAgents implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    /**
     * Execute the job.
     */
    public function handle(AgentOrchestrator $orchestrator): void
    {
        Log::info('Running scheduled agents');

        $results = $orchestrator->runScheduledAgents();

        $successful = collect($results)->filter(fn ($r) => $r->success)->count();
        $failed = collect($results)->reject(fn ($r) => $r->success)->count();

        Log::info('Scheduled agents completed', [
            'total' => count($results),
            'successful' => $successful,
            'failed' => $failed,
        ]);
    }
}
