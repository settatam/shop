<?php

namespace App\Services\StorefrontChat;

use App\Models\Store;
use App\Models\StorefrontChatMessage;
use App\Models\StorefrontChatSession;
use App\Models\StoreKnowledgeBaseEntry;
use Generator;
use Illuminate\Support\Facades\Http;

class StorefrontChatService
{
    protected ?string $apiKey;

    protected string $model;

    protected string $baseUrl = 'https://api.anthropic.com/v1';

    protected StorefrontChatToolExecutor $toolExecutor;

    public function __construct(StorefrontChatToolExecutor $toolExecutor)
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
    public function streamMessage(StorefrontChatSession $session, string $userMessage, Store $store): Generator
    {
        // Save the user message
        StorefrontChatMessage::create([
            'storefront_chat_session_id' => $session->id,
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
        StorefrontChatMessage::create([
            'storefront_chat_session_id' => $session->id,
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
    protected function buildMessages(StorefrontChatSession $session): array
    {
        $recentMessages = $session->messages()
            ->orderByDesc('created_at')
            ->limit(8)
            ->get()
            ->reverse()
            ->values();

        return $recentMessages->map(function (StorefrontChatMessage $msg) {
            return [
                'role' => $msg->role,
                'content' => $msg->content,
            ];
        })->toArray();
    }

    /**
     * Get the system prompt for the storefront assistant.
     */
    protected function getSystemPrompt(Store $store): string
    {
        $knowledgeBase = $this->buildKnowledgeBaseContext($store->id);
        $storeName = $store->name;

        $prompt = <<<PROMPT
        You are a knowledgeable and friendly jewelry sales assistant for {$storeName}. You help customers find the perfect piece by answering questions about products, materials, availability, and store policies.

        GUIDELINES:
        1. Be warm, helpful, and professional. Use conversational language.
        2. When discussing jewelry, demonstrate expertise about materials (gold karats, gemstone types, precious metals, settings, etc.)
        3. Always use the available tools to fetch real product data — never guess about prices, availability, or product details.
        4. If a customer asks about something you cannot find, suggest they contact the store directly.
        5. Recommend products that match what the customer describes. Ask clarifying questions about preferences (budget, style, occasion, etc.).
        6. Format prices nicely with currency symbols.
        7. When showing products, include the product name, price, and a brief highlight of key features.
        8. For availability, only say "in stock" or "currently unavailable" — never mention specific quantities.
        9. Keep responses concise and helpful. Customers want quick answers.

        WHAT YOU MUST NEVER DO:
        - Never reveal product costs, wholesale prices, or profit margins
        - Never share exact inventory quantities (only in-stock or out-of-stock)
        - Never discuss other customers or their purchases
        - Never share sales data, revenue figures, or business metrics
        - Never provide information about the store's suppliers or vendors
        - Never process orders or payments — direct customers to the product page for purchases
        - Never discuss topics unrelated to the store, its products, or jewelry in general

        Use the available tools to search products, get details, check availability, and retrieve store policies. Always prefer tool results over your general knowledge.
        PROMPT;

        if ($knowledgeBase) {
            $prompt .= "\n\nSTORE KNOWLEDGE BASE:\n{$knowledgeBase}";
        }

        return $prompt;
    }

    /**
     * Build knowledge base context from store entries.
     */
    protected function buildKnowledgeBaseContext(int $storeId): string
    {
        $entries = StoreKnowledgeBaseEntry::where('store_id', $storeId)
            ->where('is_active', true)
            ->orderBy('type')
            ->orderBy('sort_order')
            ->get();

        if ($entries->isEmpty()) {
            return '';
        }

        $sections = [];
        $grouped = $entries->groupBy('type');

        foreach ($grouped as $type => $group) {
            $label = match ($type) {
                'return_policy' => 'Return Policy',
                'shipping_info' => 'Shipping Information',
                'care_instructions' => 'Jewelry Care Instructions',
                'faq' => 'Frequently Asked Questions',
                'about' => 'About the Store',
                default => ucfirst(str_replace('_', ' ', $type)),
            };

            $content = $group->map(fn ($entry) => "**{$entry->title}**: {$entry->content}")->implode("\n");
            $sections[] = "[{$label}]\n{$content}";
        }

        return implode("\n\n", $sections);
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
            'max_tokens' => 1024,
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

            while (($pos = strpos($buffer, "\n\n")) !== false) {
                $event = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 2);

                $data = null;
                foreach (explode("\n", $event) as $line) {
                    if (str_starts_with($line, 'data: ')) {
                        $data = json_decode(substr($line, 6), true);
                    }
                }

                if (! $data) {
                    continue;
                }

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
     * Get or create a storefront chat session.
     */
    public function getOrCreateSession(
        ?string $sessionId,
        int $storeId,
        int $marketplaceId,
        string $visitorId,
    ): StorefrontChatSession {
        if ($sessionId) {
            $session = StorefrontChatSession::where('id', $sessionId)
                ->where('store_id', $storeId)
                ->where('visitor_id', $visitorId)
                ->first();

            if ($session && ! $session->isExpired()) {
                return $session;
            }
        }

        return StorefrontChatSession::create([
            'store_id' => $storeId,
            'store_marketplace_id' => $marketplaceId,
            'visitor_id' => $visitorId,
            'expires_at' => now()->addMinutes(30),
        ]);
    }
}
