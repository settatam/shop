<?php

namespace App\Jobs;

use App\Enums\Platform;
use App\Models\CategoryPlatformMapping;
use App\Models\EbayItemSpecific;
use App\Models\EbayItemSpecificValue;
use App\Services\Platforms\Ebay\EbayService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Fetches item specifics from the platform's taxonomy API for the mapped category
 * and stores them locally. Currently supports eBay via the Taxonomy API.
 */
class SyncPlatformCategoryItemSpecificsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public int $backoff = 15;

    public function __construct(
        public CategoryPlatformMapping $mapping
    ) {
        $this->onQueue('default');
    }

    public function handle(EbayService $ebayService): void
    {
        try {
            $platform = $this->mapping->platform;

            match ($platform) {
                Platform::Ebay => $this->syncEbayItemSpecifics($ebayService),
                default => Log::info("SyncPlatformCategoryItemSpecificsJob: No sync handler for platform {$platform->value}"),
            };

            $this->mapping->update(['item_specifics_synced_at' => now()]);

            Log::info("SyncPlatformCategoryItemSpecificsJob: Synced item specifics for mapping {$this->mapping->id}");
        } catch (\Throwable $e) {
            Log::error("SyncPlatformCategoryItemSpecificsJob: Failed for mapping {$this->mapping->id}: {$e->getMessage()}");
            throw $e;
        }
    }

    /**
     * Sync eBay item specifics for the mapped category.
     * Uses the locally-stored eBay categories/item specifics if available,
     * otherwise fetches from the eBay Taxonomy API.
     */
    protected function syncEbayItemSpecifics(EbayService $ebayService): void
    {
        $ebayCategoryId = $this->mapping->primary_category_id;

        // Check if we already have item specifics for this eBay category
        $existingCount = EbayItemSpecific::where('ebay_category_id', $ebayCategoryId)->count();

        if ($existingCount > 0) {
            Log::info("SyncPlatformCategoryItemSpecificsJob: eBay category {$ebayCategoryId} already has {$existingCount} item specifics");

            return;
        }

        // Fetch from eBay API via the marketplace connection
        $marketplace = $this->mapping->storeMarketplace;
        if (! $marketplace) {
            Log::warning("SyncPlatformCategoryItemSpecificsJob: No marketplace connection for mapping {$this->mapping->id}");

            return;
        }

        try {
            $response = $this->fetchItemSpecificsFromApi($ebayService, $marketplace, $ebayCategoryId);

            if (! empty($response['aspects'])) {
                $this->storeItemSpecifics($ebayCategoryId, $response['aspects']);
            }
        } catch (\Throwable $e) {
            Log::warning("SyncPlatformCategoryItemSpecificsJob: API fetch failed for category {$ebayCategoryId}: {$e->getMessage()}");
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function fetchItemSpecificsFromApi(
        EbayService $ebayService,
        \App\Models\StoreMarketplace $marketplace,
        string $categoryId
    ): array {
        // Use the eBay Taxonomy API to get item aspects
        $marketplaceId = $marketplace->settings['marketplace_id'] ?? 'EBAY_US';
        $categoryTreeId = $this->getCategoryTreeId($marketplaceId);

        $response = \Illuminate\Support\Facades\Http::withHeaders([
            'Authorization' => 'Bearer '.$marketplace->access_token,
            'Content-Type' => 'application/json',
        ])->get("{$this->getApiBaseUrl()}/commerce/taxonomy/v1/category_tree/{$categoryTreeId}/get_item_aspects_for_category", [
            'category_id' => $categoryId,
        ]);

        if ($response->failed()) {
            throw new \Exception("eBay Taxonomy API error: {$response->body()}");
        }

        return $response->json() ?? [];
    }

    /**
     * Store fetched item specifics in the database.
     *
     * @param  array<int, array<string, mixed>>  $aspects
     */
    protected function storeItemSpecifics(string $ebayCategoryId, array $aspects): void
    {
        foreach ($aspects as $aspect) {
            $aspectName = $aspect['localizedAspectName'] ?? '';
            if (! $aspectName) {
                continue;
            }

            $constraint = $aspect['aspectConstraint'] ?? [];
            $isRequired = ($constraint['aspectRequired'] ?? false) === true;

            $specific = EbayItemSpecific::updateOrCreate(
                [
                    'ebay_category_id' => $ebayCategoryId,
                    'name' => $aspectName,
                ],
                [
                    'type' => $constraint['itemToAspectCardinality'] ?? 'SINGLE',
                    'is_required' => $isRequired,
                    'is_recommended' => ! $isRequired,
                    'aspect_mode' => $constraint['aspectMode'] ?? 'FREE_TEXT',
                ]
            );

            // Store aspect values
            $aspectValues = $aspect['aspectValues'] ?? [];
            foreach ($aspectValues as $valueData) {
                $value = $valueData['localizedValue'] ?? '';
                if ($value) {
                    EbayItemSpecificValue::firstOrCreate([
                        'ebay_category_id' => $ebayCategoryId,
                        'ebay_item_specific_id' => $specific->id,
                        'value' => $value,
                    ]);
                }
            }
        }
    }

    protected function getCategoryTreeId(string $marketplaceId): string
    {
        return match ($marketplaceId) {
            'EBAY_US' => '0',
            'EBAY_GB' => '3',
            'EBAY_DE' => '77',
            'EBAY_AU' => '15',
            'EBAY_CA' => '2',
            'EBAY_FR' => '71',
            'EBAY_IT' => '101',
            'EBAY_ES' => '186',
            default => '0',
        };
    }

    protected function getApiBaseUrl(): string
    {
        return config('services.ebay.sandbox')
            ? 'https://api.sandbox.ebay.com'
            : 'https://api.ebay.com';
    }
}
