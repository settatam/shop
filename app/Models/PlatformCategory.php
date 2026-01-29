<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlatformCategory extends Model
{
    use HasFactory;

    protected $fillable = [
        'platform_id',
        'external_id',
        'name',
        'full_path',
        'parent_external_id',
        'level',
        'is_leaf',
        'required_fields',
        'optional_fields',
        'field_values',
    ];

    protected function casts(): array
    {
        return [
            'is_leaf' => 'boolean',
            'required_fields' => 'array',
            'optional_fields' => 'array',
            'field_values' => 'array',
        ];
    }

    public function platform(): BelongsTo
    {
        return $this->belongsTo(Platform::class);
    }

    /**
     * Get all required and optional fields combined.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getAllFields(): array
    {
        $fields = [];

        foreach ($this->required_fields ?? [] as $field) {
            $fields[$field['name'] ?? $field] = [
                'name' => $field['name'] ?? $field,
                'required' => true,
                'values' => $this->field_values[$field['name'] ?? $field] ?? null,
            ];
        }

        foreach ($this->optional_fields ?? [] as $field) {
            $fieldName = $field['name'] ?? $field;
            if (! isset($fields[$fieldName])) {
                $fields[$fieldName] = [
                    'name' => $fieldName,
                    'required' => false,
                    'values' => $this->field_values[$fieldName] ?? null,
                ];
            }
        }

        return $fields;
    }

    public function scopeLeaves($query)
    {
        return $query->where('is_leaf', true);
    }
}
