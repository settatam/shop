<?php

namespace App\Services\StorefrontChat\Tools;

use App\Services\Chat\Tools\ChatToolInterface;
use App\Services\Rag\EmbeddingService;
use App\Services\Rag\QdrantService;

class StorefrontKnowledgeSearchTool implements ChatToolInterface
{
    public function __construct(
        protected EmbeddingService $embedding,
        protected QdrantService $qdrant
    ) {}

    public function name(): string
    {
        return 'knowledge_search';
    }

    public function definition(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'Search the store\'s knowledge base for information about products, store policies, FAQs, categories, shipping, returns, and anything else the customer asks about. Use this tool when the customer asks broad or general questions, or when you need background information to answer their question. This searches across all store content semantically.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'query' => [
                        'type' => 'string',
                        'description' => 'The search query describing what information you need (e.g. "anniversary gift ideas", "return policy", "gold rings under 500", "store hours")',
                    ],
                    'content_type' => [
                        'type' => 'string',
                        'description' => 'Optional filter to search only a specific type of content',
                        'enum' => ['product', 'knowledge_base', 'category', 'store_info'],
                    ],
                ],
                'required' => ['query'],
            ],
        ];
    }

    public function execute(array $params, int $storeId): array
    {
        $query = $params['query'] ?? '';
        $contentType = $params['content_type'] ?? null;

        if (! $query) {
            return ['error' => 'A search query is required'];
        }

        try {
            $vector = $this->embedding->embed($query);
        } catch (\Throwable) {
            return [
                'found' => false,
                'message' => 'Knowledge search is temporarily unavailable.',
                'results' => [],
            ];
        }

        if (! $vector) {
            return [
                'found' => false,
                'message' => 'Unable to process search query.',
                'results' => [],
            ];
        }

        $filter = [
            'must' => [
                ['key' => 'store_id', 'match' => ['value' => $storeId]],
            ],
        ];

        if ($contentType) {
            $filter['must'][] = ['key' => 'content_type', 'match' => ['value' => $contentType]];
        }

        try {
            $results = $this->qdrant->search($vector, $filter, limit: 8);
        } catch (\Throwable) {
            return [
                'found' => false,
                'message' => 'Knowledge search is temporarily unavailable.',
                'results' => [],
            ];
        }

        if (empty($results)) {
            return [
                'found' => false,
                'message' => 'No relevant information found.',
                'results' => [],
            ];
        }

        $formatted = array_map(function (array $result) {
            $payload = $result['payload'] ?? [];

            return [
                'content_type' => $payload['content_type'] ?? 'unknown',
                'title' => $payload['title'] ?? '',
                'content' => $payload['content'] ?? $payload['text'] ?? '',
                'relevance_score' => round($result['score'] ?? 0, 3),
                'metadata' => $payload['metadata'] ?? [],
            ];
        }, $results);

        return [
            'found' => true,
            'count' => count($formatted),
            'results' => $formatted,
        ];
    }
}
