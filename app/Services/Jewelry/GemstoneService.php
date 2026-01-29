<?php

namespace App\Services\Jewelry;

use App\Models\Certification;
use App\Models\Gemstone;
use App\Models\Product;

class GemstoneService
{
    protected array $diamondColorGrades = ['D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M', 'N', 'O', 'P', 'Q', 'R', 'S', 'T', 'U', 'V', 'W', 'X', 'Y', 'Z'];

    protected array $diamondClarityGrades = ['FL', 'IF', 'VVS1', 'VVS2', 'VS1', 'VS2', 'SI1', 'SI2', 'I1', 'I2', 'I3'];

    protected array $diamondCutGrades = ['Excellent', 'Very Good', 'Good', 'Fair', 'Poor'];

    protected array $diamondShapes = [
        'round', 'princess', 'cushion', 'oval', 'emerald', 'pear',
        'marquise', 'radiant', 'asscher', 'heart', 'trillion',
    ];

    public function createGemstone(Product $product, array $data): Gemstone
    {
        return Gemstone::create([
            'store_id' => $product->store_id,
            'product_id' => $product->id,
            'type' => $data['type'],
            'shape' => $data['shape'] ?? null,
            'carat_weight' => $data['carat_weight'] ?? null,
            'color_grade' => $data['color_grade'] ?? null,
            'clarity_grade' => $data['clarity_grade'] ?? null,
            'cut_grade' => $data['cut_grade'] ?? null,
            'length_mm' => $data['length_mm'] ?? null,
            'width_mm' => $data['width_mm'] ?? null,
            'depth_mm' => $data['depth_mm'] ?? null,
            'origin' => $data['origin'] ?? null,
            'treatment' => $data['treatment'] ?? null,
            'fluorescence' => $data['fluorescence'] ?? null,
            'certification_id' => $data['certification_id'] ?? null,
            'estimated_value' => $data['estimated_value'] ?? null,
            'metadata' => $data['metadata'] ?? null,
        ]);
    }

    public function attachCertification(Gemstone $gemstone, Certification $certification): Gemstone
    {
        // Update gemstone with certification data
        $gemstone->update([
            'certification_id' => $certification->id,
            'shape' => $gemstone->shape ?? $certification->shape,
            'carat_weight' => $gemstone->carat_weight ?? $certification->carat_weight,
            'color_grade' => $gemstone->color_grade ?? $certification->color_grade,
            'clarity_grade' => $gemstone->clarity_grade ?? $certification->clarity_grade,
            'cut_grade' => $gemstone->cut_grade ?? $certification->cut_grade,
            'fluorescence' => $gemstone->fluorescence ?? $certification->fluorescence,
        ]);

        return $gemstone->fresh();
    }

    public function estimateDiamondValue(Gemstone $gemstone): ?float
    {
        if (! $gemstone->isDiamond() || ! $gemstone->carat_weight) {
            return null;
        }

        // Base price per carat (simplified Rapaport-style calculation)
        $basePricePerCarat = $this->getBasePricePerCarat($gemstone);

        if ($basePricePerCarat === null) {
            return null;
        }

        // Apply multipliers
        $multiplier = 1.0;

        // Cut quality multiplier
        $multiplier *= match ($gemstone->cut_grade) {
            'Excellent' => 1.15,
            'Very Good' => 1.05,
            'Good' => 1.00,
            'Fair' => 0.85,
            'Poor' => 0.70,
            default => 1.00,
        };

        // Fluorescence discount
        $multiplier *= match ($gemstone->fluorescence) {
            'None' => 1.00,
            'Faint' => 0.98,
            'Medium' => 0.95,
            'Strong' => 0.90,
            'Very Strong' => 0.85,
            default => 1.00,
        };

        // Certification premium
        if ($gemstone->certification) {
            $multiplier *= match (strtoupper($gemstone->certification->lab)) {
                'GIA' => 1.10,
                'AGS' => 1.05,
                'IGI' => 1.00,
                default => 0.95,
            };
        }

        $estimatedValue = $basePricePerCarat * $gemstone->carat_weight * $multiplier;

        return round($estimatedValue, 2);
    }

    public function getGradeOptions(): array
    {
        return [
            'color_grades' => $this->diamondColorGrades,
            'clarity_grades' => $this->diamondClarityGrades,
            'cut_grades' => $this->diamondCutGrades,
            'shapes' => $this->diamondShapes,
            'fluorescence' => ['None', 'Faint', 'Medium', 'Strong', 'Very Strong'],
        ];
    }

    public function validateDiamondGrades(array $data): array
    {
        $errors = [];

        if (isset($data['color_grade']) && ! in_array($data['color_grade'], $this->diamondColorGrades)) {
            $errors['color_grade'] = 'Invalid color grade';
        }

        if (isset($data['clarity_grade']) && ! in_array($data['clarity_grade'], $this->diamondClarityGrades)) {
            $errors['clarity_grade'] = 'Invalid clarity grade';
        }

        if (isset($data['cut_grade']) && ! in_array($data['cut_grade'], $this->diamondCutGrades)) {
            $errors['cut_grade'] = 'Invalid cut grade';
        }

        return $errors;
    }

    public function generateDescription(Gemstone $gemstone): string
    {
        $parts = [];

        if ($gemstone->carat_weight) {
            $parts[] = sprintf('%.2f carat', $gemstone->carat_weight);
        }

        if ($gemstone->shape) {
            $parts[] = ucfirst($gemstone->shape);
        }

        if ($gemstone->type) {
            $parts[] = ucfirst($gemstone->type);
        }

        if ($gemstone->isDiamond()) {
            $grades = array_filter([
                $gemstone->color_grade,
                $gemstone->clarity_grade,
                $gemstone->cut_grade,
            ]);

            if (! empty($grades)) {
                $parts[] = '('.implode(' / ', $grades).')';
            }
        }

        if ($gemstone->certification) {
            $parts[] = $gemstone->certification->lab.' Certified';
        }

        return implode(' ', $parts);
    }

    protected function getBasePricePerCarat(Gemstone $gemstone): ?float
    {
        // Simplified price matrix based on color and clarity
        // In production, this would use Rapaport or similar pricing data

        $colorIndex = array_search($gemstone->color_grade, $this->diamondColorGrades);
        $clarityIndex = array_search($gemstone->clarity_grade, $this->diamondClarityGrades);

        if ($colorIndex === false || $clarityIndex === false) {
            return null;
        }

        // Base price matrix (simplified, per carat prices for 1ct stones)
        $basePrices = [
            // D-F (colorless)
            0 => [15000, 14000, 12000, 11000, 9000, 7500, 5500, 4000, 2500, 1500, 1000], // D
            1 => [13000, 12000, 10000, 9500, 8000, 6500, 5000, 3500, 2200, 1300, 900],   // E
            2 => [11000, 10000, 9000, 8500, 7000, 5800, 4500, 3000, 2000, 1200, 800],    // F
            // G-J (near colorless)
            3 => [9000, 8500, 7500, 7000, 6000, 5000, 4000, 2800, 1800, 1100, 700],      // G
            4 => [7500, 7000, 6500, 6000, 5200, 4300, 3500, 2500, 1600, 1000, 650],      // H
            5 => [6500, 6000, 5500, 5200, 4500, 3800, 3000, 2200, 1400, 900, 600],       // I
            6 => [5500, 5000, 4800, 4500, 4000, 3300, 2700, 2000, 1300, 850, 550],       // J
        ];

        // Use color index 6 (J) as default for K-Z colors with reduced prices
        $priceRow = $basePrices[$colorIndex] ?? $basePrices[6];
        $basePrice = $priceRow[$clarityIndex] ?? 500;

        // Carat weight multiplier (prices increase exponentially with size)
        $caratMultiplier = pow($gemstone->carat_weight, 1.5);

        return $basePrice * $caratMultiplier;
    }
}
