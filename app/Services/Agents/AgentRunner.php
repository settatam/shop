<?php

namespace App\Services\Agents;

use App\Enums\AgentRunStatus;
use App\Enums\AgentTriggerType;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\Store;
use App\Models\StoreAgent;
use App\Services\Agents\Results\AgentRunResult;
use Illuminate\Support\Facades\Log;

class AgentRunner
{
    public function __construct(
        protected AgentRegistry $registry,
        protected ActionExecutor $actionExecutor,
    ) {}

    /**
     * Run an agent for a specific store.
     */
    public function run(
        string $agentSlug,
        Store $store,
        AgentTriggerType $triggerType = AgentTriggerType::Manual,
        array $triggerData = []
    ): AgentRunResult {
        $agentImpl = $this->registry->getAgent($agentSlug);

        if (! $agentImpl) {
            return AgentRunResult::failure("Agent '{$agentSlug}' not found in registry");
        }

        $agent = Agent::findBySlug($agentSlug);

        if (! $agent) {
            return AgentRunResult::failure("Agent '{$agentSlug}' not found in database");
        }

        $storeAgent = StoreAgent::getOrCreateForStore($store, $agent);

        if (! $agentImpl->canRun($storeAgent)) {
            return AgentRunResult::failure('Agent cannot run for this store configuration');
        }

        // Create the run record
        $run = AgentRun::create([
            'store_id' => $store->id,
            'agent_id' => $agent->id,
            'store_agent_id' => $storeAgent->id,
            'status' => AgentRunStatus::Pending,
            'trigger_type' => $triggerType,
            'trigger_data' => $triggerData,
        ]);

        try {
            $run->start();

            // Execute the agent
            $result = $agentImpl->run($run, $storeAgent);

            if ($result->success) {
                $run->complete($result->summary);
                $storeAgent->markAsRun();

                // Schedule next run for background agents
                if ($agent->isBackgroundAgent()) {
                    $storeAgent->scheduleNextRun();
                }

                // Process auto-execute actions
                $this->processAutoExecuteActions($run, $storeAgent);
            } else {
                $run->fail($result->errorMessage ?? 'Unknown error');
            }

            return $result;
        } catch (\Throwable $e) {
            Log::error('Agent run failed', [
                'agent' => $agentSlug,
                'store_id' => $store->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $run->fail($e->getMessage());

            return AgentRunResult::failure($e->getMessage());
        }
    }

    /**
     * Run an agent by its database model.
     */
    public function runAgent(
        Agent $agent,
        Store $store,
        AgentTriggerType $triggerType = AgentTriggerType::Manual,
        array $triggerData = []
    ): AgentRunResult {
        return $this->run($agent->slug, $store, $triggerType, $triggerData);
    }

    /**
     * Process actions that can be auto-executed.
     */
    protected function processAutoExecuteActions(AgentRun $run, StoreAgent $storeAgent): void
    {
        if (! $storeAgent->isAutoExecute()) {
            return;
        }

        $pendingActions = $run->actions()
            ->where('status', 'pending')
            ->where('requires_approval', false)
            ->get();

        foreach ($pendingActions as $action) {
            $this->actionExecutor->execute($action);
        }
    }
}
