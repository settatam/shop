<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;
use App\Services\Chat\ChatToolExecutor;
use App\Services\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VoiceTest extends TestCase
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

        $ownerRole = Role::factory()->owner()->create([
            'store_id' => $this->store->id,
        ]);

        StoreUser::factory()->owner()->create([
            'user_id' => $this->user->id,
            'store_id' => $this->store->id,
            'role_id' => $ownerRole->id,
        ]);

        $this->user->current_store_id = $this->store->id;
        $this->user->save();

        app(StoreContext::class)->setCurrentStore($this->store);
    }

    public function test_chat_tool_executor_has_new_tools(): void
    {
        $executor = new ChatToolExecutor;

        $this->assertTrue($executor->has('get_sales_report'));
        $this->assertTrue($executor->has('get_spot_prices'));
        $this->assertTrue($executor->has('lookup_product'));
        $this->assertTrue($executor->has('get_pending_actions'));
        $this->assertTrue($executor->has('get_dead_stock'));
    }

    public function test_spot_price_tool_returns_empty_when_no_prices(): void
    {
        $executor = new ChatToolExecutor;

        $result = $executor->execute('get_spot_prices', [], $this->store->id);

        $this->assertArrayHasKey('prices', $result);
        $this->assertEmpty($result['prices']);
    }

    public function test_product_lookup_tool_returns_not_found_for_invalid_sku(): void
    {
        $executor = new ChatToolExecutor;

        $result = $executor->execute('lookup_product', ['sku' => 'NONEXISTENT'], $this->store->id);

        $this->assertFalse($result['found']);
        $this->assertEquals('Product not found', $result['message']);
    }

    public function test_pending_actions_tool_returns_empty_when_no_actions(): void
    {
        $executor = new ChatToolExecutor;

        $result = $executor->execute('get_pending_actions', [], $this->store->id);

        $this->assertEquals(0, $result['total_pending']);
        $this->assertEmpty($result['actions']);
    }

    public function test_dead_stock_tool_returns_empty_when_no_slow_movers(): void
    {
        $executor = new ChatToolExecutor;

        $result = $executor->execute('get_dead_stock', ['threshold_days' => 90], $this->store->id);

        $this->assertEquals(0, $result['total_slow_movers']);
        $this->assertEmpty($result['items']);
    }

    public function test_voice_text_query_requires_authentication(): void
    {
        $response = $this->postJson('/api/v1/voice/text-query', [
            'query' => 'How did we do today?',
        ]);

        $response->assertUnauthorized();
    }

    public function test_voice_query_requires_authentication(): void
    {
        $response = $this->postJson('/api/v1/voice/query', [
            'audio' => 'dummy',
        ]);

        $response->assertUnauthorized();
    }

    public function test_tool_definitions_include_new_tools(): void
    {
        $executor = new ChatToolExecutor;
        $definitions = $executor->getDefinitions();

        $toolNames = array_column($definitions, 'name');

        $this->assertContains('get_spot_prices', $toolNames);
        $this->assertContains('lookup_product', $toolNames);
        $this->assertContains('get_pending_actions', $toolNames);
        $this->assertContains('get_dead_stock', $toolNames);
    }

    public function test_tool_descriptions_include_new_tools(): void
    {
        $executor = new ChatToolExecutor;

        $this->assertEquals('Pulling sales report...', $executor->getToolDescription('get_sales_report'));
        $this->assertEquals('Fetching spot prices...', $executor->getToolDescription('get_spot_prices'));
        $this->assertEquals('Looking up product...', $executor->getToolDescription('lookup_product'));
        $this->assertEquals('Checking pending actions...', $executor->getToolDescription('get_pending_actions'));
        $this->assertEquals('Analyzing slow-moving inventory...', $executor->getToolDescription('get_dead_stock'));
    }

    public function test_sales_report_tool_returns_report_for_today(): void
    {
        $executor = new ChatToolExecutor;

        $result = $executor->execute('get_sales_report', ['period' => 'today'], $this->store->id);

        $this->assertEquals('today', $result['period']);
        $this->assertEquals('Today', $result['period_label']);
        $this->assertArrayHasKey('revenue', $result);
        $this->assertArrayHasKey('transaction_count', $result);
        $this->assertArrayHasKey('average_ticket', $result);
        $this->assertArrayHasKey('new_customers', $result);
        $this->assertArrayHasKey('top_categories', $result);
        $this->assertArrayHasKey('returns', $result);
    }

    public function test_sales_report_tool_returns_report_for_week(): void
    {
        $executor = new ChatToolExecutor;

        $result = $executor->execute('get_sales_report', ['period' => 'this_week'], $this->store->id);

        $this->assertEquals('this_week', $result['period']);
        $this->assertEquals('This Week', $result['period_label']);
        $this->assertArrayHasKey('best_day', $result);
        $this->assertEquals('last week', $result['comparison_period']);
    }

    public function test_sales_report_tool_returns_report_for_month(): void
    {
        $executor = new ChatToolExecutor;

        $result = $executor->execute('get_sales_report', ['period' => 'this_month'], $this->store->id);

        $this->assertEquals('this_month', $result['period']);
        $this->assertEquals('This Month', $result['period_label']);
        $this->assertEquals('last month', $result['comparison_period']);
    }
}
