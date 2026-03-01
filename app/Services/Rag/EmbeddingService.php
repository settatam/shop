<?php

namespace App\Services\Rag;

use Illuminate\Support\Facades\Http;

class EmbeddingService
{
    protected string $apiKey;

    protected string $model;

    public function __construct()
    {
        $this->apiKey = config('services.openai.api_key', '');
        $this->model = config('services.openai.embedding_model', 'text-embedding-3-small');
    }

    /**
     * Generate an embedding vector for a single text.
     *
     * @return array<int, float>|null
     */
    public function embed(string $text): ?array
    {
        $result = $this->embedBatch([$text]);

        return $result[0] ?? null;
    }

    /**
     * Generate embedding vectors for multiple texts.
     *
     * @param  array<int, string>  $texts
     * @return array<int, array<int, float>>
     */
    public function embedBatch(array $texts): array
    {
        if (empty($texts)) {
            return [];
        }

        // OpenAI has a limit of 2048 texts per request
        $chunks = array_chunk($texts, 2048);
        $allEmbeddings = [];

        foreach ($chunks as $chunk) {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$this->apiKey}",
                'Content-Type' => 'application/json',
            ])->timeout(30)->post('https://api.openai.com/v1/embeddings', [
                'model' => $this->model,
                'input' => $chunk,
            ]);

            if ($response->failed()) {
                throw new \RuntimeException(
                    "OpenAI embedding request failed: {$response->status()} {$response->body()}"
                );
            }

            $data = $response->json('data', []);

            foreach ($data as $item) {
                $allEmbeddings[] = $item['embedding'];
            }
        }

        return $allEmbeddings;
    }

    /**
     * Get the vector dimension size for the configured model.
     */
    public function dimensions(): int
    {
        return match ($this->model) {
            'text-embedding-3-small' => 1536,
            'text-embedding-3-large' => 3072,
            'text-embedding-ada-002' => 1536,
            default => 1536,
        };
    }
}
