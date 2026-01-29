<?php

namespace Tests\Feature\Api;

use App\Models\Category;
use App\Models\ProductTemplate;
use App\Models\ProductTemplateField;
use App\Models\ProductTemplateFieldOption;
use App\Models\Store;
use App\Models\User;
use App\Services\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

class ProductTemplateApiTest extends TestCase
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

    public function test_can_list_templates(): void
    {
        ProductTemplate::factory()->count(3)->create(['store_id' => $this->store->id]);

        $response = $this->getJson('/api/v1/product-templates?all=true');

        $response->assertOk();
        $response->assertJsonCount(3);
    }

    public function test_can_list_only_active_templates(): void
    {
        ProductTemplate::factory()->count(2)->create(['store_id' => $this->store->id]);
        ProductTemplate::factory()->inactive()->create(['store_id' => $this->store->id]);

        $response = $this->getJson('/api/v1/product-templates?all=true&active_only=true');

        $response->assertOk();
        $response->assertJsonCount(2);
    }

    public function test_can_create_template(): void
    {
        $response = $this->postJson('/api/v1/product-templates', [
            'name' => 'Jewelry Template',
            'description' => 'Template for jewelry products',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('name', 'Jewelry Template');
        $this->assertDatabaseHas('product_templates', [
            'name' => 'Jewelry Template',
            'store_id' => $this->store->id,
        ]);
    }

    public function test_can_create_template_with_fields(): void
    {
        $response = $this->postJson('/api/v1/product-templates', [
            'name' => 'Electronics Template',
            'fields' => [
                [
                    'name' => 'brand',
                    'label' => 'Brand',
                    'type' => 'text',
                    'is_required' => true,
                ],
                [
                    'name' => 'condition',
                    'label' => 'Condition',
                    'type' => 'select',
                    'options' => [
                        ['label' => 'New', 'value' => 'new'],
                        ['label' => 'Used', 'value' => 'used'],
                        ['label' => 'Refurbished', 'value' => 'refurbished'],
                    ],
                ],
            ],
        ]);

        $response->assertCreated();
        $response->assertJsonPath('fields.0.name', 'brand');
        $response->assertJsonPath('fields.1.name', 'condition');
        $response->assertJsonCount(3, 'fields.1.options');
    }

    public function test_can_show_template(): void
    {
        $template = ProductTemplate::factory()->create(['store_id' => $this->store->id]);
        ProductTemplateField::factory()->count(2)->create([
            'product_template_id' => $template->id,
        ]);

        $response = $this->getJson("/api/v1/product-templates/{$template->id}");

        $response->assertOk();
        $response->assertJsonPath('id', $template->id);
        $response->assertJsonCount(2, 'fields');
    }

    public function test_can_update_template(): void
    {
        $template = ProductTemplate::factory()->create(['store_id' => $this->store->id]);

        $response = $this->patchJson("/api/v1/product-templates/{$template->id}", [
            'name' => 'Updated Template Name',
        ]);

        $response->assertOk();
        $response->assertJsonPath('name', 'Updated Template Name');
    }

    public function test_can_delete_template(): void
    {
        $template = ProductTemplate::factory()->create(['store_id' => $this->store->id]);

        $response = $this->deleteJson("/api/v1/product-templates/{$template->id}");

        $response->assertNoContent();
        $this->assertSoftDeleted('product_templates', ['id' => $template->id]);
    }

    public function test_can_add_field_to_template(): void
    {
        $template = ProductTemplate::factory()->create(['store_id' => $this->store->id]);

        $response = $this->postJson("/api/v1/product-templates/{$template->id}/fields", [
            'name' => 'color',
            'label' => 'Color',
            'type' => 'select',
            'options' => [
                ['label' => 'Red', 'value' => 'red'],
                ['label' => 'Blue', 'value' => 'blue'],
            ],
        ]);

        $response->assertCreated();
        $response->assertJsonPath('name', 'color');
        $response->assertJsonCount(2, 'options');
    }

    public function test_can_update_field(): void
    {
        $template = ProductTemplate::factory()->create(['store_id' => $this->store->id]);
        $field = ProductTemplateField::factory()->create([
            'product_template_id' => $template->id,
        ]);

        $response = $this->patchJson("/api/v1/product-templates/{$template->id}/fields/{$field->id}", [
            'label' => 'Updated Label',
            'is_required' => true,
        ]);

        $response->assertOk();
        $response->assertJsonPath('label', 'Updated Label');
        $response->assertJsonPath('is_required', true);
    }

    public function test_can_delete_field(): void
    {
        $template = ProductTemplate::factory()->create(['store_id' => $this->store->id]);
        $field = ProductTemplateField::factory()->create([
            'product_template_id' => $template->id,
        ]);

        $response = $this->deleteJson("/api/v1/product-templates/{$template->id}/fields/{$field->id}");

        $response->assertNoContent();
        $this->assertDatabaseMissing('product_template_fields', ['id' => $field->id]);
    }

    public function test_can_update_field_options(): void
    {
        $template = ProductTemplate::factory()->create(['store_id' => $this->store->id]);
        $field = ProductTemplateField::factory()->select()->create([
            'product_template_id' => $template->id,
        ]);
        ProductTemplateFieldOption::factory()->count(2)->create([
            'product_template_field_id' => $field->id,
        ]);

        $response = $this->putJson("/api/v1/product-templates/{$template->id}/fields/{$field->id}/options", [
            'options' => [
                ['label' => 'Option A', 'value' => 'a'],
                ['label' => 'Option B', 'value' => 'b'],
                ['label' => 'Option C', 'value' => 'c'],
            ],
        ]);

        $response->assertOk();
        $response->assertJsonCount(3, 'options');
    }

    public function test_can_reorder_fields(): void
    {
        $template = ProductTemplate::factory()->create(['store_id' => $this->store->id]);
        $field1 = ProductTemplateField::factory()->create([
            'product_template_id' => $template->id,
            'sort_order' => 0,
        ]);
        $field2 = ProductTemplateField::factory()->create([
            'product_template_id' => $template->id,
            'sort_order' => 1,
        ]);
        $field3 = ProductTemplateField::factory()->create([
            'product_template_id' => $template->id,
            'sort_order' => 2,
        ]);

        $response = $this->postJson("/api/v1/product-templates/{$template->id}/reorder-fields", [
            'field_ids' => [$field3->id, $field1->id, $field2->id],
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('product_template_fields', ['id' => $field3->id, 'sort_order' => 0]);
        $this->assertDatabaseHas('product_template_fields', ['id' => $field1->id, 'sort_order' => 1]);
        $this->assertDatabaseHas('product_template_fields', ['id' => $field2->id, 'sort_order' => 2]);
    }

    public function test_can_duplicate_template(): void
    {
        $template = ProductTemplate::factory()->create(['store_id' => $this->store->id]);
        $field = ProductTemplateField::factory()->select()->create([
            'product_template_id' => $template->id,
        ]);
        ProductTemplateFieldOption::factory()->count(3)->create([
            'product_template_field_id' => $field->id,
        ]);

        $response = $this->postJson("/api/v1/product-templates/{$template->id}/duplicate", [
            'name' => 'Duplicated Template',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('name', 'Duplicated Template');
        $response->assertJsonCount(1, 'fields');
        $response->assertJsonCount(3, 'fields.0.options');
    }

    public function test_templates_are_scoped_to_store(): void
    {
        ProductTemplate::factory()->count(2)->create(['store_id' => $this->store->id]);

        $otherStore = Store::factory()->create();
        ProductTemplate::factory()->count(3)->create(['store_id' => $otherStore->id]);

        $response = $this->getJson('/api/v1/product-templates?all=true');

        $response->assertOk();
        $response->assertJsonCount(2);
    }

    public function test_category_can_have_template(): void
    {
        $template = ProductTemplate::factory()->create(['store_id' => $this->store->id]);
        $category = Category::factory()->create([
            'store_id' => $this->store->id,
            'template_id' => $template->id,
        ]);

        $response = $this->getJson("/api/v1/categories/{$category->id}/template");

        $response->assertOk();
        $response->assertJsonPath('id', $template->id);
    }

    public function test_category_inherits_template_from_parent(): void
    {
        $template = ProductTemplate::factory()->create(['store_id' => $this->store->id]);
        $parent = Category::factory()->create([
            'store_id' => $this->store->id,
            'template_id' => $template->id,
        ]);
        $child = Category::factory()->withParent($parent)->create([
            'template_id' => null,
        ]);

        $response = $this->getJson("/api/v1/categories/{$child->id}/template");

        $response->assertOk();
        $response->assertJsonPath('id', $template->id);
    }

    public function test_category_without_template_returns_404(): void
    {
        $category = Category::factory()->create([
            'store_id' => $this->store->id,
            'template_id' => null,
        ]);

        $response = $this->getJson("/api/v1/categories/{$category->id}/template");

        $response->assertNotFound();
    }

    public function test_can_assign_template_to_category(): void
    {
        $template = ProductTemplate::factory()->create(['store_id' => $this->store->id]);
        $category = Category::factory()->create(['store_id' => $this->store->id]);

        $response = $this->patchJson("/api/v1/categories/{$category->id}", [
            'template_id' => $template->id,
        ]);

        $response->assertOk();
        $response->assertJsonPath('template_id', $template->id);
    }
}
