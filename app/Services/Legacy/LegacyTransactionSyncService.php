<?php

namespace App\Services\Legacy;

use App\Models\Transaction;
use App\Models\TransactionWarehouse;
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
     * Sync a transaction and its items to the legacy database.
     */
    public function sync(Transaction $transaction): void
    {
        if (! config('legacy-sync.enabled')) {
            return;
        }

        $legacyStoreIds = TransactionWarehouse::getLegacyStoreIds($transaction->store_id);

        if (empty($legacyStoreIds)) {
            return;
        }

        if (! $this->transactionExistsInLegacy($transaction->id)) {
            return;
        }

        $this->syncTransactionStatus($transaction, $legacyStoreIds[0]);
        $this->syncTransactionItems($transaction);
    }

    /**
     * Check if a transaction exists in the legacy database.
     */
    public function transactionExistsInLegacy(int $transactionId): bool
    {
        return DB::connection('legacy')
            ->table('transactions')
            ->where('id', $transactionId)
            ->exists();
    }

    /**
     * Sync the transaction status and offer fields to legacy.
     */
    protected function syncTransactionStatus(Transaction $transaction, int $legacyStoreId): void
    {
        $data = [
            'final_offer' => $transaction->final_offer,
            'preliminary_offer' => $transaction->preliminary_offer,
            'est_value' => $transaction->estimated_value,
            'is_accepted' => in_array($transaction->status, [
                Transaction::STATUS_OFFER_ACCEPTED,
                Transaction::STATUS_PAYMENT_PROCESSED,
            ], true),
            'is_declined' => $transaction->status === Transaction::STATUS_OFFER_DECLINED,
            'updated_at' => now(),
        ];

        $statusId = $this->mapStatusToLegacyId($transaction->status, $legacyStoreId);

        if ($statusId !== null) {
            $data['status_id'] = $statusId;
        }

        DB::connection('legacy')
            ->table('transactions')
            ->where('id', $transaction->id)
            ->update($data);
    }

    /**
     * Sync transaction items to legacy.
     */
    protected function syncTransactionItems(Transaction $transaction): void
    {
        $transaction->loadMissing('items');

        foreach ($transaction->items as $item) {
            $exists = DB::connection('legacy')
                ->table('transaction_items')
                ->where('id', $item->id)
                ->exists();

            if (! $exists) {
                continue;
            }

            DB::connection('legacy')
                ->table('transaction_items')
                ->where('id', $item->id)
                ->update([
                    'title' => $item->title,
                    'price' => $item->price,
                    'buy_price' => $item->buy_price,
                    'dwt' => $item->dwt,
                    'description' => $item->description,
                    'precious_metal' => $item->precious_metal,
                    'quantity' => $item->quantity,
                    'updated_at' => now(),
                ]);
        }
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
