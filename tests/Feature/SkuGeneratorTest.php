<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Role;
use App\Models\SkuSequence;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;
use App\Services\Sku\SkuGeneratorService;
use App\Services\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SkuGeneratorTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->store = Store::factory()->create([
            'user_id' => $this->user->id,
            'step' => 2, // Onboarding complete
        ]);

        $role = Role::factory()->owner()->create(['store_id' => $this->store->id]);
        StoreUser::factory()->owner()->create([
            'user_id' => $this->user->id,
            'store_id' => $this->store->id,
            'role_id' => $role->id,
        ]);

        $this->user->update(['current_store_id' => $this->store->id]);
        app(StoreContext::class)->setCurrentStore($this->store);
    }

    public function test_generates_sku_with_category_code(): void
    {
        $category = Category::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Jewelry',
            'sku_format' => '{category_code}-{sequence:5}',
            'sku_prefix' => 'JEW',
        ]);

        $product = Product::factory()->create(['store_id' => $this->store->id]);

        $service = new SkuGeneratorService;
        $sku = $service->generate($category, $product, null, $this->store);

        $this->assertMatchesRegularExpression('/^JEW-\d{5}$/', $sku);
        $this->assertEquals('JEW-00001', $sku);
    }

    public function test_generates_sku_with_category_name(): void
    {
        $category = Category::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Rings',
            'sku_format' => '{category_name:3}-{product_id}',
        ]);

        $product = Product::factory()->create(['store_id' => $this->store->id]);

        $service = new SkuGeneratorService;
        $sku = $service->generate($category, $product, null, $this->store);

        $this->assertStringStartsWith('RIN-', $sku);
    }

    public function test_increments_sequence_for_each_sku(): void
    {
        $category = Category::factory()->create([
            'store_id' => $this->store->id,
            'sku_format' => 'SKU-{sequence:4}',
        ]);

        $product1 = Product::factory()->create(['store_id' => $this->store->id]);
        $product2 = Product::factory()->create(['store_id' => $this->store->id]);

        $service = new SkuGeneratorService;

        $sku1 = $service->generate($category, $product1, null, $this->store);
        $sku2 = $service->generate($category, $product2, null, $this->store);

        $this->assertEquals('SKU-0001', $sku1);
        $this->assertEquals('SKU-0002', $sku2);
    }

    public function test_generates_sku_with_date_variables(): void
    {
        $category = Category::factory()->create([
            'store_id' => $this->store->id,
            'sku_format' => '{year:2}{month}-{sequence:3}',
        ]);

        $product = Product::factory()->create(['store_id' => $this->store->id]);

        $service = new SkuGeneratorService;
        $sku = $service->generate($category, $product, null, $this->store);

        $expectedPrefix = date('y').date('m');
        $this->assertStringStartsWith($expectedPrefix, $sku);
    }

    public function test_generates_sku_with_random_string(): void
    {
        $category = Category::factory()->create([
            'store_id' => $this->store->id,
            'sku_format' => 'SKU-{random:6}',
        ]);

        $product = Product::factory()->create(['store_id' => $this->store->id]);

        $service = new SkuGeneratorService;
        $sku = $service->generate($category, $product, null, $this->store);

        $this->assertMatchesRegularExpression('/^SKU-[A-Z0-9]{6}$/', $sku);
    }

    public function test_inherits_sku_format_from_parent_category(): void
    {
        $parentCategory = Category::factory()->create([
            'store_id' => $this->store->id,
            'sku_format' => 'PARENT-{sequence:3}',
            'sku_prefix' => 'PAR',
        ]);

        $childCategory = Category::factory()->create([
            'store_id' => $this->store->id,
            'parent_id' => $parentCategory->id,
            'sku_format' => null,
            'sku_prefix' => null,
        ]);

        $product = Product::factory()->create(['store_id' => $this->store->id]);

        $service = new SkuGeneratorService;
        $sku = $service->generate($childCategory, $product, null, $this->store);

        $this->assertEquals('PARENT-001', $sku);
    }

    public function test_validates_sku_format(): void
    {
        $service = new SkuGeneratorService;

        // Valid format
        $result = $service->validateFormat('{category_code}-{sequence:5}');
        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);

        // Invalid variable
        $result = $service->validateFormat('{invalid_var}');
        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);

        // Unbalanced braces
        $result = $service->validateFormat('{category_code');
        $this->assertFalse($result['valid']);
    }

    public function test_preview_shows_expected_sku(): void
    {
        $category = Category::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Rings',
            'sku_format' => '{category_code}-{sequence:5}',
            'sku_prefix' => 'RNG',
        ]);

        $service = new SkuGeneratorService;
        $preview = $service->preview($category);

        $this->assertEquals('RNG-00001', $preview);
    }

    public function test_can_reset_sequence(): void
    {
        $category = Category::factory()->create([
            'store_id' => $this->store->id,
            'sku_format' => 'SKU-{sequence:3}',
        ]);

        $sequence = SkuSequence::getOrCreate($category, $this->store);
        $sequence->resetTo(100);

        $product = Product::factory()->create(['store_id' => $this->store->id]);

        $service = new SkuGeneratorService;
        $sku = $service->generate($category, $product, null, $this->store);

        $this->assertEquals('SKU-101', $sku);
    }

    public function test_throws_exception_when_no_format_configured(): void
    {
        $category = Category::factory()->create([
            'store_id' => $this->store->id,
            'sku_format' => null,
        ]);

        $product = Product::factory()->create(['store_id' => $this->store->id]);

        $service = new SkuGeneratorService;

        $this->expectException(\InvalidArgumentException::class);
        $service->generate($category, $product, null, $this->store);
    }

    public function test_category_settings_page_loads(): void
    {
        $this->actingAs($this->user);

        $category = Category::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Test Category',
        ]);

        $response = $this->get("/categories/{$category->id}/settings");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('categories/Settings')
            ->has('category')
            ->has('templates')
            ->has('labelTemplates')
            ->has('availableVariables')
        );
    }

    public function test_category_settings_redirects_for_non_leaf_category(): void
    {
        $this->actingAs($this->user);

        $parentCategory = Category::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Parent',
        ]);

        Category::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Child',
            'parent_id' => $parentCategory->id,
        ]);

        $response = $this->get("/categories/{$parentCategory->id}/settings");

        $response->assertRedirect(route('categories.index'));
        $response->assertSessionHas('error');
    }

    public function test_can_update_category_settings(): void
    {
        $this->actingAs($this->user);

        $category = Category::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Test Category',
        ]);

        $response = $this->put("/categories/{$category->id}/settings", [
            'sku_format' => '{category_code}-{sequence:5}',
            'sku_prefix' => 'TST',
        ]);

        $response->assertRedirect("/categories/{$category->id}/settings");
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('categories', [
            'id' => $category->id,
            'sku_format' => '{category_code}-{sequence:5}',
            'sku_prefix' => 'TST',
        ]);
    }

    public function test_validates_sku_format_on_update(): void
    {
        $this->actingAs($this->user);

        $category = Category::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Test Category',
        ]);

        $response = $this->put("/categories/{$category->id}/settings", [
            'sku_format' => '{invalid_variable}',
        ]);

        $response->assertSessionHasErrors('sku_format');
    }

    public function test_can_preview_sku_format(): void
    {
        $this->actingAs($this->user);

        $category = Category::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Test Category',
            'sku_prefix' => 'TST',
        ]);

        $response = $this->postJson("/categories/{$category->id}/preview-sku", [
            'format' => '{category_code}-{sequence:5}',
            'sku_prefix' => 'TST',
        ]);

        $response->assertOk();
        $response->assertJson([
            'valid' => true,
            'preview' => 'TST-00001',
        ]);
    }

    public function test_can_reset_sku_sequence(): void
    {
        $this->actingAs($this->user);

        $category = Category::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Test Category',
        ]);

        SkuSequence::create([
            'category_id' => $category->id,
            'store_id' => $this->store->id,
            'current_value' => 50,
        ]);

        $response = $this->post("/categories/{$category->id}/reset-sequence", [
            'reset_to' => 10,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('sku_sequences', [
            'category_id' => $category->id,
            'store_id' => $this->store->id,
            'current_value' => 10,
        ]);
    }

    public function test_can_generate_sku_for_product(): void
    {
        $this->actingAs($this->user);

        $category = Category::factory()->create([
            'store_id' => $this->store->id,
            'sku_format' => '{category_code}-{sequence:5}',
            'sku_prefix' => 'PRD',
        ]);

        $response = $this->postJson('/products/generate-sku', [
            'category_id' => $category->id,
        ]);

        $response->assertOk();
        $response->assertJsonStructure(['sku']);
        $this->assertMatchesRegularExpression('/^PRD-\d{5}$/', $response->json('sku'));
    }

    public function test_generate_sku_returns_error_without_format(): void
    {
        $this->actingAs($this->user);

        $category = Category::factory()->create([
            'store_id' => $this->store->id,
            'sku_format' => null,
        ]);

        $response = $this->postJson('/products/generate-sku', [
            'category_id' => $category->id,
        ]);

        $response->assertStatus(400);
        $response->assertJsonStructure(['error']);
    }
}
