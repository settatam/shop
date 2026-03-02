<?php

namespace Tests\Feature\StorefrontChat;

use App\Enums\ConversationStatus;
use App\Events\ConversationStatusChanged;
use App\Models\Store;
use App\Models\StorefrontChatMessage;
use App\Models\StorefrontChatSession;
use App\Models\StoreMarketplace;
use App\Services\StorefrontChat\StorefrontChatService;
use App\Services\StorefrontChat\Tools\StorefrontEscalateToAgentTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StorefrontEscalateToAgentTest extends TestCase
{
    use RefreshDatabase;

    protected Store $store;

    protected StoreMarketplace $marketplace;

    protected StorefrontChatSession $session;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = Store::factory()->create(['name' => 'Test Store']);
        $this->marketplace = StoreMarketplace::factory()->shopify()->create([
            'store_id' => $this->store->id,
            'status' => 'active',
        ]);
        $this->session = StorefrontChatSession::factory()->create([
            'store_id' => $this->store->id,
            'store_marketplace_id' => $this->marketplace->id,
            'visitor_id' => 'test-visitor-escalation',
        ]);
    }

    public function test_tool_sets_session_status_to_waiting_for_agent(): void
    {
        Event::fake();

        $tool = new StorefrontEscalateToAgentTool;

        $result = $tool->execute([
            'session_id' => $this->session->id,
            'reason' => 'Customer requested to speak to a human',
        ], $this->store->id);

        $this->assertTrue($result['success']);

        $this->session->refresh();
        $this->assertEquals(ConversationStatus::WaitingForAgent, $this->session->status);
    }

    public function test_tool_dispatches_conversation_status_changed_event(): void
    {
        Event::fake();

        $tool = new StorefrontEscalateToAgentTool;

        $tool->execute([
            'session_id' => $this->session->id,
        ], $this->store->id);

        Event::assertDispatched(ConversationStatusChanged::class);
    }

    public function test_tool_returns_error_without_session_id(): void
    {
        $tool = new StorefrontEscalateToAgentTool;

        $result = $tool->execute([], $this->store->id);

        $this->assertArrayHasKey('error', $result);
        $this->assertEquals('Session ID is required', $result['error']);
    }

    public function test_tool_returns_error_for_invalid_session(): void
    {
        $tool = new StorefrontEscalateToAgentTool;

        $result = $tool->execute([
            'session_id' => 'non-existent-id',
        ], $this->store->id);

        $this->assertArrayHasKey('error', $result);
        $this->assertEquals('Session not found', $result['error']);
    }

    public function test_ai_skips_processing_after_escalation(): void
    {
        $this->session->update(['status' => ConversationStatus::WaitingForAgent]);

        $service = app(StorefrontChatService::class);

        $events = [];
        foreach ($service->streamMessage($this->session, 'Are you still there?', $this->store) as $event) {
            $events[] = $event;
        }

        // Should only have a done event with 0 tokens — AI did not process
        $this->assertCount(1, $events);
        $this->assertEquals('done', $events[0]['type']);
        $this->assertEquals(0, $events[0]['tokens_used']);

        // User message should still be saved
        $userMsg = StorefrontChatMessage::where('storefront_chat_session_id', $this->session->id)
            ->where('role', 'user')
            ->first();
        $this->assertNotNull($userMsg);
    }

    public function test_agentic_loop_escalates_when_claude_calls_tool(): void
    {
        Event::fake();

        $callCount = 0;
        Http::fake(function ($request) use (&$callCount) {
            if (! str_contains($request->url(), 'api.anthropic.com')) {
                return Http::response('', 404);
            }

            $callCount++;

            if ($callCount === 1) {
                return Http::response(
                    $this->buildToolUseResponse('toolu_escalate', 'escalate_to_agent', [
                        'reason' => 'Customer requested to speak to a human',
                    ]),
                    200,
                    ['Content-Type' => 'text/event-stream']
                );
            }

            return Http::response(
                $this->buildTextResponse("I've connected you with our team. A human agent will be with you shortly!"),
                200,
                ['Content-Type' => 'text/event-stream']
            );
        });

        $service = app(StorefrontChatService::class);

        $events = [];
        foreach ($service->streamMessage($this->session, 'I want to speak to a real person', $this->store) as $event) {
            $events[] = $event;
        }

        // Verify the tool was called
        $toolUseEvent = collect($events)->firstWhere('type', 'tool_use');
        $this->assertEquals('escalate_to_agent', $toolUseEvent['tool']);

        // Verify the tool result was successful
        $toolResultEvent = collect($events)->firstWhere('type', 'tool_result');
        $this->assertTrue($toolResultEvent['result']['success']);

        // Verify session status was updated
        $this->session->refresh();
        $this->assertEquals(ConversationStatus::WaitingForAgent, $this->session->status);

        // Verify event was dispatched
        Event::assertDispatched(ConversationStatusChanged::class);

        // Verify text response was streamed
        $fullText = collect($events)->where('type', 'token')->pluck('content')->implode('');
        $this->assertStringContainsString('human agent', $fullText);
    }

    /**
     * Build a fake SSE streaming response body that simulates Claude calling a tool.
     */
    protected function buildToolUseResponse(string $toolId, string $toolName, array $input): string
    {
        $inputJson = json_encode($input);

        $events = [];
        $events[] = 'event: message_start'."\n".'data: '.json_encode([
            'type' => 'message_start',
            'message' => [
                'id' => 'msg_'.uniqid(),
                'type' => 'message',
                'role' => 'assistant',
                'usage' => ['input_tokens' => 150, 'output_tokens' => 0],
            ],
        ]);

        $events[] = 'event: content_block_start'."\n".'data: '.json_encode([
            'type' => 'content_block_start',
            'index' => 0,
            'content_block' => [
                'type' => 'tool_use',
                'id' => $toolId,
                'name' => $toolName,
            ],
        ]);

        $events[] = 'event: content_block_delta'."\n".'data: '.json_encode([
            'type' => 'content_block_delta',
            'index' => 0,
            'delta' => [
                'type' => 'input_json_delta',
                'partial_json' => $inputJson,
            ],
        ]);

        $events[] = 'event: content_block_stop'."\n".'data: '.json_encode([
            'type' => 'content_block_stop',
            'index' => 0,
        ]);

        $events[] = 'event: message_delta'."\n".'data: '.json_encode([
            'type' => 'message_delta',
            'delta' => ['stop_reason' => 'tool_use'],
            'usage' => ['output_tokens' => 30],
        ]);

        $events[] = 'event: message_stop'."\n".'data: '.json_encode([
            'type' => 'message_stop',
        ]);

        return implode("\n\n", $events)."\n\n";
    }

    /**
     * Build a fake SSE streaming response body that simulates Claude responding with text.
     */
    protected function buildTextResponse(string $text): string
    {
        $events = [];
        $events[] = 'event: message_start'."\n".'data: '.json_encode([
            'type' => 'message_start',
            'message' => [
                'id' => 'msg_'.uniqid(),
                'type' => 'message',
                'role' => 'assistant',
                'usage' => ['input_tokens' => 250, 'output_tokens' => 0],
            ],
        ]);

        $events[] = 'event: content_block_start'."\n".'data: '.json_encode([
            'type' => 'content_block_start',
            'index' => 0,
            'content_block' => ['type' => 'text', 'text' => ''],
        ]);

        $events[] = 'event: content_block_delta'."\n".'data: '.json_encode([
            'type' => 'content_block_delta',
            'index' => 0,
            'delta' => ['type' => 'text_delta', 'text' => $text],
        ]);

        $events[] = 'event: content_block_stop'."\n".'data: '.json_encode([
            'type' => 'content_block_stop',
            'index' => 0,
        ]);

        $events[] = 'event: message_delta'."\n".'data: '.json_encode([
            'type' => 'message_delta',
            'delta' => ['stop_reason' => 'end_turn'],
            'usage' => ['output_tokens' => 60],
        ]);

        $events[] = 'event: message_stop'."\n".'data: '.json_encode([
            'type' => 'message_stop',
        ]);

        return implode("\n\n", $events)."\n\n";
    }
}
