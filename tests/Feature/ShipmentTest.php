<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Role;
use App\Models\ShippingLabel;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;
use App\Services\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ShipmentTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Store $store;

    protected Order $order;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->store = Store::factory()->onboarded()->create(['user_id' => $this->user->id]);

        $role = Role::factory()->owner()->create(['store_id' => $this->store->id]);
        StoreUser::factory()->owner()->create([
            'user_id' => $this->user->id,
            'store_id' => $this->store->id,
            'role_id' => $role->id,
        ]);

        $this->order = Order::factory()->confirmed()->create([
            'store_id' => $this->store->id,
        ]);

        app(StoreContext::class)->setCurrentStore($this->store);
    }

    public function test_can_view_shipments_index_page(): void
    {
        $this->actingAs($this->user);

        ShippingLabel::factory()->count(3)->create([
            'store_id' => $this->store->id,
            'shippable_type' => Order::class,
            'shippable_id' => $this->order->id,
            'type' => ShippingLabel::TYPE_OUTBOUND,
        ]);

        $response = $this->get('/shipments');

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('shipments/Index')
            ->has('statuses')
            ->has('carriers')
        );
    }

    public function test_can_track_shipment(): void
    {
        $this->actingAs($this->user);

        $label = ShippingLabel::factory()->create([
            'store_id' => $this->store->id,
            'shippable_type' => Order::class,
            'shippable_id' => $this->order->id,
            'carrier' => ShippingLabel::CARRIER_FEDEX,
            'tracking_number' => '123456789012',
        ]);

        $response = $this->get("/shipments/{$label->id}/track");

        $response->assertRedirect();
        $this->assertStringContains('fedex.com', $response->headers->get('Location'));
    }

    public function test_can_void_shipment(): void
    {
        $this->actingAs($this->user);

        $label = ShippingLabel::factory()->create([
            'store_id' => $this->store->id,
            'shippable_type' => Order::class,
            'shippable_id' => $this->order->id,
            'status' => ShippingLabel::STATUS_CREATED,
        ]);

        $response = $this->post("/shipments/{$label->id}/void");

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $label->refresh();
        $this->assertTrue($label->isVoided());
    }

    public function test_cannot_void_delivered_shipment(): void
    {
        $this->actingAs($this->user);

        $label = ShippingLabel::factory()->create([
            'store_id' => $this->store->id,
            'shippable_type' => Order::class,
            'shippable_id' => $this->order->id,
            'status' => ShippingLabel::STATUS_DELIVERED,
        ]);

        $response = $this->post("/shipments/{$label->id}/void");

        $response->assertRedirect();
        $response->assertSessionHas('error');

        $label->refresh();
        $this->assertTrue($label->isDelivered());
    }

    public function test_cannot_void_already_voided_shipment(): void
    {
        $this->actingAs($this->user);

        $label = ShippingLabel::factory()->create([
            'store_id' => $this->store->id,
            'shippable_type' => Order::class,
            'shippable_id' => $this->order->id,
            'status' => ShippingLabel::STATUS_VOIDED,
        ]);

        $response = $this->post("/shipments/{$label->id}/void");

        $response->assertRedirect();
        $response->assertSessionHas('error');
    }

    public function test_can_bulk_void_shipments(): void
    {
        $this->actingAs($this->user);

        $labels = ShippingLabel::factory()->count(3)->create([
            'store_id' => $this->store->id,
            'shippable_type' => Order::class,
            'shippable_id' => $this->order->id,
            'status' => ShippingLabel::STATUS_CREATED,
        ]);

        $response = $this->post('/shipments/bulk-action', [
            'action' => 'void',
            'ids' => $labels->pluck('id')->toArray(),
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        foreach ($labels as $label) {
            $this->assertTrue($label->fresh()->isVoided());
        }
    }

    public function test_cannot_access_other_stores_shipments(): void
    {
        $this->actingAs($this->user);

        $otherStore = Store::factory()->create();
        $otherOrder = Order::factory()->create(['store_id' => $otherStore->id]);
        $label = ShippingLabel::factory()->create([
            'store_id' => $otherStore->id,
            'shippable_type' => Order::class,
            'shippable_id' => $otherOrder->id,
        ]);

        $response = $this->post("/shipments/{$label->id}/void");

        $response->assertStatus(404);
    }

    public function test_track_returns_error_when_no_tracking_url(): void
    {
        $this->actingAs($this->user);

        $label = ShippingLabel::factory()->create([
            'store_id' => $this->store->id,
            'shippable_type' => Order::class,
            'shippable_id' => $this->order->id,
            'tracking_number' => null,
        ]);

        $response = $this->get("/shipments/{$label->id}/track");

        $response->assertRedirect();
        $response->assertSessionHas('error');
    }

    protected function assertStringContains(string $needle, ?string $haystack): void
    {
        $this->assertTrue(
            str_contains($haystack ?? '', $needle),
            "Failed asserting that '$haystack' contains '$needle'"
        );
    }
}
