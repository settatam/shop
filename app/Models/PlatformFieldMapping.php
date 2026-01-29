<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlatformFieldMapping extends Model
{
    use HasFactory;

    protected $fillable = [
        'platform_id',
        'product_template_field_id',
        'platform_field_name',
        'platform_field_id',
        'is_required',
        'is_recommended',
        'value_mappings',
        'accepted_values',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'is_required' => 'boolean',
            'is_recommended' => 'boolean',
            'value_mappings' => 'array',
            'accepted_values' => 'array',
        ];
    }

    public function platform(): BelongsTo
    {
        return $this->belongsTo(Platform::class);
    }

    public function templateField(): BelongsTo
    {
        return $this->belongsTo(ProductTemplateField::class, 'product_template_field_id');
    }

    /**
     * Map a value from our system to the platform's accepted value.
     */
    public function mapValue(string $value): string
    {
        if (! $this->value_mappings) {
            return $value;
        }

        return $this->value_mappings[$value] ?? $value;
    }

    /**
     * Check if a value is accepted by the platform.
     */
    public function isValueAccepted(string $value): bool
    {
        if (! $this->accepted_values) {
            return true;
        }

        $mappedValue = $this->mapValue($value);

        return in_array($mappedValue, $this->accepted_values);
    }
}
