<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EbayCategory extends Model
{
    protected $fillable = [
        'name',
        'level',
        'parent_id',
        'ebay_parent_id',
        'ebay_category_id',
        'comments',
    ];

    protected function casts(): array
    {
        return [
            'level' => 'integer',
            'parent_id' => 'integer',
            'ebay_parent_id' => 'integer',
            'ebay_category_id' => 'integer',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(EbayCategory::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(EbayCategory::class, 'parent_id');
    }

    public function itemSpecifics(): HasMany
    {
        return $this->hasMany(EbayItemSpecific::class, 'ebay_category_id', 'ebay_category_id');
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
