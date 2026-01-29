<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Certification;
use App\Models\Product;
use App\Models\ProductTemplate;
use App\Models\ProductTemplateField;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\GiaCardScannerService;
use App\Services\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class GiaCardScannerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->user = User::factory()->create();
        $this->store = Store::factory()->create([
            'user_id' => $this->user->id,
            'step' => 2, // Mark onboarding as complete
        ]);

        $role = Role::factory()->owner()->create(['store_id' => $this->store->id]);
        StoreUser::factory()->owner()->create([
            'user_id' => $this->user->id,
            'store_id' => $this->store->id,
            'role_id' => $role->id,
        ]);

        app(StoreContext::class)->setCurrentStore($this->store);
    }

    public function test_scan_endpoint_requires_authentication(): void
    {
        $response = $this->postJson('/gia-scanner/scan', [
            'image' => UploadedFile::fake()->image('gia-card.jpg'),
        ]);

        $response->assertStatus(401);
    }

    public function test_scan_endpoint_validates_image(): void
    {
        $this->actingAs($this->user);

        // Test missing image
        $response = $this->postJson('/gia-scanner/scan', []);
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['image']);

        // Test invalid file type
        $response = $this->postJson('/gia-scanner/scan', [
            'image' => UploadedFile::fake()->create('document.txt', 100),
        ]);
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['image']);

        // Test file too large (> 10MB)
        $response = $this->postJson('/gia-scanner/scan', [
            'image' => UploadedFile::fake()->image('gia-card.jpg')->size(11000),
        ]);
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['image']);
    }

    public function test_scan_endpoint_returns_extracted_data(): void
    {
        $this->actingAs($this->user);

        $mockExtractedData = [
            'certificate_number' => '2141234567',
            'issue_date' => 'January 1, 2024',
            'shape' => 'Round Brilliant',
            'carat_weight' => '1.05',
            'color_grade' => 'G',
            'clarity_grade' => 'VS2',
            'cut_grade' => 'Excellent',
            'polish' => 'Excellent',
            'symmetry' => 'Excellent',
            'fluorescence' => 'None',
            'measurements' => ['length' => 6.45, 'width' => 6.48, 'depth' => 3.97],
            'inscription' => 'GIA 2141234567',
            'comments' => null,
            'raw_data' => [],
        ];

        $mock = Mockery::mock(GiaCardScannerService::class);
        $mock->shouldReceive('scanImage')
            ->once()
            ->andReturn($mockExtractedData);
        $mock->shouldReceive('storeScannedImage')
            ->once()
            ->andReturn('gia-scans/'.$this->store->id.'/test.jpg');

        $this->app->instance(GiaCardScannerService::class, $mock);

        $response = $this->postJson('/gia-scanner/scan', [
            'image' => UploadedFile::fake()->image('gia-card.jpg'),
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'extracted_data' => [
                    'certificate_number' => '2141234567',
                    'carat_weight' => '1.05',
                    'color_grade' => 'G',
                    'clarity_grade' => 'VS2',
                ],
                'duplicate_warning' => false,
            ]);
    }

    public function test_scan_detects_duplicate_certificate(): void
    {
        $this->actingAs($this->user);

        // Create existing certification
        Certification::factory()->create([
            'store_id' => $this->store->id,
            'certificate_number' => '2141234567',
            'lab' => 'GIA',
        ]);

        $mockExtractedData = [
            'certificate_number' => '2141234567',
            'carat_weight' => '1.05',
            'raw_data' => [],
        ];

        $mock = Mockery::mock(GiaCardScannerService::class);
        $mock->shouldReceive('scanImage')
            ->once()
            ->andReturn($mockExtractedData);
        $mock->shouldReceive('storeScannedImage')
            ->once()
            ->andReturn('gia-scans/'.$this->store->id.'/test.jpg');

        $this->app->instance(GiaCardScannerService::class, $mock);

        $response = $this->postJson('/gia-scanner/scan', [
            'image' => UploadedFile::fake()->image('gia-card.jpg'),
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'duplicate_warning' => true,
            ]);
    }

    public function test_create_product_endpoint_requires_authentication(): void
    {
        $response = $this->postJson('/gia-scanner/create-product', []);

        $response->assertStatus(401);
    }

    public function test_can_create_product_from_scan_data(): void
    {
        $this->actingAs($this->user);

        $category = Category::factory()->create(['store_id' => $this->store->id]);
        $brand = Brand::factory()->create(['store_id' => $this->store->id]);
        $warehouse = Warehouse::factory()->create([
            'store_id' => $this->store->id,
            'is_active' => true,
        ]);

        $response = $this->postJson('/gia-scanner/create-product', [
            'certification_data' => [
                'certificate_number' => '2141234567',
                'issue_date' => '2024-01-01',
                'shape' => 'Round Brilliant',
                'carat_weight' => 1.05,
                'color_grade' => 'G',
                'clarity_grade' => 'VS2',
                'cut_grade' => 'Excellent',
                'polish' => 'Excellent',
                'symmetry' => 'Excellent',
                'fluorescence' => 'None',
            ],
            'image_path' => 'gia-scans/'.$this->store->id.'/test.jpg',
            'product' => [
                'title' => '1.05ct G/VS2 Round Diamond',
                'description' => 'Beautiful round brilliant diamond',
                'category_id' => $category->id,
                'brand_id' => $brand->id,
            ],
            'variant' => [
                'sku' => 'GIA-2141234567',
                'price' => 5000.00,
                'cost' => 3000.00,
                'quantity' => 1,
                'warehouse_id' => $warehouse->id,
            ],
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'product' => ['id', 'title'],
                'certification' => ['id', 'certificate_number'],
                'redirect_url',
            ]);

        // Verify product created
        $this->assertDatabaseHas('products', [
            'store_id' => $this->store->id,
            'title' => '1.05ct G/VS2 Round Diamond',
            'category_id' => $category->id,
            'brand_id' => $brand->id,
        ]);

        // Verify certification created
        $this->assertDatabaseHas('certifications', [
            'store_id' => $this->store->id,
            'certificate_number' => '2141234567',
            'lab' => 'GIA',
            'carat_weight' => 1.05,
            'color_grade' => 'G',
            'clarity_grade' => 'VS2',
        ]);

        // Verify gemstone created
        $this->assertDatabaseHas('gemstones', [
            'store_id' => $this->store->id,
            'type' => 'diamond',
            'carat_weight' => 1.05,
        ]);

        // Verify inventory created
        $this->assertDatabaseHas('inventory', [
            'store_id' => $this->store->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 1,
        ]);
    }

    public function test_create_product_validates_required_fields(): void
    {
        $this->actingAs($this->user);

        $response = $this->postJson('/gia-scanner/create-product', [
            'certification_data' => [
                // Missing certificate_number
            ],
            'product' => [
                // Missing title
            ],
            'variant' => [
                // Missing required fields
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors([
                'certification_data.certificate_number',
                'product.title',
                'variant.sku',
                'variant.price',
                'variant.quantity',
            ]);
    }

    public function test_create_product_saves_template_attributes(): void
    {
        $this->actingAs($this->user);

        // Create template and fields
        $template = ProductTemplate::factory()->create(['store_id' => $this->store->id]);
        $field1 = ProductTemplateField::factory()->create([
            'product_template_id' => $template->id,
            'name' => 'total_carat_weight',
            'canonical_name' => 'total_carat_weight',
            'type' => 'number',
        ]);
        $field2 = ProductTemplateField::factory()->create([
            'product_template_id' => $template->id,
            'name' => 'color_grade',
            'canonical_name' => 'color_grade',
            'type' => 'text',
        ]);

        $category = Category::factory()->create([
            'store_id' => $this->store->id,
            'template_id' => $template->id,
        ]);

        $response = $this->postJson('/gia-scanner/create-product', [
            'certification_data' => [
                'certificate_number' => '2141234567',
                'carat_weight' => 1.05,
                'color_grade' => 'G',
            ],
            'product' => [
                'title' => 'Test Diamond',
                'category_id' => $category->id,
            ],
            'variant' => [
                'sku' => 'GIA-2141234567',
                'price' => 5000.00,
                'quantity' => 1,
            ],
            'attributes' => [
                $field1->id => '1.05',
                $field2->id => 'G',
            ],
        ]);

        $response->assertStatus(200);

        // Verify attribute values saved
        $this->assertDatabaseHas('product_attribute_values', [
            'product_template_field_id' => $field1->id,
            'value' => '1.05',
        ]);
        $this->assertDatabaseHas('product_attribute_values', [
            'product_template_field_id' => $field2->id,
            'value' => 'G',
        ]);
    }

    public function test_add_to_existing_product_requires_authentication(): void
    {
        $product = Product::factory()->create(['store_id' => $this->store->id]);

        $response = $this->postJson("/gia-scanner/add-to-product/{$product->id}", []);

        $response->assertStatus(401);
    }

    public function test_can_add_certification_to_existing_product(): void
    {
        $this->actingAs($this->user);

        $product = Product::factory()->create(['store_id' => $this->store->id]);

        $response = $this->postJson("/gia-scanner/add-to-product/{$product->id}", [
            'certification_data' => [
                'certificate_number' => '2141234567',
                'shape' => 'Round Brilliant',
                'carat_weight' => 1.05,
                'color_grade' => 'G',
                'clarity_grade' => 'VS2',
            ],
            'image_path' => 'gia-scans/'.$this->store->id.'/test.jpg',
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        // Verify certification created
        $this->assertDatabaseHas('certifications', [
            'store_id' => $this->store->id,
            'certificate_number' => '2141234567',
        ]);

        // Verify gemstone linked to product
        $this->assertDatabaseHas('gemstones', [
            'store_id' => $this->store->id,
            'product_id' => $product->id,
        ]);
    }

    public function test_cannot_add_to_product_from_different_store(): void
    {
        $this->actingAs($this->user);

        $otherStore = Store::factory()->create();
        $otherProduct = Product::factory()->create(['store_id' => $otherStore->id]);

        $response = $this->postJson("/gia-scanner/add-to-product/{$otherProduct->id}", [
            'certification_data' => [
                'certificate_number' => '2141234567',
            ],
        ]);

        $response->assertStatus(404);
    }

    public function test_search_products_requires_authentication(): void
    {
        $response = $this->getJson('/gia-scanner/search-products?q=test');

        $response->assertStatus(401);
    }

    public function test_can_search_products_by_title(): void
    {
        $this->actingAs($this->user);

        Product::factory()->create([
            'store_id' => $this->store->id,
            'title' => 'Beautiful Diamond Ring',
        ]);
        Product::factory()->create([
            'store_id' => $this->store->id,
            'title' => 'Gold Necklace',
        ]);

        $response = $this->getJson('/gia-scanner/search-products?q=diamond');

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertCount(1, $data);
        $this->assertEquals('Beautiful Diamond Ring', $data[0]['title']);
    }

    public function test_search_products_only_returns_current_store(): void
    {
        $this->actingAs($this->user);

        Product::factory()->create([
            'store_id' => $this->store->id,
            'title' => 'My Diamond',
        ]);

        $otherStore = Store::factory()->create();
        Product::factory()->create([
            'store_id' => $otherStore->id,
            'title' => 'Other Diamond',
        ]);

        $response = $this->getJson('/gia-scanner/search-products?q=diamond');

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertCount(1, $data);
        $this->assertEquals('My Diamond', $data[0]['title']);
    }

    public function test_gia_to_canonical_mapping_is_correct(): void
    {
        $mapping = GiaCardScannerService::getGiaToCanonicalMapping();

        $this->assertArrayHasKey('certificate_number', $mapping);
        $this->assertArrayHasKey('carat_weight', $mapping);
        $this->assertArrayHasKey('color_grade', $mapping);
        $this->assertArrayHasKey('clarity_grade', $mapping);
        $this->assertArrayHasKey('cut_grade', $mapping);

        $this->assertEquals('certificate_number', $mapping['certificate_number']);
        $this->assertEquals('total_carat_weight', $mapping['carat_weight']);
        $this->assertEquals('color_grade', $mapping['color_grade']);
    }

    public function test_map_to_template_fields_maps_correctly(): void
    {
        $extractedData = [
            'certificate_number' => '2141234567',
            'carat_weight' => '1.05',
            'color_grade' => 'G',
            'clarity_grade' => 'VS2',
            'measurements' => ['length' => 6.45, 'width' => 6.48, 'depth' => 3.97],
        ];

        $templateFields = [
            ['id' => 1, 'canonical_name' => 'certificate_number'],
            ['id' => 2, 'canonical_name' => 'total_carat_weight'],
            ['id' => 3, 'canonical_name' => 'color_grade'],
            ['id' => 4, 'canonical_name' => 'measurements'],
            ['id' => 5, 'canonical_name' => 'gemstone_type'],
        ];

        $result = GiaCardScannerService::mapToTemplateFields($extractedData, $templateFields);

        $this->assertEquals('2141234567', $result[1]);
        $this->assertEquals('1.05', $result[2]);
        $this->assertEquals('G', $result[3]);
        $this->assertStringContainsString('6.45', $result[4]);
        $this->assertEquals('diamond', $result[5]);
    }

    public function test_category_template_fields_endpoint(): void
    {
        $this->actingAs($this->user);

        $template = ProductTemplate::factory()->create(['store_id' => $this->store->id]);
        ProductTemplateField::factory()->create([
            'product_template_id' => $template->id,
            'name' => 'color_grade',
            'canonical_name' => 'color_grade',
            'label' => 'Color Grade',
        ]);

        $category = Category::factory()->create([
            'store_id' => $this->store->id,
            'template_id' => $template->id,
        ]);

        $response = $this->getJson("/categories/{$category->id}/template-fields");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'template' => ['id', 'name'],
                'fields' => [
                    '*' => ['id', 'name', 'canonical_name', 'label', 'type'],
                ],
            ]);

        $data = $response->json();
        $this->assertEquals('color_grade', $data['fields'][0]['canonical_name']);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
