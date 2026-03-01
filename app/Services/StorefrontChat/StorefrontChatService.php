<?php

namespace App\Services\StorefrontChat;

use App\Models\Store;
use App\Models\StorefrontChatMessage;
use App\Models\StorefrontChatSession;
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
     * Maximum number of agentic tool-use loops before forcing a text response.
     */
    protected int $maxToolLoops = 5;

    /**
     * Send a message and stream the response via an agentic tool-use loop.
     *
     * Claude can call one or more tools per turn. After executing them, we send
     * the results back and let Claude decide whether to call more tools or
     * respond with text. The loop continues until Claude produces a text-only
     * response or we hit the safety limit.
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

        $fullResponse = '';
        $allToolCalls = [];
        $totalTokens = 0;
        $loops = 0;

        // Agentic loop: keep calling Claude until it responds with only text
        while ($loops < $this->maxToolLoops) {
            $loops++;
            $turnToolCalls = [];
            $turnTextParts = [];

            foreach ($this->callClaude($systemPrompt, $messages, $store->id) as $event) {
                if ($event['type'] === 'token') {
                    $fullResponse .= $event['content'];
                    $turnTextParts[] = $event['content'];
                    yield $event;
                } elseif ($event['type'] === 'tool_use') {
                    $turnToolCalls[] = $event;
                    $allToolCalls[] = $event;
                    yield [
                        'type' => 'tool_use',
                        'tool' => $event['name'],
                        'status' => $this->toolExecutor->getToolDescription($event['name']),
                    ];
                } elseif ($event['type'] === 'usage') {
                    $totalTokens += $event['total'];
                }
            }

            // If Claude made no tool calls this turn, we're done
            if (empty($turnToolCalls)) {
                break;
            }

            // Build the assistant message with any text + all tool_use blocks
            $assistantContent = [];

            $turnText = implode('', $turnTextParts);
            if ($turnText !== '') {
                $assistantContent[] = [
                    'type' => 'text',
                    'text' => $turnText,
                ];
            }

            foreach ($turnToolCalls as $toolCall) {
                $assistantContent[] = [
                    'type' => 'tool_use',
                    'id' => $toolCall['id'],
                    'name' => $toolCall['name'],
                    'input' => $toolCall['input'],
                ];
            }

            $messages[] = [
                'role' => 'assistant',
                'content' => $assistantContent,
            ];

            // Execute all tools and build the tool_result user message
            $toolResults = [];

            foreach ($turnToolCalls as $toolCall) {
                $toolInput = $toolCall['input'];
                if ($toolCall['name'] === 'capture_lead') {
                    $toolInput['session_id'] = $session->id;
                }

                $result = $this->toolExecutor->execute($toolCall['name'], $toolInput, $store->id);

                yield [
                    'type' => 'tool_result',
                    'tool' => $toolCall['name'],
                    'result' => $result,
                ];

                $toolResults[] = [
                    'type' => 'tool_result',
                    'tool_use_id' => $toolCall['id'],
                    'content' => json_encode($result),
                ];
            }

            $messages[] = [
                'role' => 'user',
                'content' => $toolResults,
            ];

            // Loop back — Claude will see the tool results and decide next action
        }

        // Save the assistant message
        StorefrontChatMessage::create([
            'storefront_chat_session_id' => $session->id,
            'role' => 'assistant',
            'content' => $fullResponse,
            'tool_calls' => ! empty($allToolCalls) ? $allToolCalls : null,
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
            ->orderBy('created_at')
            ->get();

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
        $storeName = $store->name;

        return <<<PROMPT
        You are a friendly, natural sales assistant for {$storeName}. Talk like a real person working behind the counter — warm, concise, and helpful.

        HOW TO HAVE A CONVERSATION:
        1. Act like a real salesperson. When a customer asks something broad like "any deals?" or "what do you have?", don't immediately search. Instead, ask a natural follow-up to understand what they need:
           - "What are you shopping for today — rings, necklaces, bracelets?"
           - "Is this for yourself or a gift?"
           - "Do you have a budget in mind?"
           - "What's the occasion?"
        2. Guide the conversation with one or two short questions at a time. Don't ask everything at once.
        3. Only use tools to search products or look things up AFTER you understand what the customer wants. A vague query gives vague results — narrow it down first through conversation.
        4. Keep responses short and natural. One to three sentences is usually enough. Customers want a quick chat, not an essay.
        5. Never narrate what you're doing internally. Don't say "Let me search our system" or "I notice our database shows..." — just talk like a person.

        FINDING INFORMATION:
        6. Use the knowledge_search tool to find information about store policies, FAQs, shipping, returns, product knowledge, and anything else the customer asks about. Always search before saying you don't know.
        7. Use search_products for specific product searches when you know what the customer is looking for.
        8. Use knowledge_search for broader questions like "do you offer layaway?", "what's your return policy?", or "tell me about your store".

        SHOWING PRODUCTS:
        9. When you have enough context, use the tools to find real products. Never make up prices or details.
        10. Show the product name, price, and one key highlight. Keep it scannable.
        11. Format prices with currency symbols ($).
        12. For availability, say "in stock" or "currently unavailable" — never mention specific quantities.
        13. If nothing matches, suggest they contact the store directly.

        LEAD CAPTURE:
        14. When a customer shows strong buying interest (asks about specific high-value items, wants pricing details, inquires about custom orders, asks to be contacted, or wants to schedule an appointment), naturally offer to connect them with the store team.
        15. To connect them, ask for their name and either email or phone in a conversational way. For example: "I'd love to have one of our specialists reach out to you about this piece. Could I get your name and best email or phone number?"
        16. If the customer provides their contact details, use the capture_lead tool to save their information. Include a brief summary of what they're interested in.
        17. Never pressure for contact info. If they decline, move on.
        18. If a customer proactively volunteers their name and contact info, capture it right away.
        19. You do not need to ask for the session_id — it will be provided automatically.

        WHAT YOU MUST NEVER DO:
        - Never reveal product costs, wholesale prices, or profit margins
        - Never share exact inventory quantities
        - Never discuss other customers or their purchases
        - Never share sales data, revenue figures, or business metrics
        - Never provide information about the store's suppliers or vendors
        - Never process orders or payments — direct customers to the product page
        - Never discuss topics unrelated to the store, its products, or jewelry in general
        - Never describe your internal process or what tools you're using
        PROMPT;
    }

    /**
     * Call Claude API with streaming.
     *
     * @param  array<int, array<string, mixed>>  $messages
     * @return Generator<int, array<string, mixed>>
     */
    protected function callClaude(string $systemPrompt, array $messages, int $storeId): Generator
    {
        $payload = [
            'model' => $this->model,
            'system' => $systemPrompt,
            'messages' => $messages,
            'max_tokens' => 1024,
            'stream' => true,
        ];

        $tools = $this->toolExecutor->getDefinitions();
        if (! empty($tools)) {
            $payload['tools'] = $tools;
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

            if ($session) {
                return $session;
            }
        }

        return StorefrontChatSession::create([
            'store_id' => $storeId,
            'store_marketplace_id' => $marketplaceId,
            'visitor_id' => $visitorId,
        ]);
    }
}
