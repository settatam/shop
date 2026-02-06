<?php

namespace App\Services\Agents;

use App\Models\AgentAction;
use App\Models\User;
use App\Services\Agents\Results\ActionResult;
use Illuminate\Support\Facades\Log;

class ActionExecutor
{
    public function __construct(
        protected AgentRegistry $registry
    ) {}

    /**
     * Execute a single action.
     */
    public function execute(AgentAction $action): ActionResult
    {
        if (! $action->canBeExecuted()) {
            return ActionResult::failure('Action cannot be executed in its current state');
        }

        $actionHandler = $this->registry->getAction($action->action_type);

        if (! $actionHandler) {
            $action->markAsFailed();

            return ActionResult::failure("No handler found for action type: {$action->action_type}");
        }

        try {
            if (! $actionHandler->validatePayload($action->payload ?? [])) {
                $action->markAsFailed();

                return ActionResult::failure('Invalid action payload');
            }

            $result = $actionHandler->execute($action);

            if ($result->success) {
                $action->markAsExecuted();
            } else {
                $action->markAsFailed();
            }

            return $result;
        } catch (\Throwable $e) {
            Log::error('Action execution failed', [
                'action_id' => $action->id,
                'action_type' => $action->action_type,
                'error' => $e->getMessage(),
            ]);

            $action->markAsFailed();

            return ActionResult::failure($e->getMessage());
        }
    }

    /**
     * Approve and optionally execute an action.
     */
    public function approve(AgentAction $action, User $user, bool $autoExecute = true): ActionResult
    {
        if (! $action->isPending()) {
            return ActionResult::failure('Action is not pending approval');
        }

        $action->approve($user);

        if ($autoExecute) {
            return $this->execute($action);
        }

        return ActionResult::success('Action approved');
    }

    /**
     * Reject an action.
     */
    public function reject(AgentAction $action, User $user): ActionResult
    {
        if (! $action->isPending()) {
            return ActionResult::failure('Action is not pending approval');
        }

        $action->reject($user);

        return ActionResult::success('Action rejected');
    }

    /**
     * Bulk approve actions.
     *
     * @param  array<int>  $actionIds
     */
    public function bulkApprove(array $actionIds, User $user, bool $autoExecute = true): array
    {
        $results = [];

        $actions = AgentAction::whereIn('id', $actionIds)
            ->where('status', 'pending')
            ->get();

        foreach ($actions as $action) {
            $results[$action->id] = $this->approve($action, $user, $autoExecute);
        }

        return $results;
    }

    /**
     * Bulk reject actions.
     *
     * @param  array<int>  $actionIds
     */
    public function bulkReject(array $actionIds, User $user): array
    {
        $results = [];

        $actions = AgentAction::whereIn('id', $actionIds)
            ->where('status', 'pending')
            ->get();

        foreach ($actions as $action) {
            $results[$action->id] = $this->reject($action, $user);
        }

        return $results;
    }

    /**
     * Rollback an executed action.
     */
    public function rollback(AgentAction $action): ActionResult
    {
        $actionHandler = $this->registry->getAction($action->action_type);

        if (! $actionHandler) {
            return ActionResult::failure("No handler found for action type: {$action->action_type}");
        }

        try {
            $success = $actionHandler->rollback($action);

            return $success
                ? ActionResult::success('Action rolled back successfully')
                : ActionResult::failure('Rollback not supported or failed');
        } catch (\Throwable $e) {
            Log::error('Action rollback failed', [
                'action_id' => $action->id,
                'error' => $e->getMessage(),
            ]);

            return ActionResult::failure($e->getMessage());
        }
    }
}
