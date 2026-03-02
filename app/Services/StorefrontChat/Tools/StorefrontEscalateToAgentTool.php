<?php

namespace App\Services\StorefrontChat\Tools;

use App\Events\ConversationStatusChanged;
use App\Models\StorefrontChatSession;
use App\Services\Chat\Tools\ChatToolInterface;

class StorefrontEscalateToAgentTool implements ChatToolInterface
{
    public function name(): string
    {
        return 'escalate_to_agent';
    }

    public function definition(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'Escalate the conversation to a human agent. Use this when a customer explicitly asks to speak to a real person, human, agent, or manager. Also use it when the customer is frustrated, has a complaint, or the issue is beyond your capabilities (order disputes, complex returns, etc.). Do not escalate unless the customer wants it or the situation clearly requires it.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'reason' => [
                        'type' => 'string',
                        'description' => 'Brief reason for escalation (e.g. "Customer requested to speak to a human", "Order dispute")',
                    ],
                    'session_id' => [
                        'type' => 'string',
                        'description' => 'The current chat session UUID (injected automatically)',
                    ],
                ],
                'required' => [],
            ],
        ];
    }

    public function execute(array $params, int $storeId): array
    {
        $sessionId = $params['session_id'] ?? null;

        if (! $sessionId) {
            return ['error' => 'Session ID is required'];
        }

        $session = StorefrontChatSession::withoutGlobalScopes()
            ->where('id', $sessionId)
            ->where('store_id', $storeId)
            ->first();

        if (! $session) {
            return ['error' => 'Session not found'];
        }

        $session->requestAgent();

        event(new ConversationStatusChanged($session));

        return [
            'success' => true,
            'message' => 'A human agent has been notified and will join the conversation shortly.',
        ];
    }
}
