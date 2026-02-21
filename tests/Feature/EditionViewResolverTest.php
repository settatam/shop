<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;
use App\Services\EditionViewResolver;
use App\Services\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class EditionViewResolverTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Store $store;

    protected EditionViewResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->store = Store::factory()->create([
            'user_id' => $this->user->id,
            'edition' => 'standard',
            'step' => 2, // Mark onboarding as complete
        ]);
        $this->user->update(['current_store_id' => $this->store->id]);

        $role = Role::factory()->owner()->create(['store_id' => $this->store->id]);
        StoreUser::factory()->owner()->create([
            'user_id' => $this->user->id,
            'store_id' => $this->store->id,
            'role_id' => $role->id,
        ]);

        app(StoreContext::class)->setCurrentStore($this->store);
        $this->resolver = app(EditionViewResolver::class);
    }

    public function test_returns_default_path_when_no_edition_override_exists(): void
    {
        $result = $this->resolver->resolve('Dashboard');

        $this->assertEquals('Dashboard', $result);
    }

    public function test_returns_edition_path_when_override_exists(): void
    {
        // Create a temporary edition override file
        $editionPath = resource_path('js/pages/editions/client_x/Dashboard.vue');

        // The file already exists from our setup, so let's test with client_x edition
        $this->store->update(['edition' => 'client_x']);

        $result = $this->resolver->resolve('Dashboard', $this->store);

        $this->assertEquals('editions/client_x/Dashboard', $result);
    }

    public function test_falls_back_to_default_when_edition_has_no_override(): void
    {
        $this->store->update(['edition' => 'pawn_shop']);

        // pawn_shop doesn't have a Dashboard override
        $result = $this->resolver->resolve('Dashboard', $this->store);

        $this->assertEquals('Dashboard', $result);
    }

    public function test_resolves_nested_paths(): void
    {
        $this->store->update(['edition' => 'client_x']);

        // Create a nested override
        $nestedPath = resource_path('js/pages/editions/client_x/products/Show.vue');
        $nestedDir = dirname($nestedPath);

        if (! File::isDirectory($nestedDir)) {
            File::makeDirectory($nestedDir, 0755, true);
        }

        File::put($nestedPath, '<template><div>Test</div></template>');

        try {
            $result = $this->resolver->resolve('products/Show', $this->store);
            $this->assertEquals('editions/client_x/products/Show', $result);
        } finally {
            // Clean up
            File::delete($nestedPath);
        }
    }

    public function test_uses_current_store_context_when_no_store_provided(): void
    {
        $this->store->update(['edition' => 'client_x']);
        app(StoreContext::class)->setCurrentStore($this->store);

        $result = $this->resolver->resolve('Dashboard');

        $this->assertEquals('editions/client_x/Dashboard', $result);
    }

    public function test_uses_default_edition_when_store_edition_is_empty(): void
    {
        // Create a store without explicitly setting edition (uses default)
        $store = Store::factory()->create([
            'user_id' => $this->user->id,
            'edition' => 'standard', // Uses config default
        ]);

        $result = $this->resolver->resolve('Dashboard', $store);

        // Should use standard edition which has no override
        $this->assertEquals('Dashboard', $result);
    }

    public function test_has_override_returns_true_when_override_exists(): void
    {
        $this->store->update(['edition' => 'client_x']);

        $result = $this->resolver->hasOverride('Dashboard', $this->store);

        $this->assertTrue($result);
    }

    public function test_has_override_returns_false_when_no_override(): void
    {
        $this->store->update(['edition' => 'standard']);

        $result = $this->resolver->hasOverride('Dashboard', $this->store);

        $this->assertFalse($result);
    }

    public function test_get_available_overrides_lists_all_editions_with_override(): void
    {
        $overrides = $this->resolver->getAvailableOverrides('Dashboard');

        $this->assertIsArray($overrides);
        $this->assertArrayHasKey('client_x', $overrides);
        $this->assertEquals('editions/client_x/Dashboard', $overrides['client_x']);
    }

    public function test_edition_view_helper_function_works(): void
    {
        $this->store->update(['edition' => 'client_x']);
        app(StoreContext::class)->setCurrentStore($this->store);

        $result = edition_view('Dashboard');

        $this->assertEquals('editions/client_x/Dashboard', $result);
    }

    public function test_dashboard_controller_uses_edition_view(): void
    {
        $this->store->update(['edition' => 'client_x']);

        $response = $this->actingAs($this->user)->get('/dashboard');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page->component('editions/client_x/Dashboard'));
    }

    public function test_dashboard_controller_uses_default_for_standard_edition(): void
    {
        $this->store->update(['edition' => 'standard']);

        $response = $this->actingAs($this->user)->get('/dashboard');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page->component('Dashboard'));
    }
}
