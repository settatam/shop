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
     * Get Shopify metafield mappings from metadata.
     *
     * @return array<string, string> Map of metafield full key (namespace.key) to template field name
     */
    public function getMetafieldMappings(): array
    {
        return $this->metadata['metafield_mappings'] ?? [];
    }

    /**
     * Get list of enabled Shopify metafield full keys from metadata.
     *
     * @return array<int, string> List of enabled metafield full keys (namespace.key)
     */
    public function getEnabledMetafields(): array
    {
        return $this->metadata['enabled_metafields'] ?? [];
    }

    /**
     * Check if this mapping has Shopify metafield configuration.
     */
    public function hasMetafieldConfig(): bool
    {
        return ! empty($this->metadata['enabled_metafields']);
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
