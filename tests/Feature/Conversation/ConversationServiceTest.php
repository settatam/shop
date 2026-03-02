<?php

namespace Tests\Feature\Conversation;

use App\Enums\ConversationChannel;
use App\Enums\ConversationStatus;
use App\Events\ConversationStatusChanged;
use App\Events\NewChatMessage;
use App\Models\StorefrontChatMessage;
use App\Models\StorefrontChatSession;
use App\Models\User;
use App\Services\ConversationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class ConversationServiceTest extends TestCase
{
    use RefreshDatabase;

    protected ConversationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ConversationService::class);
    }

    public function test_assign_sets_status_and_agent(): void
    {
        Event::fake();

        $session = StorefrontChatSession::factory()->create();
        $agent = User::factory()->create();

        $this->service->assign($session, $agent);

        $session->refresh();

        $this->assertEquals(ConversationStatus::Assigned, $session->status);
        $this->assertEquals($agent->id, $session->assigned_agent_id);
        $this->assertNotNull($session->assigned_at);

        Event::assertDispatched(ConversationStatusChanged::class);
    }

    public function test_release_sets_status_to_open(): void
    {
        Event::fake();

        $agent = User::factory()->create();
        $session = StorefrontChatSession::factory()->assigned()->create([
            'assigned_agent_id' => $agent->id,
        ]);

        $this->service->release($session);

        $session->refresh();

        $this->assertEquals(ConversationStatus::Open, $session->status);
        $this->assertNull($session->assigned_agent_id);
        $this->assertNull($session->assigned_at);

        Event::assertDispatched(ConversationStatusChanged::class);
    }

    public function test_close_sets_status_to_closed(): void
    {
        Event::fake();

        $session = StorefrontChatSession::factory()->create();

        $this->service->close($session);

        $session->refresh();

        $this->assertEquals(ConversationStatus::Closed, $session->status);
        $this->assertNotNull($session->closed_at);

        Event::assertDispatched(ConversationStatusChanged::class);
    }

    public function test_send_agent_message_creates_message_with_agent_role(): void
    {
        Event::fake();

        $session = StorefrontChatSession::factory()->create();
        $agent = User::factory()->create();

        $message = $this->service->sendAgentMessage($session, $agent, 'Hello, how can I help?');

        $this->assertEquals('agent', $message->role);
        $this->assertEquals($agent->id, $message->agent_id);
        $this->assertEquals('Hello, how can I help?', $message->content);
        $this->assertEquals($session->id, $message->storefront_chat_session_id);

        Event::assertDispatched(NewChatMessage::class);
    }

    public function test_session_is_assigned_check(): void
    {
        $session = StorefrontChatSession::factory()->create([
            'status' => ConversationStatus::Assigned,
        ]);

        $this->assertTrue($session->isAssigned());
        $this->assertFalse($session->isOpen());
    }

    public function test_session_is_open_check(): void
    {
        $session = StorefrontChatSession::factory()->create([
            'status' => ConversationStatus::Open,
        ]);

        $this->assertTrue($session->isOpen());
        $this->assertFalse($session->isAssigned());
    }

    public function test_message_is_from_agent(): void
    {
        $message = StorefrontChatMessage::factory()->agent()->create();

        $this->assertTrue($message->isFromAgent());
        $this->assertFalse($message->isFromUser());
        $this->assertFalse($message->isFromAssistant());
    }

    public function test_session_channel_defaults_to_web(): void
    {
        $session = StorefrontChatSession::factory()->create();
        $session->refresh();

        $this->assertEquals(ConversationChannel::Web, $session->channel);
    }

    public function test_session_whatsapp_factory(): void
    {
        $session = StorefrontChatSession::factory()->whatsapp()->create();

        $this->assertEquals(ConversationChannel::WhatsApp, $session->channel);
    }

    public function test_session_slack_factory(): void
    {
        $session = StorefrontChatSession::factory()->slack()->create();

        $this->assertEquals(ConversationChannel::Slack, $session->channel);
    }

    public function test_assigned_agent_relationship(): void
    {
        $agent = User::factory()->create();
        $session = StorefrontChatSession::factory()->assigned()->create([
            'assigned_agent_id' => $agent->id,
        ]);

        $this->assertEquals($agent->id, $session->assignedAgent->id);
    }

    public function test_message_agent_relationship(): void
    {
        $agent = User::factory()->create();
        $message = StorefrontChatMessage::factory()->agent()->create([
            'agent_id' => $agent->id,
        ]);

        $this->assertEquals($agent->id, $message->agent->id);
    }
}
