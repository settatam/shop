<?php

namespace Tests\Feature;

use App\Models\ProductTemplate;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class ProductTemplateWebTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->store = Store::factory()->create(['user_id' => $this->user->id, 'step' => 2]);

        // Create default roles for the store
        Role::createDefaultRoles($this->store->id);

        // Get the owner role
        $ownerRole = Role::where('store_id', $this->store->id)
            ->where('slug', 'owner')
            ->first();

        // Create store user with owner role
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

        // Set current store on user
        $this->user->update(['current_store_id' => $this->store->id]);
    }

    protected function withStore()
    {
        return $this->withSession(['current_store_id' => $this->store->id]);
    }

    public function test_can_view_templates_index(): void
    {
        ProductTemplate::factory()->count(3)->create(['store_id' => $this->store->id]);

        $response = $this->actingAs($this->user)->withStore()->get('/templates');

        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('templates/Index')
            ->has('templates', 3)
            ->has('filters')
        );
    }

    public function test_can_search_templates_by_name(): void
    {
        ProductTemplate::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Jewelry Template',
        ]);
        ProductTemplate::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Electronics Template',
        ]);
        ProductTemplate::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Watches Template',
        ]);

        $response = $this->actingAs($this->user)->withStore()->get('/templates?search=jewelry');

        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('templates/Index')
            ->has('templates', 1)
            ->where('templates.0.name', 'Jewelry Template')
            ->where('filters.search', 'jewelry')
        );
    }

    public function test_can_search_templates_by_description(): void
    {
        ProductTemplate::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Template A',
            'description' => 'For gold items',
        ]);
        ProductTemplate::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Template B',
            'description' => 'For silver items',
        ]);

        $response = $this->actingAs($this->user)->withStore()->get('/templates?search=gold');

        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('templates/Index')
            ->has('templates', 1)
            ->where('templates.0.description', 'For gold items')
        );
    }

    public function test_search_returns_empty_when_no_matches(): void
    {
        ProductTemplate::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Jewelry Template',
        ]);

        $response = $this->actingAs($this->user)->withStore()->get('/templates?search=nonexistent');

        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('templates/Index')
            ->has('templates', 0)
            ->where('filters.search', 'nonexistent')
        );
    }

    public function test_only_shows_templates_from_current_store(): void
    {
        $otherStore = Store::factory()->create();

        ProductTemplate::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'My Template',
        ]);
        ProductTemplate::factory()->create([
            'store_id' => $otherStore->id,
            'name' => 'Other Template',
        ]);

        $response = $this->actingAs($this->user)->withStore()->get('/templates');

        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('templates/Index')
            ->has('templates', 1)
            ->where('templates.0.name', 'My Template')
        );
    }
}
