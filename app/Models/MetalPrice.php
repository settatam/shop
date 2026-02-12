<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MetalPrice extends Model
{
    use HasFactory;

    protected $fillable = [
        'metal_type',
        'purity',
        'price_per_gram',
        'price_per_ounce',
        'price_per_dwt',
        'currency',
        'source',
        'effective_at',
    ];

    protected function casts(): array
    {
        return [
            'price_per_gram' => 'decimal:4',
            'price_per_ounce' => 'decimal:4',
            'price_per_dwt' => 'decimal:4',
            'effective_at' => 'datetime',
        ];
    }

    public static function getLatest(string $metalType, ?string $purity = null): ?self
    {
        return self::where('metal_type', $metalType)
            ->when($purity, fn ($q) => $q->where('purity', $purity))
            ->orderByDesc('effective_at')
            ->first();
    }

    public static function calculateValue(string $metalType, string $purity, float $grams): ?float
    {
        $price = self::getLatest($metalType, $purity);

        if (! $price) {
            return null;
        }

        return round($grams * $price->price_per_gram, 2);
    }

    public function scopeForMetal($query, string $metalType)
    {
        return $query->where('metal_type', $metalType);
    }

    public function scopeForPurity($query, string $purity)
    {
        return $query->where('purity', $purity);
    }

    /**
     * Purity ratios for common precious metals.
     * Keys match template field option values.
     *
     * @var array<string, float>
     */
    public const PURITY_RATIOS = [
        // Gold karats (template values)
        '10k' => 0.4167,
        '14k' => 0.5833,
        '18k' => 0.75,
        '20k' => 0.8333,
        '22k' => 0.9167,
        '24k' => 0.999,
        // Legacy format (gold_Xk)
        'gold_10k' => 0.4167,
        'gold_14k' => 0.5833,
        'gold_18k' => 0.75,
        'gold_22k' => 0.9167,
        'gold_24k' => 0.999,
        // Pure metals
        'gold' => 0.999,
        'sterling' => 0.925,
        'silver' => 0.925,
        'fine-silver' => 0.999,
        'platinum' => 0.95,
        'palladium' => 0.999,
        'rhodium' => 0.999,
    ];

    /**
     * Map precious metal values to base metal types for price lookup.
     *
     * @var array<string, string>
     */
    public const METAL_TYPE_MAP = [
        '10k' => 'gold',
        '14k' => 'gold',
        '18k' => 'gold',
        '20k' => 'gold',
        '22k' => 'gold',
        '24k' => 'gold',
        'gold_10k' => 'gold',
        'gold_14k' => 'gold',
        'gold_18k' => 'gold',
        'gold_22k' => 'gold',
        'gold_24k' => 'gold',
        'gold' => 'gold',
        'sterling' => 'silver',
        'silver' => 'silver',
        'fine-silver' => 'silver',
        'platinum' => 'platinum',
        'palladium' => 'palladium',
        'rhodium' => 'rhodium',
    ];

    /**
     * Calculate the spot price for a given precious metal and weight in DWT.
     *
     * @param  string  $preciousMetal  The precious metal type (e.g., '14k', 'sterling')
     * @param  float  $dwt  Weight in pennyweights
     * @param  int  $qty  Quantity
     * @param  Store|null  $store  Optional store to apply buy percentage markup
     * @return float|null The calculated price, or null if metal type/price not found
     */
    public static function calcSpotPrice(string $preciousMetal, float $dwt, int $qty = 1, ?Store $store = null): ?float
    {
        $purityRatio = self::PURITY_RATIOS[$preciousMetal] ?? null;

        if ($purityRatio === null) {
            return null;
        }

        // Determine the base metal type for price lookup
        $metalType = self::METAL_TYPE_MAP[$preciousMetal] ?? $preciousMetal;

        $price = self::getLatest($metalType);

        if (! $price || ! $price->price_per_dwt) {
            return null;
        }

        $spotPrice = (float) $price->price_per_dwt * $purityRatio * $dwt * $qty;

        // Apply store buy percentage if store is provided
        // If no percentage is set, we use 1.0 (100% - full spot price)
        if ($store) {
            $buyPercentage = $store->getMetalBuyPercentage($preciousMetal);
            // Only apply if a percentage is actually set (> 0)
            if ($buyPercentage > 0) {
                $spotPrice *= $buyPercentage;
            }
        }

        return round($spotPrice, 2);
    }

    public function scopeLatest($query)
    {
        return $query->orderByDesc('effective_at');
    }
}
