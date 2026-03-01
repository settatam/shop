<?php

namespace Tests\Feature\WooCommerce;

use App\Models\Store;
use App\Models\StorefrontApiToken;
use App\Models\StoreMarketplace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VerifyStorefrontTokenTest extends TestCase
{
    use RefreshDatabase;

    protected Store $store;

    protected StoreMarketplace $marketplace;

    protected StorefrontApiToken $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = Store::factory()->create();
        $this->marketplace = StoreMarketplace::factory()->woocommerce()->create([
            'store_id' => $this->store->id,
            'shop_domain' => 'https://test-store.com',
            'access_token' => 'ck_test_key',
            'status' => 'active',
        ]);
        $this->token = StorefrontApiToken::factory()->create([
            'store_id' => $this->store->id,
            'store_marketplace_id' => $this->marketplace->id,
            'is_active' => true,
        ]);
    }

    public function test_valid_token_allows_access(): void
    {
        $response = $this->postJson('/api/storefront/widget/chat/session', [
            'visitor_id' => 'visitor-123',
        ], [
            'Authorization' => 'Bearer '.$this->token->token,
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['session_id', 'expires_at']);
    }

    public function test_missing_token_returns401(): void
    {
        $response = $this->postJson('/api/storefront/widget/chat/session', [
            'visitor_id' => 'visitor-123',
        ]);

        $response->assertStatus(401);
        $response->assertJson(['error' => 'Unauthorized']);
    }

    public function test_invalid_token_returns401(): void
    {
        $response = $this->postJson('/api/storefront/widget/chat/session', [
            'visitor_id' => 'visitor-123',
        ], [
            'Authorization' => 'Bearer invalid-token-value',
        ]);

        $response->assertStatus(401);
        $response->assertJson(['error' => 'Invalid token']);
    }

    public function test_inactive_token_returns401(): void
    {
        $this->token->update(['is_active' => false]);

        $response = $this->postJson('/api/storefront/widget/chat/session', [
            'visitor_id' => 'visitor-123',
        ], [
            'Authorization' => 'Bearer '.$this->token->token,
        ]);

        $response->assertStatus(401);
    }

    public function test_inactive_marketplace_returns403(): void
    {
        $this->marketplace->update(['status' => 'inactive']);

        $response = $this->postJson('/api/storefront/widget/chat/session', [
            'visitor_id' => 'visitor-123',
        ], [
            'Authorization' => 'Bearer '.$this->token->token,
        ]);

        $response->assertStatus(403);
    }

    public function test_token_last_used_at_is_updated(): void
    {
        $this->assertNull($this->token->last_used_at);

        $this->postJson('/api/storefront/widget/chat/session', [
            'visitor_id' => 'visitor-123',
        ], [
            'Authorization' => 'Bearer '.$this->token->token,
        ]);

        $this->token->refresh();
        $this->assertNotNull($this->token->last_used_at);
    }
}
