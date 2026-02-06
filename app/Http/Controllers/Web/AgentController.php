<?php

namespace App\Http\Controllers\Web;

use App\Enums\AgentPermissionLevel;
use App\Enums\AgentTriggerType;
use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\StoreAgent;
use App\Services\Agents\AgentOrchestrator;
use App\Services\Agents\AgentRegistry;
use App\Services\Agents\AgentRunner;
use App\Services\StoreContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AgentController extends Controller
{
    public function __construct(
        protected StoreContext $storeContext,
        protected AgentOrchestrator $orchestrator,
        protected AgentRegistry $registry,
        protected AgentRunner $runner,
    ) {}

    /**
     * Display the agent dashboard.
     */
    public function index(): Response|RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return redirect()->route('dashboard')
                ->with('error', 'Please select a store first.');
        }

        $agentsData = $this->orchestrator->getAgentsForStore($store);

        $agents = $agentsData->map(function ($data) {
            $agent = $data['agent'];
            $storeAgent = $data['store_agent'];
            $implementation = $data['implementation'];

            return [
                'id' => $agent->id,
                'slug' => $agent->slug,
                'name' => $agent->name,
                'description' => $agent->description,
                'type' => $agent->type->value,
                'type_label' => $agent->type->label(),
                'is_enabled' => $storeAgent->is_enabled,
                'permission_level' => $storeAgent->permission_level->value,
                'permission_level_label' => $storeAgent->permission_level->label(),
                'last_run_at' => $storeAgent->last_run_at?->toISOString(),
                'next_run_at' => $storeAgent->next_run_at?->toISOString(),
                'config_schema' => $implementation->getConfigSchema(),
            ];
        });

        // Get stats for dashboard
        $todayRuns = $store->agentRuns()->today()->count();
        $pendingActions = $store->agentActions()->pending()->where('requires_approval', true)->count();
        $executedToday = $store->agentActions()->today()->executed()->count();

        return Inertia::render('agents/Index', [
            'agents' => $agents,
            'stats' => [
                'runs_today' => $todayRuns,
                'pending_actions' => $pendingActions,
                'executed_today' => $executedToday,
            ],
        ]);
    }

    /**
     * Display agent details and configuration.
     */
    public function show(string $slug): Response|RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return redirect()->route('dashboard')
                ->with('error', 'Please select a store first.');
        }

        $agent = Agent::where('slug', $slug)->firstOrFail();
        $storeAgent = StoreAgent::getOrCreateForStore($store, $agent);
        $implementation = $this->registry->getAgent($slug);

        if (! $implementation) {
            abort(404, 'Agent implementation not found');
        }

        // Get recent runs
        $recentRuns = $store->agentRuns()
            ->where('agent_id', $agent->id)
            ->with('actions')
            ->orderBy('created_at', 'desc')
            ->limit(20)
            ->get()
            ->map(fn ($run) => [
                'id' => $run->id,
                'status' => $run->status->value,
                'status_label' => $run->status->label(),
                'trigger_type' => $run->trigger_type->value,
                'started_at' => $run->started_at?->toISOString(),
                'completed_at' => $run->completed_at?->toISOString(),
                'duration_seconds' => $run->getDurationInSeconds(),
                'actions_count' => $run->actions->count(),
                'pending_actions_count' => $run->getPendingActionsCount(),
                'executed_actions_count' => $run->getExecutedActionsCount(),
                'summary' => $run->summary,
                'error_message' => $run->error_message,
            ]);

        return Inertia::render('agents/Show', [
            'agent' => [
                'id' => $agent->id,
                'slug' => $agent->slug,
                'name' => $agent->name,
                'description' => $agent->description,
                'type' => $agent->type->value,
                'type_label' => $agent->type->label(),
                'default_config' => $agent->default_config,
            ],
            'storeAgent' => [
                'id' => $storeAgent->id,
                'is_enabled' => $storeAgent->is_enabled,
                'permission_level' => $storeAgent->permission_level->value,
                'config' => $storeAgent->getMergedConfig(),
                'last_run_at' => $storeAgent->last_run_at?->toISOString(),
                'next_run_at' => $storeAgent->next_run_at?->toISOString(),
            ],
            'configSchema' => $implementation->getConfigSchema(),
            'permissionLevels' => collect(AgentPermissionLevel::cases())->map(fn ($level) => [
                'value' => $level->value,
                'label' => $level->label(),
                'description' => $level->description(),
            ]),
            'recentRuns' => $recentRuns,
        ]);
    }

    /**
     * Update agent configuration.
     */
    public function update(Request $request, string $slug): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return redirect()->route('dashboard')
                ->with('error', 'Please select a store first.');
        }

        $agent = Agent::where('slug', $slug)->firstOrFail();
        $storeAgent = StoreAgent::getOrCreateForStore($store, $agent);

        $validated = $request->validate([
            'is_enabled' => ['required', 'boolean'],
            'permission_level' => ['required', 'string', 'in:auto,approve,block'],
            'config' => ['nullable', 'array'],
        ]);

        $storeAgent->update([
            'is_enabled' => $validated['is_enabled'],
            'permission_level' => AgentPermissionLevel::from($validated['permission_level']),
            'config' => $validated['config'] ?? [],
        ]);

        return redirect()->route('agents.show', $slug)
            ->with('success', 'Agent configuration updated.');
    }

    /**
     * Manually trigger an agent run.
     */
    public function run(string $slug): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return redirect()->route('dashboard')
                ->with('error', 'Please select a store first.');
        }

        $result = $this->runner->run($slug, $store, AgentTriggerType::Manual);

        if ($result->success) {
            return redirect()->route('agents.show', $slug)
                ->with('success', 'Agent run completed successfully.');
        }

        return redirect()->route('agents.show', $slug)
            ->with('error', 'Agent run failed: '.$result->errorMessage);
    }

    /**
     * Display agent run history.
     */
    public function runs(Request $request): Response|RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return redirect()->route('dashboard')
                ->with('error', 'Please select a store first.');
        }

        $agentSlug = $request->get('agent');
        $agentId = null;

        if ($agentSlug) {
            $agent = Agent::where('slug', $agentSlug)->first();
            $agentId = $agent?->id;
        }

        $runs = $this->orchestrator->getRunHistory($store, $agentId, 100);

        $runsData = $runs->map(fn ($run) => [
            'id' => $run->id,
            'agent_name' => $run->agent->name,
            'agent_slug' => $run->agent->slug,
            'status' => $run->status->value,
            'status_label' => $run->status->label(),
            'status_color' => $run->status->color(),
            'trigger_type' => $run->trigger_type->value,
            'trigger_type_label' => $run->trigger_type->label(),
            'started_at' => $run->started_at?->toISOString(),
            'completed_at' => $run->completed_at?->toISOString(),
            'duration_seconds' => $run->getDurationInSeconds(),
            'actions_count' => $run->actions->count(),
            'summary' => $run->summary,
            'error_message' => $run->error_message,
            'created_at' => $run->created_at->toISOString(),
        ]);

        $agents = Agent::orderBy('name')->get()->map(fn ($a) => [
            'slug' => $a->slug,
            'name' => $a->name,
        ]);

        return Inertia::render('agents/Runs', [
            'runs' => $runsData,
            'agents' => $agents,
            'currentAgent' => $agentSlug,
        ]);
    }
}
