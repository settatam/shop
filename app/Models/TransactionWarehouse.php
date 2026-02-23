<?php

namespace App\Models;

use App\Traits\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class TransactionWarehouse extends Model
{
    use BelongsToStore, SoftDeletes;

    protected $table = 'transactions_warehouse';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'bought' => 'decimal:2',
            'estimated_value' => 'decimal:2',
            'final_offer' => 'decimal:2',
            'profit' => 'decimal:2',
            'profit_percent' => 'decimal:2',
            'estimated_profit' => 'decimal:2',
            'total_dwt' => 'decimal:2',
            'customer_since' => 'datetime',
            'dob' => 'date',
            'kit_request_date_time' => 'datetime',
            'kit_print_on' => 'datetime',
            'kit_sent_date_time' => 'datetime',
            'kit_sent_not_received_date' => 'datetime',
            'kit_received_ready_to_buy' => 'datetime',
            'pending_kit_confirmed_date_time' => 'datetime',
            'pending_kit_on_hold_date_time' => 'datetime',
            'pending_kit_returned_date_time' => 'datetime',
            'pending_kit_request_high_value' => 'datetime',
            'pending_kit_request_high_value_watch_date_time' => 'datetime',
            'pending_kit_request_bulk_date_time' => 'datetime',
            'pending_kit_request_incomplete' => 'datetime',
            'pending_kit_request_rejected_by_customer' => 'datetime',
            'shipment_received_on' => 'datetime',
            'shipment_returned_on' => 'datetime',
            'shipment_declined_on' => 'datetime',
            'offer_given_on' => 'datetime',
            'offer_given_date_time' => 'datetime',
            'offer_accepted_on' => 'datetime',
            'offer_accepted_date_time' => 'datetime',
            'offer_declined_date_time' => 'datetime',
            'offer_declined_send_back_date_time' => 'datetime',
            'offer_paid_on' => 'datetime',
            'payment_date_time' => 'datetime',
            'received_date_time' => 'datetime',
            'received_rejected_date_time' => 'datetime',
            'returned_date_time' => 'datetime',
            'refused_by_fedex_date_time' => 'datetime',
            'reviewed_date_time' => 'datetime',
            'on_hold_date_time' => 'datetime',
            'hold_date' => 'datetime',
            'sold_date_time' => 'datetime',
            'melt_date_time' => 'datetime',
            'customer_declined_date_time' => 'datetime',
            'kit_rejected_hard_to_sell_date_time' => 'datetime',
            'kit_rejected_high_markup_date_time' => 'datetime',
            'latest_incoming_sms_date' => 'datetime',
            'is_accepted' => 'boolean',
            'is_rejected' => 'boolean',
            'is_declined' => 'boolean',
            'latest_incoming_sms_is_read' => 'boolean',
        ];
    }

    /**
     * Get store ID mapping from config.
     *
     * @return array<int, int>
     */
    public static function getStoreMapping(): array
    {
        return config('legacy-sync.store_mapping', []);
    }

    /**
     * Get new store ID from legacy store ID.
     */
    public static function mapLegacyStoreId(int $legacyStoreId): ?int
    {
        return self::getStoreMapping()[$legacyStoreId] ?? null;
    }

    /**
     * Get legacy store IDs from new store ID.
     *
     * @return array<int>
     */
    public static function getLegacyStoreIds(int $newStoreId): array
    {
        return array_keys(array_filter(
            self::getStoreMapping(),
            fn ($mapped) => $mapped === $newStoreId
        ));
    }

    /**
     * Get all configured legacy store IDs.
     *
     * @return array<int>
     */
    public static function getConfiguredLegacyStoreIds(): array
    {
        return array_keys(self::getStoreMapping());
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
