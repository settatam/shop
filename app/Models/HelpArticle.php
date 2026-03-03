<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class HelpArticle extends Model
{
    /** @use HasFactory<\Database\Factories\HelpArticleFactory> */
    use HasFactory;

    public const CATEGORIES = [
        'Getting Started',
        'Products',
        'Inventory',
        'Buys',
        'Orders',
        'Customers',
        'Reports',
        'Sales Channels',
        'Settings',
    ];

    /** @var list<string> */
    protected $fillable = [
        'category',
        'title',
        'slug',
        'content',
        'excerpt',
        'sort_order',
        'is_published',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_published' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (HelpArticle $article) {
            if (empty($article->slug)) {
                $article->slug = static::generateUniqueSlug($article->title);
            }
        });
    }

    /**
     * Scope to only published articles.
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }

    /**
     * Strip HTML tags to produce plain text for chat context.
     */
    public function toSearchableText(): string
    {
        return strip_tags($this->content);
    }

    /**
     * Generate a unique slug from the given title.
     */
    protected static function generateUniqueSlug(string $title): string
    {
        $slug = Str::slug($title);
        $original = $slug;
        $count = 1;

        while (static::where('slug', $slug)->exists()) {
            $slug = "{$original}-{$count}";
            $count++;
        }

        return $slug;
    }
}
