<?php

namespace Tests\Feature;

use App\Jobs\ProcessWebhookJob;
use App\Models\Store;
use App\Models\StorefrontApiToken;
use App\Models\StoreMarketplace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ShopifyDeclarativeWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_routes_by_shop_domain_header_and_queues_job(): void
    {
        Queue::fake();

        $store = Store::factory()->create();
        $marketplace = StoreMarketplace::factory()->shopify()->create([
            'store_id' => $store->id,
            'shop_domain' => 'test-store.myshopify.com',
            'status' => 'active',
            'credentials' => ['webhook_secret' => 'test-secret'],
        ]);

        $payload = json_encode(['id' => 12345, 'name' => '#1001']);
        $hmac = base64_encode(hash_hmac('sha256', $payload, 'test-secret', true));

        $response = $this->postJson('/api/webhooks/shopify/app', json_decode($payload, true), [
            'X-Shopify-Shop-Domain' => 'test-store.myshopify.com',
            'X-Shopify-Topic' => 'orders/create',
            'X-Shopify-Hmac-Sha256' => $hmac,
        ]);

        $response->assertOk();
        $response->assertJson(['status' => 'queued']);

        Queue::assertPushed(ProcessWebhookJob::class);
    }

    public function test_returns_400_without_shop_domain_header(): void
    {
        $response = $this->postJson('/api/webhooks/shopify/app', ['id' => 1], [
            'X-Shopify-Topic' => 'orders/create',
        ]);

        $response->assertStatus(400);
        $response->assertJson(['error' => 'Missing shop domain header']);
    }

    public function test_returns_404_for_unknown_shop(): void
    {
        $payload = json_encode(['id' => 1]);
        $hmac = base64_encode(hash_hmac('sha256', $payload, 'secret', true));

        $response = $this->postJson('/api/webhooks/shopify/app', json_decode($payload, true), [
            'X-Shopify-Shop-Domain' => 'nonexistent.myshopify.com',
            'X-Shopify-Topic' => 'orders/create',
            'X-Shopify-Hmac-Sha256' => $hmac,
        ]);

        $response->assertStatus(404);
        $response->assertJson(['error' => 'Connection not found']);
    }

    public function test_rejects_invalid_hmac_signature(): void
    {
        $store = Store::factory()->create();
        StoreMarketplace::factory()->shopify()->create([
            'store_id' => $store->id,
            'shop_domain' => 'test-store.myshopify.com',
            'status' => 'active',
            'credentials' => ['webhook_secret' => 'real-secret'],
        ]);

        $response = $this->postJson('/api/webhooks/shopify/app', ['id' => 1], [
            'X-Shopify-Shop-Domain' => 'test-store.myshopify.com',
            'X-Shopify-Topic' => 'orders/create',
            'X-Shopify-Hmac-Sha256' => 'invalid-hmac',
        ]);

        $response->assertStatus(401);
        $response->assertJson(['error' => 'Invalid signature']);
    }

    public function test_handles_app_uninstalled_deactivates_marketplace_and_tokens(): void
    {
        $store = Store::factory()->create();
        $marketplace = StoreMarketplace::factory()->shopify()->create([
            'store_id' => $store->id,
            'shop_domain' => 'test-store.myshopify.com',
            'status' => 'active',
            'credentials' => ['webhook_secret' => 'test-secret'],
        ]);

        $token = StorefrontApiToken::factory()->create([
            'store_id' => $store->id,
            'store_marketplace_id' => $marketplace->id,
            'is_active' => true,
        ]);

        $payload = json_encode(['shop_domain' => 'test-store.myshopify.com']);
        $hmac = base64_encode(hash_hmac('sha256', $payload, 'test-secret', true));

        $response = $this->postJson('/api/webhooks/shopify/app', json_decode($payload, true), [
            'X-Shopify-Shop-Domain' => 'test-store.myshopify.com',
            'X-Shopify-Topic' => 'app/uninstalled',
            'X-Shopify-Hmac-Sha256' => $hmac,
        ]);

        $response->assertOk();
        $response->assertJson(['status' => 'ok']);

        $marketplace->refresh();
        $this->assertEquals('inactive', $marketplace->status);

        $token->refresh();
        $this->assertFalse($token->is_active);
    }

    public function test_rejects_non_uninstall_events_on_inactive_connections(): void
    {
        Queue::fake();

        $store = Store::factory()->create();
        $marketplace = StoreMarketplace::factory()->shopify()->create([
            'store_id' => $store->id,
            'shop_domain' => 'test-store.myshopify.com',
            'status' => 'inactive',
            'credentials' => ['webhook_secret' => 'test-secret'],
        ]);

        $payload = json_encode(['id' => 12345]);
        $hmac = base64_encode(hash_hmac('sha256', $payload, 'test-secret', true));

        $response = $this->postJson('/api/webhooks/shopify/app', json_decode($payload, true), [
            'X-Shopify-Shop-Domain' => 'test-store.myshopify.com',
            'X-Shopify-Topic' => 'orders/create',
            'X-Shopify-Hmac-Sha256' => $hmac,
        ]);

        $response->assertStatus(400);
        $response->assertJson(['error' => 'Connection is inactive']);

        Queue::assertNotPushed(ProcessWebhookJob::class);
    }
}
