<?php

namespace App\Services\Chat\Tools;

use App\Models\MetalPrice;

class MetalCalculatorTool implements ChatToolInterface
{
    public function name(): string
    {
        return 'calculate_metal_value';
    }

    public function definition(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'Quick metal value calculator. Use when user asks "what is X grams of gold worth", "calculate value of silver", "how much is this gold worth", or any metal value question. Instantly calculates spot value.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'metal_type' => [
                        'type' => 'string',
                        'enum' => ['gold_10k', 'gold_14k', 'gold_18k', 'gold_22k', 'gold_24k', 'silver', 'platinum', 'palladium'],
                        'description' => 'Type of precious metal with purity',
                    ],
                    'weight' => [
                        'type' => 'number',
                        'description' => 'Weight amount',
                    ],
                    'unit' => [
                        'type' => 'string',
                        'enum' => ['grams', 'dwt', 'oz', 'pennyweight', 'ounces'],
                        'description' => 'Weight unit (default grams)',
                    ],
                    'payout_percent' => [
                        'type' => 'number',
                        'description' => 'Payout percentage for buy offer (e.g., 60 for 60%)',
                    ],
                ],
                'required' => ['metal_type', 'weight'],
            ],
        ];
    }

    public function execute(array $params, int $storeId): array
    {
        $metalType = $params['metal_type'];
        $weight = $params['weight'];
        $unit = $params['unit'] ?? 'grams';
        $payoutPercent = $params['payout_percent'] ?? null;

        // Convert to grams
        $weightGrams = $this->convertToGrams($weight, $unit);

        // Get purity ratio
        $purityRatios = MetalPrice::PURITY_RATIOS;
        $purityRatio = $purityRatios[$metalType] ?? 1.0;

        // Get base metal type
        $baseMetal = str_starts_with($metalType, 'gold') ? 'gold' : $metalType;

        $price = MetalPrice::getLatest($baseMetal);

        if (! $price) {
            return [
                'error' => "No price data available for {$baseMetal}",
                'suggestion' => 'Metal prices may need to be updated',
            ];
        }

        // Calculate values
        $pureMetalGrams = $weightGrams * $purityRatio;
        $spotValue = $pureMetalGrams * (float) $price->price_per_gram;

        $result = [
            'input' => [
                'metal' => $this->formatMetalName($metalType),
                'weight' => $weight,
                'unit' => $unit,
                'weight_in_grams' => round($weightGrams, 2),
                'weight_in_dwt' => round($weightGrams / 1.555, 2),
            ],
            'calculation' => [
                'purity' => ($purityRatio * 100).'%',
                'pure_metal_content' => round($pureMetalGrams, 2).'g',
                'spot_price_per_gram' => '$'.number_format((float) $price->price_per_gram, 2),
                'spot_price_per_oz' => '$'.number_format((float) $price->price_per_ounce, 2),
            ],
            'spot_value' => round($spotValue, 2),
            'spot_value_formatted' => '$'.number_format($spotValue, 2),
            'price_updated' => $price->effective_at->diffForHumans(),
        ];

        // Calculate payout offers at different percentages
        $result['payout_options'] = [
            '50%' => '$'.number_format($spotValue * 0.50, 0),
            '55%' => '$'.number_format($spotValue * 0.55, 0),
            '60%' => '$'.number_format($spotValue * 0.60, 0),
            '65%' => '$'.number_format($spotValue * 0.65, 0),
            '70%' => '$'.number_format($spotValue * 0.70, 0),
        ];

        // If specific payout requested
        if ($payoutPercent) {
            $payoutAmount = $spotValue * ($payoutPercent / 100);
            $result['requested_payout'] = [
                'percent' => $payoutPercent.'%',
                'amount' => round($payoutAmount, 2),
                'amount_formatted' => '$'.number_format($payoutAmount, 0),
            ];
        }

        // Verbal summary for voice
        $result['summary'] = sprintf(
            '%s of %s is worth %s at spot. At 60%% payout, offer %s.',
            $weight.' '.$unit,
            $this->formatMetalName($metalType),
            '$'.number_format($spotValue, 0),
            '$'.number_format($spotValue * 0.60, 0)
        );

        return $result;
    }

    protected function convertToGrams(float $weight, string $unit): float
    {
        return match (strtolower($unit)) {
            'dwt', 'pennyweight' => $weight * 1.555,
            'oz', 'ounces' => $weight * 31.1035,
            default => $weight, // grams
        };
    }

    protected function formatMetalName(string $metalType): string
    {
        return match ($metalType) {
            'gold_10k' => '10K gold',
            'gold_14k' => '14K gold',
            'gold_18k' => '18K gold',
            'gold_22k' => '22K gold',
            'gold_24k' => '24K gold',
            'silver' => 'sterling silver',
            'platinum' => 'platinum',
            'palladium' => 'palladium',
            default => $metalType,
        };
    }
}
