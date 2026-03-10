<?php

namespace Tests\Feature;

use App\Models\LabelTemplate;
use App\Models\LabelTemplateElement;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;
use App\Services\LabelDataService;
use App\Services\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductPrintBarcodeZplTest extends TestCase
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

    public function test_print_barcode_page_includes_label_templates(): void
    {
        $product = Product::factory()->create(['store_id' => $this->store->id]);
        ProductVariant::factory()->create(['product_id' => $product->id]);

        $template = LabelTemplate::factory()->create([
            'store_id' => $this->store->id,
            'type' => LabelTemplate::TYPE_PRODUCT,
            'name' => 'Jewelry Side-by-Side',
        ]);
        LabelTemplateElement::factory()->barcode()->create([
            'label_template_id' => $template->id,
        ]);

        $response = $this->actingAs($this->user)
            ->get("/products/{$product->id}/print-barcode");

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('products/PrintBarcode')
            ->has('labelTemplates', 1)
            ->where('labelTemplates.0.name', 'Jewelry Side-by-Side')
            ->has('labelTemplates.0.elements', 1)
        );
    }

    public function test_generate_zpl_from_template(): void
    {
        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'price_code' => 'ABC',
        ]);
        $variant = ProductVariant::factory()->create([
            'product_id' => $product->id,
            'sku' => 'TEST-SKU-001',
            'barcode' => '123456789',
        ]);

        $template = LabelTemplate::factory()->create([
            'store_id' => $this->store->id,
            'type' => LabelTemplate::TYPE_PRODUCT,
        ]);
        LabelTemplateElement::factory()->barcode()->create([
            'label_template_id' => $template->id,
            'x' => 10,
            'y' => 10,
        ]);
        LabelTemplateElement::factory()->create([
            'label_template_id' => $template->id,
            'content' => 'variant.sku',
            'x' => 200,
            'y' => 10,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/products/{$product->id}/print-barcode/zpl", [
                'template_id' => $template->id,
                'variant_ids' => [$variant->id],
                'quantity' => 1,
            ]);

        $response->assertOk();
        $response->assertJsonStructure(['zpl', 'count']);
        $response->assertJson(['count' => 1]);
        $this->assertStringContainsString('^XA', $response->json('zpl'));
        $this->assertStringContainsString('TEST-SKU-001', $response->json('zpl'));
    }

    public function test_generate_zpl_validates_template_belongs_to_store(): void
    {
        $otherStore = Store::factory()->create(['user_id' => $this->user->id]);
        $product = Product::factory()->create(['store_id' => $this->store->id]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);

        $template = LabelTemplate::factory()->create([
            'store_id' => $otherStore->id,
            'type' => LabelTemplate::TYPE_PRODUCT,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/products/{$product->id}/print-barcode/zpl", [
                'template_id' => $template->id,
                'variant_ids' => [$variant->id],
                'quantity' => 1,
            ]);

        $response->assertStatus(404);
    }

    public function test_generate_zpl_validates_variant_ids_belong_to_product(): void
    {
        $product = Product::factory()->create(['store_id' => $this->store->id]);
        $otherProduct = Product::factory()->create(['store_id' => $this->store->id]);
        $variant = ProductVariant::factory()->create(['product_id' => $otherProduct->id]);

        $template = LabelTemplate::factory()->create([
            'store_id' => $this->store->id,
            'type' => LabelTemplate::TYPE_PRODUCT,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/products/{$product->id}/print-barcode/zpl", [
                'template_id' => $template->id,
                'variant_ids' => [$variant->id],
                'quantity' => 1,
            ]);

        // Should return OK but with count=0 since no variants match the product
        $response->assertOk();
        $response->assertJson(['count' => 0]);
    }

    public function test_generate_zpl_with_multiple_copies(): void
    {
        $product = Product::factory()->create(['store_id' => $this->store->id]);
        $variant = ProductVariant::factory()->create([
            'product_id' => $product->id,
            'barcode' => '999888777',
        ]);

        $template = LabelTemplate::factory()->create([
            'store_id' => $this->store->id,
            'type' => LabelTemplate::TYPE_PRODUCT,
        ]);
        LabelTemplateElement::factory()->barcode()->create([
            'label_template_id' => $template->id,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/products/{$product->id}/print-barcode/zpl", [
                'template_id' => $template->id,
                'variant_ids' => [$variant->id],
                'quantity' => 3,
            ]);

        $response->assertOk();
        $response->assertJson(['count' => 3]);
    }

    public function test_generate_zpl_requires_template_id(): void
    {
        $product = Product::factory()->create(['store_id' => $this->store->id]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);

        $response = $this->actingAs($this->user)
            ->postJson("/products/{$product->id}/print-barcode/zpl", [
                'variant_ids' => [$variant->id],
            ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('template_id');
    }

    public function test_generate_zpl_requires_variant_ids(): void
    {
        $product = Product::factory()->create(['store_id' => $this->store->id]);
        $template = LabelTemplate::factory()->create([
            'store_id' => $this->store->id,
            'type' => LabelTemplate::TYPE_PRODUCT,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/products/{$product->id}/print-barcode/zpl", [
                'template_id' => $template->id,
            ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('variant_ids');
    }

    public function test_individual_attributes_are_populated(): void
    {
        $category = \App\Models\Category::factory()->create([
            'store_id' => $this->store->id,
            'barcode_attributes' => ['price_code', 'category', 'price'],
        ]);

        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'category_id' => $category->id,
            'price_code' => 'XYZ',
        ]);
        $variant = ProductVariant::factory()->create([
            'product_id' => $product->id,
            'price' => 199.99,
        ]);

        $product->load('category', 'brand');

        $data = LabelDataService::formatProductVariantForLabel($variant);

        $this->assertEquals('XYZ', $data['product']['attribute_1']);
        $this->assertEquals($category->name, $data['product']['attribute_2']);
        $this->assertEquals('$199.99', $data['product']['attribute_3']);
        $this->assertNull($data['product']['attribute_4']);
        $this->assertNull($data['product']['attribute_5']);
        // attribute_line should be the comma-joined version
        $this->assertStringContainsString('XYZ', $data['product']['attribute_line']);
        $this->assertStringContainsString($category->name, $data['product']['attribute_line']);
    }

    public function test_generate_zpl_with_rotated_barcode(): void
    {
        $product = Product::factory()->create(['store_id' => $this->store->id]);
        $variant = ProductVariant::factory()->create([
            'product_id' => $product->id,
            'barcode' => 'JWL-001',
        ]);

        $template = LabelTemplate::factory()->create([
            'store_id' => $this->store->id,
            'type' => LabelTemplate::TYPE_PRODUCT,
            'canvas_width' => 355,
            'canvas_height' => 89,
        ]);
        LabelTemplateElement::factory()->barcode()->create([
            'label_template_id' => $template->id,
            'x' => 0,
            'y' => 18,
            'width' => 355,
            'height' => 40,
            'styles' => [
                'barcodeHeight' => 35,
                'moduleWidth' => 1,
                'showText' => false,
                'alignment' => 'center',
                'rotation' => 90,
            ],
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/products/{$product->id}/print-barcode/zpl", [
                'template_id' => $template->id,
                'variant_ids' => [$variant->id],
                'quantity' => 1,
            ]);

        $response->assertOk();
        $zpl = $response->json('zpl');
        $this->assertStringContainsString('^BCR', $zpl);
        $this->assertStringContainsString('JWL-001', $zpl);
        $this->assertStringContainsString('^PW355', $zpl);
        $this->assertStringContainsString('^LL89', $zpl);
    }

    public function test_does_not_include_other_store_templates(): void
    {
        $otherStore = Store::factory()->create(['user_id' => $this->user->id]);
        $product = Product::factory()->create(['store_id' => $this->store->id]);
        ProductVariant::factory()->create(['product_id' => $product->id]);

        LabelTemplate::factory()->create([
            'store_id' => $otherStore->id,
            'type' => LabelTemplate::TYPE_PRODUCT,
            'name' => 'Other Store Template',
        ]);

        $response = $this->actingAs($this->user)
            ->get("/products/{$product->id}/print-barcode");

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('products/PrintBarcode')
            ->has('labelTemplates', 0)
        );
    }
}
