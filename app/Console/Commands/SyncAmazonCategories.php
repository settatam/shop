<?php

namespace App\Console\Commands;

use App\Enums\Platform;
use App\Models\AmazonCategory;
use App\Models\StoreMarketplace;
use App\Services\Platforms\Amazon\AmazonService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class SyncAmazonCategories extends Command
{
    protected $signature = 'amazon:sync-categories
                            {--marketplace-id= : Specific store marketplace ID to use}';

    protected $description = 'Sync Amazon product type definitions into the local database';

    public function handle(AmazonService $amazonService): int
    {
        $marketplace = $this->resolveMarketplace();

        try {
            if ($marketplace) {
                $this->info("Using marketplace: {$marketplace->name} (ID: {$marketplace->id})");
                $amazonService->ensureValidToken($marketplace);

                $this->info('Fetching Amazon product type definitions...');
                $response = $amazonService->amazonRequest(
                    $marketplace,
                    'GET',
                    '/definitions/2020-09-01/productTypes',
                    ['marketplaceIds' => $marketplace->credentials['marketplace_ids'][0] ?? 'ATVPDKIKX0DER']
                );
            } else {
                $this->info('No connected marketplace found. Using .env credentials...');
                $response = $this->fetchProductTypesFromEnv();
            }

            $productTypes = $response['productTypes'] ?? $response['payload'] ?? $response ?? [];

            if (empty($productTypes)) {
                $this->warn('No product types returned from Amazon API.');

                return 1;
            }

            $this->info('Truncating existing amazon_categories...');
            AmazonCategory::truncate();

            $count = 0;
            $this->info('Importing product types...');
            $this->importProductTypes($productTypes, $count);

            $this->info("Imported {$count} Amazon product types.");

            return 0;
        } catch (\Throwable $e) {
            $this->error("Failed: {$e->getMessage()}");

            return 1;
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $productTypes
     */
    protected function importProductTypes(array $productTypes, int &$count): void
    {
        foreach ($productTypes as $type) {
            $name = $type['displayName'] ?? $type['name'] ?? '';
            $productTypeId = $type['name'] ?? $type['productType'] ?? '';

            AmazonCategory::create([
                'name' => $name,
                'amazon_category_id' => $productTypeId,
                'level' => 0,
                'parent_id' => null,
                'amazon_parent_id' => null,
                'path' => $name,
            ]);

            $count++;
        }
    }

    protected function resolveMarketplace(): ?StoreMarketplace
    {
        if ($id = $this->option('marketplace-id')) {
            return StoreMarketplace::find($id);
        }

        return StoreMarketplace::where('platform', Platform::Amazon)
            ->connected()
            ->whereNotNull('credentials')
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    protected function fetchProductTypesFromEnv(): array
    {
        $clientId = config('services.amazon.client_id');
        $clientSecret = config('services.amazon.client_secret');

        if (! $clientId || ! $clientSecret) {
            throw new \RuntimeException(
                'No Amazon credentials found. Set AMAZON_CLIENT_ID and AMAZON_CLIENT_SECRET in .env, or connect an Amazon marketplace.'
            );
        }

        $baseUrl = config('services.amazon.sandbox')
            ? 'https://sellingpartnerapi-na.amazon.com'
            : 'https://sellingpartnerapi-na.amazon.com';

        // Get access token via LWA
        $tokenResponse = Http::asForm()->post('https://api.amazon.com/auth/o2/token', [
            'grant_type' => 'client_credentials',
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'scope' => 'sellingpartnerapi::migration',
        ]);

        if ($tokenResponse->failed()) {
            throw new \RuntimeException('Failed to authenticate with Amazon: '.$tokenResponse->body());
        }

        $accessToken = $tokenResponse->json('access_token');

        // Fetch product type definitions
        $response = Http::withHeaders([
            'x-amz-access-token' => $accessToken,
            'Content-Type' => 'application/json',
        ])->get("{$baseUrl}/definitions/2020-09-01/productTypes", [
            'marketplaceIds' => 'ATVPDKIKX0DER',
        ]);

        if ($response->failed()) {
            throw new \RuntimeException('Failed to fetch product types: '.$response->body());
        }

        return $response->json() ?? [];
    }
}
