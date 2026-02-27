<?php

namespace App\Models;

use App\Traits\BelongsToStore;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketplacePolicy extends Model
{
    /** @use HasFactory<\Database\Factories\MarketplacePolicyFactory> */
    use BelongsToStore, HasFactory;

    public const TYPE_RETURN = 'return';

    public const TYPE_PAYMENT = 'payment';

    public const TYPE_FULFILLMENT = 'fulfillment';

    public const TYPES = [
        self::TYPE_RETURN,
        self::TYPE_PAYMENT,
        self::TYPE_FULFILLMENT,
    ];

    protected $fillable = [
        'store_id',
        'store_marketplace_id',
        'type',
        'external_id',
        'name',
        'description',
        'details',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'details' => 'array',
            'is_default' => 'boolean',
        ];
    }

    public function storeMarketplace(): BelongsTo
    {
        return $this->belongsTo(StoreMarketplace::class);
    }

    public function scopeReturn($query)
    {
        return $query->where('type', self::TYPE_RETURN);
    }

    public function scopePayment($query)
    {
        return $query->where('type', self::TYPE_PAYMENT);
    }

    public function scopeFulfillment($query)
    {
        return $query->where('type', self::TYPE_FULFILLMENT);
    }

    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
    }

    public function setAsDefault(): self
    {
        static::where('store_marketplace_id', $this->store_marketplace_id)
            ->where('type', $this->type)
            ->where('id', '!=', $this->id)
            ->update(['is_default' => false]);

        $this->update(['is_default' => true]);

        return $this;
    }

    /**
     * Sync policies from eBay API response into local database.
     *
     * @param  array{return_policies: array, payment_policies: array, fulfillment_policies: array}  $policies
     */
    public static function syncFromEbay(StoreMarketplace $connection, array $policies): void
    {
        $typeMap = [
            'return_policies' => [self::TYPE_RETURN, 'returnPolicyId'],
            'payment_policies' => [self::TYPE_PAYMENT, 'paymentPolicyId'],
            'fulfillment_policies' => [self::TYPE_FULFILLMENT, 'fulfillmentPolicyId'],
        ];

        foreach ($typeMap as $key => [$type, $idField]) {
            $items = $policies[$key] ?? [];
            $externalIds = [];

            foreach ($items as $item) {
                $externalId = $item[$idField] ?? null;
                if (! $externalId) {
                    continue;
                }

                $externalIds[] = $externalId;

                static::withoutGlobalScopes()->updateOrCreate(
                    [
                        'store_marketplace_id' => $connection->id,
                        'type' => $type,
                        'external_id' => $externalId,
                    ],
                    [
                        'store_id' => $connection->store_id,
                        'name' => $item['name'] ?? 'Unnamed Policy',
                        'description' => $item['description'] ?? null,
                        'details' => $item,
                    ]
                );
            }

            // Remove stale policies that no longer exist on eBay
            static::withoutGlobalScopes()
                ->where('store_marketplace_id', $connection->id)
                ->where('type', $type)
                ->whereNotIn('external_id', $externalIds)
                ->delete();
        }
    }
}
