<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WalmartCategory extends Model
{
    protected $fillable = [
        'name',
        'walmart_category_id',
        'level',
        'parent_id',
        'walmart_parent_id',
        'path',
    ];

    protected function casts(): array
    {
        return [
            'level' => 'integer',
            'parent_id' => 'integer',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(WalmartCategory::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(WalmartCategory::class, 'parent_id');
    }

    /**
     * Get the full category path (breadcrumb).
     */
    public function getPathAttribute(): string
    {
        if ($this->attributes['path'] ?? null) {
            return $this->attributes['path'];
        }

        $path = [$this->name];
        $parent = $this->parent;

        while ($parent) {
            array_unshift($path, $parent->name);
            $parent = $parent->parent;
        }

        return implode(' > ', $path);
    }
}
