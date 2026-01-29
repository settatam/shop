<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductTemplateFieldOption extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_template_field_id',
        'label',
        'value',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    public function field(): BelongsTo
    {
        return $this->belongsTo(ProductTemplateField::class, 'product_template_field_id');
    }
}
