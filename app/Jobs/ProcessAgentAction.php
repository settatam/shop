<?php

namespace App\Jobs;

use App\Models\AgentAction;
use App\Services\Agents\ActionExecutor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProcessAgentAction implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $actionId,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(ActionExecutor $executor): void
    {
        $action = AgentAction::find($this->actionId);

        if (! $action) {
            Log::warning('ProcessAgentAction: Action not found', [
                'action_id' => $this->actionId,
            ]);

            return;
        }

        if (! $action->canBeExecuted()) {
            Log::info('ProcessAgentAction: Action cannot be executed', [
                'action_id' => $this->actionId,
                'status' => $action->status->value,
            ]);

            return;
        }

        Log::info('Processing agent action', [
            'action_id' => $this->actionId,
            'action_type' => $action->action_type,
        ]);

        $result = $executor->execute($action);

        if (! $result->success) {
            Log::error('Agent action execution failed', [
                'action_id' => $this->actionId,
                'error' => $result->message,
            ]);
        }
    }
}
