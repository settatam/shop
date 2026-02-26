<?php

namespace App\Models;

use App\Enums\Platform;
use App\Traits\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CategoryPlatformMapping extends Model
{
    use BelongsToStore;

    protected $fillable = [
        'store_id',
        'category_id',
        'store_marketplace_id',
        'platform',
        'primary_category_id',
        'primary_category_name',
        'secondary_category_id',
        'secondary_category_name',
        'item_specifics_synced_at',
        'field_mappings',
        'default_values',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'platform' => Platform::class,
            'field_mappings' => 'encrypted:array',
            'default_values' => 'encrypted:array',
            'metadata' => 'array',
            'item_specifics_synced_at' => 'datetime',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function storeMarketplace(): BelongsTo
    {
        return $this->belongsTo(StoreMarketplace::class);
    }

    /**
     * Get effective field mappings, merging category-level overrides
     * with any template-level defaults.
     *
     * @return array<string, string>
     */
    public function getEffectiveFieldMappings(): array
    {
        return $this->field_mappings ?? [];
    }

    /**
     * Get effective default values for unmapped fields.
     *
     * @return array<string, mixed>
     */
    public function getEffectiveDefaultValues(): array
    {
        return $this->default_values ?? [];
    }

    /**
     * Check if item specifics need to be synced.
     */
    public function needsItemSpecificsSync(): bool
    {
        if (! $this->item_specifics_synced_at) {
            return true;
        }

        // Re-sync if older than 7 days
        return $this->item_specifics_synced_at->lt(now()->subDays(7));
    }
}
