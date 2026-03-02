<?php

namespace Tests\Feature\Conversation;

use App\Models\Store;
use App\Models\StoreMarketplace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SlackWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected Store $store;

    protected StoreMarketplace $marketplace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = Store::factory()->create();
        $this->marketplace = StoreMarketplace::factory()->create([
            'store_id' => $this->store->id,
            'status' => 'active',
        ]);
    }

    public function test_slack_url_verification_challenge(): void
    {
        $response = $this->postJson('/api/webhooks/slack', [
            'type' => 'url_verification',
            'challenge' => 'test-challenge-string',
        ]);

        $response->assertOk()
            ->assertJson(['challenge' => 'test-challenge-string']);
    }

    public function test_slack_ignores_bot_messages(): void
    {
        $response = $this->postJson('/api/webhooks/slack', [
            'type' => 'event_callback',
            'event' => [
                'type' => 'message',
                'bot_id' => 'B12345',
                'channel' => 'C12345',
                'text' => 'Bot message',
                'ts' => '1234567890.123',
            ],
        ]);

        $response->assertOk()
            ->assertJson(['status' => 'ignored']);
    }

    public function test_slack_returns_no_config_when_not_setup(): void
    {
        $response = $this->postJson('/api/webhooks/slack', [
            'type' => 'event_callback',
            'event' => [
                'type' => 'message',
                'channel' => 'C12345',
                'text' => 'Hello from Slack',
                'user' => 'U12345',
                'ts' => '1234567890.123',
            ],
        ]);

        $response->assertOk()
            ->assertJson(['status' => 'no_config']);
    }
}
