<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductAttributeValue;
use App\Models\ProductTemplate;
use App\Models\ProductTemplateField;
use App\Models\ProductTemplateFieldOption;
use App\Models\Store;
use App\Services\TitleFormatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TitleFormatServiceTest extends TestCase
{
    use RefreshDatabase;

    protected Store $store;

    protected TitleFormatService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = Store::factory()->create();
        $this->service = new TitleFormatService;
    }

    public function test_resolves_basic_variable(): void
    {
        $product = $this->createProductWithAttributes(
            titleFormat: '{{ diamond_color }}',
            attributes: ['diamond_color' => 'G'],
        );

        $result = $this->service->resolve($product);

        $this->assertEquals('G', $result);
    }

    public function test_number_format_filter(): void
    {
        $product = $this->createProductWithAttributes(
            titleFormat: '{{ main_stone_wt|number_format(2) }}',
            attributes: ['main_stone_wt' => '1.5'],
        );

        $result = $this->service->resolve($product);

        $this->assertEquals('1.50', $result);
    }

    public function test_literal_text_passes_through(): void
    {
        $product = $this->createProductWithAttributes(
            titleFormat: 'GIA Certified Diamond',
            attributes: [],
        );

        $result = $this->service->resolve($product);

        $this->assertEquals('GIA Certified Diamond', $result);
    }

    public function test_full_loose_stones_format(): void
    {
        $product = $this->createProductWithAttributes(
            titleFormat: '{{ main_stone_shape }} {{ main_stone_wt|number_format(2) }} {{ diamond_color }} {{ diamond_clarity }} GIA Certified Diamond',
            attributes: [
                'main_stone_shape' => 'Round Brilliant',
                'main_stone_wt' => '1.5',
                'diamond_color' => 'G',
                'diamond_clarity' => 'VS2',
            ],
        );

        $result = $this->service->resolve($product);

        $this->assertEquals('Round Brilliant 1.50 G VS2 GIA Certified Diamond', $result);
    }

    public function test_missing_attributes_produce_empty_string(): void
    {
        $product = $this->createProductWithAttributes(
            titleFormat: '{{ main_stone_shape }} {{ missing_attr }} Diamond',
            attributes: ['main_stone_shape' => 'Round'],
        );

        $result = $this->service->resolve($product);

        $this->assertEquals('Round Diamond', $result);
    }

    public function test_null_title_format_returns_null(): void
    {
        $category = Category::factory()->create([
            'store_id' => $this->store->id,
            'title_format' => null,
        ]);

        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'category_id' => $category->id,
        ]);

        $result = $this->service->resolve($product);

        $this->assertNull($result);
    }

    public function test_select_field_resolves_to_display_label(): void
    {
        $category = Category::factory()->create([
            'store_id' => $this->store->id,
            'title_format' => '{{ diamond_color }}',
        ]);

        $template = ProductTemplate::factory()->create([
            'store_id' => $this->store->id,
        ]);

        $field = ProductTemplateField::factory()->select()->create([
            'product_template_id' => $template->id,
            'name' => 'diamond_color',
            'label' => 'Diamond Color',
        ]);

        ProductTemplateFieldOption::factory()->create([
            'product_template_field_id' => $field->id,
            'value' => 'g',
            'label' => 'G',
        ]);

        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'category_id' => $category->id,
            'template_id' => $template->id,
        ]);

        ProductAttributeValue::create([
            'product_id' => $product->id,
            'product_template_field_id' => $field->id,
            'value' => 'g',
        ]);

        $result = $this->service->resolve($product);

        $this->assertEquals('G', $result);
    }

    public function test_multiple_spaces_collapsed(): void
    {
        $product = $this->createProductWithAttributes(
            titleFormat: '{{ shape }}  {{ color }}  Diamond',
            attributes: ['shape' => 'Round'],
        );

        $result = $this->service->resolve($product);

        $this->assertEquals('Round Diamond', $result);
    }

    public function test_number_format_with_null_value(): void
    {
        $product = $this->createProductWithAttributes(
            titleFormat: '{{ weight|number_format(2) }} carat',
            attributes: [],
        );

        $result = $this->service->resolve($product);

        $this->assertEquals('carat', $result);
    }

    public function test_built_in_price_code_field(): void
    {
        $category = Category::factory()->create([
            'store_id' => $this->store->id,
            'title_format' => '{{ price_code }} Diamond',
        ]);

        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'category_id' => $category->id,
            'price_code' => 'ABC',
        ]);

        $result = $this->service->resolve($product);

        $this->assertEquals('ABC Diamond', $result);
    }

    public function test_built_in_category_field(): void
    {
        $category = Category::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Loose Stones',
            'title_format' => '{{ category }} - Diamond',
        ]);

        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'category_id' => $category->id,
        ]);

        $result = $this->service->resolve($product);

        $this->assertEquals('Loose Stones - Diamond', $result);
    }

    /**
     * Helper to create a product with text attribute fields and a title format.
     *
     * @param  array<string, string>  $attributes
     */
    protected function createProductWithAttributes(string $titleFormat, array $attributes): Product
    {
        $category = Category::factory()->create([
            'store_id' => $this->store->id,
            'title_format' => $titleFormat,
        ]);

        $template = ProductTemplate::factory()->create([
            'store_id' => $this->store->id,
        ]);

        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'category_id' => $category->id,
            'template_id' => $template->id,
        ]);

        foreach ($attributes as $name => $value) {
            $field = ProductTemplateField::factory()->text()->create([
                'product_template_id' => $template->id,
                'name' => $name,
                'label' => ucfirst(str_replace('_', ' ', $name)),
            ]);

            ProductAttributeValue::create([
                'product_id' => $product->id,
                'product_template_field_id' => $field->id,
                'value' => $value,
            ]);
        }

        return $product;
    }
}
