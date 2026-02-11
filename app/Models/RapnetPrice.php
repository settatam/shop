<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RapnetPrice extends Model
{
    protected $table = 'rapnet_prices';

    protected $fillable = [
        'shape',
        'color',
        'clarity',
        'low_size',
        'high_size',
        'carat_price',
        'price_date',
    ];

    protected function casts(): array
    {
        return [
            'low_size' => 'decimal:2',
            'high_size' => 'decimal:2',
            'carat_price' => 'decimal:2',
            'price_date' => 'datetime',
        ];
    }

    /**
     * Find the rap price for a diamond based on its characteristics.
     */
    public static function findPrice(string $shape, string $color, string $clarity, float $weight): ?self
    {
        // Normalize shape to Round or Pear (fancy shapes use Pear pricing)
        $shapeGroup = strtolower($shape) === 'round' ? 'Round' : 'Pear';

        return self::where('shape', $shapeGroup)
            ->where('color', strtoupper($color))
            ->where('clarity', strtoupper($clarity))
            ->whereRaw('? BETWEEN low_size AND high_size', [$weight])
            ->orderBy('price_date', 'desc')
            ->first();
    }
}
