<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EtsyCategory extends Model
{
    protected $fillable = [
        'name',
        'etsy_id',
        'level',
        'etsy_parent_id',
        'parent_id',
    ];

    protected function casts(): array
    {
        return [
            'etsy_id' => 'integer',
            'level' => 'integer',
            'etsy_parent_id' => 'integer',
            'parent_id' => 'integer',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(EtsyCategory::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(EtsyCategory::class, 'parent_id');
    }

    /**
     * Get the full category path (breadcrumb).
     */
    public function getPathAttribute(): string
    {
        $path = [$this->name];
        $parent = $this->parent;

        while ($parent) {
            array_unshift($path, $parent->name);
            $parent = $parent->parent;
        }

        return implode(' > ', $path);
    }
}
