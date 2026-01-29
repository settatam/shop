<?php

namespace App\Models;

use App\Traits\BelongsToStore;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Gemstone extends Model
{
    use BelongsToStore, HasFactory;

    protected $fillable = [
        'store_id',
        'product_id',
        'type',
        'shape',
        'carat_weight',
        'color_grade',
        'clarity_grade',
        'cut_grade',
        'length_mm',
        'width_mm',
        'depth_mm',
        'origin',
        'treatment',
        'fluorescence',
        'certification_id',
        'estimated_value',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'carat_weight' => 'decimal:3',
            'length_mm' => 'decimal:2',
            'width_mm' => 'decimal:2',
            'depth_mm' => 'decimal:2',
            'estimated_value' => 'decimal:2',
            'metadata' => 'array',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function certification(): BelongsTo
    {
        return $this->belongsTo(Certification::class);
    }

    public function isDiamond(): bool
    {
        return strtolower($this->type) === 'diamond';
    }

    public function getGradeLabel(): string
    {
        if (! $this->isDiamond()) {
            return '';
        }

        return sprintf(
            '%s %s %s',
            $this->color_grade ?? '-',
            $this->clarity_grade ?? '-',
            $this->cut_grade ?? '-'
        );
    }

    public function getDimensions(): ?string
    {
        if (! $this->length_mm || ! $this->width_mm) {
            return null;
        }

        if ($this->depth_mm) {
            return sprintf('%.2f x %.2f x %.2f mm', $this->length_mm, $this->width_mm, $this->depth_mm);
        }

        return sprintf('%.2f x %.2f mm', $this->length_mm, $this->width_mm);
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    public function scopeDiamonds($query)
    {
        return $query->where('type', 'diamond');
    }

    public function scopeWithCertification($query)
    {
        return $query->whereNotNull('certification_id');
    }
}
