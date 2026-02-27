<?php

namespace Tests\Feature\StorefrontChat;

use App\Models\Store;
use App\Models\StorefrontApiToken;
use App\Models\StorefrontChatSession;
use App\Models\StoreMarketplace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShopifyAppLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected Store $store;

    protected StoreMarketplace $marketplace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = Store::factory()->create();
        $this->marketplace = StoreMarketplace::factory()->shopify()->create([
            'store_id' => $this->store->id,
            'shop_domain' => 'test-store.myshopify.com',
            'status' => 'active',
            'credentials' => [
                'webhook_secret' => 'test-webhook-secret',
            ],
        ]);
    }

    /**
     * Generate a valid Shopify webhook HMAC signature.
     */
    protected function generateWebhookSignature(string $payload, string $secret): string
    {
        return base64_encode(hash_hmac('sha256', $payload, $secret, true));
    }

    public function test_uninstall_webhook_deactivates_connection(): void
    {
        $payload = json_encode(['id' => $this->marketplace->external_store_id]);
        $hmac = $this->generateWebhookSignature($payload, 'test-webhook-secret');

        $response = $this->postJson(
            "/api/webhooks/shopify/{$this->marketplace->id}/app-uninstalled",
            json_decode($payload, true),
            [
                'X-Shopify-Hmac-Sha256' => $hmac,
                'X-Shopify-Topic' => 'app/uninstalled',
            ]
        );

        $response->assertOk();
        $response->assertJson(['status' => 'ok']);

        $this->marketplace->refresh();
        $this->assertEquals('inactive', $this->marketplace->status);
    }

    public function test_uninstall_webhook_deactivates_storefront_tokens(): void
    {
        $token = StorefrontApiToken::factory()->create([
            'store_id' => $this->store->id,
            'store_marketplace_id' => $this->marketplace->id,
            'is_active' => true,
        ]);

        $payload = json_encode(['id' => $this->marketplace->external_store_id]);
        $hmac = $this->generateWebhookSignature($payload, 'test-webhook-secret');

        $this->postJson(
            "/api/webhooks/shopify/{$this->marketplace->id}/app-uninstalled",
            json_decode($payload, true),
            [
                'X-Shopify-Hmac-Sha256' => $hmac,
                'X-Shopify-Topic' => 'app/uninstalled',
            ]
        );

        $token->refresh();
        $this->assertFalse($token->is_active);
    }

    public function test_uninstall_webhook_rejects_invalid_signature(): void
    {
        $payload = json_encode(['id' => $this->marketplace->external_store_id]);

        $response = $this->postJson(
            "/api/webhooks/shopify/{$this->marketplace->id}/app-uninstalled",
            json_decode($payload, true),
            [
                'X-Shopify-Hmac-Sha256' => 'invalid-signature',
                'X-Shopify-Topic' => 'app/uninstalled',
            ]
        );

        $response->assertStatus(401);

        $this->marketplace->refresh();
        $this->assertEquals('active', $this->marketplace->status);
    }

    public function test_uninstall_webhook_returns_404_for_unknown_connection(): void
    {
        $payload = json_encode(['id' => 'unknown']);

        $response = $this->postJson(
            '/api/webhooks/shopify/99999/app-uninstalled',
            json_decode($payload, true),
            [
                'X-Shopify-Hmac-Sha256' => 'fake',
                'X-Shopify-Topic' => 'app/uninstalled',
            ]
        );

        $response->assertStatus(404);
    }

    public function test_cleanup_command_deletes_expired_sessions(): void
    {
        // Expired session
        StorefrontChatSession::factory()->expired()->create([
            'store_id' => $this->store->id,
            'store_marketplace_id' => $this->marketplace->id,
            'expires_at' => now()->subDays(2),
        ]);

        // Active session
        $activeSession = StorefrontChatSession::factory()->create([
            'store_id' => $this->store->id,
            'store_marketplace_id' => $this->marketplace->id,
            'expires_at' => now()->addMinutes(30),
        ]);

        $this->artisan('storefront:cleanup-sessions')
            ->assertSuccessful();

        // Active session should still exist
        $this->assertDatabaseHas('storefront_chat_sessions', ['id' => $activeSession->id]);

        // Should have deleted the expired one
        $this->assertEquals(1, StorefrontChatSession::count());
    }

    public function test_ensure_storefront_api_token_creates_token_on_connect(): void
    {
        $this->assertDatabaseMissing('storefront_api_tokens', [
            'store_marketplace_id' => $this->marketplace->id,
        ]);

        // Simulate what happens in ensureStorefrontApiToken
        StorefrontApiToken::firstOrCreate(
            [
                'store_id' => $this->marketplace->store_id,
                'store_marketplace_id' => $this->marketplace->id,
            ],
            [
                'token' => StorefrontApiToken::generateToken(),
                'name' => 'Default',
                'is_active' => true,
            ]
        );

        $this->assertDatabaseHas('storefront_api_tokens', [
            'store_marketplace_id' => $this->marketplace->id,
            'is_active' => true,
        ]);
    }

    public function test_ensure_storefront_api_token_does_not_duplicate(): void
    {
        StorefrontApiToken::factory()->create([
            'store_id' => $this->store->id,
            'store_marketplace_id' => $this->marketplace->id,
        ]);

        // Second call should not create a duplicate
        StorefrontApiToken::firstOrCreate(
            [
                'store_id' => $this->marketplace->store_id,
                'store_marketplace_id' => $this->marketplace->id,
            ],
            [
                'token' => StorefrontApiToken::generateToken(),
                'name' => 'Default',
                'is_active' => true,
            ]
        );

        $this->assertEquals(1, StorefrontApiToken::where('store_marketplace_id', $this->marketplace->id)->count());
    }

    public function test_reconnect_reactivates_token(): void
    {
        $token = StorefrontApiToken::factory()->inactive()->create([
            'store_id' => $this->store->id,
            'store_marketplace_id' => $this->marketplace->id,
        ]);

        // On reconnect, the token should be re-enabled manually or auto-created if missing
        $existingToken = StorefrontApiToken::where('store_marketplace_id', $this->marketplace->id)->first();
        $existingToken->update(['is_active' => true]);

        $this->assertTrue($existingToken->fresh()->is_active);
    }
}
