<?php

namespace Tests\Feature\StorefrontChat;

use App\Models\Store;
use App\Models\StorefrontApiToken;
use App\Models\StorefrontChatSession;
use App\Models\StoreMarketplace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StorefrontChatControllerTest extends TestCase
{
    use RefreshDatabase;

    protected Store $store;

    protected StoreMarketplace $marketplace;

    protected StorefrontApiToken $token;

    protected string $shopDomain = 'test-store.myshopify.com';

    protected string $clientSecret = 'test-shopify-client-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.shopify.client_secret' => $this->clientSecret]);

        $this->store = Store::factory()->create();
        $this->marketplace = StoreMarketplace::factory()->shopify()->create([
            'store_id' => $this->store->id,
            'shop_domain' => $this->shopDomain,
            'status' => 'active',
        ]);
        $this->token = StorefrontApiToken::factory()->create([
            'store_id' => $this->store->id,
            'store_marketplace_id' => $this->marketplace->id,
            'is_active' => true,
        ]);
    }

    /**
     * Generate a valid Shopify App Proxy signature for the given query params.
     *
     * @param  array<string, string>  $params
     */
    protected function generateSignature(array $params): string
    {
        $sorted = $params;
        ksort($sorted);

        $parts = [];
        foreach ($sorted as $key => $value) {
            $parts[] = "{$key}={$value}";
        }

        $message = implode('', $parts);

        return hash_hmac('sha256', $message, $this->clientSecret);
    }

    /**
     * Build query string with valid signature for a shop.
     *
     * @return array<string, string>
     */
    protected function proxyQuery(array $extra = []): array
    {
        $params = array_merge([
            'shop' => $this->shopDomain,
            'path_prefix' => '/apps/shopmata-assistant',
            'timestamp' => (string) time(),
        ], $extra);

        $params['signature'] = $this->generateSignature($params);

        return $params;
    }

    public function test_rejects_request_without_signature(): void
    {
        $response = $this->postJson('/shopify/proxy/chat/session', [
            'visitor_id' => 'test-visitor',
        ]);

        $response->assertStatus(401);
        $response->assertJson(['error' => 'Unauthorized']);
    }

    public function test_rejects_request_without_shop_param(): void
    {
        $response = $this->postJson('/shopify/proxy/chat/session?signature=fake', [
            'visitor_id' => 'test-visitor',
        ]);

        $response->assertStatus(401);
    }

    public function test_rejects_invalid_signature(): void
    {
        $query = http_build_query([
            'shop' => $this->shopDomain,
            'path_prefix' => '/apps/shopmata-assistant',
            'timestamp' => (string) time(),
            'signature' => 'invalid-signature',
        ]);

        $response = $this->postJson("/shopify/proxy/chat/session?{$query}", [
            'visitor_id' => 'test-visitor',
        ]);

        $response->assertStatus(401);
        $response->assertJson(['error' => 'Invalid signature']);
    }

    public function test_rejects_inactive_store(): void
    {
        $this->marketplace->update(['status' => 'inactive']);

        $query = http_build_query($this->proxyQuery());

        $response = $this->postJson("/shopify/proxy/chat/session?{$query}", [
            'visitor_id' => 'test-visitor',
        ]);

        $response->assertStatus(404);
        $response->assertJson(['error' => 'Store not found']);
    }

    public function test_rejects_when_assistant_not_enabled(): void
    {
        $this->token->update(['is_active' => false]);

        $query = http_build_query($this->proxyQuery());

        $response = $this->postJson("/shopify/proxy/chat/session?{$query}", [
            'visitor_id' => 'test-visitor',
        ]);

        $response->assertStatus(403);
        $response->assertJson(['error' => 'Assistant not enabled']);
    }

    public function test_creates_session_with_valid_signature(): void
    {
        $query = http_build_query($this->proxyQuery());

        $response = $this->postJson("/shopify/proxy/chat/session?{$query}", [
            'visitor_id' => 'test-visitor-123',
        ]);

        $response->assertOk();
        $response->assertJsonStructure([
            'session_id',
            'expires_at',
            'config' => ['welcome_message', 'assistant_name', 'accent_color'],
        ]);

        $this->assertDatabaseHas('storefront_chat_sessions', [
            'store_id' => $this->store->id,
            'visitor_id' => 'test-visitor-123',
        ]);
    }

    public function test_restores_existing_session(): void
    {
        $session = StorefrontChatSession::factory()->create([
            'store_id' => $this->store->id,
            'store_marketplace_id' => $this->marketplace->id,
            'visitor_id' => 'test-visitor-123',
            'expires_at' => now()->addMinutes(30),
        ]);

        $query = http_build_query($this->proxyQuery());

        $response = $this->postJson("/shopify/proxy/chat/session?{$query}", [
            'visitor_id' => 'test-visitor-123',
            'session_id' => $session->id,
        ]);

        $response->assertOk();
        $response->assertJson([
            'session_id' => $session->id,
        ]);
    }

    public function test_creates_new_session_when_expired(): void
    {
        $expiredSession = StorefrontChatSession::factory()->expired()->create([
            'store_id' => $this->store->id,
            'store_marketplace_id' => $this->marketplace->id,
            'visitor_id' => 'test-visitor-123',
        ]);

        $query = http_build_query($this->proxyQuery());

        $response = $this->postJson("/shopify/proxy/chat/session?{$query}", [
            'visitor_id' => 'test-visitor-123',
            'session_id' => $expiredSession->id,
        ]);

        $response->assertOk();
        $this->assertNotEquals($expiredSession->id, $response->json('session_id'));
    }

    public function test_updates_last_used_at_on_token(): void
    {
        $this->assertNull($this->token->fresh()->last_used_at);

        $query = http_build_query($this->proxyQuery());

        $this->postJson("/shopify/proxy/chat/session?{$query}", [
            'visitor_id' => 'test-visitor-123',
        ]);

        $this->assertNotNull($this->token->fresh()->last_used_at);
    }

    public function test_returns_widget_config_from_token_settings(): void
    {
        $this->token->update([
            'settings' => [
                'welcome_message' => 'Welcome to our store!',
                'assistant_name' => 'Ruby Bot',
                'accent_color' => '#ff0000',
            ],
        ]);

        $query = http_build_query($this->proxyQuery());

        $response = $this->postJson("/shopify/proxy/chat/session?{$query}", [
            'visitor_id' => 'test-visitor-123',
        ]);

        $response->assertOk();
        $response->assertJson([
            'config' => [
                'welcome_message' => 'Welcome to our store!',
                'assistant_name' => 'Ruby Bot',
                'accent_color' => '#ff0000',
            ],
        ]);
    }

    public function test_chat_endpoint_validates_message(): void
    {
        $query = http_build_query($this->proxyQuery());

        $response = $this->postJson("/shopify/proxy/chat?{$query}", [
            'visitor_id' => 'test-visitor-123',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['message']);
    }

    public function test_chat_endpoint_validates_visitor_id(): void
    {
        $query = http_build_query($this->proxyQuery());

        $response = $this->postJson("/shopify/proxy/chat?{$query}", [
            'message' => 'Hello!',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['visitor_id']);
    }

    public function test_unknown_shop_domain_returns_not_found(): void
    {
        $params = [
            'shop' => 'nonexistent-store.myshopify.com',
            'path_prefix' => '/apps/shopmata-assistant',
            'timestamp' => (string) time(),
        ];
        $params['signature'] = $this->generateSignature($params);

        $query = http_build_query($params);

        $response = $this->postJson("/shopify/proxy/chat/session?{$query}", [
            'visitor_id' => 'test-visitor-123',
        ]);

        $response->assertStatus(404);
    }
}
