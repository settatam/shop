<?php

namespace App\Services\Chat\Tools;

use App\Enums\AgentActionStatus;
use App\Models\Agent;
use App\Models\AgentAction;

class PendingActionsTool implements ChatToolInterface
{
    public function name(): string
    {
        return 'get_pending_actions';
    }

    public function definition(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'Get pending agent actions that need approval. Use this when users ask about pending approvals, what agents are suggesting, or actions waiting for review.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'agent_slug' => [
                        'type' => 'string',
                        'enum' => ['auto-pricing', 'dead-stock', 'new-item-researcher'],
                        'description' => 'Filter by specific agent. Leave empty for all agents.',
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'description' => 'Maximum number of actions to return (default 10)',
                    ],
                ],
                'required' => [],
            ],
        ];
    }

    public function execute(array $params, int $storeId): array
    {
        $agentSlug = $params['agent_slug'] ?? null;
        $limit = min($params['limit'] ?? 10, 50);

        $query = AgentAction::where('store_id', $storeId)
            ->where('status', AgentActionStatus::Pending)
            ->with(['agentRun.agent'])
            ->orderBy('created_at', 'desc');

        if ($agentSlug) {
            $agent = Agent::where('slug', $agentSlug)->first();
            if ($agent) {
                $query->whereHas('agentRun', function ($q) use ($agent) {
                    $q->where('agent_id', $agent->id);
                });
            }
        }

        $actions = $query->limit($limit)->get();

        $totalPending = AgentAction::where('store_id', $storeId)
            ->where('status', AgentActionStatus::Pending)
            ->count();

        if ($actions->isEmpty()) {
            return [
                'total_pending' => 0,
                'message' => 'No pending actions require approval.',
                'actions' => [],
            ];
        }

        return [
            'total_pending' => $totalPending,
            'showing' => $actions->count(),
            'actions' => $actions->map(function ($action) {
                $payload = $action->payload ?? [];

                return [
                    'id' => $action->id,
                    'agent' => $action->agentRun?->agent?->name ?? 'Unknown',
                    'type' => $action->action_type,
                    'type_description' => $this->getTypeDescription($action->action_type),
                    'summary' => $this->buildActionSummary($action->action_type, $payload),
                    'created_at' => $action->created_at->diffForHumans(),
                ];
            })->toArray(),
        ];
    }

    protected function getTypeDescription(string $type): string
    {
        return match ($type) {
            'price_update' => 'Price Change',
            'markdown_schedule' => 'Markdown',
            'send_notification' => 'Customer Notification',
            'create_listing' => 'New Listing',
            'flag_for_review' => 'Flag for Review',
            default => ucwords(str_replace('_', ' ', $type)),
        };
    }

    protected function buildActionSummary(string $type, array $payload): string
    {
        return match ($type) {
            'price_update' => sprintf(
                'Change price from %s to %s',
                $payload['before']['price'] ?? '?',
                $payload['after']['price'] ?? '?'
            ),
            'markdown_schedule' => sprintf(
                '%s%% markdown suggested',
                $payload['discount_percent'] ?? '?'
            ),
            'send_notification' => sprintf(
                'Notify %d customer(s)',
                count($payload['customer_ids'] ?? [])
            ),
            default => 'Action pending approval',
        };
    }
}
