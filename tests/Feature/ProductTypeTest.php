<?php

namespace Tests\Feature;

use App\Models\Bucket;
use App\Models\Category;
use App\Models\LabelTemplate;
use App\Models\ProductTemplate;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductTypeTest extends TestCase
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

    public function test_can_view_product_types_index(): void
    {
        $this->actingAs($this->user);

        // Create parent category with children (leaf categories)
        $parent = Category::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Jewelry',
        ]);

        // Leaf categories (product types)
        Category::factory()->create([
            'store_id' => $this->store->id,
            'parent_id' => $parent->id,
            'name' => 'Rings',
        ]);

        Category::factory()->create([
            'store_id' => $this->store->id,
            'parent_id' => $parent->id,
            'name' => 'Necklaces',
        ]);

        $response = $this->withStore()->get('/product-types');

        $response->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('product-types/Index')
                ->has('storeId')
            );
    }

    public function test_can_view_product_type_settings(): void
    {
        $this->actingAs($this->user);

        $category = Category::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Rings',
        ]);

        $response = $this->withStore()->get("/product-types/{$category->id}/settings");

        $response->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('product-types/Settings')
                ->has('category')
                ->has('templates')
                ->has('labelTemplates')
                ->has('buckets')
                ->has('availableVariables')
                ->has('availableAttributes')
            );
    }

    public function test_cannot_view_settings_for_non_leaf_category(): void
    {
        $this->actingAs($this->user);

        $parent = Category::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Jewelry',
        ]);

        Category::factory()->create([
            'store_id' => $this->store->id,
            'parent_id' => $parent->id,
            'name' => 'Rings',
        ]);

        $response = $this->withStore()->get("/product-types/{$parent->id}/settings");

        $response->assertRedirect('/product-types')
            ->assertSessionHas('error');
    }

    public function test_can_update_product_type_settings(): void
    {
        $this->actingAs($this->user);

        $template = ProductTemplate::factory()->create([
            'store_id' => $this->store->id,
            'is_active' => true,
        ]);

        $labelTemplate = LabelTemplate::factory()->create([
            'store_id' => $this->store->id,
            'type' => LabelTemplate::TYPE_PRODUCT,
        ]);

        $bucket = Bucket::factory()->create([
            'store_id' => $this->store->id,
        ]);

        $category = Category::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Rings',
        ]);

        $response = $this->withStore()->put("/product-types/{$category->id}/settings", [
            'template_id' => $template->id,
            'sku_prefix' => 'RNG',
            'sku_suffix' => '-A',
            'default_bucket_id' => $bucket->id,
            'barcode_attributes' => ['category', 'sku', 'price'],
            'label_template_id' => $labelTemplate->id,
        ]);

        $response->assertRedirect("/product-types/{$category->id}/settings")
            ->assertSessionHas('success');

        $this->assertDatabaseHas('categories', [
            'id' => $category->id,
            'template_id' => $template->id,
            'sku_prefix' => 'RNG',
            'sku_suffix' => '-A',
            'default_bucket_id' => $bucket->id,
            'label_template_id' => $labelTemplate->id,
        ]);

        $category->refresh();
        $this->assertEquals(['category', 'sku', 'price'], $category->barcode_attributes);
    }

    public function test_cannot_set_bucket_from_different_store(): void
    {
        $this->actingAs($this->user);

        $otherStore = Store::factory()->create();
        $otherBucket = Bucket::factory()->create([
            'store_id' => $otherStore->id,
        ]);

        $category = Category::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Rings',
        ]);

        $response = $this->withStore()->put("/product-types/{$category->id}/settings", [
            'default_bucket_id' => $otherBucket->id,
        ]);

        $response->assertRedirect()
            ->assertSessionHasErrors('default_bucket_id');
    }

    public function test_effective_sku_suffix_inherits_from_parent(): void
    {
        $parent = Category::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Jewelry',
            'sku_suffix' => '-JEW',
        ]);

        $child = Category::factory()->create([
            'store_id' => $this->store->id,
            'parent_id' => $parent->id,
            'name' => 'Rings',
            'sku_suffix' => null,
        ]);

        $this->assertEquals('-JEW', $child->getEffectiveSkuSuffix());
    }

    public function test_effective_sku_suffix_uses_own_value_when_set(): void
    {
        $parent = Category::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Jewelry',
            'sku_suffix' => '-JEW',
        ]);

        $child = Category::factory()->create([
            'store_id' => $this->store->id,
            'parent_id' => $parent->id,
            'name' => 'Rings',
            'sku_suffix' => '-RNG',
        ]);

        $this->assertEquals('-RNG', $child->getEffectiveSkuSuffix());
    }

    public function test_effective_default_bucket_inherits_from_parent(): void
    {
        $bucket = Bucket::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Junk Jewelry',
        ]);

        $parent = Category::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Jewelry',
            'default_bucket_id' => $bucket->id,
        ]);

        $child = Category::factory()->create([
            'store_id' => $this->store->id,
            'parent_id' => $parent->id,
            'name' => 'Rings',
            'default_bucket_id' => null,
        ]);

        $this->assertNotNull($child->getEffectiveDefaultBucket());
        $this->assertEquals($bucket->id, $child->getEffectiveDefaultBucket()->id);
    }

    public function test_effective_barcode_attributes_uses_defaults(): void
    {
        $category = Category::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Rings',
            'barcode_attributes' => null,
        ]);

        $defaultAttributes = ['category', 'sku', 'price', 'material'];
        $this->assertEquals($defaultAttributes, $category->getEffectiveBarcodeAttributes());
    }

    public function test_effective_barcode_attributes_inherits_from_parent(): void
    {
        $parent = Category::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Jewelry',
            'barcode_attributes' => ['sku', 'price', 'weight'],
        ]);

        $child = Category::factory()->create([
            'store_id' => $this->store->id,
            'parent_id' => $parent->id,
            'name' => 'Rings',
            'barcode_attributes' => null,
        ]);

        $this->assertEquals(['sku', 'price', 'weight'], $child->getEffectiveBarcodeAttributes());
    }

    public function test_effective_barcode_attributes_uses_own_value_when_set(): void
    {
        $parent = Category::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Jewelry',
            'barcode_attributes' => ['sku', 'price', 'weight'],
        ]);

        $child = Category::factory()->create([
            'store_id' => $this->store->id,
            'parent_id' => $parent->id,
            'name' => 'Rings',
            'barcode_attributes' => ['category', 'sku'],
        ]);

        $this->assertEquals(['category', 'sku'], $child->getEffectiveBarcodeAttributes());
    }

    public function test_can_get_available_attributes_endpoint(): void
    {
        $this->actingAs($this->user);

        $template = ProductTemplate::factory()->create([
            'store_id' => $this->store->id,
            'is_active' => true,
        ]);

        $category = Category::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Rings',
            'template_id' => $template->id,
        ]);

        $response = $this->withStore()->getJson("/product-types/{$category->id}/attributes");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'attributes' => [
                    'built_in',
                    'template',
                ],
            ]);
    }

    public function test_product_types_only_shows_leaf_categories(): void
    {
        $this->actingAs($this->user);

        // Create parent category with child
        $parent = Category::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Jewelry',
        ]);

        $leaf = Category::factory()->create([
            'store_id' => $this->store->id,
            'parent_id' => $parent->id,
            'name' => 'Rings',
        ]);

        // Parent should not be returned in widget
        $response = $this->withStore()->get('/widgets/view?type=ProductTypes\\ProductTypesTable');

        $response->assertStatus(200);

        // The parent category should not appear since it has children
        $data = $response->json();
        $items = $data['data']['items'] ?? [];
        $categoryIds = array_column(array_column($items, 'id'), 'data');

        $this->assertContains($leaf->id, $categoryIds);
        $this->assertNotContains($parent->id, $categoryIds);
    }

    public function test_only_store_categories_are_visible(): void
    {
        $this->actingAs($this->user);

        $otherStore = Store::factory()->create();

        Category::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Our Rings',
        ]);

        Category::factory()->create([
            'store_id' => $otherStore->id,
            'name' => 'Other Store Rings',
        ]);

        $response = $this->withStore()->get('/widgets/view?type=ProductTypes\\ProductTypesTable');

        $response->assertStatus(200);

        $data = $response->json();
        $items = $data['data']['items'] ?? [];

        $this->assertCount(1, $items);
    }

    public function test_cannot_update_settings_for_other_store_category(): void
    {
        $this->actingAs($this->user);

        $otherStore = Store::factory()->create();
        $otherCategory = Category::factory()->create([
            'store_id' => $otherStore->id,
            'name' => 'Other Store Category',
        ]);

        $response = $this->withStore()->put("/product-types/{$otherCategory->id}/settings", [
            'sku_prefix' => 'TEST',
        ]);

        $response->assertStatus(404);
    }

    public function test_barcode_attributes_validation(): void
    {
        $this->actingAs($this->user);

        $category = Category::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Rings',
        ]);

        $response = $this->withStore()->put("/product-types/{$category->id}/settings", [
            'barcode_attributes' => ['category', 'sku', 'price'],
        ]);

        $response->assertRedirect()
            ->assertSessionHas('success');

        $category->refresh();
        $this->assertEquals(['category', 'sku', 'price'], $category->barcode_attributes);
    }

    public function test_can_clear_barcode_attributes(): void
    {
        $this->actingAs($this->user);

        $category = Category::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Rings',
            'barcode_attributes' => ['category', 'sku'],
        ]);

        $response = $this->withStore()->put("/product-types/{$category->id}/settings", [
            'barcode_attributes' => [],
        ]);

        $response->assertRedirect()
            ->assertSessionHas('success');

        $category->refresh();
        $this->assertEmpty($category->barcode_attributes);
    }

    public function test_default_bucket_relationship(): void
    {
        $bucket = Bucket::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Junk Jewelry',
        ]);

        $category = Category::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Rings',
            'default_bucket_id' => $bucket->id,
        ]);

        $this->assertNotNull($category->defaultBucket);
        $this->assertEquals($bucket->id, $category->defaultBucket->id);
        $this->assertEquals('Junk Jewelry', $category->defaultBucket->name);
    }
}
