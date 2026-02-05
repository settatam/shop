<?php

namespace App\Models;

use App\Traits\BelongsToStore;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class SalesChannel extends Model
{
    use BelongsToStore, HasFactory, SoftDeletes;

    public const TYPE_LOCAL = 'local';

    public const TYPE_SHOPIFY = 'shopify';

    public const TYPE_EBAY = 'ebay';

    public const TYPE_AMAZON = 'amazon';

    public const TYPE_ETSY = 'etsy';

    public const TYPE_WALMART = 'walmart';

    public const TYPE_WOOCOMMERCE = 'woocommerce';

    public const TYPES = [
        self::TYPE_LOCAL,
        self::TYPE_SHOPIFY,
        self::TYPE_EBAY,
        self::TYPE_AMAZON,
        self::TYPE_ETSY,
        self::TYPE_WALMART,
        self::TYPE_WOOCOMMERCE,
    ];

    public const EXTERNAL_TYPES = [
        self::TYPE_SHOPIFY,
        self::TYPE_EBAY,
        self::TYPE_AMAZON,
        self::TYPE_ETSY,
        self::TYPE_WALMART,
        self::TYPE_WOOCOMMERCE,
    ];

    protected $fillable = [
        'store_id',
        'name',
        'code',
        'type',
        'is_local',
        'warehouse_id',
        'store_marketplace_id',
        'color',
        'sort_order',
        'is_active',
        'is_default',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'is_local' => 'boolean',
            'is_active' => 'boolean',
            'is_default' => 'boolean',
            'sort_order' => 'integer',
            'settings' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (SalesChannel $channel) {
            // Generate code from name if not provided
            if (empty($channel->code)) {
                $channel->code = Str::slug($channel->name, '_');
            }

            // Set is_local based on type
            $channel->is_local = $channel->type === self::TYPE_LOCAL;

            // Set sort order if not provided
            if (empty($channel->sort_order)) {
                $maxOrder = static::where('store_id', $channel->store_id)->max('sort_order') ?? 0;
                $channel->sort_order = $maxOrder + 1;
            }
        });

        static::saving(function (SalesChannel $channel) {
            // If this is being set as default, unset others
            if ($channel->isDirty('is_default') && $channel->is_default) {
                static::where('store_id', $channel->store_id)
                    ->where('id', '!=', $channel->id ?? 0)
                    ->update(['is_default' => false]);
            }
        });
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function storeMarketplace(): BelongsTo
    {
        return $this->belongsTo(StoreMarketplace::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function isLocal(): bool
    {
        return $this->is_local || $this->type === self::TYPE_LOCAL;
    }

    public function isExternal(): bool
    {
        return ! $this->isLocal();
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeLocal($query)
    {
        return $query->where('is_local', true);
    }

    public function scopeExternal($query)
    {
        return $query->where('is_local', false);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order');
    }

    public static function getTypeLabel(string $type): string
    {
        return match ($type) {
            self::TYPE_LOCAL => 'Local Store',
            self::TYPE_SHOPIFY => 'Shopify',
            self::TYPE_EBAY => 'eBay',
            self::TYPE_AMAZON => 'Amazon',
            self::TYPE_ETSY => 'Etsy',
            self::TYPE_WALMART => 'Walmart',
            self::TYPE_WOOCOMMERCE => 'WooCommerce',
            default => ucfirst($type),
        };
    }

    public function getTypeLabelAttribute(): string
    {
        return self::getTypeLabel($this->type);
    }

    /**
     * Get the default local sales channel for a store.
     * Returns the channel marked as default, or the first local channel, or creates one.
     */
    public static function getDefaultLocalChannel(int $storeId): self
    {
        // First try to find a default local channel
        $channel = static::where('store_id', $storeId)
            ->where('is_local', true)
            ->where('is_default', true)
            ->where('is_active', true)
            ->first();

        if ($channel) {
            return $channel;
        }

        // Fall back to any active local channel
        $channel = static::where('store_id', $storeId)
            ->where('is_local', true)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->first();

        if ($channel) {
            return $channel;
        }

        // Create a default local channel if none exists
        return static::create([
            'store_id' => $storeId,
            'name' => 'In Store',
            'code' => 'in_store',
            'type' => self::TYPE_LOCAL,
            'is_local' => true,
            'is_active' => true,
            'is_default' => true,
        ]);
    }

    /**
     * Get or create a sales channel for a given source_platform.
     * This helps migrate existing orders to the new channel system.
     */
    public static function getOrCreateForSourcePlatform(int $storeId, string $sourcePlatform): ?self
    {
        // Normalize the source platform
        $type = strtolower($sourcePlatform);

        // Map legacy values to types
        $typeMap = [
            'in_store' => self::TYPE_LOCAL,
            'reb' => self::TYPE_LOCAL,
            'memo' => self::TYPE_LOCAL,
            'repair' => self::TYPE_LOCAL,
            'layaway' => self::TYPE_LOCAL,
        ];

        $type = $typeMap[$type] ?? $type;

        // Check if it's a valid type
        if (! in_array($type, self::TYPES)) {
            return null;
        }

        // Find existing channel or create one
        return static::firstOrCreate(
            [
                'store_id' => $storeId,
                'type' => $type,
                'code' => $type === self::TYPE_LOCAL ? 'local_store' : $type,
            ],
            [
                'name' => self::getTypeLabel($type),
                'is_local' => $type === self::TYPE_LOCAL,
                'is_active' => true,
            ]
        );
    }
}
