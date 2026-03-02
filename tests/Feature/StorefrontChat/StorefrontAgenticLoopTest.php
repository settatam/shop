<?php

namespace Tests\Feature\StorefrontChat;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\StorefrontChatMessage;
use App\Models\StorefrontChatSession;
use App\Models\StoreMarketplace;
use App\Services\StorefrontChat\StorefrontChatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StorefrontAgenticLoopTest extends TestCase
{
    use RefreshDatabase;

    protected Store $store;

    protected StoreMarketplace $marketplace;

    protected StorefrontChatSession $session;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = Store::factory()->create(['name' => 'Diamond & Gold Jewelers']);
        $this->marketplace = StoreMarketplace::factory()->shopify()->create([
            'store_id' => $this->store->id,
            'status' => 'active',
        ]);
        $this->session = StorefrontChatSession::factory()->create([
            'store_id' => $this->store->id,
            'store_marketplace_id' => $this->marketplace->id,
            'visitor_id' => 'test-visitor-agentic',
        ]);
    }

    /**
     * Build a fake SSE streaming response body that simulates Claude calling a tool.
     */
    protected function buildToolUseResponse(string $toolId, string $toolName, array $input): string
    {
        $inputJson = json_encode($input);

        $events = [];
        $events[] = 'event: message_start'."\n".'data: '.json_encode([
            'type' => 'message_start',
            'message' => [
                'id' => 'msg_'.uniqid(),
                'type' => 'message',
                'role' => 'assistant',
                'usage' => ['input_tokens' => 150, 'output_tokens' => 0],
            ],
        ]);

        $events[] = 'event: content_block_start'."\n".'data: '.json_encode([
            'type' => 'content_block_start',
            'index' => 0,
            'content_block' => [
                'type' => 'tool_use',
                'id' => $toolId,
                'name' => $toolName,
            ],
        ]);

        $events[] = 'event: content_block_delta'."\n".'data: '.json_encode([
            'type' => 'content_block_delta',
            'index' => 0,
            'delta' => [
                'type' => 'input_json_delta',
                'partial_json' => $inputJson,
            ],
        ]);

        $events[] = 'event: content_block_stop'."\n".'data: '.json_encode([
            'type' => 'content_block_stop',
            'index' => 0,
        ]);

        $events[] = 'event: message_delta'."\n".'data: '.json_encode([
            'type' => 'message_delta',
            'delta' => ['stop_reason' => 'tool_use'],
            'usage' => ['output_tokens' => 30],
        ]);

        $events[] = 'event: message_stop'."\n".'data: '.json_encode([
            'type' => 'message_stop',
        ]);

        return implode("\n\n", $events)."\n\n";
    }

    /**
     * Build a fake SSE streaming response body that simulates Claude responding with text.
     */
    protected function buildTextResponse(string $text): string
    {
        $events = [];
        $events[] = 'event: message_start'."\n".'data: '.json_encode([
            'type' => 'message_start',
            'message' => [
                'id' => 'msg_'.uniqid(),
                'type' => 'message',
                'role' => 'assistant',
                'usage' => ['input_tokens' => 250, 'output_tokens' => 0],
            ],
        ]);

        $events[] = 'event: content_block_start'."\n".'data: '.json_encode([
            'type' => 'content_block_start',
            'index' => 0,
            'content_block' => ['type' => 'text', 'text' => ''],
        ]);

        $events[] = 'event: content_block_delta'."\n".'data: '.json_encode([
            'type' => 'content_block_delta',
            'index' => 0,
            'delta' => ['type' => 'text_delta', 'text' => $text],
        ]);

        $events[] = 'event: content_block_stop'."\n".'data: '.json_encode([
            'type' => 'content_block_stop',
            'index' => 0,
        ]);

        $events[] = 'event: message_delta'."\n".'data: '.json_encode([
            'type' => 'message_delta',
            'delta' => ['stop_reason' => 'end_turn'],
            'usage' => ['output_tokens' => 60],
        ]);

        $events[] = 'event: message_stop'."\n".'data: '.json_encode([
            'type' => 'message_stop',
        ]);

        return implode("\n\n", $events)."\n\n";
    }

    public function test_agentic_loop_searches_products_and_responds(): void
    {
        // Create jewelry products under $1000
        $ringCategory = Category::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Rings',
        ]);

        $product1 = Product::factory()->create([
            'store_id' => $this->store->id,
            'title' => '14K Gold Diamond Engagement Ring',
            'status' => Product::STATUS_ACTIVE,
            'category_id' => $ringCategory->id,
        ]);

        ProductVariant::factory()->create([
            'product_id' => $product1->id,
            'price' => 799.99,
            'quantity' => 3,
            'sku' => 'RING-001',
        ]);

        $product2 = Product::factory()->create([
            'store_id' => $this->store->id,
            'title' => 'Sterling Silver Pearl Necklace',
            'status' => Product::STATUS_ACTIVE,
        ]);

        ProductVariant::factory()->create([
            'product_id' => $product2->id,
            'price' => 249.99,
            'quantity' => 5,
            'sku' => 'NECK-001',
        ]);

        $product3 = Product::factory()->create([
            'store_id' => $this->store->id,
            'title' => 'Platinum Diamond Bracelet',
            'status' => Product::STATUS_ACTIVE,
        ]);

        ProductVariant::factory()->create([
            'product_id' => $product3->id,
            'price' => 2500.00,
            'quantity' => 1,
            'sku' => 'BRAC-001',
        ]);

        // Mock Claude API - first call returns tool_use, second returns text
        $callCount = 0;
        Http::fake(function ($request) use (&$callCount) {
            if (! str_contains($request->url(), 'api.anthropic.com')) {
                return Http::response('', 404);
            }

            $callCount++;

            if ($callCount === 1) {
                // Claude decides to search for products under $1000
                return Http::response(
                    $this->buildToolUseResponse('toolu_01search', 'search_products', [
                        'max_price' => 1000,
                    ]),
                    200,
                    ['Content-Type' => 'text/event-stream']
                );
            }

            // Second call: Claude responds with product recommendations
            return Http::response(
                $this->buildTextResponse("Great news! I found 2 beautiful pieces under $1,000:\n\n1. **14K Gold Diamond Engagement Ring** - $799.99 (in stock)\n2. **Sterling Silver Pearl Necklace** - $249.99 (in stock)\n\nWould you like more details on either of these?"),
                200,
                ['Content-Type' => 'text/event-stream']
            );
        });

        $service = app(StorefrontChatService::class);

        $events = [];
        foreach ($service->streamMessage($this->session, 'What jewelry deals do you have that are under $1000', $this->store) as $event) {
            $events[] = $event;
        }

        // Verify the agentic loop events
        $eventTypes = array_column($events, 'type');

        // Should have: tool_use → tool_result → token(s) → usage → done
        $this->assertContains('tool_use', $eventTypes, 'Claude should call a tool');
        $this->assertContains('tool_result', $eventTypes, 'Tool should return results');
        $this->assertContains('token', $eventTypes, 'Claude should generate text response');
        $this->assertContains('done', $eventTypes, 'Loop should complete');

        // Verify the tool call was search_products
        $toolUseEvent = collect($events)->firstWhere('type', 'tool_use');
        $this->assertEquals('search_products', $toolUseEvent['tool']);

        // Verify the tool result contains our products
        $toolResultEvent = collect($events)->firstWhere('type', 'tool_result');
        $this->assertEquals('search_products', $toolResultEvent['tool']);
        $this->assertTrue($toolResultEvent['result']['found']);
        $this->assertEquals(2, $toolResultEvent['result']['count']);

        // Verify both products under $1000 are returned, not the $2500 one
        $productTitles = array_column($toolResultEvent['result']['products'], 'title');
        $this->assertContains('14K Gold Diamond Engagement Ring', $productTitles);
        $this->assertContains('Sterling Silver Pearl Necklace', $productTitles);
        $this->assertNotContains('Platinum Diamond Bracelet', $productTitles);

        // Verify prices are correct
        $products = collect($toolResultEvent['result']['products']);
        $ring = $products->firstWhere('title', '14K Gold Diamond Engagement Ring');
        $this->assertEquals(799.99, $ring['price']);
        $this->assertEquals('$799.99', $ring['price_formatted']);
        $this->assertTrue($ring['available']);

        $necklace = $products->firstWhere('title', 'Sterling Silver Pearl Necklace');
        $this->assertEquals(249.99, $necklace['price']);
        $this->assertTrue($necklace['available']);

        // Verify the final text response was streamed
        $tokenEvents = collect($events)->where('type', 'token');
        $fullText = $tokenEvents->pluck('content')->implode('');
        $this->assertStringContainsString('14K Gold Diamond Engagement Ring', $fullText);
        $this->assertStringContainsString('$799.99', $fullText);

        // Verify Claude was called exactly twice (tool_use → text)
        $this->assertEquals(2, $callCount);

        // Verify messages were persisted
        $messages = StorefrontChatMessage::where('storefront_chat_session_id', $this->session->id)->get();
        $this->assertCount(2, $messages);

        $userMsg = $messages->firstWhere('role', 'user');
        $this->assertEquals('What jewelry deals do you have that are under $1000', $userMsg->content);

        $assistantMsg = $messages->firstWhere('role', 'assistant');
        $this->assertNotEmpty($assistantMsg->content);
        $this->assertNotNull($assistantMsg->tool_calls);
        $this->assertGreaterThan(0, $assistantMsg->tokens_used);
    }

    public function test_agentic_loop_responds_without_tools_for_simple_greeting(): void
    {
        // For a simple greeting, Claude should respond directly without tools
        Http::fake(function ($request) {
            if (! str_contains($request->url(), 'api.anthropic.com')) {
                return Http::response('', 404);
            }

            return Http::response(
                $this->buildTextResponse('Hi there! Welcome to Diamond & Gold Jewelers. What can I help you find today?'),
                200,
                ['Content-Type' => 'text/event-stream']
            );
        });

        $service = app(StorefrontChatService::class);

        $events = [];
        foreach ($service->streamMessage($this->session, 'Hello!', $this->store) as $event) {
            $events[] = $event;
        }

        $eventTypes = array_column($events, 'type');

        // Should have text tokens but NO tool calls
        $this->assertContains('token', $eventTypes);
        $this->assertContains('done', $eventTypes);
        $this->assertNotContains('tool_use', $eventTypes);
        $this->assertNotContains('tool_result', $eventTypes);

        // Verify response text
        $fullText = collect($events)->where('type', 'token')->pluck('content')->implode('');
        $this->assertStringContainsString('Diamond & Gold Jewelers', $fullText);
    }

    public function test_agentic_loop_handles_no_products_found(): void
    {
        // No products in database - tool will return empty
        $callCount = 0;
        Http::fake(function ($request) use (&$callCount) {
            if (! str_contains($request->url(), 'api.anthropic.com')) {
                return Http::response('', 404);
            }

            $callCount++;

            if ($callCount === 1) {
                return Http::response(
                    $this->buildToolUseResponse('toolu_01empty', 'search_products', [
                        'query' => 'vintage watches',
                        'max_price' => 500,
                    ]),
                    200,
                    ['Content-Type' => 'text/event-stream']
                );
            }

            return Http::response(
                $this->buildTextResponse("I wasn't able to find any vintage watches under $500 right now. Would you like me to search for something else, or I can let our team know you're interested in vintage watches?"),
                200,
                ['Content-Type' => 'text/event-stream']
            );
        });

        $service = app(StorefrontChatService::class);

        $events = [];
        foreach ($service->streamMessage($this->session, 'Do you have any vintage watches under $500?', $this->store) as $event) {
            $events[] = $event;
        }

        // Tool was called but returned no results
        $toolResult = collect($events)->firstWhere('type', 'tool_result');
        $this->assertFalse($toolResult['result']['found']);
        $this->assertEmpty($toolResult['result']['products']);

        // Claude still responded gracefully
        $fullText = collect($events)->where('type', 'token')->pluck('content')->implode('');
        $this->assertNotEmpty($fullText);
    }

    public function test_agentic_loop_persists_tool_calls_in_message(): void
    {
        Product::factory()->create([
            'store_id' => $this->store->id,
            'title' => 'Gold Bracelet',
            'status' => Product::STATUS_ACTIVE,
        ])->each(function ($product) {
            ProductVariant::factory()->create([
                'product_id' => $product->id,
                'price' => 450.00,
                'quantity' => 2,
            ]);
        });

        $callCount = 0;
        Http::fake(function ($request) use (&$callCount) {
            if (! str_contains($request->url(), 'api.anthropic.com')) {
                return Http::response('', 404);
            }

            $callCount++;

            if ($callCount === 1) {
                return Http::response(
                    $this->buildToolUseResponse('toolu_bracelet', 'search_products', [
                        'max_price' => 500,
                    ]),
                    200,
                    ['Content-Type' => 'text/event-stream']
                );
            }

            return Http::response(
                $this->buildTextResponse('I found a lovely Gold Bracelet for $450!'),
                200,
                ['Content-Type' => 'text/event-stream']
            );
        });

        $service = app(StorefrontChatService::class);

        foreach ($service->streamMessage($this->session, 'Show me bracelets under $500', $this->store) as $event) {
            // consume the generator
        }

        // Verify the assistant message has tool_calls persisted
        $assistantMsg = StorefrontChatMessage::where('storefront_chat_session_id', $this->session->id)
            ->where('role', 'assistant')
            ->first();

        $this->assertNotNull($assistantMsg->tool_calls);
        $this->assertIsArray($assistantMsg->tool_calls);
        $this->assertCount(1, $assistantMsg->tool_calls);
        $this->assertEquals('search_products', $assistantMsg->tool_calls[0]['name']);
        $this->assertEquals('toolu_bracelet', $assistantMsg->tool_calls[0]['id']);
    }
}
