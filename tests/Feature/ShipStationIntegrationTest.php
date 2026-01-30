<?php

namespace Tests\Feature;

use App\Jobs\SyncOrderToShipStation;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreIntegration;
use App\Models\StoreUser;
use App\Models\User;
use App\Services\ShipStation\ShipStationService;
use App\Services\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ShipStationIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->store = Store::factory()->create(['user_id' => $this->user->id, 'step' => 2]);

        Role::createDefaultRoles($this->store->id);

        $ownerRole = Role::where('store_id', $this->store->id)
            ->where('slug', 'owner')
            ->first();

        StoreUser::create([
            'user_id' => $this->user->id,
            'store_id' => $this->store->id,
            'role_id' => $ownerRole->id,
            'is_owner' => true,
            'status' => 'active',
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => $this->user->email,
        ]);

        $this->user->update(['current_store_id' => $this->store->id]);

        app(StoreContext::class)->setCurrentStore($this->store);
    }

    protected function authenticatedRequest(): static
    {
        return $this->actingAs($this->user)
            ->withSession(['current_store_id' => $this->store->id]);
    }

    public function test_can_save_shipstation_integration(): void
    {
        $response = $this->authenticatedRequest()->post('/integrations/shipstation', [
            'api_key' => 'test-api-key',
            'api_secret' => 'test-api-secret',
            'store_id' => 12345,
            'auto_sync_orders' => true,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('store_integrations', [
            'store_id' => $this->store->id,
            'provider' => StoreIntegration::PROVIDER_SHIPSTATION,
            'name' => 'ShipStation',
            'status' => StoreIntegration::STATUS_ACTIVE,
        ]);

        $integration = StoreIntegration::where('store_id', $this->store->id)
            ->where('provider', StoreIntegration::PROVIDER_SHIPSTATION)
            ->first();

        $this->assertEquals('test-api-key', $integration->getShipStationApiKey());
        $this->assertEquals('test-api-secret', $integration->getShipStationApiSecret());
        $this->assertEquals(12345, $integration->getShipStationStoreId());
        $this->assertTrue($integration->isShipStationAutoSyncEnabled());
    }

    public function test_can_delete_shipstation_integration(): void
    {
        $integration = StoreIntegration::factory()->create([
            'store_id' => $this->store->id,
            'provider' => StoreIntegration::PROVIDER_SHIPSTATION,
            'name' => 'ShipStation',
            'status' => StoreIntegration::STATUS_ACTIVE,
            'credentials' => [
                'api_key' => 'test-key',
                'api_secret' => 'test-secret',
            ],
        ]);

        $response = $this->authenticatedRequest()->delete("/integrations/{$integration->id}");

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertSoftDeleted('store_integrations', [
            'id' => $integration->id,
        ]);
    }

    public function test_shipstation_job_dispatched_when_order_confirmed(): void
    {
        Queue::fake();

        $order = Order::factory()->pending()->create([
            'store_id' => $this->store->id,
        ]);

        $order->confirm();

        Queue::assertPushed(SyncOrderToShipStation::class, function ($job) use ($order) {
            return $job->order->id === $order->id;
        });
    }

    public function test_shipstation_service_is_not_configured_without_integration(): void
    {
        $service = ShipStationService::forStore($this->store->id);

        $this->assertFalse($service->isConfigured());
    }

    public function test_shipstation_service_is_configured_with_integration(): void
    {
        StoreIntegration::factory()->create([
            'store_id' => $this->store->id,
            'provider' => StoreIntegration::PROVIDER_SHIPSTATION,
            'status' => StoreIntegration::STATUS_ACTIVE,
            'credentials' => [
                'api_key' => 'test-key',
                'api_secret' => 'test-secret',
            ],
            'settings' => [
                'auto_sync_orders' => true,
            ],
        ]);

        $service = ShipStationService::forStore($this->store->id);

        $this->assertTrue($service->isConfigured());
        $this->assertTrue($service->isAutoSyncEnabled());
    }

    public function test_shipstation_service_creates_order(): void
    {
        Http::fake([
            'ssapi.shipstation.com/*' => Http::response([
                'orderId' => 999888777,
                'orderKey' => 'order-123',
                'orderNumber' => 'ORD-123',
            ], 200),
        ]);

        StoreIntegration::factory()->create([
            'store_id' => $this->store->id,
            'provider' => StoreIntegration::PROVIDER_SHIPSTATION,
            'status' => StoreIntegration::STATUS_ACTIVE,
            'credentials' => [
                'api_key' => 'test-key',
                'api_secret' => 'test-secret',
            ],
            'settings' => [
                'auto_sync_orders' => true,
            ],
        ]);

        $customer = Customer::factory()->create(['store_id' => $this->store->id]);

        $order = Order::factory()->confirmed()->create([
            'store_id' => $this->store->id,
            'customer_id' => $customer->id,
            'shipping_address' => [
                'name' => 'John Doe',
                'street1' => '123 Main St',
                'city' => 'Austin',
                'state' => 'TX',
                'postal_code' => '78701',
                'country' => 'US',
            ],
        ]);

        $service = ShipStationService::forStore($this->store->id);
        $result = $service->createOrder($order);

        $this->assertTrue($result['success']);
        $this->assertEquals(999888777, $result['order_id']);
        $this->assertNull($result['error']);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'ssapi.shipstation.com/orders/createorder');
        });
    }

    public function test_shipstation_service_handles_api_error(): void
    {
        Http::fake([
            'ssapi.shipstation.com/*' => Http::response([
                'Message' => 'Invalid API credentials',
            ], 401),
        ]);

        StoreIntegration::factory()->create([
            'store_id' => $this->store->id,
            'provider' => StoreIntegration::PROVIDER_SHIPSTATION,
            'status' => StoreIntegration::STATUS_ACTIVE,
            'credentials' => [
                'api_key' => 'invalid-key',
                'api_secret' => 'invalid-secret',
            ],
        ]);

        $order = Order::factory()->confirmed()->create([
            'store_id' => $this->store->id,
        ]);

        $service = ShipStationService::forStore($this->store->id);
        $result = $service->createOrder($order);

        $this->assertFalse($result['success']);
        $this->assertNotNull($result['error']);
    }

    public function test_integrations_page_shows_shipstation(): void
    {
        $response = $this->authenticatedRequest()->get('/integrations');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page->component('integrations/Index'));
    }
}
