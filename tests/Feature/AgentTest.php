<?php

namespace Tests\Feature;

use App\Enums\AgentActionStatus;
use App\Enums\AgentPermissionLevel;
use App\Enums\AgentRunStatus;
use App\Enums\AgentTriggerType;
use App\Models\Agent;
use App\Models\AgentAction;
use App\Models\AgentRun;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreAgent;
use App\Models\StoreUser;
use App\Models\User;
use App\Services\Agents\ActionExecutor;
use App\Services\Agents\AgentRegistry;
use App\Services\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Store $store;

    protected Role $ownerRole;

    protected StoreUser $storeUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\AgentSeeder::class);

        $this->user = User::factory()->create();
        $this->store = Store::factory()->create([
            'user_id' => $this->user->id,
            'step' => 2,
        ]);

        $this->ownerRole = Role::factory()->owner()->create([
            'store_id' => $this->store->id,
        ]);

        $this->storeUser = StoreUser::factory()->owner()->create([
            'user_id' => $this->user->id,
            'store_id' => $this->store->id,
            'role_id' => $this->ownerRole->id,
        ]);

        $this->user->current_store_id = $this->store->id;
        $this->user->save();

        app(StoreContext::class)->setCurrentStore($this->store);
    }

    public function test_guests_are_redirected_from_agents_page(): void
    {
        $response = $this->get(route('agents.index'));
        $response->assertRedirect(route('login'));
    }

    public function test_agent_seeder_creates_all_agents(): void
    {
        $agents = Agent::all();

        $this->assertCount(7, $agents);
        $this->assertTrue($agents->contains('slug', 'dead-stock'));
        $this->assertTrue($agents->contains('slug', 'auto-pricing'));
        $this->assertTrue($agents->contains('slug', 'new-item-researcher'));
    }

    public function test_agent_registry_returns_all_registered_agents(): void
    {
        $registry = app(AgentRegistry::class);
        $agents = $registry->getAllAgents();

        $this->assertCount(7, $agents);
    }

    public function test_agent_registry_can_get_agent_by_slug(): void
    {
        $registry = app(AgentRegistry::class);

        $agent = $registry->getAgent('dead-stock');
        $this->assertNotNull($agent);
        $this->assertEquals('Dead Stock Agent', $agent->getName());

        $agent = $registry->getAgent('auto-pricing');
        $this->assertNotNull($agent);
        $this->assertEquals('Auto-Pricing Agent', $agent->getName());

        $agent = $registry->getAgent('new-item-researcher');
        $this->assertNotNull($agent);
        $this->assertEquals('New Item Researcher', $agent->getName());
    }

    public function test_store_agent_can_be_created(): void
    {
        $agent = Agent::where('slug', 'dead-stock')->first();

        $storeAgent = StoreAgent::create([
            'store_id' => $this->store->id,
            'agent_id' => $agent->id,
            'is_enabled' => true,
            'config' => ['custom' => 'value'],
            'permission_level' => AgentPermissionLevel::Auto,
        ]);

        $this->assertDatabaseHas('store_agents', [
            'store_id' => $this->store->id,
            'agent_id' => $agent->id,
            'is_enabled' => true,
        ]);
    }

    public function test_agent_run_can_be_created(): void
    {
        $agent = Agent::where('slug', 'dead-stock')->first();

        $storeAgent = StoreAgent::create([
            'store_id' => $this->store->id,
            'agent_id' => $agent->id,
            'is_enabled' => true,
            'permission_level' => AgentPermissionLevel::Auto,
        ]);

        $run = AgentRun::create([
            'store_id' => $this->store->id,
            'agent_id' => $agent->id,
            'store_agent_id' => $storeAgent->id,
            'status' => AgentRunStatus::Running,
            'trigger_type' => AgentTriggerType::Manual,
            'started_at' => now(),
        ]);

        $this->assertDatabaseHas('agent_runs', [
            'store_id' => $this->store->id,
            'agent_id' => $agent->id,
            'status' => 'running',
        ]);
    }

    public function test_agent_action_can_be_created(): void
    {
        $agent = Agent::where('slug', 'dead-stock')->first();

        $storeAgent = StoreAgent::create([
            'store_id' => $this->store->id,
            'agent_id' => $agent->id,
            'is_enabled' => true,
            'permission_level' => AgentPermissionLevel::Approve,
        ]);

        $run = AgentRun::create([
            'store_id' => $this->store->id,
            'agent_id' => $agent->id,
            'store_agent_id' => $storeAgent->id,
            'status' => AgentRunStatus::Running,
            'trigger_type' => AgentTriggerType::Scheduled,
            'started_at' => now(),
        ]);

        $action = AgentAction::create([
            'agent_run_id' => $run->id,
            'store_id' => $this->store->id,
            'action_type' => 'markdown_schedule',
            'status' => AgentActionStatus::Pending,
            'requires_approval' => true,
            'payload' => [
                'product_id' => 1,
                'before' => ['price' => 100],
                'after' => ['price' => 80],
                'discount_percent' => 20,
                'reason' => 'Dead stock markdown',
            ],
        ]);

        $this->assertDatabaseHas('agent_actions', [
            'agent_run_id' => $run->id,
            'store_id' => $this->store->id,
            'action_type' => 'markdown_schedule',
            'status' => 'pending',
            'requires_approval' => true,
        ]);
    }

    public function test_store_agents_are_scoped_to_store(): void
    {
        $store2 = Store::factory()->create();
        $agent = Agent::where('slug', 'dead-stock')->first();

        StoreAgent::withoutGlobalScopes()->create([
            'store_id' => $this->store->id,
            'agent_id' => $agent->id,
            'is_enabled' => true,
            'permission_level' => AgentPermissionLevel::Auto,
        ]);

        StoreAgent::withoutGlobalScopes()->create([
            'store_id' => $store2->id,
            'agent_id' => $agent->id,
            'is_enabled' => false,
            'permission_level' => AgentPermissionLevel::Approve,
        ]);

        $this->assertCount(1, StoreAgent::all());

        app(StoreContext::class)->setCurrentStore($store2);
        $this->assertCount(1, StoreAgent::all());
    }

    public function test_agent_actions_page_requires_authentication(): void
    {
        $response = $this->get(route('agents.actions'));
        $response->assertRedirect(route('login'));
    }

    public function test_agent_show_returns_404_for_invalid_slug(): void
    {
        $response = $this->actingAs($this->user)->get(route('agents.show', 'invalid-agent'));
        $response->assertStatus(404);
    }

    public function test_can_update_store_agent_config(): void
    {
        $agent = Agent::where('slug', 'dead-stock')->first();

        $storeAgent = StoreAgent::create([
            'store_id' => $this->store->id,
            'agent_id' => $agent->id,
            'is_enabled' => true,
            'permission_level' => AgentPermissionLevel::Auto,
        ]);

        $response = $this->actingAs($this->user)->put(route('agents.update', 'dead-stock'), [
            'is_enabled' => false,
            'permission_level' => 'approve',
            'config' => ['slow_mover_threshold_days' => 120],
        ]);

        $response->assertRedirect();

        $storeAgent->refresh();
        $this->assertFalse($storeAgent->is_enabled);
        $this->assertEquals(AgentPermissionLevel::Approve, $storeAgent->permission_level);
    }

    public function test_action_executor_can_approve_without_execute(): void
    {
        $agent = Agent::where('slug', 'dead-stock')->first();

        $storeAgent = StoreAgent::create([
            'store_id' => $this->store->id,
            'agent_id' => $agent->id,
            'is_enabled' => true,
            'permission_level' => AgentPermissionLevel::Approve,
        ]);

        $run = AgentRun::create([
            'store_id' => $this->store->id,
            'agent_id' => $agent->id,
            'store_agent_id' => $storeAgent->id,
            'status' => AgentRunStatus::Completed,
            'trigger_type' => AgentTriggerType::Scheduled,
            'started_at' => now(),
            'completed_at' => now(),
        ]);

        $action = AgentAction::create([
            'agent_run_id' => $run->id,
            'store_id' => $this->store->id,
            'action_type' => 'markdown_schedule',
            'status' => AgentActionStatus::Pending,
            'requires_approval' => true,
            'payload' => [
                'product_id' => 1,
                'before' => ['price' => 100],
                'after' => ['price' => 80],
                'discount_percent' => 20,
            ],
        ]);

        $executor = app(ActionExecutor::class);
        $result = $executor->approve($action, $this->user, false);

        $this->assertTrue($result->success);
        $action->refresh();
        $this->assertEquals(AgentActionStatus::Approved, $action->status);
        $this->assertEquals($this->user->id, $action->approved_by);
    }

    public function test_action_executor_can_reject(): void
    {
        $agent = Agent::where('slug', 'dead-stock')->first();

        $storeAgent = StoreAgent::create([
            'store_id' => $this->store->id,
            'agent_id' => $agent->id,
            'is_enabled' => true,
            'permission_level' => AgentPermissionLevel::Approve,
        ]);

        $run = AgentRun::create([
            'store_id' => $this->store->id,
            'agent_id' => $agent->id,
            'store_agent_id' => $storeAgent->id,
            'status' => AgentRunStatus::Completed,
            'trigger_type' => AgentTriggerType::Scheduled,
            'started_at' => now(),
            'completed_at' => now(),
        ]);

        $action = AgentAction::create([
            'agent_run_id' => $run->id,
            'store_id' => $this->store->id,
            'action_type' => 'markdown_schedule',
            'status' => AgentActionStatus::Pending,
            'requires_approval' => true,
            'payload' => [
                'product_id' => 1,
                'before' => ['price' => 100],
                'after' => ['price' => 80],
                'discount_percent' => 20,
            ],
        ]);

        $executor = app(ActionExecutor::class);
        $result = $executor->reject($action, $this->user);

        $this->assertTrue($result->success);
        $action->refresh();
        $this->assertEquals(AgentActionStatus::Rejected, $action->status);
    }

    public function test_action_executor_validates_payload(): void
    {
        $registry = app(AgentRegistry::class);
        $markdownAction = $registry->getAction('markdown_schedule');

        $this->assertNotNull($markdownAction);

        // Valid payload
        $validPayload = [
            'before' => ['price' => 100],
            'after' => ['price' => 80],
            'discount_percent' => 20,
        ];
        $this->assertTrue($markdownAction->validatePayload($validPayload));

        // Invalid payload - missing discount_percent
        $invalidPayload = [
            'before' => ['price' => 100],
            'after' => ['price' => 80],
        ];
        $this->assertFalse($markdownAction->validatePayload($invalidPayload));
    }

    public function test_agent_relationships_work(): void
    {
        $agent = Agent::where('slug', 'dead-stock')->first();

        $storeAgent = StoreAgent::create([
            'store_id' => $this->store->id,
            'agent_id' => $agent->id,
            'is_enabled' => true,
            'permission_level' => AgentPermissionLevel::Auto,
        ]);

        $run = AgentRun::create([
            'store_id' => $this->store->id,
            'agent_id' => $agent->id,
            'store_agent_id' => $storeAgent->id,
            'status' => AgentRunStatus::Running,
            'trigger_type' => AgentTriggerType::Manual,
            'started_at' => now(),
        ]);

        $this->assertEquals($agent->id, $storeAgent->agent->id);
        $this->assertEquals($this->store->id, $storeAgent->store->id);
        $this->assertEquals($run->id, $storeAgent->runs->first()->id);
        $this->assertEquals($storeAgent->id, $run->storeAgent->id);
    }

    public function test_agent_permission_levels_work(): void
    {
        $this->assertEquals('auto', AgentPermissionLevel::Auto->value);
        $this->assertEquals('approve', AgentPermissionLevel::Approve->value);
        $this->assertEquals('block', AgentPermissionLevel::Block->value);
    }

    public function test_agent_run_status_enum_works(): void
    {
        $this->assertEquals('pending', AgentRunStatus::Pending->value);
        $this->assertEquals('running', AgentRunStatus::Running->value);
        $this->assertEquals('completed', AgentRunStatus::Completed->value);
        $this->assertEquals('failed', AgentRunStatus::Failed->value);
        $this->assertEquals('cancelled', AgentRunStatus::Cancelled->value);
    }

    public function test_agent_action_status_enum_works(): void
    {
        $this->assertEquals('pending', AgentActionStatus::Pending->value);
        $this->assertEquals('approved', AgentActionStatus::Approved->value);
        $this->assertEquals('executed', AgentActionStatus::Executed->value);
        $this->assertEquals('rejected', AgentActionStatus::Rejected->value);
        $this->assertEquals('failed', AgentActionStatus::Failed->value);
    }

    public function test_agent_has_subscribed_events(): void
    {
        $registry = app(AgentRegistry::class);

        $newItemAgent = $registry->getAgent('new-item-researcher');
        $events = $newItemAgent->getSubscribedEvents();

        $this->assertNotEmpty($events);
        $this->assertContains('transaction_item.ready_for_inventory', $events);
    }

    public function test_registry_can_get_agents_for_event(): void
    {
        $registry = app(AgentRegistry::class);

        $agentSlugs = $registry->getAgentsForEvent('transaction_item.ready_for_inventory');

        $this->assertContains('new-item-researcher', $agentSlugs);
    }
}
