<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductAttributeValue;
use App\Models\ProductTemplate;
use App\Models\ProductTemplateField;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;
use App\Services\Gia\GiaApiService;
use App\Services\Gia\GiaProductService;
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
        // Test weight range labels - values match select field option values (underscores)
        $this->assertEquals('26_50', GiaApiService::getWeightRangeLabel(0.35));
        $this->assertEquals('100_125', GiaApiService::getWeightRangeLabel(1.25));
        $this->assertEquals('501', GiaApiService::getWeightRangeLabel(15.0));
    }

    public function test_gia_api_service_total_stone_weight_ranges(): void
    {
        // Earrings total stone weight uses different ranges
        $this->assertEquals('26_50', GiaApiService::getTotalStoneWeightRangeLabel(0.35));
        $this->assertEquals('76_100', GiaApiService::getTotalStoneWeightRangeLabel(0.80));
        $this->assertEquals('101_125', GiaApiService::getTotalStoneWeightRangeLabel(1.10));
        $this->assertEquals('176_200', GiaApiService::getTotalStoneWeightRangeLabel(1.90));
        $this->assertEquals('301', GiaApiService::getTotalStoneWeightRangeLabel(5.0));
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

    public function test_earrings_get_diamond_color_and_clarity_range(): void
    {
        $this->actingAs($this->user);

        // Create Earrings template with color/clarity range fields
        $template = ProductTemplate::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Earrings',
        ]);

        $fieldNames = [
            'main_stone_gia_report_number' => 'text',
            'main_stone_cert_type' => 'select',
            'main_stone_type' => 'select',
            'main_stone_shape' => 'select',
            'main_stone_wt' => 'text',
            'diamond_color' => 'select',
            'diamond_clarity' => 'select',
            'diamond_cut' => 'select',
            'main_stone_polish' => 'select',
            'main_stone_symmetry' => 'select',
            'main_stone_min_diameter_length' => 'text',
            'main_stone_max_diameter_width' => 'text',
            'main_stone_depth' => 'text',
            'main_stone_weight' => 'select',
            'diamond_color_range' => 'select',
            'diamond_clarity_range' => 'select',
        ];

        foreach ($fieldNames as $name => $type) {
            ProductTemplateField::factory()->create([
                'product_template_id' => $template->id,
                'name' => $name,
                'type' => $type,
            ]);
        }

        // Create category hierarchy for earrings
        $jewelry = Category::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Jewelry',
            'parent_id' => null,
        ]);

        $earringsCategory = Category::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Diamond Studs GIA Certified',
            'parent_id' => $jewelry->id,
            'template_id' => $template->id,
        ]);

        // Mock the GIA API service
        $mockGiaService = Mockery::mock(GiaApiService::class);
        $mockGiaService->shouldReceive('getReport')
            ->with('9999999999')
            ->andReturn([
                'data' => [
                    'report_number' => '9999999999',
                    'report_date' => '2024-06-01',
                    'report_type' => 'Diamond Grading Report',
                    'results' => [
                        'shape_and_cutting_style' => 'Round Brilliant',
                        'carat_weight' => '0.52',
                        'color_grade' => 'G',
                        'clarity_grade' => 'VS1',
                        'cut_grade' => 'Excellent',
                        'polish' => 'Excellent',
                        'symmetry' => 'Excellent',
                        'fluorescence' => 'None',
                        'measurements' => '5.10 - 5.14 x 3.18 mm',
                        'data' => [
                            'weight' => ['weight' => 0.52],
                            'clarity' => 'VS1',
                            'color_grades' => ['color_grade_code' => 'G'],
                            'shape' => ['shape_group' => 'Round'],
                        ],
                    ],
                ],
                'errors' => null,
            ]);

        $this->app->instance(GiaApiService::class, $mockGiaService);

        $service = app(GiaProductService::class);
        $result = $service->createFromGia(
            '9999999999',
            null,
            $earringsCategory,
            $this->store,
            $this->user->id,
        );

        $this->assertNotNull($result['product']);
        $product = $result['product'];

        // Verify diamond_color_range is set (G belongs to g_h_i_j group)
        $colorRangeField = ProductTemplateField::where('product_template_id', $template->id)
            ->where('name', 'diamond_color_range')
            ->first();
        $colorRangeValue = ProductAttributeValue::where('product_id', $product->id)
            ->where('product_template_field_id', $colorRangeField->id)
            ->first();
        $this->assertNotNull($colorRangeValue);
        $this->assertEquals('g_h_i_j', $colorRangeValue->value);

        // Verify diamond_clarity_range is set (VS1 belongs to vs1_vs2 group)
        $clarityRangeField = ProductTemplateField::where('product_template_id', $template->id)
            ->where('name', 'diamond_clarity_range')
            ->first();
        $clarityRangeValue = ProductAttributeValue::where('product_id', $product->id)
            ->where('product_template_field_id', $clarityRangeField->id)
            ->first();
        $this->assertNotNull($clarityRangeValue);
        $this->assertEquals('vs1_vs2', $clarityRangeValue->value);

        // Verify main_stone_weight (weight range) is set (0.52 belongs to 51_75 range)
        $weightRangeField = ProductTemplateField::where('product_template_id', $template->id)
            ->where('name', 'main_stone_weight')
            ->first();
        $weightRangeValue = ProductAttributeValue::where('product_id', $product->id)
            ->where('product_template_field_id', $weightRangeField->id)
            ->first();
        $this->assertNotNull($weightRangeValue);
        $this->assertEquals('51_75', $weightRangeValue->value);
    }

    public function test_sku_uses_category_format_when_configured(): void
    {
        $this->actingAs($this->user);

        // Create template
        $template = ProductTemplate::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Loose Stones',
        ]);

        // Create basic template fields
        foreach (['gia_report_number', 'cert_type', 'main_stone_type', 'includes'] as $name) {
            ProductTemplateField::factory()->text()->create([
                'product_template_id' => $template->id,
                'name' => $name,
            ]);
        }

        // Create category with SKU format
        $diamonds = Category::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Diamonds',
            'parent_id' => null,
        ]);

        $diamond = Category::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Diamond',
            'parent_id' => $diamonds->id,
            'template_id' => $template->id,
            'sku_prefix' => 'DIA',
            'sku_format' => '{category_code}-{product_id}',
        ]);

        // Mock the GIA API service
        $mockGiaService = Mockery::mock(GiaApiService::class);
        $mockGiaService->shouldReceive('getReport')
            ->with('5555555555')
            ->andReturn([
                'data' => [
                    'report_number' => '5555555555',
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

        $service = app(GiaProductService::class);
        $result = $service->createFromGia(
            '5555555555',
            null,
            $diamond,
            $this->store,
            $this->user->id,
        );

        $this->assertNotNull($result['product']);
        $product = $result['product'];

        // SKU should use category format: DIA-{product_id}
        $variant = $product->variants->first();
        $this->assertNotNull($variant);
        $this->assertEquals('DIA-'.$product->id, $variant->sku);
    }

    public function test_sku_falls_back_to_prefix_report_number_without_format(): void
    {
        $this->actingAs($this->user);

        // Create template
        $template = ProductTemplate::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Loose Stones',
        ]);

        foreach (['gia_report_number', 'cert_type', 'main_stone_type', 'includes'] as $name) {
            ProductTemplateField::factory()->text()->create([
                'product_template_id' => $template->id,
                'name' => $name,
            ]);
        }

        // Category WITHOUT sku_format but with sku_prefix
        $diamond = Category::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Diamond',
            'parent_id' => null,
            'template_id' => $template->id,
            'sku_prefix' => 'LS',
        ]);

        $mockGiaService = Mockery::mock(GiaApiService::class);
        $mockGiaService->shouldReceive('getReport')
            ->with('7777777777')
            ->andReturn([
                'data' => [
                    'report_number' => '7777777777',
                    'report_date' => '2024-01-15',
                    'report_type' => 'Diamond Grading Report',
                    'results' => [
                        'shape_and_cutting_style' => 'Round Brilliant',
                        'carat_weight' => '1.00',
                        'color_grade' => 'E',
                        'clarity_grade' => 'IF',
                        'cut_grade' => 'Excellent',
                        'polish' => 'Excellent',
                        'symmetry' => 'Excellent',
                        'fluorescence' => 'None',
                        'measurements' => '6.40 - 6.42 x 3.95 mm',
                        'data' => [
                            'weight' => ['weight' => 1.00],
                            'clarity' => 'IF',
                            'color_grades' => ['color_grade_code' => 'E'],
                            'shape' => ['shape_group' => 'Round'],
                        ],
                    ],
                ],
                'errors' => null,
            ]);

        $this->app->instance(GiaApiService::class, $mockGiaService);

        $service = app(GiaProductService::class);
        $result = $service->createFromGia(
            '7777777777',
            null,
            $diamond,
            $this->store,
            $this->user->id,
        );

        $this->assertNotNull($result['product']);
        $variant = $result['product']->variants->first();
        $this->assertNotNull($variant);
        $this->assertEquals('LS-7777777777', $variant->sku);
    }

    public function test_diamond_color_range_mapping(): void
    {
        // Test the getDiamondColorRange method via reflection
        $service = app(GiaProductService::class);
        $method = new \ReflectionMethod($service, 'getDiamondColorRange');

        $this->assertEquals('d_e_f', $method->invoke($service, 'D'));
        $this->assertEquals('d_e_f', $method->invoke($service, 'F'));
        $this->assertEquals('g_h_i_j', $method->invoke($service, 'G'));
        $this->assertEquals('g_h_i_j', $method->invoke($service, 'J'));
        $this->assertEquals('k_l_m', $method->invoke($service, 'K'));
        $this->assertEquals('n_to_z', $method->invoke($service, 'N'));
        $this->assertEquals('n_to_z', $method->invoke($service, 'Z'));
        $this->assertEquals('fancy', $method->invoke($service, 'Fancy Yellow'));
        $this->assertNull($method->invoke($service, 'Unknown'));
    }

    public function test_diamond_clarity_range_mapping(): void
    {
        $service = app(GiaProductService::class);
        $method = new \ReflectionMethod($service, 'getDiamondClarityRange');

        $this->assertEquals('fl_if', $method->invoke($service, 'FL'));
        $this->assertEquals('fl_if', $method->invoke($service, 'IF'));
        $this->assertEquals('vvs1_vvs2', $method->invoke($service, 'VVS1'));
        $this->assertEquals('vvs1_vvs2', $method->invoke($service, 'VVS2'));
        $this->assertEquals('vs1_vs2', $method->invoke($service, 'VS1'));
        $this->assertEquals('vs1_vs2', $method->invoke($service, 'VS2'));
        $this->assertEquals('si1_si2', $method->invoke($service, 'SI1'));
        $this->assertEquals('i1_i3', $method->invoke($service, 'I1'));
        $this->assertEquals('i1_i3', $method->invoke($service, 'I3'));
        $this->assertNull($method->invoke($service, 'Unknown'));
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
