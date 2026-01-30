<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductTemplate;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;
use App\Services\Gia\GiaApiService;
use App\Services\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class GiaProductTest extends TestCase
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
            'step' => 2,
        ]);

        $role = Role::factory()->owner()->create(['store_id' => $this->store->id]);
        StoreUser::factory()->owner()->create([
            'user_id' => $this->user->id,
            'store_id' => $this->store->id,
            'role_id' => $role->id,
        ]);

        app(StoreContext::class)->setCurrentStore($this->store);
    }

    public function test_gia_index_page_loads(): void
    {
        $this->actingAs($this->user);

        $response = $this->get('/gia');

        $response->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('gia/Index')
                ->has('categories')
            );
    }

    public function test_gia_index_shows_eligible_categories(): void
    {
        $this->actingAs($this->user);

        // Create category hierarchy
        $diamonds = Category::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Diamonds',
            'parent_id' => null,
        ]);

        $looseStones = Category::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Loose Stones',
            'parent_id' => $diamonds->id,
        ]);

        $diamond = Category::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Diamond',
            'parent_id' => $looseStones->id,
        ]);

        $jewelry = Category::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Jewelry',
            'parent_id' => null,
        ]);

        $earrings = Category::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Earrings',
            'parent_id' => $jewelry->id,
        ]);

        $studs = Category::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Diamond Studs GIA Certified',
            'parent_id' => $earrings->id,
        ]);

        $response = $this->get('/gia');

        $response->assertStatus(200)
            ->assertInertia(function ($page) use ($diamond, $studs) {
                $page->component('gia/Index');
                $categories = $page->toArray()['props']['categories'];

                // Should include Diamond (under Loose Stones) and Diamond Studs GIA Certified
                $categoryIds = collect($categories)->pluck('id')->toArray();
                $this->assertContains($diamond->id, $categoryIds);
                $this->assertContains($studs->id, $categoryIds);

                // Verify is_stud flag
                $studCategory = collect($categories)->firstWhere('id', $studs->id);
                $this->assertTrue($studCategory['is_stud']);

                $diamondCategory = collect($categories)->firstWhere('id', $diamond->id);
                $this->assertFalse($diamondCategory['is_stud']);
            });
    }

    public function test_gia_api_service_mapping(): void
    {
        $mainStoneMapping = GiaApiService::getMainStoneMapping();
        $secondStoneMapping = GiaApiService::getSecondStoneMapping();

        // Verify main stone mapping has expected fields
        $this->assertArrayHasKey('main_stone_shape', $mainStoneMapping);
        $this->assertArrayHasKey('main_stone_wt', $mainStoneMapping);
        $this->assertArrayHasKey('diamond_color', $mainStoneMapping);
        $this->assertArrayHasKey('diamond_clarity', $mainStoneMapping);
        $this->assertArrayHasKey('diamond_cut', $mainStoneMapping);

        // Verify second stone mapping has expected fields
        $this->assertArrayHasKey('second_stone_shape', $secondStoneMapping);
        $this->assertArrayHasKey('second_stone_weight', $secondStoneMapping);
        $this->assertArrayHasKey('second_stone_color', $secondStoneMapping);
        $this->assertArrayHasKey('second_stone_clarity', $secondStoneMapping);
    }

    public function test_gia_api_service_parses_measurements(): void
    {
        // Test valid measurements
        $result = GiaApiService::parseMeasurements('6.45 - 6.48 x 3.97 mm');
        $this->assertEquals('6.45', $result['min_diameter']);
        $this->assertEquals('6.48', $result['max_diameter']);
        $this->assertEquals('3.97', $result['depth']);

        // Test null measurements
        $result = GiaApiService::parseMeasurements(null);
        $this->assertNull($result['min_diameter']);
        $this->assertNull($result['max_diameter']);
        $this->assertNull($result['depth']);
    }

    public function test_gia_api_service_weight_ranges(): void
    {
        // Test weight range labels
        $this->assertEquals('0.3 - 0.39', GiaApiService::getWeightRangeLabel(0.35));
        $this->assertEquals('1 - 1.49', GiaApiService::getWeightRangeLabel(1.25));
        $this->assertEquals('10.0 +', GiaApiService::getWeightRangeLabel(15.0));
    }

    public function test_gia_data_endpoint_requires_authentication(): void
    {
        $response = $this->postJson('/gia/data', [
            'reference_number' => '1234567890',
            'product_type_id' => 1,
        ]);

        $response->assertStatus(401); // Unauthenticated for JSON requests
    }

    public function test_gia_data_endpoint_validates_input(): void
    {
        $this->actingAs($this->user);

        $response = $this->postJson('/gia/data', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['reference_number', 'product_type_id']);
    }

    public function test_gia_lookup_endpoint_works(): void
    {
        $this->actingAs($this->user);

        // Mock the GIA API service
        $mockGiaService = Mockery::mock(GiaApiService::class);
        $mockGiaService->shouldReceive('getReport')
            ->with('1234567890')
            ->andReturn([
                'data' => [
                    'report_number' => '1234567890',
                    'results' => [
                        'shape_and_cutting_style' => 'Round Brilliant',
                        'carat_weight' => '1.05',
                        'color_grade' => 'D',
                        'clarity_grade' => 'VS1',
                        'cut_grade' => 'Excellent',
                    ],
                ],
                'errors' => null,
            ]);

        $this->app->instance(GiaApiService::class, $mockGiaService);

        $response = $this->postJson('/gia/lookup', [
            'reference_number' => '1234567890',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);
    }

    public function test_gia_product_service_creates_product(): void
    {
        $this->actingAs($this->user);

        // Create template
        $template = ProductTemplate::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Loose Stones',
        ]);

        // Create category hierarchy
        $diamonds = Category::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Diamonds',
            'parent_id' => null,
        ]);

        $looseStones = Category::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Loose Stones',
            'parent_id' => $diamonds->id,
        ]);

        $diamond = Category::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Diamond',
            'parent_id' => $looseStones->id,
            'template_id' => $template->id,
        ]);

        // Mock the GIA API service
        $mockGiaService = Mockery::mock(GiaApiService::class);
        $mockGiaService->shouldReceive('getReport')
            ->with('1234567890')
            ->andReturn([
                'data' => [
                    'report_number' => '1234567890',
                    'report_date' => '2024-01-15',
                    'report_type' => 'Diamond Grading Report',
                    'results' => [
                        'shape_and_cutting_style' => 'Round Brilliant',
                        'carat_weight' => '1.05',
                        'color_grade' => 'D',
                        'clarity_grade' => 'VS1',
                        'cut_grade' => 'Excellent',
                        'polish' => 'Excellent',
                        'symmetry' => 'Excellent',
                        'fluorescence' => 'None',
                        'measurements' => '6.45 - 6.48 x 3.97 mm',
                        'data' => [
                            'weight' => ['weight' => 1.05],
                            'clarity' => 'VS1',
                            'color_grades' => ['color_grade_code' => 'D'],
                            'shape' => ['shape_group' => 'Round'],
                        ],
                    ],
                ],
                'errors' => null,
            ]);

        $this->app->instance(GiaApiService::class, $mockGiaService);

        $response = $this->postJson('/gia/data', [
            'reference_number' => '1234567890',
            'product_type_id' => $diamond->id,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);

        // Verify product was created
        $product = Product::where('store_id', $this->store->id)
            ->where('category_id', $diamond->id)
            ->first();

        $this->assertNotNull($product);
        $this->assertStringContainsString('GIA Certified Diamond', $product->title);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
