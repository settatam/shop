<?php

namespace App\Models;

use App\Enums\Platform;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TemplatePlatformMapping extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_template_id',
        'platform',
        'field_mappings',
        'default_values',
        'metafield_mappings',
        'excluded_metafields',
        'is_ai_generated',
    ];

    protected function casts(): array
    {
        return [
            'platform' => Platform::class,
            'field_mappings' => 'array',
            'default_values' => 'array',
            'metafield_mappings' => 'array',
            'excluded_metafields' => 'array',
            'is_ai_generated' => 'boolean',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ProductTemplate::class, 'product_template_id');
    }

    /**
     * Get the mapping for a specific template field.
     */
    public function getMappingForField(string $fieldName): ?string
    {
        return $this->field_mappings[$fieldName] ?? null;
    }

    /**
     * Get all template fields that are mapped.
     *
     * @return array<string>
     */
    public function getMappedTemplateFields(): array
    {
        return array_keys($this->field_mappings ?? []);
    }

    /**
     * Get all platform fields that have mappings.
     *
     * @return array<string>
     */
    public function getMappedPlatformFields(): array
    {
        return array_values($this->field_mappings ?? []);
    }

    /**
     * Check if a specific template field is mapped.
     */
    public function hasMapping(string $fieldName): bool
    {
        return isset($this->field_mappings[$fieldName]);
    }

    /**
     * Get default value for a platform field.
     */
    public function getDefaultValue(string $platformField): mixed
    {
        return $this->default_values[$platformField] ?? null;
    }

    /**
     * Get metafield configuration for a template field.
     *
     * @return array{namespace: string, key: string, enabled: bool}|null
     */
    public function getMetafieldConfig(string $templateFieldName): ?array
    {
        return $this->metafield_mappings[$templateFieldName] ?? null;
    }

    /**
     * Check if a template field should be sent as a metafield.
     */
    public function isMetafieldEnabled(string $templateFieldName): bool
    {
        $config = $this->getMetafieldConfig($templateFieldName);

        return $config['enabled'] ?? false;
    }

    /**
     * Get all enabled metafield mappings.
     *
     * @return array<string, array{namespace: string, key: string}>
     */
    public function getEnabledMetafields(): array
    {
        return collect($this->metafield_mappings ?? [])
            ->filter(fn ($config) => $config['enabled'] ?? false)
            ->map(fn ($config) => [
                'namespace' => $config['namespace'] ?? 'custom',
                'key' => $config['key'],
            ])
            ->toArray();
    }
}
