<?php

namespace App\Services\Chat;

use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\Store;
use Generator;
use Illuminate\Support\Facades\Http;

class ChatService
{
    protected ?string $apiKey;

    protected string $model;

    protected string $baseUrl = 'https://api.anthropic.com/v1';

    protected ChatToolExecutor $toolExecutor;

    public function __construct(ChatToolExecutor $toolExecutor)
    {
        $this->apiKey = config('services.anthropic.api_key', '');
        $this->model = config('services.anthropic.model', 'claude-sonnet-4-20250514');
        $this->toolExecutor = $toolExecutor;
    }

    /**
     * Send a message and stream the response.
     *
     * @return Generator<int, array<string, mixed>>
     */
    public function streamMessage(ChatSession $session, string $userMessage, Store $store): Generator
    {
        // Save the user message
        $userMsg = ChatMessage::create([
            'chat_session_id' => $session->id,
            'role' => 'user',
            'content' => $userMessage,
        ]);

        $session->touchLastMessage();
        $session->generateTitle();

        // Build conversation history
        $messages = $this->buildMessages($session);

        // Get system prompt
        $systemPrompt = $this->getSystemPrompt($store);

        // Call Claude with streaming
        $fullResponse = '';
        $toolCalls = [];
        $totalTokens = 0;

        // Initial API call
        foreach ($this->callClaude($systemPrompt, $messages, $store->id) as $event) {
            if ($event['type'] === 'token') {
                $fullResponse .= $event['content'];
                yield $event;
            } elseif ($event['type'] === 'tool_use') {
                $toolCalls[] = $event;
                yield [
                    'type' => 'tool_use',
                    'tool' => $event['name'],
                    'status' => $this->toolExecutor->getToolDescription($event['name']),
                ];

                // Execute the tool
                $result = $this->toolExecutor->execute($event['name'], $event['input'], $store->id);

                yield [
                    'type' => 'tool_result',
                    'tool' => $event['name'],
                    'result' => $result,
                ];

                // Continue conversation with tool result
                $messages[] = [
                    'role' => 'assistant',
                    'content' => [
                        [
                            'type' => 'tool_use',
                            'id' => $event['id'],
                            'name' => $event['name'],
                            'input' => $event['input'],
                        ],
                    ],
                ];

                $messages[] = [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'tool_result',
                            'tool_use_id' => $event['id'],
                            'content' => json_encode($result),
                        ],
                    ],
                ];

                // Get the follow-up response
                foreach ($this->callClaude($systemPrompt, $messages, $store->id, false) as $followUp) {
                    if ($followUp['type'] === 'token') {
                        $fullResponse .= $followUp['content'];
                        yield $followUp;
                    } elseif ($followUp['type'] === 'usage') {
                        $totalTokens += $followUp['total'];
                    }
                }
            } elseif ($event['type'] === 'usage') {
                $totalTokens += $event['total'];
            }
        }

        // Save the assistant message
        ChatMessage::create([
            'chat_session_id' => $session->id,
            'role' => 'assistant',
            'content' => $fullResponse,
            'tool_calls' => ! empty($toolCalls) ? $toolCalls : null,
            'tokens_used' => $totalTokens,
        ]);

        yield [
            'type' => 'done',
            'session_id' => $session->id,
            'tokens_used' => $totalTokens,
        ];
    }

    /**
     * Build messages array from session history.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function buildMessages(ChatSession $session): array
    {
        $recentMessages = $session->messages()
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->reverse()
            ->values();

        return $recentMessages->map(function (ChatMessage $msg) {
            return [
                'role' => $msg->role,
                'content' => $msg->content,
            ];
        })->toArray();
    }

    /**
     * Get the system prompt for the assistant.
     */
    protected function getSystemPrompt(Store $store): string
    {
        return <<<PROMPT
You are a helpful AI assistant for {$store->name}, an inventory management system. You help store staff understand their business performance by answering questions about sales, orders, inventory, and customers.

GUIDELINES:
1. Be conversational and friendly, but concise
2. When presenting numbers, format them nicely (use currency symbols, percentages, etc.)
3. Provide context and insights when sharing data (e.g., "That's a 15% increase from yesterday")
4. If you don't have enough data to answer, say so clearly
5. Focus only on business data from this store - don't discuss other topics
6. Use the available tools to fetch real data rather than making assumptions

AVAILABLE DATA:
- Sales and revenue metrics
- Order status and counts
- Inventory levels and alerts
- Customer information and insights
- Product performance data

When users ask about performance, sales, or "how we're doing", use the get_sales_summary tool to get actual data.
PROMPT;
    }

    /**
     * Call Claude API with streaming.
     *
     * @param  array<int, array<string, mixed>>  $messages
     * @return Generator<int, array<string, mixed>>
     */
    protected function callClaude(string $systemPrompt, array $messages, int $storeId, bool $includeTools = true): Generator
    {
        $payload = [
            'model' => $this->model,
            'system' => $systemPrompt,
            'messages' => $messages,
            'max_tokens' => 2048,
            'stream' => true,
        ];

        if ($includeTools) {
            $tools = $this->toolExecutor->getDefinitions();
            if (! empty($tools)) {
                $payload['tools'] = $tools;
            }
        }

        $response = Http::withHeaders([
            'x-api-key' => $this->apiKey,
            'anthropic-version' => '2023-06-01',
            'Content-Type' => 'application/json',
        ])->withOptions([
            'stream' => true,
        ])->timeout(120)->post("{$this->baseUrl}/messages", $payload);

        if ($response->failed()) {
            yield [
                'type' => 'error',
                'message' => 'Failed to connect to AI service',
            ];

            return;
        }

        $body = $response->toPsrResponse()->getBody();
        $buffer = '';
        $currentToolUse = null;
        $inputTokens = 0;
        $outputTokens = 0;

        while (! $body->eof()) {
            $chunk = $body->read(1024);
            $buffer .= $chunk;

            // Process complete SSE events
            while (($pos = strpos($buffer, "\n\n")) !== false) {
                $event = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 2);

                // Parse SSE event
                $data = null;
                foreach (explode("\n", $event) as $line) {
                    if (str_starts_with($line, 'data: ')) {
                        $data = json_decode(substr($line, 6), true);
                    }
                }

                if (! $data) {
                    continue;
                }

                // Handle different event types
                switch ($data['type'] ?? '') {
                    case 'content_block_start':
                        if (($data['content_block']['type'] ?? '') === 'tool_use') {
                            $currentToolUse = [
                                'id' => $data['content_block']['id'],
                                'name' => $data['content_block']['name'],
                                'input' => '',
                            ];
                        }
                        break;

                    case 'content_block_delta':
                        $delta = $data['delta'] ?? [];
                        if (($delta['type'] ?? '') === 'text_delta') {
                            yield [
                                'type' => 'token',
                                'content' => $delta['text'] ?? '',
                            ];
                        } elseif (($delta['type'] ?? '') === 'input_json_delta') {
                            if ($currentToolUse) {
                                $currentToolUse['input'] .= $delta['partial_json'] ?? '';
                            }
                        }
                        break;

                    case 'content_block_stop':
                        if ($currentToolUse) {
                            $currentToolUse['input'] = json_decode($currentToolUse['input'], true) ?? [];
                            yield [
                                'type' => 'tool_use',
                                'id' => $currentToolUse['id'],
                                'name' => $currentToolUse['name'],
                                'input' => $currentToolUse['input'],
                            ];
                            $currentToolUse = null;
                        }
                        break;

                    case 'message_delta':
                        if (isset($data['usage'])) {
                            $outputTokens = $data['usage']['output_tokens'] ?? 0;
                        }
                        break;

                    case 'message_start':
                        if (isset($data['message']['usage'])) {
                            $inputTokens = $data['message']['usage']['input_tokens'] ?? 0;
                        }
                        break;

                    case 'message_stop':
                        yield [
                            'type' => 'usage',
                            'input' => $inputTokens,
                            'output' => $outputTokens,
                            'total' => $inputTokens + $outputTokens,
                        ];
                        break;
                }
            }
        }
    }

    /**
     * Get or create a chat session for the user.
     */
    public function getOrCreateSession(?string $sessionId, int $storeId, int $userId): ChatSession
    {
        if ($sessionId) {
            $session = ChatSession::where('id', $sessionId)
                ->where('store_id', $storeId)
                ->where('user_id', $userId)
                ->first();

            if ($session) {
                return $session;
            }
        }

        return ChatSession::create([
            'store_id' => $storeId,
            'user_id' => $userId,
        ]);
    }

    /**
     * Get recent sessions for a user.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, ChatSession>
     */
    public function getRecentSessions(int $storeId, int $userId, int $limit = 10)
    {
        return ChatSession::where('store_id', $storeId)
            ->where('user_id', $userId)
            ->whereNotNull('title')
            ->orderByDesc('last_message_at')
            ->limit($limit)
            ->get();
    }
}
