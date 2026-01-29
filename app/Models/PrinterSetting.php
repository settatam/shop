<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrinterSetting extends Model
{
    /** @use HasFactory<\Database\Factories\PrinterSettingFactory> */
    use HasFactory;

    public const TYPE_ZEBRA = 'zebra';

    public const TYPE_GODEX = 'godex';

    public const TYPE_OTHER = 'other';

    protected $fillable = [
        'store_id',
        'name',
        'printer_type',
        'top_offset',
        'left_offset',
        'right_offset',
        'text_size',
        'barcode_height',
        'line_height',
        'label_width',
        'label_height',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'top_offset' => 'integer',
            'left_offset' => 'integer',
            'right_offset' => 'integer',
            'text_size' => 'integer',
            'barcode_height' => 'integer',
            'line_height' => 'integer',
            'label_width' => 'integer',
            'label_height' => 'integer',
            'is_default' => 'boolean',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public static function getTypes(): array
    {
        return [
            self::TYPE_ZEBRA => 'Zebra',
            self::TYPE_GODEX => 'Godex',
            self::TYPE_OTHER => 'Other',
        ];
    }
}
