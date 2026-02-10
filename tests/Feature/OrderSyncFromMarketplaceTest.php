<?php

namespace Tests\Feature;

use App\Enums\Platform;
use App\Models\Customer;
use App\Models\Order;
use App\Models\PlatformOrder;
use App\Models\Role;
use App\Models\SalesChannel;
use App\Models\State;
use App\Models\Store;
use App\Models\StoreMarketplace;
use App\Models\StoreUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OrderSyncFromMarketplaceTest extends TestCase
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
    }

    protected function withStore()
    {
        return $this->withSession(['current_store_id' => $this->store->id]);
    }

    public function test_sync_fails_without_external_marketplace_id(): void
    {
        $this->actingAs($this->user);

        $order = Order::factory()->create([
            'store_id' => $this->store->id,
            'external_marketplace_id' => null,
            'source_platform' => null,
        ]);

        $response = $this->withStore()
            ->post("/orders/{$order->id}/sync-from-marketplace");

        $response->assertRedirect();
        $response->assertSessionHas('error', 'This order is not linked to an external platform.');
    }

    public function test_sync_works_with_existing_platform_order(): void
    {
        $this->actingAs($this->user);

        // Create state record for Illinois
        $illinoisState = State::firstOrCreate(
            ['abbreviation' => 'IL', 'country_code' => 'US'],
            ['name' => 'Illinois']
        );

        $marketplace = StoreMarketplace::factory()->shopify()->create([
            'store_id' => $this->store->id,
            'shop_domain' => 'test-store.myshopify.com',
            'access_token' => 'test-token',
            'status' => 'active',
        ]);

        // Create customer without address (all address fields must be null for sync to populate them)
        $customer = Customer::factory()->create([
            'store_id' => $this->store->id,
            'first_name' => 'Test',
            'last_name' => 'Customer',
            'address' => null,
            'address2' => null,
            'city' => null,
            'state' => null,
            'zip' => null,
            'phone_number' => null,
        ]);

        $order = Order::factory()->create([
            'store_id' => $this->store->id,
            'customer_id' => $customer->id,
            'external_marketplace_id' => '7358483726583',
            'source_platform' => 'shopify',
            'status' => 'confirmed',
        ]);

        $platformOrder = PlatformOrder::create([
            'store_marketplace_id' => $marketplace->id,
            'order_id' => $order->id,
            'external_order_id' => '7358483726583',
            'external_order_number' => '1001',
            'status' => 'paid',
            'fulfillment_status' => null,
            'payment_status' => 'paid',
            'total' => 100.00,
            'currency' => 'USD',
            'ordered_at' => now(),
        ]);

        // Mock the Shopify API response with delivered shipment
        Http::fake([
            'test-store.myshopify.com/admin/api/*' => Http::response([
                'order' => [
                    'id' => 7358483726583,
                    'order_number' => 1001,
                    'financial_status' => 'paid',
                    'fulfillment_status' => 'fulfilled',
                    'total_price' => '125.00',
                    'subtotal_price' => '100.00',
                    'total_tax' => '10.00',
                    'currency' => 'USD',
                    'created_at' => now()->toISOString(),
                    'shipping_lines' => [],
                    'discount_codes' => [],
                    'line_items' => [],
                    'fulfillments' => [
                        [
                            'id' => 1,
                            'status' => 'success',
                            'shipment_status' => 'delivered',
                            'tracking_number' => '123456789',
                            'tracking_company' => 'FedEx',
                        ],
                    ],
                    'shipping_address' => [
                        'address1' => '456 Oak Ave',
                        'city' => 'Chicago',
                        'province_code' => 'IL',
                        'zip' => '60601',
                    ],
                ],
            ], 200),
        ]);

        $response = $this->withStore()
            ->post("/orders/{$order->id}/sync-from-marketplace");

        $response->assertRedirect();
        $response->assertSessionHas('success');

        // Verify platform order was updated
        $platformOrder->refresh();
        $this->assertEquals('fulfilled', $platformOrder->fulfillment_status);

        // Verify order status was updated to completed (shipment delivered)
        $order->refresh();
        $this->assertEquals('completed', $order->status);
        $this->assertEquals('123456789', $order->tracking_number);
        $this->assertEquals('fedex', $order->shipping_carrier);

        // Verify customer address was created in addresses table
        $customer->refresh();
        $this->assertCount(1, $customer->addresses);

        $address = $customer->addresses->first();
        $this->assertEquals('456 Oak Ave', $address->address);
        $this->assertEquals('Chicago', $address->city);
        $this->assertEquals($illinoisState->id, $address->state_id);
        $this->assertEquals('60601', $address->zip);
        $this->assertTrue($address->is_default);
        $this->assertTrue($address->is_shipping);
    }

    public function test_order_show_includes_can_sync_from_platform_flag(): void
    {
        $this->actingAs($this->user);

        $marketplace = StoreMarketplace::factory()->shopify()->create([
            'store_id' => $this->store->id,
            'status' => 'active',
        ]);

        $salesChannel = SalesChannel::create([
            'store_id' => $this->store->id,
            'name' => 'Shopify',
            'type' => 'shopify',
            'is_local' => false,
            'store_marketplace_id' => $marketplace->id,
            'is_active' => true,
        ]);

        $order = Order::factory()->create([
            'store_id' => $this->store->id,
            'external_marketplace_id' => '7358483726583',
            'source_platform' => 'shopify',
            'sales_channel_id' => $salesChannel->id,
        ]);

        $response = $this->withStore()
            ->get("/orders/{$order->id}");

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('orders/Show')
                ->where('order.can_sync_from_platform', true)
                ->where('order.source_platform', 'shopify')
                ->where('order.external_marketplace_id', '7358483726583')
            );
    }

    public function test_order_without_external_id_cannot_sync(): void
    {
        $this->actingAs($this->user);

        $order = Order::factory()->create([
            'store_id' => $this->store->id,
            'external_marketplace_id' => null,
            'source_platform' => null,
        ]);

        $response = $this->withStore()
            ->get("/orders/{$order->id}");

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('orders/Show')
                ->where('order.can_sync_from_platform', false)
            );
    }

    public function test_sync_creates_platform_order_if_missing(): void
    {
        $this->actingAs($this->user);

        $marketplace = StoreMarketplace::factory()->shopify()->create([
            'store_id' => $this->store->id,
            'shop_domain' => 'test-store.myshopify.com',
            'access_token' => 'test-token',
            'status' => 'active',
        ]);

        $salesChannel = SalesChannel::create([
            'store_id' => $this->store->id,
            'name' => 'Shopify',
            'type' => 'shopify',
            'is_local' => false,
            'store_marketplace_id' => $marketplace->id,
            'is_active' => true,
        ]);

        $order = Order::factory()->create([
            'store_id' => $this->store->id,
            'external_marketplace_id' => '7358483726583',
            'source_platform' => 'shopify',
            'sales_channel_id' => $salesChannel->id,
        ]);

        // Mock the Shopify API response
        Http::fake([
            'test-store.myshopify.com/admin/api/*' => Http::response([
                'order' => [
                    'id' => 7358483726583,
                    'order_number' => 1001,
                    'name' => '#1001',
                    'financial_status' => 'paid',
                    'fulfillment_status' => 'fulfilled',
                    'total_price' => '125.00',
                    'subtotal_price' => '100.00',
                    'total_tax' => '10.00',
                    'currency' => 'USD',
                    'created_at' => now()->toISOString(),
                    'shipping_lines' => [
                        ['price' => '15.00'],
                    ],
                    'discount_codes' => [],
                    'line_items' => [
                        [
                            'id' => 1,
                            'title' => 'Test Product',
                            'quantity' => 1,
                            'price' => '100.00',
                        ],
                    ],
                    'shipping_address' => [
                        'first_name' => 'John',
                        'last_name' => 'Doe',
                        'address1' => '123 Main St',
                        'city' => 'New York',
                        'province_code' => 'NY',
                        'zip' => '10001',
                        'country_code' => 'US',
                    ],
                    'billing_address' => [
                        'first_name' => 'John',
                        'last_name' => 'Doe',
                        'address1' => '123 Main St',
                        'city' => 'New York',
                        'province_code' => 'NY',
                        'zip' => '10001',
                        'country_code' => 'US',
                    ],
                ],
            ], 200),
        ]);

        // Verify no platform order exists yet
        $this->assertNull($order->platformOrder);

        $response = $this->withStore()
            ->post("/orders/{$order->id}/sync-from-marketplace");

        $response->assertRedirect();
        $response->assertSessionHas('success');

        // Verify platform order was created
        $order->refresh();
        $this->assertNotNull($order->platformOrder);
        $this->assertEquals('7358483726583', $order->platformOrder->external_order_id);
        $this->assertEquals(1001, $order->platformOrder->external_order_number);
        $this->assertEquals('paid', $order->platformOrder->status);
        $this->assertEquals('fulfilled', $order->platformOrder->fulfillment_status);

        // Verify shipping address was updated
        $this->assertNotNull($order->shipping_address);
        $this->assertEquals('John Doe', $order->shipping_address['name']);

        // Verify order status was updated to shipped (fulfilled in Shopify)
        $this->assertEquals('shipped', $order->status);
    }

    public function test_sync_updates_order_status_to_completed_when_closed(): void
    {
        $this->actingAs($this->user);

        $marketplace = StoreMarketplace::factory()->shopify()->create([
            'store_id' => $this->store->id,
            'shop_domain' => 'test-store.myshopify.com',
            'access_token' => 'test-token',
            'status' => 'active',
        ]);

        $salesChannel = SalesChannel::create([
            'store_id' => $this->store->id,
            'name' => 'Shopify',
            'type' => 'shopify',
            'is_local' => false,
            'store_marketplace_id' => $marketplace->id,
            'is_active' => true,
        ]);

        $order = Order::factory()->create([
            'store_id' => $this->store->id,
            'external_marketplace_id' => '7358483726583',
            'source_platform' => 'shopify',
            'sales_channel_id' => $salesChannel->id,
            'status' => 'pending',
        ]);

        // Mock Shopify response with fulfilled and closed order
        Http::fake([
            'test-store.myshopify.com/admin/api/*' => Http::response([
                'order' => [
                    'id' => 7358483726583,
                    'order_number' => 1001,
                    'financial_status' => 'paid',
                    'fulfillment_status' => 'fulfilled',
                    'closed_at' => now()->toISOString(),
                    'total_price' => '100.00',
                    'subtotal_price' => '100.00',
                    'total_tax' => '0.00',
                    'currency' => 'USD',
                    'created_at' => now()->subDays(3)->toISOString(),
                    'shipping_lines' => [],
                    'discount_codes' => [],
                    'line_items' => [],
                    'fulfillments' => [
                        [
                            'id' => 1,
                            'status' => 'success',
                            'tracking_number' => '1Z999AA10123456784',
                            'tracking_company' => 'UPS',
                            'created_at' => now()->subDay()->toISOString(),
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->withStore()
            ->post("/orders/{$order->id}/sync-from-marketplace");

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $order->refresh();
        $this->assertEquals('completed', $order->status);
        $this->assertEquals('1Z999AA10123456784', $order->tracking_number);
        $this->assertEquals('ups', $order->shipping_carrier);
    }

    public function test_sync_marks_order_completed_when_shipment_delivered(): void
    {
        $this->actingAs($this->user);

        $marketplace = StoreMarketplace::factory()->shopify()->create([
            'store_id' => $this->store->id,
            'shop_domain' => 'test-store.myshopify.com',
            'access_token' => 'test-token',
            'status' => 'active',
        ]);

        $salesChannel = SalesChannel::create([
            'store_id' => $this->store->id,
            'name' => 'Shopify',
            'type' => 'shopify',
            'is_local' => false,
            'store_marketplace_id' => $marketplace->id,
            'is_active' => true,
        ]);

        $order = Order::factory()->create([
            'store_id' => $this->store->id,
            'external_marketplace_id' => '7358483726583',
            'source_platform' => 'shopify',
            'sales_channel_id' => $salesChannel->id,
            'status' => 'shipped',
        ]);

        // Mock Shopify response with delivered shipment
        Http::fake([
            'test-store.myshopify.com/admin/api/*' => Http::response([
                'order' => [
                    'id' => 7358483726583,
                    'order_number' => 1001,
                    'financial_status' => 'paid',
                    'fulfillment_status' => 'fulfilled',
                    'total_price' => '100.00',
                    'subtotal_price' => '100.00',
                    'total_tax' => '0.00',
                    'currency' => 'USD',
                    'created_at' => now()->subDays(5)->toISOString(),
                    'shipping_lines' => [],
                    'discount_codes' => [],
                    'line_items' => [],
                    'fulfillments' => [
                        [
                            'id' => 1,
                            'status' => 'success',
                            'shipment_status' => 'delivered',
                            'tracking_number' => '398369978630',
                            'tracking_company' => 'FedEx',
                            'created_at' => now()->subDays(3)->toISOString(),
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->withStore()
            ->post("/orders/{$order->id}/sync-from-marketplace");

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $order->refresh();
        $this->assertEquals('completed', $order->status);
    }

    public function test_sync_updates_order_status_to_cancelled(): void
    {
        $this->actingAs($this->user);

        $marketplace = StoreMarketplace::factory()->shopify()->create([
            'store_id' => $this->store->id,
            'shop_domain' => 'test-store.myshopify.com',
            'access_token' => 'test-token',
            'status' => 'active',
        ]);

        $salesChannel = SalesChannel::create([
            'store_id' => $this->store->id,
            'name' => 'Shopify',
            'type' => 'shopify',
            'is_local' => false,
            'store_marketplace_id' => $marketplace->id,
            'is_active' => true,
        ]);

        $order = Order::factory()->create([
            'store_id' => $this->store->id,
            'external_marketplace_id' => '7358483726583',
            'source_platform' => 'shopify',
            'sales_channel_id' => $salesChannel->id,
            'status' => 'pending',
        ]);

        // Mock Shopify response with cancelled order
        Http::fake([
            'test-store.myshopify.com/admin/api/*' => Http::response([
                'order' => [
                    'id' => 7358483726583,
                    'order_number' => 1001,
                    'financial_status' => 'voided',
                    'fulfillment_status' => null,
                    'cancelled_at' => now()->toISOString(),
                    'total_price' => '100.00',
                    'subtotal_price' => '100.00',
                    'total_tax' => '0.00',
                    'currency' => 'USD',
                    'created_at' => now()->subDays(3)->toISOString(),
                    'shipping_lines' => [],
                    'discount_codes' => [],
                    'line_items' => [],
                ],
            ], 200),
        ]);

        $response = $this->withStore()
            ->post("/orders/{$order->id}/sync-from-marketplace");

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $order->refresh();
        $this->assertEquals('cancelled', $order->status);
    }

    public function test_sync_confirms_order_when_paid_in_shopify(): void
    {
        $this->actingAs($this->user);

        $marketplace = StoreMarketplace::factory()->shopify()->create([
            'store_id' => $this->store->id,
            'shop_domain' => 'test-store.myshopify.com',
            'access_token' => 'test-token',
            'status' => 'active',
        ]);

        $salesChannel = SalesChannel::create([
            'store_id' => $this->store->id,
            'name' => 'Shopify',
            'type' => 'shopify',
            'is_local' => false,
            'store_marketplace_id' => $marketplace->id,
            'is_active' => true,
        ]);

        $order = Order::factory()->create([
            'store_id' => $this->store->id,
            'external_marketplace_id' => '7358483726583',
            'source_platform' => 'shopify',
            'sales_channel_id' => $salesChannel->id,
            'status' => 'pending',
        ]);

        // Mock Shopify response with paid but unfulfilled order
        Http::fake([
            'test-store.myshopify.com/admin/api/*' => Http::response([
                'order' => [
                    'id' => 7358483726583,
                    'order_number' => 1001,
                    'financial_status' => 'paid',
                    'fulfillment_status' => null,
                    'total_price' => '100.00',
                    'subtotal_price' => '100.00',
                    'total_tax' => '0.00',
                    'currency' => 'USD',
                    'created_at' => now()->toISOString(),
                    'shipping_lines' => [],
                    'discount_codes' => [],
                    'line_items' => [],
                ],
            ], 200),
        ]);

        $response = $this->withStore()
            ->post("/orders/{$order->id}/sync-from-marketplace");

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $order->refresh();
        $this->assertEquals('confirmed', $order->status);
    }

    public function test_sync_updates_customer_address_from_shopify(): void
    {
        $this->actingAs($this->user);

        // Create state record for New York
        $newYorkState = State::firstOrCreate(
            ['abbreviation' => 'NY', 'country_code' => 'US'],
            ['name' => 'New York']
        );

        $marketplace = StoreMarketplace::factory()->shopify()->create([
            'store_id' => $this->store->id,
            'shop_domain' => 'test-store.myshopify.com',
            'access_token' => 'test-token',
            'status' => 'active',
        ]);

        $salesChannel = SalesChannel::create([
            'store_id' => $this->store->id,
            'name' => 'Shopify',
            'type' => 'shopify',
            'is_local' => false,
            'store_marketplace_id' => $marketplace->id,
            'is_active' => true,
        ]);

        // Create customer without address
        $customer = Customer::factory()->create([
            'store_id' => $this->store->id,
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john@example.com',
            'address' => null,
            'city' => null,
            'state' => null,
            'zip' => null,
            'phone_number' => null,
        ]);

        $order = Order::factory()->create([
            'store_id' => $this->store->id,
            'customer_id' => $customer->id,
            'external_marketplace_id' => '7358483726583',
            'source_platform' => 'shopify',
            'sales_channel_id' => $salesChannel->id,
            'status' => 'pending',
        ]);

        // Mock Shopify response with customer address
        Http::fake([
            'test-store.myshopify.com/admin/api/*' => Http::response([
                'order' => [
                    'id' => 7358483726583,
                    'order_number' => 1001,
                    'financial_status' => 'paid',
                    'fulfillment_status' => null,
                    'total_price' => '100.00',
                    'subtotal_price' => '100.00',
                    'total_tax' => '0.00',
                    'currency' => 'USD',
                    'created_at' => now()->toISOString(),
                    'shipping_lines' => [],
                    'discount_codes' => [],
                    'line_items' => [],
                    'customer' => [
                        'id' => 123456789,
                        'email' => 'john@example.com',
                        'first_name' => 'John',
                        'last_name' => 'Doe',
                        'phone' => '+1234567890',
                        'default_address' => [
                            'address1' => '123 Main Street',
                            'address2' => 'Apt 4B',
                            'city' => 'New York',
                            'province_code' => 'NY',
                            'zip' => '10001',
                            'country_code' => 'US',
                            'company' => 'Acme Inc',
                        ],
                    ],
                    'shipping_address' => [
                        'first_name' => 'John',
                        'last_name' => 'Doe',
                        'address1' => '123 Main Street',
                        'address2' => 'Apt 4B',
                        'city' => 'New York',
                        'province_code' => 'NY',
                        'zip' => '10001',
                        'country_code' => 'US',
                        'phone' => '+1234567890',
                        'company' => 'Acme Inc',
                    ],
                ],
            ], 200),
        ]);

        $response = $this->withStore()
            ->post("/orders/{$order->id}/sync-from-marketplace");

        $response->assertRedirect();
        $response->assertSessionHas('success');

        // Verify customer address was created in addresses table
        $customer->refresh();
        $this->assertCount(1, $customer->addresses);

        $address = $customer->addresses->first();
        $this->assertEquals('123 Main Street', $address->address);
        $this->assertEquals('Apt 4B', $address->address2);
        $this->assertEquals('New York', $address->city);
        $this->assertEquals($newYorkState->id, $address->state_id);
        $this->assertEquals('10001', $address->zip);
        $this->assertEquals('+1234567890', $address->phone);
        $this->assertEquals('Acme Inc', $address->company);
        $this->assertEquals('John', $address->first_name);
        $this->assertEquals('Doe', $address->last_name);
        $this->assertTrue($address->is_default);
        $this->assertTrue($address->is_shipping);
        $this->assertEquals('shipping', $address->type);

        // Verify basic customer info was updated
        $this->assertEquals('+1234567890', $customer->phone_number);
        $this->assertEquals('Acme Inc', $customer->company_name);
    }
}
