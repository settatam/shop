<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductAttributeValue;
use App\Models\ProductTemplate;
use App\Models\ProductTemplateField;
use App\Models\RapnetPrice;
use App\Models\Store;
use App\Services\Rapnet\RapnetPriceService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RapnetPriceServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_rapnet_price_model_finds_price_for_round_diamond(): void
    {
        RapnetPrice::create([
            'shape' => 'Round',
            'color' => 'D',
            'clarity' => 'VVS1',
            'low_size' => 0.50,
            'high_size' => 0.69,
            'carat_price' => 12500.00,
            'price_date' => Carbon::now(),
        ]);

        $price = RapnetPrice::findPrice('Round', 'D', 'VVS1', 0.63);

        $this->assertNotNull($price);
        $this->assertEquals(12500.00, $price->carat_price);
    }

    public function test_rapnet_price_model_finds_price_for_fancy_shape(): void
    {
        RapnetPrice::create([
            'shape' => 'Pear',
            'color' => 'E',
            'clarity' => 'VS2',
            'low_size' => 1.00,
            'high_size' => 1.49,
            'carat_price' => 8500.00,
            'price_date' => Carbon::now(),
        ]);

        // Fancy shapes (Emerald, Pear, Oval, etc.) use Pear pricing
        $price = RapnetPrice::findPrice('Emerald', 'E', 'VS2', 1.25);

        $this->assertNotNull($price);
        $this->assertEquals(8500.00, $price->carat_price);
    }

    public function test_rapnet_price_model_returns_null_for_no_match(): void
    {
        RapnetPrice::create([
            'shape' => 'Round',
            'color' => 'D',
            'clarity' => 'VVS1',
            'low_size' => 0.50,
            'high_size' => 0.69,
            'carat_price' => 12500.00,
            'price_date' => Carbon::now(),
        ]);

        // Weight outside range
        $price = RapnetPrice::findPrice('Round', 'D', 'VVS1', 0.80);
        $this->assertNull($price);

        // Different color
        $price = RapnetPrice::findPrice('Round', 'G', 'VVS1', 0.63);
        $this->assertNull($price);
    }

    public function test_lookup_price_returns_price_and_date(): void
    {
        $priceDate = Carbon::parse('2024-01-15');

        RapnetPrice::create([
            'shape' => 'Round',
            'color' => 'F',
            'clarity' => 'SI1',
            'low_size' => 0.70,
            'high_size' => 0.89,
            'carat_price' => 5200.00,
            'price_date' => $priceDate,
        ]);

        $service = new RapnetPriceService;
        $result = $service->lookupPrice('Round', 'F', 'SI1', 0.75);

        $this->assertNotNull($result);
        $this->assertEquals(5200.00, $result['price']);
        $this->assertEquals('2024-01-15', $result['date']->format('Y-m-d'));
    }

    public function test_set_product_rap_price_creates_attribute_values(): void
    {
        $store = Store::factory()->create();
        $template = ProductTemplate::factory()->create([
            'store_id' => $store->id,
            'name' => 'Loose Stones',
        ]);

        // Create the rap price fields
        $rapPriceField = ProductTemplateField::factory()->create([
            'product_template_id' => $template->id,
            'name' => 'rap_price',
            'type' => 'text',
        ]);
        $dateField = ProductTemplateField::factory()->create([
            'product_template_id' => $template->id,
            'name' => 'date_of_rap_price',
            'type' => 'text',
        ]);
        $currentRapField = ProductTemplateField::factory()->create([
            'product_template_id' => $template->id,
            'name' => 'current_rap_price',
            'type' => 'text',
        ]);

        $product = Product::factory()->create([
            'store_id' => $store->id,
            'template_id' => $template->id,
        ]);

        // Create rap price data
        RapnetPrice::create([
            'shape' => 'Round',
            'color' => 'G',
            'clarity' => 'VS1',
            'low_size' => 1.00,
            'high_size' => 1.49,
            'carat_price' => 7800.00,
            'price_date' => Carbon::parse('2024-02-01'),
        ]);

        $service = new RapnetPriceService;
        $result = $service->setProductRapPrice($product, 'Round', 'G', 'VS1', 1.15, isInitial: true);

        $this->assertTrue($result);

        // Check attribute values were created
        $this->assertDatabaseHas('product_attribute_values', [
            'product_id' => $product->id,
            'product_template_field_id' => $rapPriceField->id,
            'value' => '7800',
        ]);

        $this->assertDatabaseHas('product_attribute_values', [
            'product_id' => $product->id,
            'product_template_field_id' => $dateField->id,
            'value' => '2024-02-01',
        ]);

        $this->assertDatabaseHas('product_attribute_values', [
            'product_id' => $product->id,
            'product_template_field_id' => $currentRapField->id,
            'value' => '7800',
        ]);
    }

    public function test_set_product_rap_price_only_updates_current_when_not_initial(): void
    {
        $store = Store::factory()->create();
        $template = ProductTemplate::factory()->create([
            'store_id' => $store->id,
            'name' => 'Loose Stones',
        ]);

        $rapPriceField = ProductTemplateField::factory()->create([
            'product_template_id' => $template->id,
            'name' => 'rap_price',
            'type' => 'text',
        ]);
        $dateField = ProductTemplateField::factory()->create([
            'product_template_id' => $template->id,
            'name' => 'date_of_rap_price',
            'type' => 'text',
        ]);
        $currentRapField = ProductTemplateField::factory()->create([
            'product_template_id' => $template->id,
            'name' => 'current_rap_price',
            'type' => 'text',
        ]);

        $product = Product::factory()->create([
            'store_id' => $store->id,
            'template_id' => $template->id,
        ]);

        // Set initial values
        ProductAttributeValue::create([
            'product_id' => $product->id,
            'product_template_field_id' => $rapPriceField->id,
            'value' => '7500',
        ]);
        ProductAttributeValue::create([
            'product_id' => $product->id,
            'product_template_field_id' => $dateField->id,
            'value' => '2024-01-15',
        ]);
        ProductAttributeValue::create([
            'product_id' => $product->id,
            'product_template_field_id' => $currentRapField->id,
            'value' => '7500',
        ]);

        // Create updated rap price
        RapnetPrice::create([
            'shape' => 'Round',
            'color' => 'G',
            'clarity' => 'VS1',
            'low_size' => 1.00,
            'high_size' => 1.49,
            'carat_price' => 7800.00,
            'price_date' => Carbon::parse('2024-02-01'),
        ]);

        $service = new RapnetPriceService;
        $result = $service->setProductRapPrice($product, 'Round', 'G', 'VS1', 1.15, isInitial: false);

        $this->assertTrue($result);

        // Original rap_price and date should NOT change
        $this->assertDatabaseHas('product_attribute_values', [
            'product_id' => $product->id,
            'product_template_field_id' => $rapPriceField->id,
            'value' => '7500',
        ]);
        $this->assertDatabaseHas('product_attribute_values', [
            'product_id' => $product->id,
            'product_template_field_id' => $dateField->id,
            'value' => '2024-01-15',
        ]);

        // current_rap_price SHOULD be updated
        $this->assertDatabaseHas('product_attribute_values', [
            'product_id' => $product->id,
            'product_template_field_id' => $currentRapField->id,
            'value' => '7800',
        ]);
    }

    public function test_sync_command_displays_help(): void
    {
        $this->artisan('sync:rapnet-prices', ['--help' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('Sync diamond prices from Rapnet API');
    }
}
