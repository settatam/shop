<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LabelTemplateElement extends Model
{
    /** @use HasFactory<\Database\Factories\LabelTemplateElementFactory> */
    use HasFactory;

    public const TYPE_TEXT_FIELD = 'text_field';

    public const TYPE_BARCODE = 'barcode';

    public const TYPE_STATIC_TEXT = 'static_text';

    public const TYPE_LINE = 'line';

    protected $fillable = [
        'label_template_id',
        'element_type',
        'x',
        'y',
        'width',
        'height',
        'content',
        'styles',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'x' => 'integer',
            'y' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'styles' => 'array',
            'sort_order' => 'integer',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(LabelTemplate::class, 'label_template_id');
    }

    public static function getElementTypes(): array
    {
        return [
            self::TYPE_TEXT_FIELD => 'Dynamic Field',
            self::TYPE_BARCODE => 'Barcode',
            self::TYPE_STATIC_TEXT => 'Static Text',
            self::TYPE_LINE => 'Line',
        ];
    }
}
