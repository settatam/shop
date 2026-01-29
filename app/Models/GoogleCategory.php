<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GoogleCategory extends Model
{
    protected $fillable = [
        'name',
        'google_id',
    ];

    /**
     * Parse the hierarchical name to get path segments.
     *
     * @return array<string>
     */
    public function getPathSegmentsAttribute(): array
    {
        return explode(' > ', $this->name);
    }

    /**
     * Get the leaf category name (last segment).
     */
    public function getLeafNameAttribute(): string
    {
        $segments = $this->path_segments;

        return end($segments);
    }

    /**
     * Get the category level (depth in hierarchy).
     */
    public function getLevelAttribute(): int
    {
        return count($this->path_segments) - 1;
    }
}
