<?php

namespace Tests\Feature\Conversation;

use App\Models\Store;
use App\Models\StoreMarketplace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class WhatsAppWebhookTest extends TestCase
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

    public function test_whatsapp_verification_challenge(): void
    {
        config(['services.whatsapp.verify_token' => 'test-verify-token']);

        $response = $this->get('/api/webhooks/whatsapp?'.http_build_query([
            'hub_mode' => 'subscribe',
            'hub_verify_token' => 'test-verify-token',
            'hub_challenge' => 'challenge123',
        ]));

        $response->assertOk();
        $this->assertEquals('challenge123', $response->getContent());
    }

    public function test_whatsapp_verification_rejects_invalid_token(): void
    {
        config(['services.whatsapp.verify_token' => 'test-verify-token']);

        $response = $this->get('/api/webhooks/whatsapp?'.http_build_query([
            'hub_mode' => 'subscribe',
            'hub_verify_token' => 'wrong-token',
            'hub_challenge' => 'challenge123',
        ]));

        $response->assertForbidden();
    }

    public function test_whatsapp_webhook_ignores_non_text_messages(): void
    {
        $response = $this->postJson('/api/webhooks/whatsapp', [
            'entry' => [
                [
                    'changes' => [
                        [
                            'field' => 'messages',
                            'value' => [
                                'messages' => [
                                    [
                                        'type' => 'image',
                                        'from' => '1234567890',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $response->assertOk()
            ->assertJson(['status' => 'ignored']);
    }

    public function test_whatsapp_webhook_returns_no_config_when_not_setup(): void
    {
        Event::fake();

        $response = $this->postJson('/api/webhooks/whatsapp', [
            'entry' => [
                [
                    'changes' => [
                        [
                            'field' => 'messages',
                            'value' => [
                                'messages' => [
                                    [
                                        'type' => 'text',
                                        'from' => '1234567890',
                                        'id' => 'msg123',
                                        'text' => ['body' => 'Hello'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $response->assertOk()
            ->assertJson(['status' => 'no_config']);
    }
}
