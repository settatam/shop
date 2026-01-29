<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EbayItemSpecific extends Model
{
    protected $fillable = [
        'ebay_category_id',
        'name',
        'type',
        'is_required',
        'is_recommended',
        'aspect_mode',
        'is_condition_descriptor',
    ];

    protected function casts(): array
    {
        return [
            'ebay_category_id' => 'integer',
            'is_required' => 'boolean',
            'is_recommended' => 'boolean',
            'is_condition_descriptor' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(EbayCategory::class, 'ebay_category_id', 'ebay_category_id');
    }

    public function values(): HasMany
    {
        return $this->hasMany(EbayItemSpecificValue::class, 'ebay_item_specific_id');
    }

    /**
     * Determine the appropriate template field type based on eBay item specific.
     */
    public function getTemplateFieldTypeAttribute(): string
    {
        // If there are predefined values, it's likely a select/dropdown
        if ($this->values()->count() > 0) {
            return 'select';
        }

        // Check type/aspect_mode for hints
        $name = strtolower($this->name);

        if (str_contains($name, 'size') || str_contains($name, 'length') || str_contains($name, 'width')) {
            return 'number';
        }

        if (str_contains($name, 'date') || str_contains($name, 'year')) {
            return 'text';
        }

        if (str_contains($name, 'description') || str_contains($name, 'notes')) {
            return 'textarea';
        }

        return 'text';
    }
}
