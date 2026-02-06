<?php

namespace App\Services\Agents;

use App\Enums\AgentTriggerType;
use App\Models\Agent;
use App\Models\Store;
use App\Models\StoreAgent;
use App\Services\Agents\Results\AgentRunResult;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class AgentOrchestrator
{
    public function __construct(
        protected AgentRegistry $registry,
        protected AgentRunner $runner,
    ) {}

    /**
     * Run all scheduled agents that are due.
     *
     * @return array<string, AgentRunResult>
     */
    public function runScheduledAgents(): array
    {
        $results = [];

        $dueAgents = StoreAgent::with(['store', 'agent'])
            ->where('is_enabled', true)
            ->where('permission_level', '!=', 'block')
            ->where(function ($query) {
                $query->whereNull('next_run_at')
                    ->orWhere('next_run_at', '<=', now());
            })
            ->get();

        foreach ($dueAgents as $storeAgent) {
            if (! $storeAgent->agent->isBackgroundAgent()) {
                continue;
            }

            $key = "{$storeAgent->store_id}:{$storeAgent->agent->slug}";

            try {
                $results[$key] = $this->runner->runAgent(
                    $storeAgent->agent,
                    $storeAgent->store,
                    AgentTriggerType::Scheduled
                );
            } catch (\Throwable $e) {
                Log::error('Scheduled agent run failed', [
                    'store_id' => $storeAgent->store_id,
                    'agent' => $storeAgent->agent->slug,
                    'error' => $e->getMessage(),
                ]);

                $results[$key] = AgentRunResult::failure($e->getMessage());
            }
        }

        return $results;
    }

    /**
     * Dispatch an event to all subscribed agents.
     *
     * @param  array<string, mixed>  $payload
     */
    public function dispatchEvent(string $event, array $payload, Store $store): void
    {
        $agentSlugs = $this->registry->getAgentsForEvent($event);

        foreach ($agentSlugs as $slug) {
            $agent = Agent::findBySlug($slug);

            if (! $agent) {
                continue;
            }

            $storeAgent = StoreAgent::getOrCreateForStore($store, $agent);

            if (! $storeAgent->canRun()) {
                continue;
            }

            $this->runner->run(
                $slug,
                $store,
                AgentTriggerType::Event,
                ['event' => $event, 'payload' => $payload]
            );
        }
    }

    /**
     * Get all agents available for a store.
     *
     * @return Collection<int, array{agent: Agent, store_agent: StoreAgent, implementation: \App\Services\Agents\Contracts\AgentInterface}>
     */
    public function getAgentsForStore(Store $store): Collection
    {
        $agents = Agent::all();
        $result = collect();

        foreach ($agents as $agent) {
            $storeAgent = StoreAgent::getOrCreateForStore($store, $agent);
            $implementation = $this->registry->getAgent($agent->slug);

            if ($implementation) {
                $result->push([
                    'agent' => $agent,
                    'store_agent' => $storeAgent,
                    'implementation' => $implementation,
                ]);
            }
        }

        return $result;
    }

    /**
     * Initialize agents for a new store.
     */
    public function initializeAgentsForStore(Store $store): void
    {
        $agents = Agent::where('default_enabled', true)->get();

        foreach ($agents as $agent) {
            StoreAgent::getOrCreateForStore($store, $agent);
        }
    }

    /**
     * Get pending actions for a store.
     */
    public function getPendingActions(Store $store, int $limit = 50): Collection
    {
        return $store->agentActions()
            ->with(['agentRun.agent', 'actionable'])
            ->where('status', 'pending')
            ->where('requires_approval', true)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Get agent run history for a store.
     */
    public function getRunHistory(Store $store, ?int $agentId = null, int $limit = 50): Collection
    {
        $query = $store->agentRuns()
            ->with(['agent', 'actions'])
            ->orderBy('created_at', 'desc')
            ->limit($limit);

        if ($agentId) {
            $query->where('agent_id', $agentId);
        }

        return $query->get();
    }
}
