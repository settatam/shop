<?php

namespace App\Services\Platforms\Ebay;

use App\Models\EbayItemSpecific;
use App\Models\EbayItemSpecificValue;
use App\Models\StoreMarketplace;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class EbayItemSpecificsService
{
    /**
     * Ensure item specifics exist locally for the given eBay category.
     * If they don't exist, fetches them from the eBay Taxonomy API.
     */
    public function ensureItemSpecificsExist(string $ebayCategoryId, StoreMarketplace $marketplace): void
    {
        $existingCount = EbayItemSpecific::where('ebay_category_id', $ebayCategoryId)->count();

        if ($existingCount > 0) {
            return;
        }

        try {
            $response = $this->fetchItemSpecificsFromApi($marketplace, $ebayCategoryId);

            if (! empty($response['aspects'])) {
                $this->storeItemSpecifics($ebayCategoryId, $response['aspects']);
            }
        } catch (\Throwable $e) {
            Log::warning("EbayItemSpecificsService: API fetch failed for category {$ebayCategoryId}: {$e->getMessage()}");

            throw $e;
        }
    }

    /**
     * Get item specifics for the given eBay category.
     *
     * @return Collection<int, EbayItemSpecific>
     */
    public function getItemSpecifics(string $ebayCategoryId): Collection
    {
        return EbayItemSpecific::where('ebay_category_id', $ebayCategoryId)
            ->with('values')
            ->orderByDesc('is_required')
            ->orderByDesc('is_recommended')
            ->orderBy('name')
            ->get();
    }

    /**
     * Fetch item specifics from the eBay Taxonomy API.
     *
     * @return array<string, mixed>
     */
    public function fetchItemSpecificsFromApi(StoreMarketplace $marketplace, string $categoryId): array
    {
        $marketplaceId = $marketplace->settings['marketplace_id'] ?? 'EBAY_US';
        $categoryTreeId = $this->getCategoryTreeId($marketplaceId);

        $response = Http::withHeaders([
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
    public function storeItemSpecifics(string $ebayCategoryId, array $aspects): void
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

    public function getCategoryTreeId(string $marketplaceId): string
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
