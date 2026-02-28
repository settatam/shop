<?php

namespace App\Console\Commands;

use App\Enums\Platform;
use App\Models\EtsyCategory;
use App\Models\StoreMarketplace;
use App\Services\Platforms\Etsy\EtsyService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class SyncEtsyCategories extends Command
{
    protected $signature = 'etsy:sync-categories
                            {--marketplace-id= : Specific store marketplace ID to use}';

    protected $description = 'Sync Etsy seller taxonomy into the local database';

    public function handle(EtsyService $etsyService): int
    {
        $marketplace = $this->resolveMarketplace();

        try {
            if ($marketplace) {
                $this->info("Using marketplace: {$marketplace->name} (ID: {$marketplace->id})");
                $etsyService->ensureValidToken($marketplace);

                $this->info('Fetching Etsy taxonomy...');
                $response = $etsyService->etsyRequest(
                    $marketplace,
                    'GET',
                    '/application/seller-taxonomy/nodes'
                );
            } else {
                $this->info('No connected marketplace found. Using .env credentials...');
                $response = $this->fetchTaxonomyFromEnv();
            }

            $taxonomyNodes = $response['results'] ?? $response ?? [];

            if (empty($taxonomyNodes)) {
                $this->warn('No categories returned from Etsy API.');

                return 1;
            }

            $this->info('Truncating existing etsy_categories...');
            EtsyCategory::truncate();

            $count = 0;
            $this->info('Importing categories...');
            $this->importCategories($taxonomyNodes, null, null, 0, $count);

            $this->info("Imported {$count} Etsy categories.");

            return 0;
        } catch (\Throwable $e) {
            $this->error("Failed: {$e->getMessage()}");

            return 1;
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $nodes
     */
    protected function importCategories(array $nodes, ?int $parentId, ?string $etsyParentId, int $level, int &$count): void
    {
        foreach ($nodes as $node) {
            $etsyId = (string) ($node['id'] ?? '');
            $name = $node['name'] ?? $etsyId;

            $category = EtsyCategory::create([
                'name' => $name,
                'etsy_id' => $etsyId,
                'level' => $level,
                'parent_id' => $parentId,
                'etsy_parent_id' => $etsyParentId,
            ]);

            $count++;

            $children = $node['children'] ?? [];
            if (! empty($children)) {
                $this->importCategories($children, $category->id, $etsyId, $level + 1, $count);
            }
        }
    }

    protected function resolveMarketplace(): ?StoreMarketplace
    {
        if ($id = $this->option('marketplace-id')) {
            return StoreMarketplace::find($id);
        }

        return StoreMarketplace::where('platform', Platform::Etsy)
            ->connected()
            ->whereNotNull('credentials')
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    protected function fetchTaxonomyFromEnv(): array
    {
        $keystring = config('services.etsy.keystring');

        if (! $keystring) {
            throw new \RuntimeException(
                'No Etsy credentials found. Set ETSY_CLIENT_ID in .env, or connect an Etsy marketplace.'
            );
        }

        $response = Http::withHeaders([
            'x-api-key' => $keystring,
        ])->get('https://openapi.etsy.com/v3/application/seller-taxonomy/nodes');

        if ($response->failed()) {
            throw new \RuntimeException('Failed to fetch taxonomy: '.$response->body());
        }

        return $response->json() ?? [];
    }
}
