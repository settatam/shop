<?php

namespace Tests\Feature\Api;

use App\Models\Category;
use App\Models\Store;
use App\Models\User;
use App\Services\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

class CategoryApiTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->store = Store::factory()->create(['user_id' => $this->user->id]);
        app(StoreContext::class)->setCurrentStore($this->store);
        Passport::actingAs($this->user);
    }

    public function test_can_list_categories(): void
    {
        Category::factory()->count(3)->create(['store_id' => $this->store->id]);

        $response = $this->getJson('/api/v1/categories?all=true');

        $response->assertOk();
        $response->assertJsonCount(3);
    }

    public function test_can_list_only_root_categories(): void
    {
        $root = Category::factory()->create(['store_id' => $this->store->id]);
        Category::factory()->withParent($root)->create();
        Category::factory()->create(['store_id' => $this->store->id]);

        $response = $this->getJson('/api/v1/categories?roots_only=true&all=true');

        $response->assertOk();
        $response->assertJsonCount(2);
    }

    public function test_can_get_category_tree(): void
    {
        $root = Category::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Electronics',
        ]);

        $child = Category::factory()->withParent($root)->create([
            'name' => 'Computers',
        ]);

        Category::factory()->withParent($child)->create([
            'name' => 'Laptops',
        ]);

        $response = $this->getJson('/api/v1/categories/tree');

        $response->assertOk();
        $response->assertJsonCount(1);
        $response->assertJsonPath('0.name', 'Electronics');
        $response->assertJsonPath('0.children.0.name', 'Computers');
        $response->assertJsonPath('0.children.0.children.0.name', 'Laptops');
    }

    public function test_can_get_flat_category_list(): void
    {
        $root = Category::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Electronics',
            'level' => 0,
        ]);

        $child = Category::factory()->withParent($root)->create([
            'name' => 'Computers',
        ]);

        Category::factory()->withParent($child)->create([
            'name' => 'Laptops',
        ]);

        $response = $this->getJson('/api/v1/categories/flat');

        $response->assertOk();
        $response->assertJsonCount(3);
        $response->assertJsonFragment(['full_path' => 'Electronics']);
        $response->assertJsonFragment(['full_path' => 'Electronics > Computers']);
        $response->assertJsonFragment(['full_path' => 'Electronics > Computers > Laptops']);
    }

    public function test_can_get_category_ancestors(): void
    {
        $root = Category::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Electronics',
        ]);

        $child = Category::factory()->withParent($root)->create([
            'name' => 'Computers',
        ]);

        $grandchild = Category::factory()->withParent($child)->create([
            'name' => 'Laptops',
        ]);

        $response = $this->getJson("/api/v1/categories/{$grandchild->id}/ancestors");

        $response->assertOk();
        $response->assertJsonCount(2);
        $response->assertJsonPath('0.name', 'Electronics');
        $response->assertJsonPath('1.name', 'Computers');
    }

    public function test_can_get_category_descendants(): void
    {
        $root = Category::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Electronics',
        ]);

        Category::factory()->withParent($root)->count(2)->create();

        $response = $this->getJson("/api/v1/categories/{$root->id}/descendants");

        $response->assertOk();
        $response->assertJsonCount(2);
    }

    public function test_can_create_category(): void
    {
        $response = $this->postJson('/api/v1/categories', [
            'name' => 'New Category',
            'slug' => 'new-category',
            'description' => 'Test description',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('name', 'New Category');
        $this->assertDatabaseHas('categories', [
            'name' => 'New Category',
            'store_id' => $this->store->id,
        ]);
    }

    public function test_can_create_child_category(): void
    {
        $parent = Category::factory()->create(['store_id' => $this->store->id]);

        $response = $this->postJson('/api/v1/categories', [
            'name' => 'Child Category',
            'slug' => 'child-category',
            'parent_id' => $parent->id,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('parent_id', $parent->id);
    }

    public function test_can_update_category(): void
    {
        $category = Category::factory()->create(['store_id' => $this->store->id]);

        $response = $this->patchJson("/api/v1/categories/{$category->id}", [
            'name' => 'Updated Name',
        ]);

        $response->assertOk();
        $response->assertJsonPath('name', 'Updated Name');
    }

    public function test_can_delete_category(): void
    {
        $category = Category::factory()->create(['store_id' => $this->store->id]);

        $response = $this->deleteJson("/api/v1/categories/{$category->id}");

        $response->assertNoContent();
        $this->assertSoftDeleted('categories', ['id' => $category->id]);
    }

    public function test_categories_are_scoped_to_store(): void
    {
        Category::factory()->count(2)->create(['store_id' => $this->store->id]);

        $otherStore = Store::factory()->create();
        Category::factory()->count(3)->create(['store_id' => $otherStore->id]);

        $response = $this->getJson('/api/v1/categories?all=true');

        $response->assertOk();
        $response->assertJsonCount(2);
    }

    public function test_can_search_categories(): void
    {
        Category::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Electronics',
        ]);
        Category::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Books',
        ]);

        $response = $this->getJson('/api/v1/categories?search=elect&all=true');

        $response->assertOk();
        $response->assertJsonCount(1);
        $response->assertJsonPath('0.name', 'Electronics');
    }
}
