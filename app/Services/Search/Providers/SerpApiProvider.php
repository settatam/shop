<?php

namespace App\Services\Search\Providers;

use App\Models\StoreIntegration;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SerpApiProvider
{
    protected string $baseUrl = 'https://serpapi.com/search';

    protected ?StoreIntegration $integration = null;

    public function setIntegration(StoreIntegration $integration): self
    {
        $this->integration = $integration;

        return $this;
    }

    public function isConfigured(): bool
    {
        return $this->integration !== null && $this->integration->getSerpApiKey() !== null;
    }

    /**
     * Search Google Shopping for products.
     *
     * @return array<string, mixed>
     */
    public function searchGoogleShopping(string $query, int $limit = 10): array
    {
        if (! $this->isConfigured()) {
            return ['error' => 'SerpAPI not configured'];
        }

        try {
            $response = Http::timeout(30)->get($this->baseUrl, [
                'api_key' => $this->integration->getSerpApiKey(),
                'engine' => 'google_shopping',
                'q' => $query,
                'num' => $limit,
                'gl' => 'us',
                'hl' => 'en',
            ]);

            if ($response->failed()) {
                Log::warning('SerpAPI Google Shopping request failed', [
                    'status' => $response->status(),
                    'query' => $query,
                ]);

                return ['error' => 'Search request failed'];
            }

            $this->integration->recordUsage();

            return $response->json();
        } catch (\Exception $e) {
            Log::error('SerpAPI Google Shopping error', [
                'error' => $e->getMessage(),
                'query' => $query,
            ]);

            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Search eBay for sold listings.
     *
     * @return array<string, mixed>
     */
    public function searchEbaySold(string $query, int $limit = 10): array
    {
        if (! $this->isConfigured()) {
            return ['error' => 'SerpAPI not configured'];
        }

        try {
            $response = Http::timeout(30)->get($this->baseUrl, [
                'api_key' => $this->integration->getSerpApiKey(),
                'engine' => 'ebay',
                '_nkw' => $query,
                'LH_Complete' => 1, // Completed listings
                'LH_Sold' => 1, // Sold items
                '_ipg' => $limit,
            ]);

            if ($response->failed()) {
                Log::warning('SerpAPI eBay request failed', [
                    'status' => $response->status(),
                    'query' => $query,
                ]);

                return ['error' => 'Search request failed'];
            }

            $this->integration->recordUsage();

            return $response->json();
        } catch (\Exception $e) {
            Log::error('SerpAPI eBay error', [
                'error' => $e->getMessage(),
                'query' => $query,
            ]);

            return ['error' => $e->getMessage()];
        }
    }
}
