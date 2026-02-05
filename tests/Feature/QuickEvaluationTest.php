<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\QuickEvaluation;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;
use App\Services\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class QuickEvaluationTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected Store $store;

    protected Role $ownerRole;

    protected StoreUser $ownerStoreUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->store = Store::factory()->create([
            'user_id' => $this->owner->id,
            'step' => 2, // Mark onboarding as complete
        ]);

        $this->ownerRole = Role::factory()->owner()->create(['store_id' => $this->store->id]);

        $this->ownerStoreUser = StoreUser::factory()->owner()->create([
            'user_id' => $this->owner->id,
            'store_id' => $this->store->id,
            'role_id' => $this->ownerRole->id,
        ]);

        $this->owner->update(['current_store_id' => $this->store->id]);
        app(StoreContext::class)->setCurrentStore($this->store);
    }

    public function test_quick_evaluation_page_can_be_rendered(): void
    {
        $this->actingAs($this->owner);

        $response = $this->get('/transactions/quick-evaluation');

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('transactions/QuickEvaluation')
            ->has('categories')
            ->has('preciousMetals')
            ->has('conditions')
            ->has('paymentMethods')
            ->has('storeUsers')
        );
    }

    public function test_quick_evaluation_can_be_stored(): void
    {
        $this->actingAs($this->owner);

        $category = Category::factory()->create(['store_id' => $this->store->id]);

        $response = $this->postJson('/transactions/quick-evaluation', [
            'title' => '14K Gold Ring',
            'description' => 'A beautiful gold ring',
            'category_id' => $category->id,
            'precious_metal' => '14k_gold',
            'condition' => 'used',
            'estimated_weight' => 2.5,
            'estimated_value' => 150.00,
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'evaluation' => [
                'id',
                'title',
                'description',
                'category_id',
                'precious_metal',
                'condition',
                'estimated_weight',
                'estimated_value',
                'status',
            ],
        ]);

        $this->assertDatabaseHas('quick_evaluations', [
            'store_id' => $this->store->id,
            'user_id' => $this->owner->id,
            'title' => '14K Gold Ring',
            'status' => QuickEvaluation::STATUS_DRAFT,
        ]);
    }

    public function test_quick_evaluation_requires_title(): void
    {
        $this->actingAs($this->owner);

        $response = $this->postJson('/transactions/quick-evaluation', [
            'description' => 'A ring without a title',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['title']);
    }

    public function test_similar_items_search_works(): void
    {
        $this->actingAs($this->owner);

        $response = $this->postJson('/transactions/quick-evaluation/similar-items', [
            'title' => '14K Gold Ring',
            'category_id' => null,
            'precious_metal' => '14k_gold',
            'condition' => 'used',
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'items',
        ]);
    }

    public function test_quick_evaluation_can_be_discarded(): void
    {
        $this->actingAs($this->owner);

        $evaluation = QuickEvaluation::factory()->create([
            'store_id' => $this->store->id,
            'user_id' => $this->owner->id,
            'title' => 'Test Item',
            'status' => QuickEvaluation::STATUS_DRAFT,
        ]);

        $response = $this->delete("/transactions/quick-evaluation/{$evaluation->id}");

        $response->assertRedirect('/transactions');

        $this->assertDatabaseHas('quick_evaluations', [
            'id' => $evaluation->id,
            'status' => QuickEvaluation::STATUS_DISCARDED,
        ]);
    }

    public function test_quick_evaluation_requires_authentication(): void
    {
        $response = $this->get('/transactions/quick-evaluation');

        $response->assertRedirect('/login');
    }

    public function test_user_cannot_access_another_stores_evaluation(): void
    {
        $otherUser = User::factory()->create();
        $otherStore = Store::factory()->create(['user_id' => $otherUser->id]);

        $evaluation = QuickEvaluation::factory()->create([
            'store_id' => $otherStore->id,
            'user_id' => $otherUser->id,
            'title' => 'Other Store Item',
            'status' => QuickEvaluation::STATUS_DRAFT,
        ]);

        $this->actingAs($this->owner);

        $response = $this->delete("/transactions/quick-evaluation/{$evaluation->id}");

        $response->assertStatus(404);
    }
}
