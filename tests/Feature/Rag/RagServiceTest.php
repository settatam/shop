<?php

namespace Tests\Feature\Rag;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\StoreKnowledgeBaseEntry;
use App\Services\Rag\ContentChunker;
use App\Services\Rag\EmbeddingService;
use App\Services\Rag\QdrantService;
use App\Services\Rag\RagIndexer;
use App\Services\StorefrontChat\Tools\StorefrontKnowledgeSearchTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RagServiceTest extends TestCase
{
    use RefreshDatabase;

    protected Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        // Disable Scout indexing for these tests
        Product::disableSearchSyncing();
        Category::disableSearchSyncing();

        $this->store = Store::factory()->create(['name' => 'Test Jewelry Store']);
    }

    protected function tearDown(): void
    {
        Product::enableSearchSyncing();
        Category::enableSearchSyncing();

        parent::tearDown();
    }

    protected function fakeEmbeddingResponse(int $count = 1): void
    {
        $data = [];
        for ($i = 0; $i < $count; $i++) {
            $data[] = [
                'embedding' => array_fill(0, 1536, 0.1),
                'index' => $i,
            ];
        }

        Http::fake([
            'api.openai.com/v1/embeddings' => Http::response([
                'data' => $data,
                'usage' => ['total_tokens' => 10],
            ]),
            '127.0.0.1:6333/*' => Http::response(['result' => [], 'status' => 'ok']),
        ]);
    }

    public function test_embedding_service_returns_vector(): void
    {
        Http::fake([
            'api.openai.com/v1/embeddings' => Http::response([
                'data' => [
                    ['embedding' => array_fill(0, 1536, 0.5), 'index' => 0],
                ],
            ]),
        ]);

        $service = new EmbeddingService;
        $vector = $service->embed('test text');

        $this->assertIsArray($vector);
        $this->assertCount(1536, $vector);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'embeddings')
                && $request['model'] === 'text-embedding-3-small';
        });
    }

    public function test_embedding_service_batch(): void
    {
        Http::fake([
            'api.openai.com/v1/embeddings' => Http::response([
                'data' => [
                    ['embedding' => array_fill(0, 1536, 0.1), 'index' => 0],
                    ['embedding' => array_fill(0, 1536, 0.2), 'index' => 1],
                ],
            ]),
        ]);

        $service = new EmbeddingService;
        $vectors = $service->embedBatch(['text one', 'text two']);

        $this->assertCount(2, $vectors);
    }

    public function test_qdrant_service_upsert(): void
    {
        Http::fake([
            '127.0.0.1:6333/*' => Http::response(['result' => null, 'status' => 'ok']),
        ]);

        $service = new QdrantService;
        $service->upsert('test_1', array_fill(0, 1536, 0.1), [
            'store_id' => 1,
            'content_type' => 'product',
            'title' => 'Test Product',
        ]);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'points')
                && $request->method() === 'PUT';
        });
    }

    public function test_qdrant_service_search(): void
    {
        Http::fake([
            '127.0.0.1:6333/*' => Http::response([
                'result' => [
                    [
                        'id' => 'product_1',
                        'score' => 0.95,
                        'payload' => [
                            'store_id' => 1,
                            'content_type' => 'product',
                            'title' => 'Gold Ring',
                        ],
                    ],
                ],
            ]),
        ]);

        $service = new QdrantService;
        $results = $service->search(array_fill(0, 1536, 0.1), [
            'must' => [['key' => 'store_id', 'match' => ['value' => 1]]],
        ]);

        $this->assertCount(1, $results);
        $this->assertEquals('Gold Ring', $results[0]['payload']['title']);
    }

    public function test_content_chunker_product(): void
    {
        $category = Category::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Rings',
        ]);

        $brand = Brand::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'TestBrand',
        ]);

        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'title' => '14K Gold Diamond Ring',
            'description' => 'A beautiful diamond ring.',
            'category_id' => $category->id,
            'brand_id' => $brand->id,
            'status' => Product::STATUS_ACTIVE,
        ]);

        ProductVariant::factory()->create([
            'product_id' => $product->id,
            'price' => 2999.99,
        ]);

        $chunker = new ContentChunker;
        $chunk = $chunker->chunkProduct($product);

        $this->assertEquals("product_{$product->id}", $chunk['point_id']);
        $this->assertStringContains('14K Gold Diamond Ring', $chunk['text']);
        $this->assertStringContains('TestBrand', $chunk['text']);
        $this->assertStringContains('Rings', $chunk['text']);
        $this->assertStringContains('$2,999.99', $chunk['text']);
        $this->assertEquals('product', $chunk['payload']['content_type']);
        $this->assertEquals($this->store->id, $chunk['payload']['store_id']);
    }

    public function test_content_chunker_knowledge_base(): void
    {
        $entry = StoreKnowledgeBaseEntry::create([
            'store_id' => $this->store->id,
            'type' => 'return_policy',
            'title' => '30 Day Returns',
            'content' => 'We accept returns within 30 days of purchase.',
            'is_active' => true,
        ]);

        $chunker = new ContentChunker;
        $chunk = $chunker->chunkKnowledgeBaseEntry($entry);

        $this->assertEquals("kb_{$entry->id}", $chunk['point_id']);
        $this->assertStringContains('Return Policy', $chunk['text']);
        $this->assertStringContains('30 Day Returns', $chunk['text']);
        $this->assertEquals('knowledge_base', $chunk['payload']['content_type']);
    }

    public function test_content_chunker_category(): void
    {
        $category = Category::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Engagement Rings',
            'description' => 'Beautiful engagement rings for the special moment.',
        ]);

        $chunker = new ContentChunker;
        $chunk = $chunker->chunkCategory($category);

        $this->assertEquals("category_{$category->id}", $chunk['point_id']);
        $this->assertStringContains('Engagement Rings', $chunk['text']);
        $this->assertEquals('category', $chunk['payload']['content_type']);
    }

    public function test_content_chunker_store(): void
    {
        $chunker = new ContentChunker;
        $chunk = $chunker->chunkStore($this->store);

        $this->assertEquals("store_{$this->store->id}", $chunk['point_id']);
        $this->assertStringContains('Test Jewelry Store', $chunk['text']);
        $this->assertEquals('store_info', $chunk['payload']['content_type']);
    }

    public function test_rag_indexer_indexes_product(): void
    {
        $this->fakeEmbeddingResponse();

        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'title' => 'Silver Necklace',
            'status' => Product::STATUS_ACTIVE,
        ]);

        ProductVariant::factory()->create([
            'product_id' => $product->id,
            'price' => 199.99,
        ]);

        $indexer = app(RagIndexer::class);
        $indexer->indexProduct($product);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'embeddings');
        });

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'points')
                && $request->method() === 'PUT';
        });
    }

    public function test_knowledge_search_tool_returns_results(): void
    {
        Http::fake([
            'api.openai.com/v1/embeddings' => Http::response([
                'data' => [
                    ['embedding' => array_fill(0, 1536, 0.1), 'index' => 0],
                ],
            ]),
            '127.0.0.1:6333/collections/*/points/search' => Http::response([
                'result' => [
                    [
                        'id' => 'kb_1',
                        'score' => 0.92,
                        'payload' => [
                            'store_id' => $this->store->id,
                            'content_type' => 'knowledge_base',
                            'title' => 'Return Policy',
                            'metadata' => ['kb_type' => 'return_policy'],
                        ],
                    ],
                ],
            ]),
        ]);

        $tool = app(StorefrontKnowledgeSearchTool::class);
        $result = $tool->execute(['query' => 'return policy'], $this->store->id);

        $this->assertTrue($result['found']);
        $this->assertCount(1, $result['results']);
        $this->assertEquals('Return Policy', $result['results'][0]['title']);
    }

    public function test_knowledge_search_tool_handles_no_results(): void
    {
        Http::fake([
            'api.openai.com/v1/embeddings' => Http::response([
                'data' => [
                    ['embedding' => array_fill(0, 1536, 0.1), 'index' => 0],
                ],
            ]),
            '127.0.0.1:6333/collections/*/points/search' => Http::response([
                'result' => [],
            ]),
        ]);

        $tool = app(StorefrontKnowledgeSearchTool::class);
        $result = $tool->execute(['query' => 'something obscure'], $this->store->id);

        $this->assertFalse($result['found']);
        $this->assertEmpty($result['results']);
    }

    public function test_knowledge_search_tool_requires_query(): void
    {
        $tool = app(StorefrontKnowledgeSearchTool::class);
        $result = $tool->execute([], $this->store->id);

        $this->assertArrayHasKey('error', $result);
    }

    /**
     * Assert that a string contains a substring.
     */
    protected function assertStringContains(string $needle, string $haystack): void
    {
        $this->assertStringContainsString($needle, $haystack);
    }
}
