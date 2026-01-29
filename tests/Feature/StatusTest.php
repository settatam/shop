<?php

namespace Tests\Feature;

use App\Enums\StatusableType;
use App\Models\Order;
use App\Models\Role;
use App\Models\Status;
use App\Models\StatusAutomation;
use App\Models\StatusTransition;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Statuses\StatusService;
use App\Services\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

class StatusTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Store $store;

    protected Role $ownerRole;

    protected StoreUser $storeUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->store = Store::factory()->create(['user_id' => $this->user->id]);

        $this->ownerRole = Role::factory()->owner()->create([
            'store_id' => $this->store->id,
        ]);

        $this->storeUser = StoreUser::factory()->owner()->create([
            'user_id' => $this->user->id,
            'store_id' => $this->store->id,
            'role_id' => $this->ownerRole->id,
        ]);

        app(StoreContext::class)->setCurrentStore($this->store);
    }

    public function test_can_create_status(): void
    {
        $status = Status::factory()->forOrder()->create([
            'store_id' => $this->store->id,
            'name' => 'Custom Status',
            'slug' => 'custom-status',
            'color' => '#3b82f6',
        ]);

        $this->assertDatabaseHas('statuses', [
            'store_id' => $this->store->id,
            'entity_type' => 'order',
            'name' => 'Custom Status',
            'slug' => 'custom-status',
        ]);
    }

    public function test_status_slug_must_be_unique_per_store_and_entity_type(): void
    {
        Status::factory()->forOrder()->create([
            'store_id' => $this->store->id,
            'slug' => 'pending',
        ]);

        // Same slug for different entity type should work
        $status = Status::factory()->forTransaction()->create([
            'store_id' => $this->store->id,
            'slug' => 'pending',
        ]);

        $this->assertNotNull($status->id);
    }

    public function test_only_one_default_status_per_entity_type(): void
    {
        $status1 = Status::factory()->forOrder()->default()->create([
            'store_id' => $this->store->id,
        ]);

        $status2 = Status::factory()->forOrder()->default()->create([
            'store_id' => $this->store->id,
        ]);

        $status1->refresh();
        $status2->refresh();

        $this->assertFalse($status1->is_default);
        $this->assertTrue($status2->is_default);
    }

    public function test_status_allows_behavior_check(): void
    {
        $status = Status::factory()->create([
            'store_id' => $this->store->id,
            'behavior' => [
                'allows_payment' => true,
                'allows_cancellation' => false,
            ],
        ]);

        $this->assertTrue($status->allows('allows_payment'));
        $this->assertFalse($status->allows('allows_cancellation'));
        $this->assertFalse($status->allows('non_existent_flag'));
    }

    public function test_can_create_status_transition(): void
    {
        $fromStatus = Status::factory()->forOrder()->create([
            'store_id' => $this->store->id,
            'slug' => 'pending',
        ]);

        $toStatus = Status::factory()->forOrder()->create([
            'store_id' => $this->store->id,
            'slug' => 'confirmed',
        ]);

        $transition = StatusTransition::create([
            'from_status_id' => $fromStatus->id,
            'to_status_id' => $toStatus->id,
            'name' => 'Confirm Order',
        ]);

        $this->assertTrue($fromStatus->canTransitionTo($toStatus));
    }

    public function test_cannot_transition_without_valid_transition(): void
    {
        $fromStatus = Status::factory()->forOrder()->create([
            'store_id' => $this->store->id,
            'slug' => 'pending',
        ]);

        $toStatus = Status::factory()->forOrder()->create([
            'store_id' => $this->store->id,
            'slug' => 'confirmed',
        ]);

        // No transition created
        $this->assertFalse($fromStatus->canTransitionTo($toStatus));
    }

    public function test_disabled_transition_is_not_allowed(): void
    {
        $fromStatus = Status::factory()->forOrder()->create([
            'store_id' => $this->store->id,
        ]);

        $toStatus = Status::factory()->forOrder()->create([
            'store_id' => $this->store->id,
        ]);

        StatusTransition::create([
            'from_status_id' => $fromStatus->id,
            'to_status_id' => $toStatus->id,
            'is_enabled' => false,
        ]);

        $this->assertFalse($fromStatus->canTransitionTo($toStatus));
    }

    public function test_can_create_status_automation(): void
    {
        $status = Status::factory()->forOrder()->create([
            'store_id' => $this->store->id,
        ]);

        $automation = StatusAutomation::create([
            'status_id' => $status->id,
            'trigger' => 'on_enter',
            'action_type' => 'webhook',
            'action_config' => [
                'url' => 'https://example.com/webhook',
                'method' => 'POST',
            ],
        ]);

        $this->assertEquals($status->id, $automation->status_id);
        $this->assertTrue($automation->runsOnEnter());
        $this->assertTrue($automation->isWebhook());
        $this->assertEquals('https://example.com/webhook', $automation->getWebhookUrl());
    }

    public function test_status_service_creates_default_statuses(): void
    {
        $statusService = app(StatusService::class);

        $statusService->createDefaultStatuses($this->store->id, StatusableType::Order);

        $statuses = Status::query()
            ->where('store_id', $this->store->id)
            ->where('entity_type', 'order')
            ->get();

        $this->assertGreaterThan(0, $statuses->count());
        $this->assertTrue($statuses->contains('slug', 'draft'));
        $this->assertTrue($statuses->contains('slug', 'pending'));
        $this->assertTrue($statuses->contains('slug', 'completed'));
    }

    public function test_default_status_has_transitions(): void
    {
        $statusService = app(StatusService::class);

        $statusService->createDefaultStatuses($this->store->id, StatusableType::Order);

        $pendingStatus = Status::query()
            ->where('store_id', $this->store->id)
            ->where('entity_type', 'order')
            ->where('slug', 'pending')
            ->first();

        $this->assertGreaterThan(0, $pendingStatus->outgoingTransitions()->count());
    }

    public function test_can_list_statuses_via_api(): void
    {
        Passport::actingAs($this->user);

        Status::factory()->forOrder()->count(3)->create(['store_id' => $this->store->id]);

        $response = $this->getJson('/api/v1/statuses?entity_type=order');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'statuses',
                'entity_types',
            ]);
    }

    public function test_can_create_status_via_api(): void
    {
        Passport::actingAs($this->user);

        $response = $this->postJson('/api/v1/statuses', [
            'entity_type' => 'order',
            'name' => 'Custom Status',
            'slug' => 'custom_status',
            'color' => '#3b82f6',
        ]);

        $response->assertStatus(201)
            ->assertJsonFragment([
                'name' => 'Custom Status',
                'slug' => 'custom_status',
            ]);

        $this->assertDatabaseHas('statuses', [
            'name' => 'Custom Status',
            'slug' => 'custom_status',
            'store_id' => $this->store->id,
        ]);
    }

    public function test_can_update_status_via_api(): void
    {
        Passport::actingAs($this->user);

        $status = Status::factory()->forOrder()->create(['store_id' => $this->store->id]);

        $response = $this->patchJson("/api/v1/statuses/{$status->id}", [
            'name' => 'Updated Status Name',
            'color' => '#ef4444',
        ]);

        $response->assertStatus(200)
            ->assertJsonFragment(['name' => 'Updated Status Name']);
    }

    public function test_cannot_delete_system_status(): void
    {
        Passport::actingAs($this->user);

        $status = Status::factory()->forOrder()->system()->create(['store_id' => $this->store->id]);

        $response = $this->deleteJson("/api/v1/statuses/{$status->id}");

        $response->assertStatus(403);
    }

    public function test_can_delete_custom_status(): void
    {
        Passport::actingAs($this->user);

        $status = Status::factory()->forOrder()->create([
            'store_id' => $this->store->id,
            'is_system' => false,
        ]);

        $response = $this->deleteJson("/api/v1/statuses/{$status->id}");

        $response->assertStatus(204);

        $this->assertSoftDeleted('statuses', ['id' => $status->id]);
    }

    public function test_can_create_transition_via_api(): void
    {
        Passport::actingAs($this->user);

        $fromStatus = Status::factory()->forOrder()->create(['store_id' => $this->store->id]);
        $toStatus = Status::factory()->forOrder()->create(['store_id' => $this->store->id]);

        $response = $this->postJson("/api/v1/statuses/{$fromStatus->id}/transitions", [
            'to_status_id' => $toStatus->id,
            'name' => 'Move Forward',
        ]);

        $response->assertStatus(201)
            ->assertJsonFragment(['name' => 'Move Forward']);

        $this->assertDatabaseHas('status_transitions', [
            'from_status_id' => $fromStatus->id,
            'to_status_id' => $toStatus->id,
        ]);
    }

    public function test_cannot_create_transition_to_different_entity_type(): void
    {
        Passport::actingAs($this->user);

        $orderStatus = Status::factory()->forOrder()->create(['store_id' => $this->store->id]);
        $transactionStatus = Status::factory()->forTransaction()->create(['store_id' => $this->store->id]);

        $response = $this->postJson("/api/v1/statuses/{$orderStatus->id}/transitions", [
            'to_status_id' => $transactionStatus->id,
        ]);

        $response->assertStatus(422);
    }

    public function test_can_create_automation_via_api(): void
    {
        Passport::actingAs($this->user);

        $status = Status::factory()->forOrder()->create(['store_id' => $this->store->id]);

        $response = $this->postJson("/api/v1/statuses/{$status->id}/automations", [
            'trigger' => 'on_enter',
            'action_type' => 'webhook',
            'action_config' => [
                'url' => 'https://example.com/webhook',
                'method' => 'POST',
            ],
        ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('status_automations', [
            'status_id' => $status->id,
            'trigger' => 'on_enter',
            'action_type' => 'webhook',
        ]);
    }

    public function test_reorder_statuses_via_api(): void
    {
        Passport::actingAs($this->user);

        $status1 = Status::factory()->forOrder()->create([
            'store_id' => $this->store->id,
            'sort_order' => 0,
        ]);
        $status2 = Status::factory()->forOrder()->create([
            'store_id' => $this->store->id,
            'sort_order' => 1,
        ]);
        $status3 = Status::factory()->forOrder()->create([
            'store_id' => $this->store->id,
            'sort_order' => 2,
        ]);

        $response = $this->postJson('/api/v1/statuses/reorder', [
            'status_ids' => [$status3->id, $status1->id, $status2->id],
        ]);

        $response->assertStatus(200);

        $status1->refresh();
        $status2->refresh();
        $status3->refresh();

        $this->assertEquals(1, $status1->sort_order);
        $this->assertEquals(2, $status2->sort_order);
        $this->assertEquals(0, $status3->sort_order);
    }

    public function test_get_default_status(): void
    {
        $defaultStatus = Status::factory()->forOrder()->default()->create([
            'store_id' => $this->store->id,
        ]);

        Status::factory()->forOrder()->count(2)->create([
            'store_id' => $this->store->id,
        ]);

        $retrieved = Status::getDefault($this->store->id, StatusableType::Order);

        $this->assertEquals($defaultStatus->id, $retrieved->id);
    }

    public function test_find_status_by_slug(): void
    {
        $status = Status::factory()->forOrder()->create([
            'store_id' => $this->store->id,
            'slug' => 'processing',
        ]);

        $found = Status::findBySlug($this->store->id, StatusableType::Order, 'processing');

        $this->assertEquals($status->id, $found->id);
    }

    public function test_status_transition_conditions(): void
    {
        $transition = StatusTransition::factory()->make([
            'conditions' => [
                'is_paid' => true,
                'has_items' => ['operator' => 'greater_than', 'value' => 0],
            ],
        ]);

        $this->assertTrue($transition->isAllowed([
            'is_paid' => true,
            'has_items' => 5,
        ]));

        $this->assertFalse($transition->isAllowed([
            'is_paid' => false,
            'has_items' => 5,
        ]));
    }

    public function test_order_can_use_has_custom_statuses_trait(): void
    {
        $statusService = app(StatusService::class);
        $statusService->createDefaultStatuses($this->store->id, StatusableType::Order);

        $order = Order::factory()->create([
            'store_id' => $this->store->id,
            'status' => 'draft',
        ]);

        // Sync status from legacy field
        $order->syncStatusFromLegacy();
        $order->refresh();

        $this->assertNotNull($order->statusModel);
        $this->assertEquals('draft', $order->statusModel->slug);
    }

    public function test_entity_can_transition_status(): void
    {
        $statusService = app(StatusService::class);
        $statusService->createDefaultStatuses($this->store->id, StatusableType::Order);

        $draftStatus = Status::findBySlug($this->store->id, StatusableType::Order, 'draft');
        $pendingStatus = Status::findBySlug($this->store->id, StatusableType::Order, 'pending');

        $order = Order::factory()->create([
            'store_id' => $this->store->id,
            'status' => 'draft',
            'status_id' => $draftStatus->id,
        ]);

        $this->assertTrue($order->canTransitionTo($pendingStatus));

        $success = $order->transitionTo($pendingStatus);

        $this->assertTrue($success);
        $order->refresh();
        $this->assertEquals('pending', $order->status);
        $this->assertEquals($pendingStatus->id, $order->status_id);
    }

    public function test_entity_cannot_transition_to_invalid_status(): void
    {
        $statusService = app(StatusService::class);
        $statusService->createDefaultStatuses($this->store->id, StatusableType::Order);

        $draftStatus = Status::findBySlug($this->store->id, StatusableType::Order, 'draft');
        $completedStatus = Status::findBySlug($this->store->id, StatusableType::Order, 'completed');

        $order = Order::factory()->create([
            'store_id' => $this->store->id,
            'status' => 'draft',
            'status_id' => $draftStatus->id,
        ]);

        // Draft cannot directly transition to completed
        $this->assertFalse($order->canTransitionTo($completedStatus));
    }

    public function test_transaction_uses_has_custom_statuses_trait(): void
    {
        $statusService = app(StatusService::class);
        $statusService->createDefaultStatuses($this->store->id, StatusableType::Transaction);

        $pendingStatus = Status::findBySlug($this->store->id, StatusableType::Transaction, 'pending');

        $transaction = Transaction::factory()->create([
            'store_id' => $this->store->id,
            'status' => 'pending',
            'status_id' => $pendingStatus->id,
        ]);

        $this->assertEquals(StatusableType::Transaction, $transaction->getStatusableType());
        $this->assertNotNull($transaction->statusModel);
    }
}
