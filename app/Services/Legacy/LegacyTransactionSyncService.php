<?php

namespace App\Services\Legacy;

use App\Models\Customer;
use App\Models\Legacy\LegacyStatusUpdate;
use App\Models\Legacy\LegacyStore;
use App\Models\Legacy\LegacyTransaction;
use App\Models\Legacy\LegacyTransactionItem;
use App\Models\Legacy\LegacyUser;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class LegacyTransactionSyncService
{
    /**
     * Map new system status slugs to legacy status names.
     *
     * @var array<string, string>
     */
    protected array $statusMap = [
        'pending' => 'Pending Offer',
        'kit_request_confirmed' => 'Pending Kit Request - Confirmed',
        'kit_request_on_hold' => 'Pending Kit Request - On Hold',
        'kit_request_rejected' => 'Kit Received - Rejected By Admin',
        'kit_sent' => 'Kit Sent',
        'kit_delivered' => 'Kit Sent',
        'items_received' => 'Kits Received',
        'items_reviewed' => 'Kit Received - Ready to buy',
        'offer_given' => 'Offer Given',
        'offer_accepted' => 'Offer Accepted',
        'offer_declined' => 'Offer Declined',
        'payment_processed' => 'Payment Processed',
        'return_requested' => 'Returned kit',
        'items_returned' => 'Returned By Admin',
        'cancelled' => 'Cancelled',
    ];

    /**
     * Cache for legacy store ID lookups.
     *
     * @var array<string, int|null>
     */
    protected array $storeIdCache = [];

    /**
     * Cache for the system user ID.
     */
    protected ?int $systemUserIdCache = null;

    /**
     * Sync a transaction and its items to the legacy database.
     */
    public function sync(Transaction $transaction): void
    {
        if (! config('legacy-sync.enabled')) {
            return;
        }

        $legacyStoreId = $this->getLegacyStoreId($transaction);

        if ($legacyStoreId === null) {
            return;
        }

        if (! LegacyTransaction::where('id', $transaction->id)->exists()) {
            return;
        }

        $this->syncTransactionStatus($transaction, $legacyStoreId);
        $this->syncTransactionItems($transaction);
    }

    /**
     * Sync a customer's details and primary address to the legacy database.
     */
    public function syncCustomer(Customer $customer): void
    {
        if (! config('legacy-sync.enabled')) {
            return;
        }

        $legacyCustomer = DB::connection('legacy')
            ->table('customers')
            ->where('id', $customer->id)
            ->first();

        if (! $legacyCustomer) {
            return;
        }

        $customer->loadMissing('addresses');

        $primaryAddress = $customer->addresses->firstWhere('is_default', true)
            ?? $customer->addresses->first();

        $data = [
            'first_name' => $customer->first_name,
            'last_name' => $customer->last_name,
            'email' => $customer->email,
            'phone_number' => $customer->phone_number,
            'company_name' => $customer->company_name,
            'updated_at' => now(),
        ];

        if ($primaryAddress) {
            $data['street_address'] = $primaryAddress->address;
            $data['street_address2'] = $primaryAddress->address2;
            $data['city'] = $primaryAddress->city;
            $data['state_id'] = $primaryAddress->state_id;
            $data['zip'] = $primaryAddress->zip;
        } else {
            $data['street_address'] = $customer->address;
            $data['street_address2'] = $customer->address2;
            $data['city'] = $customer->city;
            $data['zip'] = $customer->zip;
        }

        DB::connection('legacy')
            ->table('customers')
            ->where('id', $customer->id)
            ->update($data);
    }

    /**
     * Check if a transaction exists in the legacy database.
     */
    public function transactionExistsInLegacy(int $transactionId): bool
    {
        return LegacyTransaction::where('id', $transactionId)->exists();
    }

    /**
     * Sync the transaction status and offer fields to legacy.
     */
    protected function syncTransactionStatus(Transaction $transaction, int $legacyStoreId): void
    {
        $legacyTransaction = LegacyTransaction::find($transaction->id);

        if (! $legacyTransaction) {
            return;
        }

        $previousStatusId = $legacyTransaction->status_id;

        $legacyTransaction->final_offer = $transaction->final_offer;
        $legacyTransaction->preliminary_offer = $transaction->preliminary_offer;
        $legacyTransaction->estimated_value = $transaction->estimated_value;
        $legacyTransaction->is_accepted = in_array($transaction->status, [
            Transaction::STATUS_OFFER_ACCEPTED,
            Transaction::STATUS_PAYMENT_PROCESSED,
        ], true);
        $legacyTransaction->is_declined = $transaction->status === Transaction::STATUS_OFFER_DECLINED;

        $statusId = $this->mapStatusToLegacyId($transaction->status, $legacyStoreId);

        if ($statusId !== null) {
            $legacyTransaction->status_id = $statusId;
        }

        $legacyTransaction->save();

        // Create activity and status update if status changed
        if ($statusId !== null && $statusId !== $previousStatusId) {
            $systemUserId = $this->getSystemUserId();

            $legacyTransaction->activities()->create([
                'activity' => 'status_updated',
                'description' => "Status updated from {$previousStatusId} to {$statusId}",
                'user_id' => $systemUserId,
                'creatable_id' => $systemUserId,
                'creatable_type' => 'App\Models\User',
            ]);

            LegacyStatusUpdate::create([
                'store_id' => $legacyStoreId,
                'user_id' => $systemUserId,
                'updateable_id' => $legacyTransaction->id,
                'updateable_type' => 'App\Models\Transaction',
                'previous_status' => (string) $previousStatusId,
                'current_status' => (string) $statusId,
            ]);
        }
    }

    /**
     * Sync transaction items to legacy.
     */
    protected function syncTransactionItems(Transaction $transaction): void
    {
        $transaction->loadMissing('items');
        $legacyTransaction = LegacyTransaction::find($transaction->id);

        if (! $legacyTransaction) {
            return;
        }

        $systemUserId = $this->getSystemUserId();

        foreach ($transaction->items as $item) {
            $legacyItem = LegacyTransactionItem::find($item->id);

            if (! $legacyItem) {
                continue;
            }

            $legacyItem->title = $item->title;
            $legacyItem->price = $item->price;
            $legacyItem->buy_price = $item->buy_price;
            $legacyItem->dwt = $item->dwt;
            $legacyItem->description = $item->description;
            $legacyItem->precious_metal = $item->precious_metal;
            $legacyItem->quantity = $item->quantity;

            // Check if item was reviewed in the new system
            if ($item->reviewed_at !== null) {
                $legacyItem->reviewed_date_time = $item->reviewed_at;
                $legacyItem->reviewed_by = $item->reviewed_by;
            }

            $dirty = $legacyItem->getDirty();

            // Log price change activity on both transaction and item
            if (isset($dirty['buy_price']) || isset($dirty['price'])) {
                $legacyTransaction->activities()->create([
                    'activity' => 'updated_transaction_item_amount',
                    'description' => "Item {$item->id} amount updated",
                    'user_id' => $systemUserId,
                    'creatable_id' => $systemUserId,
                    'creatable_type' => 'App\Models\User',
                ]);

                $legacyItem->activities()->create([
                    'activity' => 'updated_transaction_item_amount',
                    'description' => "Item {$item->id} amount updated",
                    'user_id' => $systemUserId,
                    'creatable_id' => $systemUserId,
                    'creatable_type' => 'App\Models\User',
                ]);
            }

            // Log reviewed activity on the item
            if (isset($dirty['reviewed_date_time'])) {
                $legacyItem->activities()->create([
                    'activity' => 'transaction_item_reviewed',
                    'description' => "Item {$item->id} Reviewed by System",
                    'user_id' => $systemUserId,
                    'creatable_id' => $systemUserId,
                    'creatable_type' => 'App\Models\User',
                ]);
            }

            $legacyItem->save();
        }

        // Recalculate final_offer from items
        $legacyTransaction->calculateOfferFromItems();
    }

    /**
     * Get the legacy store ID by matching store name.
     */
    protected function getLegacyStoreId(Transaction $transaction): ?int
    {
        $transaction->loadMissing('store');
        $storeName = $transaction->store?->name;

        if ($storeName === null) {
            return null;
        }

        if (array_key_exists($storeName, $this->storeIdCache)) {
            return $this->storeIdCache[$storeName];
        }

        $this->storeIdCache[$storeName] = LegacyStore::where('name', $storeName)->value('id');

        return $this->storeIdCache[$storeName];
    }

    /**
     * Get the legacy system user ID.
     */
    protected function getSystemUserId(): ?int
    {
        if ($this->systemUserIdCache !== null) {
            return $this->systemUserIdCache;
        }

        $this->systemUserIdCache = LegacyUser::where('username', 'system')->value('id');

        return $this->systemUserIdCache;
    }

    /**
     * Map a new system status slug to a legacy status_id.
     */
    protected function mapStatusToLegacyId(string $status, int $legacyStoreId): ?int
    {
        $legacyName = $this->statusMap[$status] ?? null;

        if ($legacyName === null) {
            Log::warning('Legacy sync: unmapped status', ['status' => $status]);

            return null;
        }

        $legacyStatus = DB::connection('legacy')
            ->table('statuses')
            ->where('store_id', $legacyStoreId)
            ->where('name', $legacyName)
            ->first();

        return $legacyStatus?->status_id;
    }
}
