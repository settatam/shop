<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\CategoryPlatformMapping;
use App\Models\ProductTemplate;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreMarketplace;
use App\Models\StoreUser;
use App\Models\User;
use App\Services\Platforms\CategoryMappingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CategoryPlatformMappingTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Store $store;

    protected StoreMarketplace $marketplace;

    protected Category $category;

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

        $this->marketplace = StoreMarketplace::factory()->ebay()->create([
            'store_id' => $this->store->id,
            'is_app' => false,
            'connected_successfully' => true,
        ]);

        $this->category = Category::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Watches',
        ]);
    }

    protected function withStore()
    {
        return $this->withSession(['current_store_id' => $this->store->id]);
    }

    public function test_can_create_category_platform_mapping(): void
    {
        $this->actingAs($this->user);

        $response = $this->withStore()
            ->postJson("/categories/{$this->category->id}/platform-mappings/{$this->marketplace->id}", [
                'primary_category_id' => '31387',
                'primary_category_name' => 'Jewelry & Watches > Watches, Parts & Accessories > Wristwatches',
            ]);

        $response->assertStatus(201);
        $response->assertJson([
            'primary_category_id' => '31387',
            'primary_category_name' => 'Jewelry & Watches > Watches, Parts & Accessories > Wristwatches',
        ]);

        $this->assertDatabaseHas('category_platform_mappings', [
            'category_id' => $this->category->id,
            'store_marketplace_id' => $this->marketplace->id,
            'primary_category_id' => '31387',
            'platform' => 'ebay',
        ]);
    }

    public function test_can_create_mapping_with_secondary_category(): void
    {
        $this->actingAs($this->user);

        $response = $this->withStore()
            ->postJson("/categories/{$this->category->id}/platform-mappings/{$this->marketplace->id}", [
                'primary_category_id' => '31387',
                'primary_category_name' => 'Wristwatches',
                'secondary_category_id' => '260325',
                'secondary_category_name' => 'Luxury Watches',
            ]);

        $response->assertStatus(201);

        $mapping = CategoryPlatformMapping::first();
        $this->assertEquals('31387', $mapping->primary_category_id);
        $this->assertEquals('260325', $mapping->secondary_category_id);
        $this->assertEquals('Luxury Watches', $mapping->secondary_category_name);
    }

    public function test_updating_mapping_replaces_existing(): void
    {
        CategoryPlatformMapping::create([
            'store_id' => $this->store->id,
            'category_id' => $this->category->id,
            'store_marketplace_id' => $this->marketplace->id,
            'platform' => 'ebay',
            'primary_category_id' => '11111',
            'primary_category_name' => 'Old Category',
        ]);

        $this->actingAs($this->user);

        $response = $this->withStore()
            ->postJson("/categories/{$this->category->id}/platform-mappings/{$this->marketplace->id}", [
                'primary_category_id' => '22222',
                'primary_category_name' => 'New Category',
            ]);

        $response->assertStatus(201);

        $this->assertDatabaseCount('category_platform_mappings', 1);
        $this->assertDatabaseHas('category_platform_mappings', [
            'primary_category_id' => '22222',
            'primary_category_name' => 'New Category',
        ]);
    }

    public function test_can_delete_mapping(): void
    {
        $mapping = CategoryPlatformMapping::create([
            'store_id' => $this->store->id,
            'category_id' => $this->category->id,
            'store_marketplace_id' => $this->marketplace->id,
            'platform' => 'ebay',
            'primary_category_id' => '31387',
            'primary_category_name' => 'Wristwatches',
        ]);

        $this->actingAs($this->user);

        $response = $this->withStore()
            ->deleteJson("/categories/{$this->category->id}/platform-mappings/{$mapping->id}");

        $response->assertOk();
        $this->assertDatabaseMissing('category_platform_mappings', ['id' => $mapping->id]);
    }

    public function test_can_list_mappings_for_category(): void
    {
        CategoryPlatformMapping::create([
            'store_id' => $this->store->id,
            'category_id' => $this->category->id,
            'store_marketplace_id' => $this->marketplace->id,
            'platform' => 'ebay',
            'primary_category_id' => '31387',
            'primary_category_name' => 'Wristwatches',
        ]);

        $this->actingAs($this->user);

        $response = $this->withStore()
            ->getJson("/categories/{$this->category->id}/platform-mappings");

        $response->assertOk();
        $response->assertJsonCount(1);
        $response->assertJsonFragment([
            'primary_category_id' => '31387',
            'platform' => 'ebay',
        ]);
    }

    public function test_validation_requires_primary_category(): void
    {
        $this->actingAs($this->user);

        $response = $this->withStore()
            ->postJson("/categories/{$this->category->id}/platform-mappings/{$this->marketplace->id}", [
                'primary_category_name' => 'Some Category',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('primary_category_id');
    }

    public function test_cannot_create_mapping_for_other_store_category(): void
    {
        $otherStore = Store::factory()->create();
        $otherCategory = Category::factory()->create([
            'store_id' => $otherStore->id,
            'name' => 'Other Store Category',
        ]);

        $this->actingAs($this->user);

        $response = $this->withStore()
            ->postJson("/categories/{$otherCategory->id}/platform-mappings/{$this->marketplace->id}", [
                'primary_category_id' => '31387',
                'primary_category_name' => 'Wristwatches',
            ]);

        $response->assertStatus(403);
    }

    public function test_category_mapping_service_resolves_direct_mapping(): void
    {
        $mapping = CategoryPlatformMapping::create([
            'store_id' => $this->store->id,
            'category_id' => $this->category->id,
            'store_marketplace_id' => $this->marketplace->id,
            'platform' => 'ebay',
            'primary_category_id' => '31387',
            'primary_category_name' => 'Wristwatches',
            'secondary_category_id' => '260325',
            'secondary_category_name' => 'Luxury Watches',
        ]);

        $product = \App\Models\Product::factory()->create([
            'store_id' => $this->store->id,
            'category_id' => $this->category->id,
        ]);

        $service = app(CategoryMappingService::class);
        $result = $service->resolveCategory($product, $this->marketplace);

        $this->assertEquals('31387', $result['primary_category_id']);
        $this->assertEquals('260325', $result['secondary_category_id']);
        $this->assertNotNull($result['mapping']);
        $this->assertEquals($mapping->id, $result['mapping']->id);
    }

    public function test_category_mapping_service_walks_up_hierarchy(): void
    {
        $parentCategory = Category::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Jewelry',
        ]);

        $childCategory = Category::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Rings',
            'parent_id' => $parentCategory->id,
        ]);

        // Only the parent has a mapping
        CategoryPlatformMapping::create([
            'store_id' => $this->store->id,
            'category_id' => $parentCategory->id,
            'store_marketplace_id' => $this->marketplace->id,
            'platform' => 'ebay',
            'primary_category_id' => '281',
            'primary_category_name' => 'Jewelry',
        ]);

        $product = \App\Models\Product::factory()->create([
            'store_id' => $this->store->id,
            'category_id' => $childCategory->id,
        ]);

        $service = app(CategoryMappingService::class);
        $result = $service->resolveCategory($product, $this->marketplace);

        $this->assertEquals('281', $result['primary_category_id']);
    }

    public function test_category_mapping_service_returns_null_when_no_mapping(): void
    {
        $product = \App\Models\Product::factory()->create([
            'store_id' => $this->store->id,
            'category_id' => $this->category->id,
        ]);

        $service = app(CategoryMappingService::class);
        $result = $service->resolveCategory($product, $this->marketplace);

        $this->assertNull($result['primary_category_id']);
        $this->assertNull($result['mapping']);
    }

    public function test_category_platform_mapping_model_needs_sync(): void
    {
        $mapping = new CategoryPlatformMapping([
            'item_specifics_synced_at' => null,
        ]);

        $this->assertTrue($mapping->needsItemSpecificsSync());

        $mapping->item_specifics_synced_at = now()->subDays(8);
        $this->assertTrue($mapping->needsItemSpecificsSync());

        $mapping->item_specifics_synced_at = now()->subDays(1);
        $this->assertFalse($mapping->needsItemSpecificsSync());
    }

    public function test_category_model_has_platform_mappings_relationship(): void
    {
        CategoryPlatformMapping::create([
            'store_id' => $this->store->id,
            'category_id' => $this->category->id,
            'store_marketplace_id' => $this->marketplace->id,
            'platform' => 'ebay',
            'primary_category_id' => '31387',
            'primary_category_name' => 'Wristwatches',
        ]);

        $this->category->refresh();
        $this->assertCount(1, $this->category->platformMappings);

        $mapping = $this->category->getPlatformMapping($this->marketplace);
        $this->assertNotNull($mapping);
        $this->assertEquals('31387', $mapping->primary_category_id);
    }

    public function test_can_update_field_mappings(): void
    {
        $mapping = CategoryPlatformMapping::create([
            'store_id' => $this->store->id,
            'category_id' => $this->category->id,
            'store_marketplace_id' => $this->marketplace->id,
            'platform' => 'ebay',
            'primary_category_id' => '31387',
            'primary_category_name' => 'Wristwatches',
        ]);

        $this->actingAs($this->user);

        $response = $this->withStore()
            ->putJson("/categories/{$this->category->id}/platform-mappings/{$mapping->id}", [
                'field_mappings' => ['Brand' => 'brand', 'Model' => 'model_name'],
                'default_values' => ['Dial Color' => 'Black'],
            ]);

        $response->assertOk();
        $response->assertJson([
            'id' => $mapping->id,
        ]);

        $mapping->refresh();
        $this->assertEquals(['Brand' => 'brand', 'Model' => 'model_name'], $mapping->field_mappings);
        $this->assertEquals(['Dial Color' => 'Black'], $mapping->default_values);
    }

    public function test_settings_page_returns_marketplace_and_mapping_data(): void
    {
        $template = ProductTemplate::factory()->create([
            'store_id' => $this->store->id,
        ]);

        $this->category->update(['template_id' => $template->id]);

        CategoryPlatformMapping::create([
            'store_id' => $this->store->id,
            'category_id' => $this->category->id,
            'store_marketplace_id' => $this->marketplace->id,
            'platform' => 'ebay',
            'primary_category_id' => '31387',
            'primary_category_name' => 'Wristwatches',
        ]);

        $this->actingAs($this->user);

        $response = $this->withStore()
            ->get("/categories/{$this->category->id}/settings");

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('categories/Settings')
            ->has('connectedMarketplaces')
            ->has('platformMappings')
            ->where('platformMappings.0.primary_category_id', '31387')
        );
    }
}
