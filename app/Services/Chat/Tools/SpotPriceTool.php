<?php

namespace App\Services\Chat\Tools;

use App\Models\MetalPrice;

class SpotPriceTool implements ChatToolInterface
{
    public function name(): string
    {
        return 'get_spot_prices';
    }

    public function definition(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'Get current spot prices for precious metals (gold, silver, platinum, palladium). Use this when users ask about metal prices, what gold is worth, or current market values for precious metals.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'metals' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'string',
                            'enum' => ['gold', 'silver', 'platinum', 'palladium'],
                        ],
                        'description' => 'Which metals to get prices for. Leave empty for all metals.',
                    ],
                ],
                'required' => [],
            ],
        ];
    }

    public function execute(array $params, int $storeId): array
    {
        $requestedMetals = $params['metals'] ?? [];

        $query = MetalPrice::query()->orderBy('effective_at', 'desc');

        if (! empty($requestedMetals)) {
            $query->whereIn('metal_type', $requestedMetals);
        }

        // Get the latest price for each metal
        $prices = $query->get()
            ->groupBy('metal_type')
            ->map(fn ($group) => $group->first())
            ->values();

        if ($prices->isEmpty()) {
            return [
                'message' => 'No spot price data available. Prices may need to be fetched.',
                'prices' => [],
            ];
        }

        $result = [
            'prices' => $prices->map(function ($price) {
                return [
                    'metal' => ucfirst($price->metal_type),
                    'purity' => $price->purity,
                    'price_per_oz' => round((float) $price->price_per_ounce, 2),
                    'price_per_oz_formatted' => '$'.number_format((float) $price->price_per_ounce, 2),
                    'price_per_gram' => round((float) $price->price_per_gram, 2),
                    'price_per_gram_formatted' => '$'.number_format((float) $price->price_per_gram, 2),
                    'price_per_dwt' => round((float) $price->price_per_dwt, 2),
                    'price_per_dwt_formatted' => '$'.number_format((float) $price->price_per_dwt, 2),
                    'last_updated' => $price->effective_at->diffForHumans(),
                ];
            })->toArray(),
            'updated_at' => $prices->first()?->effective_at?->format('Y-m-d H:i:s'),
        ];

        return $result;
    }
}
