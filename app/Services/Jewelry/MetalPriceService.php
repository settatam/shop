<?php

namespace App\Services\Jewelry;

use App\Models\MetalPrice;
use Illuminate\Support\Facades\Http;

class MetalPriceService
{
    protected array $purityMultipliers = [
        // Gold
        '24k' => 1.000,
        '22k' => 0.916,
        '18k' => 0.750,
        '14k' => 0.585,
        '10k' => 0.417,
        // Silver
        'sterling' => 0.925,
        'fine' => 0.999,
        // Platinum
        '950' => 0.950,
        '900' => 0.900,
        '850' => 0.850,
    ];

    protected float $gramsPerTroyOunce = 31.1035;

    protected float $gramsPerPennyweight = 1.55517;

    public function fetchCurrentPrices(): array
    {
        $prices = [];

        // Fetch spot prices (you'd replace this with your actual API)
        $spotPrices = $this->fetchSpotPrices();

        foreach (['gold', 'silver', 'platinum', 'palladium'] as $metal) {
            $spotPricePerOunce = $spotPrices[$metal] ?? 0;
            $spotPricePerGram = $spotPricePerOunce / $this->gramsPerTroyOunce;

            $purities = $this->getPuritiesForMetal($metal);

            foreach ($purities as $purity => $multiplier) {
                $prices[] = MetalPrice::create([
                    'metal_type' => $metal,
                    'purity' => $purity,
                    'price_per_gram' => round($spotPricePerGram * $multiplier, 4),
                    'price_per_ounce' => round($spotPricePerOunce * $multiplier, 4),
                    'price_per_dwt' => round($spotPricePerGram * $this->gramsPerPennyweight * $multiplier, 4),
                    'currency' => 'USD',
                    'source' => 'api',
                    'effective_at' => now(),
                ]);
            }
        }

        return $prices;
    }

    public function calculateMetalValue(string $metalType, string $purity, float $weightGrams): ?float
    {
        $price = MetalPrice::getLatest($metalType, $purity);

        if (! $price) {
            // Try to calculate from spot price
            $price = MetalPrice::getLatest($metalType);
            if (! $price) {
                return null;
            }

            $multiplier = $this->purityMultipliers[$purity] ?? 1.0;

            return round($weightGrams * $price->price_per_gram * $multiplier, 2);
        }

        return round($weightGrams * $price->price_per_gram, 2);
    }

    public function calculateScrapValue(string $metalType, string $purity, float $weightGrams, float $scrapPercentage = 0.80): ?float
    {
        $fullValue = $this->calculateMetalValue($metalType, $purity, $weightGrams);

        if ($fullValue === null) {
            return null;
        }

        return round($fullValue * $scrapPercentage, 2);
    }

    public function getLatestPrices(): array
    {
        $metals = ['gold', 'silver', 'platinum', 'palladium'];
        $prices = [];

        foreach ($metals as $metal) {
            $latestPrice = MetalPrice::where('metal_type', $metal)
                ->orderByDesc('effective_at')
                ->first();

            if ($latestPrice) {
                $prices[$metal] = [
                    'price_per_gram' => $latestPrice->price_per_gram,
                    'price_per_ounce' => $latestPrice->price_per_ounce,
                    'price_per_dwt' => $latestPrice->price_per_dwt,
                    'updated_at' => $latestPrice->effective_at,
                ];
            }
        }

        return $prices;
    }

    public function convertWeight(float $value, string $from, string $to): float
    {
        // Convert everything to grams first
        $grams = match ($from) {
            'grams', 'g' => $value,
            'ounces', 'oz', 'troy_oz' => $value * $this->gramsPerTroyOunce,
            'dwt', 'pennyweight' => $value * $this->gramsPerPennyweight,
            default => $value,
        };

        // Convert from grams to target unit
        return match ($to) {
            'grams', 'g' => round($grams, 3),
            'ounces', 'oz', 'troy_oz' => round($grams / $this->gramsPerTroyOunce, 4),
            'dwt', 'pennyweight' => round($grams / $this->gramsPerPennyweight, 4),
            default => round($grams, 3),
        };
    }

    protected function fetchSpotPrices(): array
    {
        // This would typically fetch from a metals API like Kitco, GoldAPI, etc.
        // For now, return placeholder values - replace with actual API integration
        try {
            $response = Http::get(config('services.metals.api_url'), [
                'access_key' => config('services.metals.api_key'),
                'base' => 'USD',
                'symbols' => 'XAU,XAG,XPT,XPD',
            ]);

            if ($response->successful()) {
                $data = $response->json();

                return [
                    'gold' => $data['rates']['XAU'] ?? 2000.00,
                    'silver' => $data['rates']['XAG'] ?? 25.00,
                    'platinum' => $data['rates']['XPT'] ?? 1000.00,
                    'palladium' => $data['rates']['XPD'] ?? 1200.00,
                ];
            }
        } catch (\Throwable $e) {
            // Log error
        }

        // Fallback prices (would need to be updated or removed in production)
        return [
            'gold' => 2000.00,
            'silver' => 25.00,
            'platinum' => 1000.00,
            'palladium' => 1200.00,
        ];
    }

    protected function getPuritiesForMetal(string $metal): array
    {
        return match ($metal) {
            'gold' => [
                '24k' => 1.000,
                '22k' => 0.916,
                '18k' => 0.750,
                '14k' => 0.585,
                '10k' => 0.417,
            ],
            'silver' => [
                'fine' => 0.999,
                'sterling' => 0.925,
            ],
            'platinum' => [
                '950' => 0.950,
                '900' => 0.900,
                '850' => 0.850,
            ],
            'palladium' => [
                '950' => 0.950,
                '500' => 0.500,
            ],
            default => ['pure' => 1.000],
        };
    }
}
