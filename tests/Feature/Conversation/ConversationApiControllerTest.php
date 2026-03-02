<?php

namespace Tests\Feature\Conversation;

use App\Enums\ConversationStatus;
use App\Models\Store;
use App\Models\StorefrontChatMessage;
use App\Models\StorefrontChatSession;
use App\Models\StoreMarketplace;
use App\Models\StoreUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Passport\Passport;
use Tests\TestCase;

class ConversationApiControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Store $store;

    protected StoreMarketplace $marketplace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = Store::factory()->create();
        $this->user = User::factory()->create();
        StoreUser::factory()->create([
            'store_id' => $this->store->id,
            'user_id' => $this->user->id,
        ]);

        $this->marketplace = StoreMarketplace::factory()->create([
            'store_id' => $this->store->id,
        ]);

        Passport::actingAs($this->user);

        // Set the store context
        app(\App\Services\StoreContext::class)->setCurrentStore($this->store);
    }

    public function test_assign_conversation(): void
    {
        Event::fake();

        $session = StorefrontChatSession::factory()->create([
            'store_id' => $this->store->id,
            'store_marketplace_id' => $this->marketplace->id,
        ]);

        $response = $this->postJson("/api/v1/conversations/{$session->id}/assign");

        $response->assertOk()
            ->assertJson(['status' => 'assigned']);

        $session->refresh();
        $this->assertEquals(ConversationStatus::Assigned, $session->status);
        $this->assertEquals($this->user->id, $session->assigned_agent_id);
    }

    public function test_release_conversation(): void
    {
        Event::fake();

        $session = StorefrontChatSession::factory()->assigned()->create([
            'store_id' => $this->store->id,
            'store_marketplace_id' => $this->marketplace->id,
            'assigned_agent_id' => $this->user->id,
        ]);

        $response = $this->postJson("/api/v1/conversations/{$session->id}/release");

        $response->assertOk()
            ->assertJson(['status' => 'released']);

        $session->refresh();
        $this->assertEquals(ConversationStatus::Open, $session->status);
        $this->assertNull($session->assigned_agent_id);
    }

    public function test_close_conversation(): void
    {
        Event::fake();

        $session = StorefrontChatSession::factory()->create([
            'store_id' => $this->store->id,
            'store_marketplace_id' => $this->marketplace->id,
        ]);

        $response = $this->postJson("/api/v1/conversations/{$session->id}/close");

        $response->assertOk()
            ->assertJson(['status' => 'closed']);

        $session->refresh();
        $this->assertEquals(ConversationStatus::Closed, $session->status);
    }

    public function test_send_agent_message(): void
    {
        Event::fake();

        $session = StorefrontChatSession::factory()->assigned()->create([
            'store_id' => $this->store->id,
            'store_marketplace_id' => $this->marketplace->id,
            'assigned_agent_id' => $this->user->id,
        ]);

        $response = $this->postJson("/api/v1/conversations/{$session->id}/messages", [
            'content' => 'Hello, I can help you with that!',
        ]);

        $response->assertCreated()
            ->assertJsonPath('message.role', 'agent')
            ->assertJsonPath('message.content', 'Hello, I can help you with that!');

        $this->assertDatabaseHas('storefront_chat_messages', [
            'storefront_chat_session_id' => $session->id,
            'role' => 'agent',
            'agent_id' => $this->user->id,
            'content' => 'Hello, I can help you with that!',
        ]);
    }

    public function test_send_message_validation_requires_content(): void
    {
        $session = StorefrontChatSession::factory()->create([
            'store_id' => $this->store->id,
            'store_marketplace_id' => $this->marketplace->id,
        ]);

        $response = $this->postJson("/api/v1/conversations/{$session->id}/messages", []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('content');
    }

    public function test_get_messages(): void
    {
        $session = StorefrontChatSession::factory()->create([
            'store_id' => $this->store->id,
            'store_marketplace_id' => $this->marketplace->id,
        ]);

        StorefrontChatMessage::factory()->count(3)->create([
            'storefront_chat_session_id' => $session->id,
        ]);

        $response = $this->getJson("/api/v1/conversations/{$session->id}/messages");

        $response->assertOk()
            ->assertJsonCount(3, 'data');
    }
}
