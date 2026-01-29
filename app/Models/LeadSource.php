<?php

namespace App\Models;

use App\Traits\BelongsToStore;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class LeadSource extends Model
{
    /** @use HasFactory<\Database\Factories\LeadSourceFactory> */
    use BelongsToStore, HasFactory;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'store_id',
        'name',
        'slug',
        'description',
        'is_active',
        'sort_order',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * @var array<string, array{name: string, slug: string}>
     */
    public const DEFAULT_SOURCES = [
        ['name' => 'Walk-in', 'slug' => 'walk-in'],
        ['name' => 'Online Ad', 'slug' => 'online-ad'],
        ['name' => 'Social Media', 'slug' => 'social-media'],
        ['name' => 'Referral', 'slug' => 'referral'],
        ['name' => 'Google Search', 'slug' => 'google-search'],
        ['name' => 'Email Campaign', 'slug' => 'email-campaign'],
        ['name' => 'Other', 'slug' => 'other'],
    ];

    protected static function booted(): void
    {
        static::creating(function (LeadSource $leadSource) {
            if (empty($leadSource->slug)) {
                $leadSource->slug = Str::slug($leadSource->name);
            }
        });
    }

    /**
     * @return HasMany<Customer, $this>
     */
    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    /**
     * Create default lead sources for a store.
     */
    public static function createDefaultsForStore(int $storeId): void
    {
        foreach (self::DEFAULT_SOURCES as $index => $source) {
            self::firstOrCreate(
                ['store_id' => $storeId, 'slug' => $source['slug']],
                [
                    'name' => $source['name'],
                    'sort_order' => $index,
                ]
            );
        }
    }
}
