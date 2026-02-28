<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\StorefrontApiToken;
use App\Models\StoreMarketplace;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShopifyEmbeddedSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_embedded_page_renders_with_api_key_meta_tag(): void
    {
        config(['services.shopify.client_id' => 'test-api-key']);

        $response = $this->get('/shopify/embedded?shop=test.myshopify.com');

        $response->assertOk();
        $response->assertSee('content="test-api-key"', false);
        $response->assertSee('shopify-api-key', false);
    }

    public function test_get_settings_returns_current_settings(): void
    {
        config(['services.shopify.client_secret' => 'test-secret']);

        $store = Store::factory()->create();
        $marketplace = StoreMarketplace::factory()->shopify()->create([
            'store_id' => $store->id,
            'shop_domain' => 'test-store.myshopify.com',
            'status' => 'active',
        ]);

        StorefrontApiToken::factory()->create([
            'store_id' => $store->id,
            'store_marketplace_id' => $marketplace->id,
            'is_active' => true,
            'settings' => [
                'assistant_name' => 'Gem Bot',
                'welcome_message' => 'Hello!',
                'accent_color' => '#FF0000',
            ],
        ]);

        $token = $this->generateSessionToken('test-store.myshopify.com', 'test-secret');

        $response = $this->getJson('/shopify/embedded/api/settings', [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertOk();
        $response->assertJsonPath('settings.assistant_name', 'Gem Bot');
        $response->assertJsonPath('settings.accent_color', '#FF0000');
        $response->assertJsonPath('shop_domain', 'test-store.myshopify.com');
    }

    public function test_put_settings_merges_partial_update(): void
    {
        config(['services.shopify.client_secret' => 'test-secret']);

        $store = Store::factory()->create();
        $marketplace = StoreMarketplace::factory()->shopify()->create([
            'store_id' => $store->id,
            'shop_domain' => 'test-store.myshopify.com',
            'status' => 'active',
        ]);

        StorefrontApiToken::factory()->create([
            'store_id' => $store->id,
            'store_marketplace_id' => $marketplace->id,
            'is_active' => true,
            'settings' => [
                'assistant_name' => 'Old Name',
                'welcome_message' => 'Old message',
                'accent_color' => '#000000',
            ],
        ]);

        $token = $this->generateSessionToken('test-store.myshopify.com', 'test-secret');

        $response = $this->putJson('/shopify/embedded/api/settings', [
            'assistant_name' => 'New Name',
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertOk();
        $response->assertJsonPath('settings.assistant_name', 'New Name');
        // Existing settings should be preserved
        $response->assertJsonPath('settings.welcome_message', 'Old message');
        $response->assertJsonPath('settings.accent_color', '#000000');
    }

    public function test_validates_accent_color_format(): void
    {
        config(['services.shopify.client_secret' => 'test-secret']);

        $store = Store::factory()->create();
        $marketplace = StoreMarketplace::factory()->shopify()->create([
            'store_id' => $store->id,
            'shop_domain' => 'test-store.myshopify.com',
            'status' => 'active',
        ]);

        StorefrontApiToken::factory()->create([
            'store_id' => $store->id,
            'store_marketplace_id' => $marketplace->id,
            'is_active' => true,
        ]);

        $token = $this->generateSessionToken('test-store.myshopify.com', 'test-secret');

        $response = $this->putJson('/shopify/embedded/api/settings', [
            'accent_color' => 'not-a-color',
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('accent_color');
    }

    public function test_rejects_missing_auth_token(): void
    {
        $response = $this->getJson('/shopify/embedded/api/settings');

        $response->assertStatus(401);
        $response->assertJson(['error' => 'Missing session token']);
    }

    public function test_rejects_invalid_auth_token(): void
    {
        config(['services.shopify.client_secret' => 'test-secret']);

        $response = $this->getJson('/shopify/embedded/api/settings', [
            'Authorization' => 'Bearer invalid-jwt-token',
        ]);

        $response->assertStatus(401);
        $response->assertJson(['error' => 'Invalid session token']);
    }

    /**
     * Generate a Shopify session token JWT for testing.
     */
    protected function generateSessionToken(string $shopDomain, string $secret): string
    {
        $payload = [
            'iss' => "https://{$shopDomain}/admin",
            'dest' => "https://{$shopDomain}",
            'aud' => config('services.shopify.client_id'),
            'sub' => '1',
            'exp' => time() + 60,
            'nbf' => time() - 10,
            'iat' => time(),
            'jti' => bin2hex(random_bytes(16)),
        ];

        return JWT::encode($payload, $secret, 'HS256');
    }
}
