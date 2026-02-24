<?php

namespace Tests\Feature\Reports;

use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;
use App\Services\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

class ReportTemplateApiTest extends TestCase
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

        $this->user->update(['current_store_id' => $this->store->id]);
        app(StoreContext::class)->setCurrentStore($this->store);

        Passport::actingAs($this->user);
    }

    public function test_can_list_report_classes(): void
    {
        $response = $this->getJson('/api/v1/ai/templates/report-classes');

        $response->assertOk()
            ->assertJson(['success' => true])
            ->assertJsonStructure([
                'success',
                'data' => [
                    'reports' => [
                        '*' => ['value', 'label', 'slug'],
                    ],
                ],
            ]);

        // Should have at least daily_sales and daily_buy
        $reports = $response->json('data.reports');
        $values = collect($reports)->pluck('value')->toArray();

        $this->assertContains('daily_sales', $values);
        $this->assertContains('daily_buy', $values);
    }

    public function test_can_get_class_structure(): void
    {
        $response = $this->getJson('/api/v1/ai/templates/class-structure?report_type=daily_sales');

        $response->assertOk()
            ->assertJson(['success' => true])
            ->assertJsonStructure([
                'success',
                'data' => [
                    'type',
                    'name',
                    'slug',
                    'structure' => [
                        'tables',
                    ],
                    'sample_data',
                ],
            ]);

        $this->assertEquals('daily_sales', $response->json('data.type'));
        $this->assertEquals('Daily Sales Report', $response->json('data.name'));
    }

    public function test_class_structure_returns_404_for_unknown_type(): void
    {
        $response = $this->getJson('/api/v1/ai/templates/class-structure?report_type=unknown_report');

        $response->assertNotFound()
            ->assertJson([
                'success' => false,
                'error' => 'Report type not found: unknown_report',
            ]);
    }

    public function test_can_get_available_fields(): void
    {
        $response = $this->getJson('/api/v1/ai/templates/fields');

        $response->assertOk()
            ->assertJson(['success' => true])
            ->assertJsonStructure([
                'success',
                'data' => [
                    'fields',
                    'categories',
                ],
            ]);

        // Should have customer fields
        $fields = $response->json('data.fields');
        $this->assertArrayHasKey('customer_name', $fields);
    }
}
