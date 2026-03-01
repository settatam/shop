<?php

namespace App\Services\Rag;

use Illuminate\Support\Facades\Http;

class QdrantService
{
    protected string $url;

    protected string $collection;

    protected ?string $apiKey;

    public function __construct()
    {
        $this->url = rtrim(config('services.qdrant.url', 'http://127.0.0.1:6333'), '/');
        $this->collection = config('services.qdrant.collection', 'shopmata_store_content');
        $this->apiKey = config('services.qdrant.api_key');
    }

    /**
     * Ensure the collection exists, creating it if necessary.
     */
    public function ensureCollection(int $vectorSize = 1536): void
    {
        $response = $this->request('GET', "/collections/{$this->collection}");

        if ($response->status() === 404) {
            $this->request('PUT', "/collections/{$this->collection}", [
                'vectors' => [
                    'size' => $vectorSize,
                    'distance' => 'Cosine',
                ],
            ]);

            // Create payload indexes for filtering
            $this->request('PUT', "/collections/{$this->collection}/index", [
                'field_name' => 'store_id',
                'field_schema' => 'integer',
            ]);

            $this->request('PUT', "/collections/{$this->collection}/index", [
                'field_name' => 'content_type',
                'field_schema' => 'keyword',
            ]);
        }
    }

    /**
     * Upsert a single point into the collection.
     *
     * @param  array<int, float>  $vector
     * @param  array<string, mixed>  $payload
     */
    public function upsert(string $pointId, array $vector, array $payload): void
    {
        $this->upsertBatch([
            [
                'id' => $pointId,
                'vector' => $vector,
                'payload' => $payload,
            ],
        ]);
    }

    /**
     * Upsert multiple points into the collection.
     *
     * @param  array<int, array{id: string, vector: array<int, float>, payload: array<string, mixed>}>  $points
     */
    public function upsertBatch(array $points): void
    {
        if (empty($points)) {
            return;
        }

        // Qdrant limits batch size
        $chunks = array_chunk($points, 100);

        foreach ($chunks as $chunk) {
            $response = $this->request('PUT', "/collections/{$this->collection}/points", [
                'points' => $chunk,
            ]);

            if ($response->failed()) {
                throw new \RuntimeException(
                    "Qdrant upsert failed: {$response->status()} {$response->body()}"
                );
            }
        }
    }

    /**
     * Delete points by their IDs.
     *
     * @param  array<int, string>  $pointIds
     */
    public function delete(array $pointIds): void
    {
        if (empty($pointIds)) {
            return;
        }

        $this->request('POST', "/collections/{$this->collection}/points/delete", [
            'points' => $pointIds,
        ]);
    }

    /**
     * Delete all points matching a filter.
     *
     * @param  array<string, mixed>  $filter
     */
    public function deleteByFilter(array $filter): void
    {
        $this->request('POST', "/collections/{$this->collection}/points/delete", [
            'filter' => $filter,
        ]);
    }

    /**
     * Search for similar vectors with optional filtering.
     *
     * @param  array<int, float>  $vector
     * @param  array<string, mixed>  $filter
     * @return array<int, array<string, mixed>>
     */
    public function search(array $vector, array $filter = [], int $limit = 5, float $scoreThreshold = 0.3): array
    {
        $body = [
            'vector' => $vector,
            'limit' => $limit,
            'with_payload' => true,
            'score_threshold' => $scoreThreshold,
        ];

        if (! empty($filter)) {
            $body['filter'] = $filter;
        }

        $response = $this->request('POST', "/collections/{$this->collection}/points/search", $body);

        if ($response->failed()) {
            throw new \RuntimeException(
                "Qdrant search failed: {$response->status()} {$response->body()}"
            );
        }

        return $response->json('result', []);
    }

    /**
     * Get collection info (point count, etc.).
     *
     * @return array<string, mixed>
     */
    public function collectionInfo(): array
    {
        $response = $this->request('GET', "/collections/{$this->collection}");

        return $response->json('result', []);
    }

    /**
     * Send an HTTP request to Qdrant.
     */
    protected function request(string $method, string $path, ?array $body = null): \Illuminate\Http\Client\Response
    {
        $http = Http::baseUrl($this->url)->timeout(30);

        if ($this->apiKey) {
            $http = $http->withHeaders(['api-key' => $this->apiKey]);
        }

        return match (strtoupper($method)) {
            'GET' => $http->get($path),
            'PUT' => $http->put($path, $body ?? []),
            'POST' => $http->post($path, $body ?? []),
            'DELETE' => $http->delete($path, $body ?? []),
            default => throw new \InvalidArgumentException("Unsupported HTTP method: {$method}"),
        };
    }
}
