<?php

namespace Tests\Feature\Chat;

use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\Store;
use App\Models\User;
use App\Services\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

class ChatControllerTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->store = Store::factory()->create(['user_id' => $this->user->id]);
        app(StoreContext::class)->setCurrentStore($this->store);
        Passport::actingAs($this->user);
    }

    public function test_can_list_chat_sessions(): void
    {
        ChatSession::factory()
            ->count(3)
            ->create([
                'store_id' => $this->store->id,
                'user_id' => $this->user->id,
            ]);

        $response = $this->getJson('/api/v1/chat/sessions');

        $response->assertOk();
        $response->assertJsonCount(3, 'data');
    }

    public function test_sessions_are_scoped_to_current_user_and_store(): void
    {
        // Sessions for current user/store
        ChatSession::factory()
            ->count(2)
            ->create([
                'store_id' => $this->store->id,
                'user_id' => $this->user->id,
            ]);

        // Sessions for different user
        $otherUser = User::factory()->create();
        ChatSession::factory()
            ->count(3)
            ->create([
                'store_id' => $this->store->id,
                'user_id' => $otherUser->id,
            ]);

        // Sessions for different store
        $otherStore = Store::factory()->create();
        ChatSession::factory()
            ->count(4)
            ->create([
                'store_id' => $otherStore->id,
                'user_id' => $this->user->id,
            ]);

        $response = $this->getJson('/api/v1/chat/sessions');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
    }

    public function test_sessions_without_title_are_excluded(): void
    {
        ChatSession::factory()
            ->count(2)
            ->create([
                'store_id' => $this->store->id,
                'user_id' => $this->user->id,
            ]);

        ChatSession::factory()
            ->untitled()
            ->create([
                'store_id' => $this->store->id,
                'user_id' => $this->user->id,
            ]);

        $response = $this->getJson('/api/v1/chat/sessions');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
    }

    public function test_can_view_chat_session_with_messages(): void
    {
        $session = ChatSession::factory()->create([
            'store_id' => $this->store->id,
            'user_id' => $this->user->id,
        ]);

        ChatMessage::factory()
            ->fromUser()
            ->create([
                'chat_session_id' => $session->id,
                'content' => 'Hello',
            ]);

        ChatMessage::factory()
            ->fromAssistant()
            ->create([
                'chat_session_id' => $session->id,
                'content' => 'Hi there!',
            ]);

        $response = $this->getJson("/api/v1/chat/sessions/{$session->id}");

        $response->assertOk();
        $response->assertJsonPath('data.id', $session->id);
        $response->assertJsonCount(2, 'data.messages');
    }

    public function test_cannot_view_other_users_session(): void
    {
        $otherUser = User::factory()->create();
        $session = ChatSession::factory()->create([
            'store_id' => $this->store->id,
            'user_id' => $otherUser->id,
        ]);

        $response = $this->getJson("/api/v1/chat/sessions/{$session->id}");

        $response->assertNotFound();
    }

    public function test_cannot_view_session_from_other_store(): void
    {
        $otherStore = Store::factory()->create();
        $session = ChatSession::factory()->create([
            'store_id' => $otherStore->id,
            'user_id' => $this->user->id,
        ]);

        $response = $this->getJson("/api/v1/chat/sessions/{$session->id}");

        $response->assertNotFound();
    }

    public function test_can_delete_chat_session(): void
    {
        $session = ChatSession::factory()->create([
            'store_id' => $this->store->id,
            'user_id' => $this->user->id,
        ]);

        ChatMessage::factory()
            ->count(3)
            ->create(['chat_session_id' => $session->id]);

        $response = $this->deleteJson("/api/v1/chat/sessions/{$session->id}");

        $response->assertOk();
        $this->assertDatabaseMissing('chat_sessions', ['id' => $session->id]);
        $this->assertDatabaseMissing('chat_messages', ['chat_session_id' => $session->id]);
    }

    public function test_cannot_delete_other_users_session(): void
    {
        $otherUser = User::factory()->create();
        $session = ChatSession::factory()->create([
            'store_id' => $this->store->id,
            'user_id' => $otherUser->id,
        ]);

        $response = $this->deleteJson("/api/v1/chat/sessions/{$session->id}");

        $response->assertNotFound();
        $this->assertDatabaseHas('chat_sessions', ['id' => $session->id]);
    }

    public function test_sessions_are_ordered_by_last_message_at(): void
    {
        $oldSession = ChatSession::factory()->create([
            'store_id' => $this->store->id,
            'user_id' => $this->user->id,
            'last_message_at' => now()->subHours(2),
        ]);

        $newSession = ChatSession::factory()->create([
            'store_id' => $this->store->id,
            'user_id' => $this->user->id,
            'last_message_at' => now(),
        ]);

        $response = $this->getJson('/api/v1/chat/sessions');

        $response->assertOk();
        $data = $response->json('data');
        $this->assertEquals($newSession->id, $data[0]['id']);
        $this->assertEquals($oldSession->id, $data[1]['id']);
    }

    public function test_message_endpoint_requires_message_content(): void
    {
        $response = $this->postJson('/api/v1/chat/message', []);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['message']);
    }

    public function test_message_content_has_max_length(): void
    {
        $response = $this->postJson('/api/v1/chat/message', [
            'message' => str_repeat('a', 2001),
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['message']);
    }

    public function test_session_id_must_be_valid_uuid(): void
    {
        $response = $this->postJson('/api/v1/chat/message', [
            'message' => 'Hello',
            'session_id' => 'not-a-uuid',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['session_id']);
    }
}
