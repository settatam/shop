<?php

namespace Tests\Feature;

use App\Enums\Platform;
use App\Jobs\ImportShopifyProductsJob;
use App\Models\PlatformListing;
use App\Models\Product;
use App\Models\Store;
use App\Models\StorefrontApiToken;
use App\Models\StoreMarketplace;
use App\Models\User;
use App\Services\Platforms\Shopify\ShopifyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ShopifyAppInstallTest extends TestCase
{
    use RefreshDatabase;

    public function test_install_redirects_to_shopify_oauth(): void
    {
        config([
            'services.shopify.client_id' => 'test-client-id',
            'services.shopify.scopes' => 'read_products,write_products',
        ]);

        $response = $this->get('/shopify/app/install?shop=test-store.myshopify.com');

        $response->assertRedirect();
        $location = $response->headers->get('Location');
        $this->assertStringContainsString('https://test-store.myshopify.com/admin/oauth/authorize', $location);
        $this->assertStringContainsString('client_id=test-client-id', $location);
        $this->assertStringContainsString('read_products', $location);
    }

    public function test_callback_provisions_store_and_marketplace(): void
    {
        Queue::fake();

        config([
            'services.shopify.client_id' => 'test-client-id',
            'services.shopify.client_secret' => 'test-client-secret',
            'services.shopify.webhook_secret' => 'test-webhook-secret',
        ]);

        Http::fake([
            'test-store.myshopify.com/admin/oauth/access_token' => Http::response([
                'access_token' => 'shpat_test_token',
                'scope' => 'read_products,write_products',
            ]),
        ]);

        $queryParams = [
            'shop' => 'test-store.myshopify.com',
            'code' => 'test-auth-code',
            'state' => 'test-state',
            'timestamp' => time(),
        ];
        $queryParams['hmac'] = $this->computeCallbackHmac($queryParams, 'test-client-secret');

        $response = $this->get('/shopify/app/callback?'.http_build_query($queryParams));

        $response->assertRedirect();
        $this->assertStringContainsString('test-store.myshopify.com/admin/apps', $response->headers->get('Location'));

        // Declarative webhooks: no API-based webhook registration should occur
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'webhooks.json'));

        // Assert User was created
        $this->assertDatabaseHas('users', [
            'email' => 'test-store@shopify-app.shopmata.internal',
        ]);

        // Assert Store was created
        $user = User::where('email', 'test-store@shopify-app.shopmata.internal')->first();
        $store = Store::where('user_id', $user->id)->first();
        $this->assertNotNull($store);
        $this->assertEquals('shopify-app', $store->edition);
        $this->assertEquals(2, $store->step);

        // Assert StoreMarketplace was created
        $marketplace = StoreMarketplace::where('store_id', $store->id)
            ->where('platform', Platform::Shopify)
            ->first();
        $this->assertNotNull($marketplace);
        $this->assertEquals('test-store.myshopify.com', $marketplace->shop_domain);
        $this->assertEquals('shpat_test_token', $marketplace->access_token);
        $this->assertEquals('active', $marketplace->status);

        // Assert StorefrontApiToken was created
        $this->assertDatabaseHas('storefront_api_tokens', [
            'store_marketplace_id' => $marketplace->id,
            'is_active' => true,
        ]);

        // Assert import job was dispatched
        Queue::assertPushed(ImportShopifyProductsJob::class, function ($job) use ($marketplace) {
            return $job->marketplace->id === $marketplace->id;
        });
    }

    public function test_callback_is_idempotent_on_reinstall(): void
    {
        Queue::fake();

        config([
            'services.shopify.client_id' => 'test-client-id',
            'services.shopify.client_secret' => 'test-client-secret',
            'services.shopify.webhook_secret' => 'test-webhook-secret',
        ]);

        Http::fake([
            'test-store.myshopify.com/admin/oauth/access_token' => Http::response([
                'access_token' => 'shpat_new_token',
                'scope' => 'read_products,write_products',
            ]),
        ]);

        // Pre-create existing store + marketplace (simulating first install)
        $store = Store::factory()->onboarded()->withEdition('shopify-app')->create();
        $marketplace = StoreMarketplace::factory()->shopify()->create([
            'store_id' => $store->id,
            'shop_domain' => 'test-store.myshopify.com',
            'access_token' => 'shpat_old_token',
            'status' => 'inactive',
        ]);

        $userCountBefore = User::count();
        $storeCountBefore = Store::count();

        $queryParams = [
            'shop' => 'test-store.myshopify.com',
            'code' => 'test-auth-code',
            'state' => 'test-state',
            'timestamp' => time(),
        ];
        $queryParams['hmac'] = $this->computeCallbackHmac($queryParams, 'test-client-secret');

        $this->get('/shopify/app/callback?'.http_build_query($queryParams));

        // Should NOT have created a new user or store
        $this->assertEquals($userCountBefore, User::count());
        $this->assertEquals($storeCountBefore, Store::count());

        // Marketplace token should be updated
        $marketplace->refresh();
        $this->assertEquals('shpat_new_token', $marketplace->access_token);
        $this->assertEquals('active', $marketplace->status);
    }

    public function test_callback_rejects_invalid_hmac(): void
    {
        config([
            'services.shopify.client_id' => 'test-client-id',
            'services.shopify.client_secret' => 'test-client-secret',
        ]);

        $queryParams = [
            'shop' => 'test-store.myshopify.com',
            'code' => 'test-auth-code',
            'hmac' => 'invalid-hmac-signature',
            'timestamp' => time(),
        ];

        $response = $this->get('/shopify/app/callback?'.http_build_query($queryParams));

        $response->assertRedirect();
        $this->assertStringContainsString('test-store.myshopify.com/admin', $response->headers->get('Location'));

        // No store should have been created
        $this->assertDatabaseMissing('store_marketplaces', [
            'shop_domain' => 'test-store.myshopify.com',
            'platform' => Platform::Shopify->value,
        ]);
    }

    public function test_gdpr_shop_erasure_deactivates_store(): void
    {
        config(['services.shopify.webhook_secret' => 'test-webhook-secret']);

        $store = Store::factory()->create();
        $marketplace = StoreMarketplace::factory()->shopify()->create([
            'store_id' => $store->id,
            'shop_domain' => 'test-store.myshopify.com',
            'status' => 'active',
        ]);

        $payload = json_encode([
            'shop_id' => 123,
            'shop_domain' => 'test-store.myshopify.com',
        ]);

        $hmac = base64_encode(hash_hmac('sha256', $payload, 'test-webhook-secret', true));

        $response = $this->postJson(
            '/api/webhooks/shopify/gdpr/shop-redact',
            json_decode($payload, true),
            [
                'X-Shopify-Hmac-Sha256' => $hmac,
            ]
        );

        $response->assertOk();
        $response->assertJson(['status' => 'ok']);

        $marketplace->refresh();
        $this->assertEquals('inactive', $marketplace->status);
    }

    public function test_import_job_creates_products_from_shopify(): void
    {
        $store = Store::factory()->create();
        $marketplace = StoreMarketplace::factory()->shopify()->create([
            'store_id' => $store->id,
            'shop_domain' => 'test-store.myshopify.com',
        ]);

        Http::fake([
            'test-store.myshopify.com/admin/api/*/products.json*' => Http::response([
                'products' => [
                    [
                        'id' => 12345,
                        'title' => 'Diamond Ring',
                        'body_html' => '<p>Beautiful diamond ring</p>',
                        'handle' => 'diamond-ring',
                        'vendor' => 'JewelryCo',
                        'product_type' => 'Ring',
                        'variants' => [
                            [
                                'id' => 111,
                                'sku' => 'DR-001',
                                'price' => '1299.99',
                                'inventory_quantity' => 5,
                                'barcode' => '1234567890',
                            ],
                        ],
                        'images' => [],
                    ],
                    [
                        'id' => 67890,
                        'title' => 'Gold Necklace',
                        'body_html' => '<p>Elegant gold necklace</p>',
                        'handle' => 'gold-necklace',
                        'vendor' => 'JewelryCo',
                        'product_type' => 'Necklace',
                        'variants' => [
                            [
                                'id' => 222,
                                'sku' => 'GN-001',
                                'price' => '499.99',
                                'inventory_quantity' => 10,
                                'barcode' => null,
                            ],
                        ],
                        'images' => [],
                    ],
                ],
            ]),
        ]);

        $job = new ImportShopifyProductsJob($marketplace);
        $job->handle(app(ShopifyService::class));

        // Assert products were created
        $this->assertEquals(2, Product::where('store_id', $store->id)->count());

        $diamondRing = Product::where('store_id', $store->id)->where('title', 'Diamond Ring')->first();
        $this->assertNotNull($diamondRing);
        $this->assertEquals(1, $diamondRing->variants()->count());
        $this->assertEquals('DR-001', $diamondRing->variants->first()->sku);

        // Assert platform listings were created
        $this->assertEquals(2, PlatformListing::where('store_marketplace_id', $marketplace->id)->count());

        $listing = PlatformListing::where('store_marketplace_id', $marketplace->id)
            ->where('external_listing_id', '12345')
            ->first();
        $this->assertNotNull($listing);
        $this->assertEquals($diamondRing->id, $listing->product_id);
        $this->assertEquals(PlatformListing::STATUS_LISTED, $listing->status);
    }

    /**
     * Compute the HMAC for Shopify OAuth callback verification.
     *
     * @param  array<string, mixed>  $params
     */
    protected function computeCallbackHmac(array $params, string $secret): string
    {
        ksort($params);
        $message = http_build_query($params);

        return hash_hmac('sha256', $message, $secret);
    }
}
