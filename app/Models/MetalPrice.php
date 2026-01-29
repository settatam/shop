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
     *
     * @var array<string, float>
     */
    public const PURITY_RATIOS = [
        'gold_10k' => 0.4167,
        'gold_14k' => 0.5833,
        'gold_18k' => 0.75,
        'gold_22k' => 0.9167,
        'gold_24k' => 0.999,
        'silver' => 0.925,
        'platinum' => 0.95,
        'palladium' => 0.999,
    ];

    /**
     * Calculate the spot price for a given precious metal and weight in DWT.
     */
    public static function calcSpotPrice(string $preciousMetal, float $dwt, int $qty = 1): ?float
    {
        $purityRatio = self::PURITY_RATIOS[$preciousMetal] ?? null;

        if ($purityRatio === null) {
            return null;
        }

        // Determine the base metal type from the precious metal key
        $metalType = match (true) {
            str_starts_with($preciousMetal, 'gold') => 'gold',
            default => $preciousMetal,
        };

        $price = self::getLatest($metalType);

        if (! $price || ! $price->price_per_dwt) {
            return null;
        }

        return round((float) $price->price_per_dwt * $purityRatio * $dwt * $qty, 2);
    }

    public function scopeLatest($query)
    {
        return $query->orderByDesc('effective_at');
    }
}
