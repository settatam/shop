<?php

namespace Tests\Feature\StorefrontChat;

use App\Models\AssistantDataGap;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\StoreKnowledgeBaseEntry;
use App\Services\StorefrontChat\StorefrontChatToolExecutor;
use App\Services\StorefrontChat\Tools\StorefrontAvailabilityTool;
use App\Services\StorefrontChat\Tools\StorefrontProductCompareTool;
use App\Services\StorefrontChat\Tools\StorefrontProductDetailTool;
use App\Services\StorefrontChat\Tools\StorefrontStoreInfoTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StorefrontToolsTest extends TestCase
{
    use RefreshDatabase;

    protected Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = Store::factory()->create();
    }

    // --- StorefrontProductDetailTool ---

    public function test_product_detail_returns_product_info(): void
    {
        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'status' => Product::STATUS_ACTIVE,
            'title' => 'Gold Diamond Ring',
            'description' => '<p>Beautiful 18k gold ring</p>',
        ]);

        ProductVariant::factory()->create([
            'product_id' => $product->id,
            'price' => 1299.99,
            'cost' => 600.00,
            'quantity' => 5,
            'sku' => 'GDR-001',
        ]);

        $tool = new StorefrontProductDetailTool;
        $result = $tool->execute(['product_id' => $product->id], $this->store->id);

        $this->assertTrue($result['found']);
        $this->assertEquals('Gold Diamond Ring', $result['product']['title']);
        $this->assertEquals('Beautiful 18k gold ring', $result['product']['description']);
        $this->assertTrue($result['product']['available']);
        $this->assertNotEmpty($result['product']['variants']);
        $this->assertEquals(1299.99, $result['product']['variants'][0]['price']);
        $this->assertEquals('$1,299.99', $result['product']['variants'][0]['price_formatted']);
    }

    public function test_product_detail_never_exposes_cost(): void
    {
        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'status' => Product::STATUS_ACTIVE,
        ]);

        ProductVariant::factory()->create([
            'product_id' => $product->id,
            'price' => 500.00,
            'cost' => 200.00,
            'quantity' => 3,
        ]);

        $tool = new StorefrontProductDetailTool;
        $result = $tool->execute(['product_id' => $product->id], $this->store->id);

        $resultJson = json_encode($result);

        $this->assertStringNotContainsString('"cost"', $resultJson);
        $this->assertStringNotContainsString('200', $resultJson);
    }

    public function test_product_detail_returns_not_found_for_inactive_product(): void
    {
        $product = Product::factory()->draft()->create([
            'store_id' => $this->store->id,
        ]);

        $tool = new StorefrontProductDetailTool;
        $result = $tool->execute(['product_id' => $product->id], $this->store->id);

        $this->assertFalse($result['found']);
    }

    public function test_product_detail_scopes_to_store(): void
    {
        $otherStore = Store::factory()->create();
        $product = Product::factory()->create([
            'store_id' => $otherStore->id,
            'status' => Product::STATUS_ACTIVE,
        ]);

        $tool = new StorefrontProductDetailTool;
        $result = $tool->execute(['product_id' => $product->id], $this->store->id);

        $this->assertFalse($result['found']);
    }

    public function test_product_detail_logs_data_gaps_for_missing_fields(): void
    {
        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'status' => Product::STATUS_ACTIVE,
        ]);

        ProductVariant::factory()->create([
            'product_id' => $product->id,
            'quantity' => 1,
        ]);

        $tool = new StorefrontProductDetailTool;
        $tool->execute(['product_id' => $product->id], $this->store->id);

        // With no attribute values, should log data gaps for missing jewelry fields
        $this->assertDatabaseHas('assistant_data_gaps', [
            'store_id' => $this->store->id,
            'product_id' => $product->id,
        ]);

        $gaps = AssistantDataGap::where('store_id', $this->store->id)
            ->where('product_id', $product->id)
            ->count();

        // Limited to 3 at a time
        $this->assertLessThanOrEqual(3, $gaps);
        $this->assertGreaterThan(0, $gaps);
    }

    // --- StorefrontAvailabilityTool ---

    public function test_availability_shows_in_stock(): void
    {
        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'status' => Product::STATUS_ACTIVE,
            'quantity' => 5,
        ]);

        ProductVariant::factory()->create([
            'product_id' => $product->id,
            'quantity' => 5,
        ]);

        $tool = new StorefrontAvailabilityTool;
        $result = $tool->execute(['product_id' => $product->id], $this->store->id);

        $this->assertTrue($result['found']);
        $this->assertTrue($result['available']);
    }

    public function test_availability_shows_out_of_stock(): void
    {
        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'status' => Product::STATUS_ACTIVE,
            'quantity' => 0,
        ]);

        ProductVariant::factory()->create([
            'product_id' => $product->id,
            'quantity' => 0,
        ]);

        $tool = new StorefrontAvailabilityTool;
        $result = $tool->execute(['product_id' => $product->id], $this->store->id);

        $this->assertTrue($result['found']);
        $this->assertFalse($result['available']);
    }

    public function test_availability_never_exposes_quantity_numbers(): void
    {
        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'status' => Product::STATUS_ACTIVE,
            'quantity' => 42,
        ]);

        ProductVariant::factory()->create([
            'product_id' => $product->id,
            'quantity' => 42,
        ]);

        $tool = new StorefrontAvailabilityTool;
        $result = $tool->execute(['product_id' => $product->id], $this->store->id);

        $resultJson = json_encode($result);

        // Should only have boolean availability, not numeric quantities
        $this->assertStringNotContainsString('"42"', $resultJson);
        $this->assertStringNotContainsString(':42', $resultJson);
        $this->assertIsBool($result['available']);

        foreach ($result['variants'] as $variant) {
            $this->assertIsBool($variant['available']);
        }
    }

    // --- StorefrontStoreInfoTool ---

    public function test_store_info_returns_knowledge_base_entries(): void
    {
        StoreKnowledgeBaseEntry::factory()->returnPolicy()->create([
            'store_id' => $this->store->id,
        ]);

        StoreKnowledgeBaseEntry::factory()->shippingInfo()->create([
            'store_id' => $this->store->id,
        ]);

        $tool = new StorefrontStoreInfoTool;
        $result = $tool->execute(['topic' => 'all'], $this->store->id);

        $this->assertTrue($result['found']);
        $this->assertArrayHasKey('return_policy', $result['entries']);
        $this->assertArrayHasKey('shipping_info', $result['entries']);
    }

    public function test_store_info_filters_by_topic(): void
    {
        StoreKnowledgeBaseEntry::factory()->returnPolicy()->create([
            'store_id' => $this->store->id,
        ]);

        StoreKnowledgeBaseEntry::factory()->shippingInfo()->create([
            'store_id' => $this->store->id,
        ]);

        $tool = new StorefrontStoreInfoTool;
        $result = $tool->execute(['topic' => 'return_policy'], $this->store->id);

        $this->assertTrue($result['found']);
        $this->assertArrayHasKey('return_policy', $result['entries']);
        $this->assertArrayNotHasKey('shipping_info', $result['entries']);
    }

    public function test_store_info_excludes_inactive_entries(): void
    {
        StoreKnowledgeBaseEntry::factory()->returnPolicy()->inactive()->create([
            'store_id' => $this->store->id,
        ]);

        $tool = new StorefrontStoreInfoTool;
        $result = $tool->execute(['topic' => 'return_policy'], $this->store->id);

        $this->assertFalse($result['found']);
    }

    // --- StorefrontProductCompareTool ---

    public function test_compare_products_side_by_side(): void
    {
        $product1 = Product::factory()->create([
            'store_id' => $this->store->id,
            'status' => Product::STATUS_ACTIVE,
            'title' => 'Gold Ring',
        ]);

        ProductVariant::factory()->create([
            'product_id' => $product1->id,
            'price' => 500.00,
        ]);

        $product2 = Product::factory()->create([
            'store_id' => $this->store->id,
            'status' => Product::STATUS_ACTIVE,
            'title' => 'Silver Ring',
        ]);

        ProductVariant::factory()->create([
            'product_id' => $product2->id,
            'price' => 200.00,
        ]);

        $tool = new StorefrontProductCompareTool;
        $result = $tool->execute([
            'product_ids' => [$product1->id, $product2->id],
        ], $this->store->id);

        $this->assertTrue($result['found']);
        $this->assertCount(2, $result['products']);
        $this->assertEquals('Gold Ring', $result['products'][0]['title']);
        $this->assertEquals('Silver Ring', $result['products'][1]['title']);
    }

    public function test_compare_requires_at_least_two_products(): void
    {
        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'status' => Product::STATUS_ACTIVE,
        ]);

        $tool = new StorefrontProductCompareTool;
        $result = $tool->execute([
            'product_ids' => [$product->id],
        ], $this->store->id);

        $this->assertArrayHasKey('error', $result);
    }

    public function test_compare_limits_to_four_products(): void
    {
        $tool = new StorefrontProductCompareTool;
        $result = $tool->execute([
            'product_ids' => [1, 2, 3, 4, 5],
        ], $this->store->id);

        $this->assertArrayHasKey('error', $result);
    }

    // --- StorefrontChatToolExecutor ---

    public function test_executor_registers_all_five_tools(): void
    {
        $executor = new StorefrontChatToolExecutor;
        $definitions = $executor->getDefinitions();

        $this->assertCount(5, $definitions);

        $toolNames = array_column($definitions, 'name');
        $this->assertContains('search_products', $toolNames);
        $this->assertContains('get_product_details', $toolNames);
        $this->assertContains('check_availability', $toolNames);
        $this->assertContains('get_store_info', $toolNames);
        $this->assertContains('compare_products', $toolNames);
    }

    public function test_executor_returns_error_for_unknown_tool(): void
    {
        $executor = new StorefrontChatToolExecutor;
        $result = $executor->execute('nonexistent_tool', [], $this->store->id);

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('Unknown tool', $result['error']);
    }

    public function test_executor_has_friendly_descriptions(): void
    {
        $executor = new StorefrontChatToolExecutor;

        $this->assertEquals('Searching products...', $executor->getToolDescription('search_products'));
        $this->assertEquals('Getting product details...', $executor->getToolDescription('get_product_details'));
        $this->assertEquals('Checking availability...', $executor->getToolDescription('check_availability'));
        $this->assertEquals('Looking up store information...', $executor->getToolDescription('get_store_info'));
        $this->assertEquals('Comparing products...', $executor->getToolDescription('compare_products'));
        $this->assertEquals('Processing...', $executor->getToolDescription('unknown'));
    }
}
