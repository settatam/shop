<?php

namespace App\Models;

use App\Traits\BelongsToStore;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Str;

class Tag extends Model
{
    use BelongsToStore, HasFactory;

    protected $fillable = [
        'store_id',
        'name',
        'slug',
        'color',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Tag $tag) {
            if (empty($tag->slug)) {
                $tag->slug = Str::slug($tag->name);
            }
            if (empty($tag->color)) {
                $tag->color = '#6b7280';
            }
        });
    }

    public function products(): MorphToMany
    {
        return $this->morphedByMany(Product::class, 'taggable')->withTimestamps();
    }

    public function customers(): MorphToMany
    {
        return $this->morphedByMany(Customer::class, 'taggable')->withTimestamps();
    }

    public function vendors(): MorphToMany
    {
        return $this->morphedByMany(Vendor::class, 'taggable')->withTimestamps();
    }

    public function orders(): MorphToMany
    {
        return $this->morphedByMany(Order::class, 'taggable')->withTimestamps();
    }

    public function transactions(): MorphToMany
    {
        return $this->morphedByMany(Transaction::class, 'taggable')->withTimestamps();
    }

    public function memos(): MorphToMany
    {
        return $this->morphedByMany(Memo::class, 'taggable')->withTimestamps();
    }

    public function repairs(): MorphToMany
    {
        return $this->morphedByMany(Repair::class, 'taggable')->withTimestamps();
    }

    public function scopeSearch($query, ?string $search)
    {
        if (! $search) {
            return $query;
        }

        return $query->where('name', 'like', "%{$search}%");
    }
}
