<?php

namespace Tests\Feature;

use App\Models\PaymentTerminal;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;
use App\Services\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

class PaymentTerminalTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->store = Store::factory()->create(['user_id' => $this->user->id]);

        $role = Role::factory()->owner()->create(['store_id' => $this->store->id]);
        StoreUser::factory()->owner()->create([
            'user_id' => $this->user->id,
            'store_id' => $this->store->id,
            'role_id' => $role->id,
        ]);

        app(StoreContext::class)->setCurrentStore($this->store);
    }

    public function test_can_list_terminals(): void
    {
        Passport::actingAs($this->user);

        PaymentTerminal::factory()->count(3)->create([
            'store_id' => $this->store->id,
        ]);

        $response = $this->getJson('/api/v1/terminals');

        $response->assertStatus(200)
            ->assertJsonCount(3, 'data');
    }

    public function test_can_filter_terminals_by_status(): void
    {
        Passport::actingAs($this->user);

        PaymentTerminal::factory()->active()->count(2)->create([
            'store_id' => $this->store->id,
        ]);
        PaymentTerminal::factory()->inactive()->create([
            'store_id' => $this->store->id,
        ]);

        $response = $this->getJson('/api/v1/terminals?status=active');

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data');
    }

    public function test_can_filter_terminals_by_gateway(): void
    {
        Passport::actingAs($this->user);

        PaymentTerminal::factory()->square()->count(2)->create([
            'store_id' => $this->store->id,
        ]);
        PaymentTerminal::factory()->dejavoo()->create([
            'store_id' => $this->store->id,
        ]);

        $response = $this->getJson('/api/v1/terminals?gateway=square');

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data');
    }

    public function test_can_show_terminal_details(): void
    {
        Passport::actingAs($this->user);

        $terminal = PaymentTerminal::factory()->create([
            'store_id' => $this->store->id,
        ]);

        $response = $this->getJson("/api/v1/terminals/{$terminal->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $terminal->id)
            ->assertJsonPath('data.name', $terminal->name);
    }

    public function test_can_update_terminal(): void
    {
        Passport::actingAs($this->user);

        $terminal = PaymentTerminal::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Old Name',
        ]);

        $response = $this->putJson("/api/v1/terminals/{$terminal->id}", [
            'name' => 'New Name',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.name', 'New Name');
    }

    public function test_can_deactivate_terminal(): void
    {
        Passport::actingAs($this->user);

        $terminal = PaymentTerminal::factory()->active()->create([
            'store_id' => $this->store->id,
        ]);

        $response = $this->putJson("/api/v1/terminals/{$terminal->id}", [
            'status' => 'inactive',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'inactive');
    }

    public function test_can_disconnect_terminal(): void
    {
        Passport::actingAs($this->user);

        $terminal = PaymentTerminal::factory()->create([
            'store_id' => $this->store->id,
        ]);

        $response = $this->deleteJson("/api/v1/terminals/{$terminal->id}");

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Terminal disconnected successfully.');

        $terminal->refresh();
        $this->assertEquals(PaymentTerminal::STATUS_DISCONNECTED, $terminal->status);
    }

    public function test_can_test_terminal_connection(): void
    {
        Passport::actingAs($this->user);

        $terminal = PaymentTerminal::factory()->create([
            'store_id' => $this->store->id,
            'last_seen_at' => null,
        ]);

        $response = $this->postJson("/api/v1/terminals/{$terminal->id}/test");

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Connection test successful.');

        $terminal->refresh();
        $this->assertNotNull($terminal->last_seen_at);
    }

    public function test_can_list_available_gateways(): void
    {
        Passport::actingAs($this->user);

        $response = $this->getJson('/api/v1/terminals/gateways');

        $response->assertStatus(200)
            ->assertJsonPath('data', ['square', 'dejavoo']);
    }

    public function test_only_store_terminals_are_visible(): void
    {
        Passport::actingAs($this->user);

        $otherStore = Store::factory()->create();

        PaymentTerminal::factory()->count(2)->create([
            'store_id' => $this->store->id,
        ]);
        PaymentTerminal::factory()->count(3)->create([
            'store_id' => $otherStore->id,
        ]);

        $response = $this->getJson('/api/v1/terminals');

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data');
    }

    public function test_terminal_factory_states(): void
    {
        $activeTerminal = PaymentTerminal::factory()->active()->create([
            'store_id' => $this->store->id,
        ]);
        $this->assertEquals(PaymentTerminal::STATUS_ACTIVE, $activeTerminal->status);

        $inactiveTerminal = PaymentTerminal::factory()->inactive()->create([
            'store_id' => $this->store->id,
        ]);
        $this->assertEquals(PaymentTerminal::STATUS_INACTIVE, $inactiveTerminal->status);

        $squareTerminal = PaymentTerminal::factory()->square()->create([
            'store_id' => $this->store->id,
        ]);
        $this->assertEquals(PaymentTerminal::GATEWAY_SQUARE, $squareTerminal->gateway);

        $dejavooTerminal = PaymentTerminal::factory()->dejavoo()->create([
            'store_id' => $this->store->id,
        ]);
        $this->assertEquals(PaymentTerminal::GATEWAY_DEJAVOO, $dejavooTerminal->gateway);
    }

    public function test_terminal_is_active_helper(): void
    {
        $activeTerminal = PaymentTerminal::factory()->active()->create([
            'store_id' => $this->store->id,
        ]);
        $this->assertTrue($activeTerminal->isActive());

        $inactiveTerminal = PaymentTerminal::factory()->inactive()->create([
            'store_id' => $this->store->id,
        ]);
        $this->assertFalse($inactiveTerminal->isActive());
    }
}
