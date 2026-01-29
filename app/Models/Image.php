<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Image extends Model
{
    use HasFactory;

    protected $fillable = [
        'store_id',
        'imageable_type',
        'imageable_id',
        'path',
        'url',
        'thumbnail_url',
        'alt_text',
        'disk',
        'size',
        'mime_type',
        'width',
        'height',
        'sort_order',
        'is_primary',
        'is_internal',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'sort_order' => 'integer',
            'is_primary' => 'boolean',
            'is_internal' => 'boolean',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function imageable(): MorphTo
    {
        return $this->morphTo();
    }

    public function getFullUrlAttribute(): string
    {
        return $this->url;
    }

    public function getThumbnailAttribute(): ?string
    {
        return $this->thumbnail_url;
    }
}
