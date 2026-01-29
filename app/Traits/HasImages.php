<?php

namespace App\Traits;

use App\Models\Image;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

trait HasImages
{
    public function images(): MorphMany
    {
        return $this->morphMany(Image::class, 'imageable')->orderBy('sort_order');
    }

    /**
     * Get the primary image relationship (for eager loading).
     * Returns the image marked as primary, or the first image by sort order.
     */
    public function primaryImage(): MorphOne
    {
        return $this->morphOne(Image::class, 'imageable')
            ->orderByDesc('is_primary')
            ->orderBy('sort_order');
    }

    /**
     * Get the first image by sort order (for eager loading).
     */
    public function firstImage(): MorphOne
    {
        return $this->morphOne(Image::class, 'imageable')
            ->orderBy('sort_order');
    }

    public function hasPrimaryImage(): bool
    {
        return $this->images()->where('is_primary', true)->exists();
    }

    public function setPrimaryImage(Image $image): void
    {
        $this->images()->update(['is_primary' => false]);
        $image->update(['is_primary' => true]);
    }
}
