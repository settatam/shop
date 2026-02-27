<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductAttributeValue extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'product_template_field_id',
        'value',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function field(): BelongsTo
    {
        return $this->belongsTo(ProductTemplateField::class, 'product_template_field_id');
    }

    /**
     * Resolve the raw stored value to its display label.
     *
     * For select/radio/checkbox fields, returns the option label instead of the raw value.
     * For brand fields, returns the brand name instead of the ID.
     * For all other fields, returns the raw value unchanged.
     */
    public function resolveDisplayValue(): ?string
    {
        $value = $this->value;
        if ($value === null || $value === '') {
            return null;
        }

        $field = $this->field;
        if (! $field) {
            return $value;
        }

        if (in_array($field->type, ProductTemplateField::TYPES_WITH_OPTIONS)) {
            $option = $field->options->firstWhere('value', $value);

            return $option?->label ?? $value;
        }

        if ($field->isBrandField()) {
            return Brand::find($value)?->name ?? $value;
        }

        return $value;
    }
}
