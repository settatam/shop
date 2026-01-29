<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class LabelTemplate extends Model
{
    /** @use HasFactory<\Database\Factories\LabelTemplateFactory> */
    use HasFactory, SoftDeletes;

    public const TYPE_PRODUCT = 'product';

    public const TYPE_TRANSACTION = 'transaction';

    protected $fillable = [
        'store_id',
        'name',
        'type',
        'canvas_width',
        'canvas_height',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'canvas_width' => 'integer',
            'canvas_height' => 'integer',
            'is_default' => 'boolean',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function elements(): HasMany
    {
        return $this->hasMany(LabelTemplateElement::class)->orderBy('sort_order');
    }

    public static function getTypes(): array
    {
        return [
            self::TYPE_PRODUCT => 'Product Labels',
            self::TYPE_TRANSACTION => 'Transaction Labels',
        ];
    }

    public function makeDefault(): void
    {
        // Unset other defaults for the same type
        static::where('store_id', $this->store_id)
            ->where('type', $this->type)
            ->where('id', '!=', $this->id)
            ->update(['is_default' => false]);

        $this->update(['is_default' => true]);
    }
}
