<?php

namespace Tests\Feature;

use App\Models\LabelTemplate;
use App\Models\LabelTemplateElement;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;
use App\Services\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LabelTemplateTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->store = Store::factory()->create(['user_id' => $this->user->id, 'step' => 2]);

        $role = Role::factory()->owner()->create(['store_id' => $this->store->id]);
        StoreUser::factory()->owner()->create([
            'user_id' => $this->user->id,
            'store_id' => $this->store->id,
            'role_id' => $role->id,
        ]);

        app(StoreContext::class)->setCurrentStore($this->store);
    }

    public function test_can_list_label_templates(): void
    {
        $this->actingAs($this->user);

        LabelTemplate::factory()->count(3)->create(['store_id' => $this->store->id]);

        $response = $this->get('/labels');

        $response->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('labels/Index')
                ->has('templates', 3)
            );
    }

    public function test_can_view_create_form(): void
    {
        $this->actingAs($this->user);

        $response = $this->get('/labels/create');

        $response->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('labels/Designer')
                ->has('types')
                ->has('elementTypes')
                ->has('productFields')
                ->has('transactionFields')
            );
    }

    public function test_can_create_label_template(): void
    {
        $this->actingAs($this->user);

        $response = $this->post('/labels', [
            'name' => 'Product Label',
            'type' => 'product',
            'canvas_width' => 406,
            'canvas_height' => 203,
            'is_default' => true,
            'elements' => [
                [
                    'element_type' => 'text_field',
                    'x' => 10,
                    'y' => 10,
                    'width' => 150,
                    'height' => 25,
                    'content' => 'product.title',
                    'styles' => ['fontSize' => 20],
                ],
                [
                    'element_type' => 'barcode',
                    'x' => 10,
                    'y' => 50,
                    'width' => 200,
                    'height' => 60,
                    'content' => 'variant.barcode',
                    'styles' => ['barcodeHeight' => 50],
                ],
            ],
        ]);

        $response->assertRedirect('/labels');

        $this->assertDatabaseHas('label_templates', [
            'store_id' => $this->store->id,
            'name' => 'Product Label',
            'type' => 'product',
            'canvas_width' => 406,
            'canvas_height' => 203,
        ]);

        $template = LabelTemplate::where('store_id', $this->store->id)->first();
        $this->assertCount(2, $template->elements);
    }

    public function test_first_template_is_automatically_default(): void
    {
        $this->actingAs($this->user);

        $response = $this->post('/labels', [
            'name' => 'First Template',
            'type' => 'product',
            'canvas_width' => 406,
            'canvas_height' => 203,
            'is_default' => false,
            'elements' => [],
        ]);

        $response->assertRedirect('/labels');

        $this->assertDatabaseHas('label_templates', [
            'store_id' => $this->store->id,
            'name' => 'First Template',
            'is_default' => true,
        ]);
    }

    public function test_can_edit_label_template(): void
    {
        $this->actingAs($this->user);

        $template = LabelTemplate::factory()
            ->has(LabelTemplateElement::factory()->count(2), 'elements')
            ->create(['store_id' => $this->store->id]);

        $response = $this->get("/labels/{$template->id}/edit");

        $response->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('labels/Designer')
                ->has('template')
                ->where('template.id', $template->id)
                ->where('template.name', $template->name)
            );
    }

    public function test_can_update_label_template(): void
    {
        $this->actingAs($this->user);

        $template = LabelTemplate::factory()->create(['store_id' => $this->store->id]);

        $response = $this->put("/labels/{$template->id}", [
            'name' => 'Updated Template',
            'type' => 'transaction',
            'canvas_width' => 500,
            'canvas_height' => 300,
            'is_default' => true,
            'elements' => [
                [
                    'element_type' => 'text_field',
                    'x' => 20,
                    'y' => 20,
                    'width' => 180,
                    'height' => 30,
                    'content' => 'transaction.transaction_number',
                    'styles' => ['fontSize' => 24],
                ],
            ],
        ]);

        $response->assertRedirect('/labels');

        $this->assertDatabaseHas('label_templates', [
            'id' => $template->id,
            'name' => 'Updated Template',
            'type' => 'transaction',
            'canvas_width' => 500,
            'canvas_height' => 300,
        ]);

        $template->refresh();
        $this->assertCount(1, $template->elements);
    }

    public function test_can_delete_label_template(): void
    {
        $this->actingAs($this->user);

        $template = LabelTemplate::factory()->create(['store_id' => $this->store->id]);

        $response = $this->delete("/labels/{$template->id}");

        $response->assertRedirect('/labels');
        $this->assertSoftDeleted('label_templates', ['id' => $template->id]);
    }

    public function test_can_duplicate_label_template(): void
    {
        $this->actingAs($this->user);

        $template = LabelTemplate::factory()
            ->has(LabelTemplateElement::factory()->count(2), 'elements')
            ->create(['store_id' => $this->store->id, 'name' => 'Original Template']);

        $response = $this->post("/labels/{$template->id}/duplicate");

        $response->assertRedirect();

        $this->assertDatabaseHas('label_templates', [
            'store_id' => $this->store->id,
            'name' => 'Original Template (Copy)',
        ]);

        $duplicate = LabelTemplate::where('name', 'Original Template (Copy)')->first();
        $this->assertCount(2, $duplicate->elements);
    }

    public function test_cannot_access_other_store_templates(): void
    {
        $this->actingAs($this->user);

        $otherStore = Store::factory()->create();
        $otherTemplate = LabelTemplate::factory()->create(['store_id' => $otherStore->id]);

        $response = $this->get("/labels/{$otherTemplate->id}/edit");

        $response->assertStatus(404);
    }

    public function test_name_must_be_unique_per_store(): void
    {
        $this->actingAs($this->user);

        LabelTemplate::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Product Label',
        ]);

        $response = $this->post('/labels', [
            'name' => 'Product Label',
            'type' => 'product',
            'canvas_width' => 406,
            'canvas_height' => 203,
            'elements' => [],
        ]);

        $response->assertSessionHasErrors('name');
    }

    public function test_deleting_default_sets_another_as_default(): void
    {
        $this->actingAs($this->user);

        $template1 = LabelTemplate::factory()->default()->create([
            'store_id' => $this->store->id,
            'type' => 'product',
        ]);
        $template2 = LabelTemplate::factory()->create([
            'store_id' => $this->store->id,
            'type' => 'product',
        ]);

        $this->delete("/labels/{$template1->id}");

        $template2->refresh();
        $this->assertTrue($template2->is_default);
    }

    public function test_making_default_unsets_previous_default(): void
    {
        $this->actingAs($this->user);

        $template1 = LabelTemplate::factory()->default()->create([
            'store_id' => $this->store->id,
            'type' => 'product',
        ]);

        $this->post('/labels', [
            'name' => 'New Default',
            'type' => 'product',
            'canvas_width' => 406,
            'canvas_height' => 203,
            'is_default' => true,
            'elements' => [],
        ]);

        $template1->refresh();
        $this->assertFalse($template1->is_default);
    }

    public function test_validates_element_types(): void
    {
        $this->actingAs($this->user);

        $response = $this->post('/labels', [
            'name' => 'Test Template',
            'type' => 'product',
            'canvas_width' => 406,
            'canvas_height' => 203,
            'elements' => [
                [
                    'element_type' => 'invalid_type',
                    'x' => 10,
                    'y' => 10,
                    'width' => 100,
                    'height' => 25,
                    'content' => 'test',
                ],
            ],
        ]);

        $response->assertSessionHasErrors('elements.0.element_type');
    }

    public function test_validates_template_type(): void
    {
        $this->actingAs($this->user);

        $response = $this->post('/labels', [
            'name' => 'Test Template',
            'type' => 'invalid_type',
            'canvas_width' => 406,
            'canvas_height' => 203,
            'elements' => [],
        ]);

        $response->assertSessionHasErrors('type');
    }
}
