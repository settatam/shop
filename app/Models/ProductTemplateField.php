<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductTemplateField extends Model
{
    use HasFactory;

    public const TYPE_TEXT = 'text';

    public const TYPE_TEXTAREA = 'textarea';

    public const TYPE_NUMBER = 'number';

    public const TYPE_SELECT = 'select';

    public const TYPE_CHECKBOX = 'checkbox';

    public const TYPE_RADIO = 'radio';

    public const TYPE_DATE = 'date';

    public const TYPE_BRAND = 'brand';

    public const TYPES = [
        self::TYPE_TEXT,
        self::TYPE_TEXTAREA,
        self::TYPE_NUMBER,
        self::TYPE_SELECT,
        self::TYPE_CHECKBOX,
        self::TYPE_RADIO,
        self::TYPE_DATE,
        self::TYPE_BRAND,
    ];

    public const TYPES_WITH_OPTIONS = [
        self::TYPE_SELECT,
        self::TYPE_CHECKBOX,
        self::TYPE_RADIO,
    ];

    /**
     * Check if this field is a brand field.
     */
    public function isBrandField(): bool
    {
        return $this->type === self::TYPE_BRAND;
    }

    protected $fillable = [
        'product_template_id',
        'name',
        'canonical_name',
        'label',
        'type',
        'placeholder',
        'help_text',
        'default_value',
        'is_required',
        'is_private',
        'is_searchable',
        'is_filterable',
        'show_in_listing',
        'validation_rules',
        'sort_order',
        'group_name',
        'group_position',
        'width_class',
        'ai_generated',
    ];

    protected function casts(): array
    {
        return [
            'is_required' => 'boolean',
            'is_private' => 'boolean',
            'is_searchable' => 'boolean',
            'is_filterable' => 'boolean',
            'show_in_listing' => 'boolean',
            'validation_rules' => 'array',
            'sort_order' => 'integer',
            'ai_generated' => 'boolean',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ProductTemplate::class, 'product_template_id');
    }

    public function options(): HasMany
    {
        return $this->hasMany(ProductTemplateFieldOption::class)->orderBy('sort_order');
    }

    public function platformMappings(): HasMany
    {
        return $this->hasMany(PlatformFieldMapping::class);
    }

    public function hasOptions(): bool
    {
        return in_array($this->type, self::TYPES_WITH_OPTIONS);
    }

    public function getOptionValuesAttribute(): string
    {
        return $this->options->pluck('value')->implode(', ');
    }
}
