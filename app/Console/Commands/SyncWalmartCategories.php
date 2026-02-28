<?php

namespace App\Console\Commands;

use App\Enums\Platform;
use App\Models\StoreMarketplace;
use App\Models\WalmartCategory;
use App\Services\Platforms\Walmart\WalmartService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class SyncWalmartCategories extends Command
{
    protected $signature = 'walmart:sync-categories
                            {--marketplace-id= : Specific store marketplace ID to use}';

    protected $description = 'Sync Walmart category taxonomy into the local database';

    public function handle(WalmartService $walmartService): int
    {
        $marketplace = $this->resolveMarketplace();

        try {
            if ($marketplace) {
                $this->info("Using marketplace: {$marketplace->name} (ID: {$marketplace->id})");
                $walmartService->ensureValidToken($marketplace);

                $this->info('Fetching Walmart taxonomy...');
                $response = $walmartService->walmartRequest(
                    $marketplace,
                    'GET',
                    '/v3/utilities/taxonomy',
                    ['feedType' => 'MP_ITEM', 'version' => '4.2']
                );
            } else {
                $this->info('No connected marketplace found. Using .env credentials...');
                $response = $this->fetchTaxonomyFromEnv();
            }

            $taxonomyNodes = $response['payload'] ?? $response['categories'] ?? $response ?? [];

            if (empty($taxonomyNodes)) {
                $this->warn('No categories returned from Walmart API.');

                return 1;
            }

            $this->info('Truncating existing walmart_categories...');
            WalmartCategory::truncate();

            $count = 0;
            $this->info('Importing categories...');
            $this->importCategories($taxonomyNodes, null, null, 0, $count);

            $this->info("Imported {$count} Walmart categories.");

            return 0;
        } catch (\Throwable $e) {
            $this->error("Failed: {$e->getMessage()}");

            return 1;
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $nodes
     */
    protected function importCategories(array $nodes, ?int $parentId, ?string $walmartParentId, int $level, int &$count): void
    {
        foreach ($nodes as $node) {
            $categoryId = (string) ($node['category'] ?? $node['categoryId'] ?? $node['id'] ?? '');
            $name = $node['categoryName'] ?? $node['name'] ?? $categoryId;

            $category = WalmartCategory::create([
                'name' => $name,
                'walmart_category_id' => $categoryId,
                'level' => $level,
                'parent_id' => $parentId,
                'walmart_parent_id' => $walmartParentId,
                'path' => null,
            ]);

            $count++;

            // Build and store the path
            $pathParts = [];
            $ancestor = $category->parent;
            while ($ancestor) {
                array_unshift($pathParts, $ancestor->name);
                $ancestor = $ancestor->parent;
            }
            $pathParts[] = $name;
            $category->update(['path' => implode(' > ', $pathParts)]);

            $children = $node['children'] ?? $node['subcategories'] ?? [];
            if (! empty($children)) {
                $this->importCategories($children, $category->id, $categoryId, $level + 1, $count);
            }
        }
    }

    protected function resolveMarketplace(): ?StoreMarketplace
    {
        if ($id = $this->option('marketplace-id')) {
            return StoreMarketplace::find($id);
        }

        return StoreMarketplace::where('platform', Platform::Walmart)
            ->connected()
            ->whereNotNull('credentials')
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    protected function fetchTaxonomyFromEnv(): array
    {
        $clientId = config('services.walmart.client_id');
        $clientSecret = config('services.walmart.client_secret');
        $baseUrl = config('services.walmart.endpoint')
            ?? (config('services.walmart.sandbox')
                ? 'https://sandbox.walmartapis.com'
                : 'https://marketplace.walmartapis.com');

        if (! $clientId || ! $clientSecret) {
            throw new \RuntimeException(
                'No Walmart credentials found. Set WALMART_CLIENT_ID and WALMART_CLIENT_SECRET in .env, or connect a Walmart marketplace.'
            );
        }

        // Get access token
        $tokenResponse = Http::withHeaders([
            'WM_SVC.NAME' => 'Walmart Marketplace',
            'WM_QOS.CORRELATION_ID' => uniqid(),
            'Accept' => 'application/json',
        ])->withBasicAuth($clientId, $clientSecret)
            ->asForm()
            ->post("{$baseUrl}/v3/token", [
                'grant_type' => 'client_credentials',
            ]);

        if ($tokenResponse->failed()) {
            throw new \RuntimeException('Failed to authenticate with Walmart: '.$tokenResponse->body());
        }

        $accessToken = $tokenResponse->json('access_token');

        // Fetch taxonomy
        $response = Http::withHeaders([
            'WM_SEC.ACCESS_TOKEN' => $accessToken,
            'WM_SVC.NAME' => 'Walmart Marketplace',
            'WM_QOS.CORRELATION_ID' => uniqid(),
            'Accept' => 'application/json',
        ])->get("{$baseUrl}/v3/utilities/taxonomy", [
            'feedType' => 'MP_ITEM',
            'version' => '4.2',
        ]);

        if ($response->failed()) {
            throw new \RuntimeException('Failed to fetch taxonomy: '.$response->body());
        }

        return $response->json() ?? [];
    }
}
