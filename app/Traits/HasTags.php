<?php

namespace App\Traits;

use App\Models\Tag;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

trait HasTags
{
    public function tags(): MorphToMany
    {
        return $this->morphToMany(Tag::class, 'taggable')->withTimestamps();
    }

    /**
     * Sync tags by IDs.
     */
    public function syncTags(array $tagIds): void
    {
        $this->tags()->sync($tagIds);
    }

    /**
     * Attach tags by IDs.
     */
    public function attachTags(array $tagIds): void
    {
        $this->tags()->syncWithoutDetaching($tagIds);
    }

    /**
     * Detach tags by IDs.
     */
    public function detachTags(array $tagIds): void
    {
        $this->tags()->detach($tagIds);
    }

    /**
     * Sync tags by names (creates new tags if they don't exist).
     */
    public function syncTagsByName(array $names, int $storeId): void
    {
        $tagIds = [];
        foreach ($names as $name) {
            $tag = Tag::firstOrCreate(
                ['store_id' => $storeId, 'name' => $name],
                ['slug' => \Illuminate\Support\Str::slug($name)]
            );
            $tagIds[] = $tag->id;
        }
        $this->tags()->sync($tagIds);
    }

    /**
     * Check if model has a specific tag.
     */
    public function hasTag(int|Tag $tag): bool
    {
        $tagId = $tag instanceof Tag ? $tag->id : $tag;

        return $this->tags()->where('tags.id', $tagId)->exists();
    }

    /**
     * Scope to filter by tag IDs.
     */
    public function scopeWithTags($query, array $tagIds)
    {
        return $query->whereHas('tags', function ($q) use ($tagIds) {
            $q->whereIn('tags.id', $tagIds);
        });
    }

    /**
     * Scope to filter by tag names.
     */
    public function scopeWithTagNames($query, array $names)
    {
        return $query->whereHas('tags', function ($q) use ($names) {
            $q->whereIn('tags.name', $names);
        });
    }
}
