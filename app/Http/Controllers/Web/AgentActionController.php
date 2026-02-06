<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AgentAction;
use App\Services\Agents\ActionExecutor;
use App\Services\StoreContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AgentActionController extends Controller
{
    public function __construct(
        protected StoreContext $storeContext,
        protected ActionExecutor $actionExecutor,
    ) {}

    /**
     * Display pending agent actions.
     */
    public function index(Request $request): Response|RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return redirect()->route('dashboard')
                ->with('error', 'Please select a store first.');
        }

        $status = $request->get('status', 'pending');

        $query = $store->agentActions()
            ->with(['agentRun.agent', 'actionable', 'approvedByUser'])
            ->orderBy('created_at', 'desc');

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        $actions = $query->limit(100)->get()->map(fn ($action) => [
            'id' => $action->id,
            'action_type' => $action->action_type,
            'status' => $action->status->value,
            'status_label' => $action->status->label(),
            'status_color' => $action->status->color(),
            'requires_approval' => $action->requires_approval,
            'agent_name' => $action->agentRun?->agent?->name,
            'agent_slug' => $action->agentRun?->agent?->slug,
            'actionable_type' => class_basename($action->actionable_type ?? ''),
            'actionable_id' => $action->actionable_id,
            'actionable_title' => $action->actionable?->title ?? $action->actionable?->name ?? 'Unknown',
            'payload' => $action->payload,
            'before' => $action->getBeforeValue(),
            'after' => $action->getAfterValue(),
            'reasoning' => $action->getReasoning(),
            'approved_by' => $action->approvedByUser?->name,
            'approved_at' => $action->approved_at?->toISOString(),
            'executed_at' => $action->executed_at?->toISOString(),
            'created_at' => $action->created_at->toISOString(),
        ]);

        // Get counts for status tabs
        $pendingCount = $store->agentActions()->where('status', 'pending')->count();
        $executedCount = $store->agentActions()->where('status', 'executed')->count();
        $rejectedCount = $store->agentActions()->where('status', 'rejected')->count();

        return Inertia::render('agents/Actions', [
            'actions' => $actions,
            'currentStatus' => $status,
            'counts' => [
                'pending' => $pendingCount,
                'executed' => $executedCount,
                'rejected' => $rejectedCount,
            ],
        ]);
    }

    /**
     * Approve a single action.
     */
    public function approve(AgentAction $action): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store || $action->store_id !== $store->id) {
            abort(404);
        }

        $user = auth()->user();
        $result = $this->actionExecutor->approve($action, $user, true);

        if ($result->success) {
            return redirect()->back()
                ->with('success', 'Action approved and executed.');
        }

        return redirect()->back()
            ->with('error', 'Failed to execute action: '.$result->message);
    }

    /**
     * Reject a single action.
     */
    public function reject(AgentAction $action): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store || $action->store_id !== $store->id) {
            abort(404);
        }

        $user = auth()->user();
        $result = $this->actionExecutor->reject($action, $user);

        if ($result->success) {
            return redirect()->back()
                ->with('success', 'Action rejected.');
        }

        return redirect()->back()
            ->with('error', 'Failed to reject action: '.$result->message);
    }

    /**
     * Bulk approve actions.
     */
    public function bulkApprove(Request $request): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return redirect()->route('dashboard')
                ->with('error', 'Please select a store first.');
        }

        $validated = $request->validate([
            'action_ids' => ['required', 'array', 'min:1'],
            'action_ids.*' => ['integer', 'exists:agent_actions,id'],
        ]);

        // Verify all actions belong to this store
        $actionIds = AgentAction::whereIn('id', $validated['action_ids'])
            ->where('store_id', $store->id)
            ->where('status', 'pending')
            ->pluck('id')
            ->toArray();

        if (empty($actionIds)) {
            return redirect()->back()
                ->with('error', 'No valid pending actions to approve.');
        }

        $user = auth()->user();
        $results = $this->actionExecutor->bulkApprove($actionIds, $user, true);

        $successful = collect($results)->filter(fn ($r) => $r->success)->count();
        $failed = count($results) - $successful;

        if ($failed > 0) {
            return redirect()->back()
                ->with('warning', "{$successful} actions approved, {$failed} failed.");
        }

        return redirect()->back()
            ->with('success', "{$successful} actions approved and executed.");
    }

    /**
     * Bulk reject actions.
     */
    public function bulkReject(Request $request): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return redirect()->route('dashboard')
                ->with('error', 'Please select a store first.');
        }

        $validated = $request->validate([
            'action_ids' => ['required', 'array', 'min:1'],
            'action_ids.*' => ['integer', 'exists:agent_actions,id'],
        ]);

        // Verify all actions belong to this store
        $actionIds = AgentAction::whereIn('id', $validated['action_ids'])
            ->where('store_id', $store->id)
            ->where('status', 'pending')
            ->pluck('id')
            ->toArray();

        if (empty($actionIds)) {
            return redirect()->back()
                ->with('error', 'No valid pending actions to reject.');
        }

        $user = auth()->user();
        $results = $this->actionExecutor->bulkReject($actionIds, $user);

        $successful = collect($results)->filter(fn ($r) => $r->success)->count();

        return redirect()->back()
            ->with('success', "{$successful} actions rejected.");
    }
}
