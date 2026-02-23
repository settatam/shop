<?php

namespace App\Console\Commands;

use App\Models\TransactionWarehouse;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncLegacyWarehouse extends Command
{
    protected $signature = 'sync:legacy-warehouse
        {--store= : Comma-separated list of legacy store IDs to sync (defaults to all configured)}
        {--fresh : Clear existing data before syncing}
        {--dry-run : Show what would be synced without making changes}';

    protected $description = 'Sync transaction warehouse data from the legacy database';

    public function handle(): int
    {
        if (! config('legacy-sync.enabled')) {
            $this->error('Legacy sync is disabled. Set LEGACY_SYNC_ENABLED=true to enable.');

            return self::FAILURE;
        }

        $storeMapping = TransactionWarehouse::getStoreMapping();

        if (empty($storeMapping)) {
            $this->error('No store mappings configured. Set LEGACY_STORE_MAPPING env variable.');
            $this->line('Example: LEGACY_STORE_MAPPING="43:43,44:1,63:3"');

            return self::FAILURE;
        }

        $legacyStoreIds = $this->getLegacyStoreIds($storeMapping);

        if (empty($legacyStoreIds)) {
            $this->error('No valid store IDs to sync.');

            return self::FAILURE;
        }

        $dryRun = $this->option('dry-run');
        $connection = config('legacy-sync.connection');

        $this->info('Syncing legacy warehouse data...');
        $this->table(
            ['Legacy Store ID', 'New Store ID'],
            collect($legacyStoreIds)->map(fn ($id) => [$id, $storeMapping[$id]])->toArray()
        );

        if ($dryRun) {
            $this->warn('DRY RUN - No changes will be made');
        }

        if ($this->option('fresh') && ! $dryRun) {
            $this->clearExistingData($legacyStoreIds);
        }

        $totalSynced = 0;
        $totalSkipped = 0;

        foreach ($legacyStoreIds as $legacyStoreId) {
            $newStoreId = $storeMapping[$legacyStoreId];
            $this->newLine();
            $this->info("Syncing legacy store {$legacyStoreId} → new store {$newStoreId}...");

            try {
                $count = $this->syncStore($connection, $legacyStoreId, $newStoreId, $dryRun);
                $totalSynced += $count;
                $this->line("  Synced {$count} records");
            } catch (\Exception $e) {
                $this->error("  Error syncing store {$legacyStoreId}: {$e->getMessage()}");
                $totalSkipped++;
            }
        }

        $this->newLine();
        $this->info("Sync complete. Total records synced: {$totalSynced}");

        if ($totalSkipped > 0) {
            $this->warn("Stores with errors: {$totalSkipped}");
        }

        return self::SUCCESS;
    }

    /**
     * @return array<int>
     */
    protected function getLegacyStoreIds(array $storeMapping): array
    {
        $storeOption = $this->option('store');

        if ($storeOption) {
            $requestedIds = array_map('intval', explode(',', $storeOption));

            return array_filter($requestedIds, fn ($id) => isset($storeMapping[$id]));
        }

        return array_keys($storeMapping);
    }

    protected function clearExistingData(array $legacyStoreIds): void
    {
        $this->info('Clearing existing warehouse data...');

        $deleted = TransactionWarehouse::whereIn('legacy_store_id', $legacyStoreIds)->delete();

        $this->line("  Deleted {$deleted} existing records");
    }

    protected function syncStore(string $connection, int $legacyStoreId, int $newStoreId, bool $dryRun): int
    {
        $query = DB::connection($connection)
            ->table('transactions_warehouse')
            ->where('store_id', $legacyStoreId)
            ->whereNull('deleted_at');

        $totalCount = $query->count();

        if ($dryRun) {
            return $totalCount;
        }

        $synced = 0;
        $chunkSize = 500;

        $query->orderBy('id')->chunk($chunkSize, function ($records) use ($newStoreId, $legacyStoreId, &$synced) {
            foreach ($records as $record) {
                $this->upsertRecord($record, $newStoreId, $legacyStoreId);
                $synced++;
            }

            $this->output->write("\r  Processing... {$synced}");
        });

        $this->output->write("\r");

        return $synced;
    }

    protected function upsertRecord(object $record, int $newStoreId, int $legacyStoreId): void
    {
        $data = [
            'legacy_id' => $record->id,
            'legacy_store_id' => $legacyStoreId,
            'store_id' => $newStoreId,
            'transaction_id' => $record->transaction_id,
            'bought' => $record->bought,
            'estimated_value' => $record->estimated_value,
            'final_offer' => $record->final_offer,
            'profit' => $record->profit,
            'profit_percent' => $record->profit_percent,
            'estimated_profit' => $record->estimated_profit,
            'total_dwt' => $record->total_dwt,
            'number_of_transaction' => $record->number_of_transaction,
            'number_of_items' => $record->number_of_items,
            'status' => $record->status,
            'status_id' => $record->status_id,
            'source' => $record->source,
            'payment_type' => $record->payment_type,
            'payment_type_id' => $record->payment_type_id,
            'customer_id' => $record->customer_id,
            'customer_name' => $record->customer_name,
            'customer_first_name' => $record->customer_first_name,
            'customer_last_name' => $record->customer_last_name,
            'first_name' => $record->first_name,
            'last_name' => $record->last_name,
            'email' => $record->email,
            'phone' => $record->phone,
            'customer_since' => $record->customer_since,
            'is_repeat_customer' => $record->is_repeat_customer,
            'age' => $record->age,
            'gender' => $record->gender,
            'dob' => $record->dob,
            'behavior' => $record->behavior,
            'street_address' => $record->street_address,
            'suite_apt' => $record->suite_apt,
            'city' => $record->city,
            'state' => $record->state,
            'zip' => $record->zip,
            'state_id' => $record->state_id,
            'ip_address' => $record->ip_address,
            'incoming_fedex' => $record->incoming_fedex,
            'outgoing_fedex' => $record->outgoing_fedex,
            'incoming_tracking' => $record->incoming_tracking,
            'outgoing_tracking' => $record->outgoing_tracking,
            'transit_days' => $record->transit_days,
            'kit_request_date_time' => $record->kit_request_date_time,
            'kit_print_on' => $record->kit_print_on,
            'kit_sent_date_time' => $record->kit_sent_date_time,
            'kit_sent_not_received_date' => $record->kit_sent_not_received_date,
            'kit_received_ready_to_buy' => $record->kit_received_ready_to_buy,
            'pending_kit_confirmed_date_time' => $record->pending_kit_confirmed_date_time,
            'pending_kit_on_hold_date_time' => $record->pending_kit_on_hold_date_time,
            'pending_kit_returned_date_time' => $record->pending_kit_returned_date_time,
            'pending_kit_request_high_value' => $record->pending_kit_request_high_value,
            'pending_kit_request_high_value_watch_date_time' => $record->pending_kit_request_high_value_watch_date_time,
            'pending_kit_request_bulk_date_time' => $record->pending_kit_request_bulk_date_time,
            'pending_kit_request_incomplete' => $record->pending_kit_request_incomplete,
            'pending_kit_request_rejected_by_customer' => $record->pending_kit_request_rejected_by_customer,
            'shipment_received_on' => $record->shipment_received_on,
            'shipment_returned_on' => $record->shipment_returned_on,
            'shipment_declined_on' => $record->shipment_declined_on,
            'offer_given_on' => $record->offer_given_on,
            'offer_given_date_time' => $record->offer_given_date_time,
            'offer_accepted_on' => $record->offer_accepted_on,
            'offer_accepted_date_time' => $record->offer_accepted_date_time,
            'offer_declined_date_time' => $record->offer_declined_date_time,
            'offer_declined_send_back_date_time' => $record->offer_declined_send_back_date_time,
            'offer_declined' => $record->offer_declined,
            'offer_paid_on' => $record->offer_paid_on,
            'payment_date_time' => $record->payment_date_time,
            'received_date_time' => $record->received_date_time,
            'received_rejected_date_time' => $record->received_rejected_date_time,
            'returned_date_time' => $record->returned_date_time,
            'refused_by_fedex_date_time' => $record->refused_by_fedex_date_time,
            'reviewed_date_time' => $record->reviewed_date_time,
            'on_hold_date_time' => $record->on_hold_date_time,
            'hold_date' => $record->hold_date,
            'sold_date_time' => $record->sold_date_time,
            'melt_date_time' => $record->melt_date_time,
            'customer_declined_date_time' => $record->customer_declined_date_time,
            'kit_rejected_hard_to_sell_date_time' => $record->kit_rejected_hard_to_sell_date_time,
            'kit_rejected_high_markup_date_time' => $record->kit_rejected_high_markup_date_time,
            'paypal_address' => $record->paypal_address,
            'venmo_address' => $record->venmo_address,
            'bank_name' => $record->bank_name,
            'bank_address' => $record->bank_address,
            'bank_address_2' => $record->bank_address_2,
            'bank_address_city' => $record->bank_address_city,
            'bank_address_state_id' => $record->bank_address_state_id,
            'bank_address_zip' => $record->bank_address_zip,
            'routing_number' => $record->routing_number,
            'account_number' => $record->account_number,
            'account_name' => $record->account_name,
            'account_type' => $record->account_type,
            'check_name' => $record->check_name,
            'check_address' => $record->check_address,
            'check_address_2' => $record->check_address_2,
            'check_city' => $record->check_city,
            'check_zip' => $record->check_zip,
            'check_state_id' => $record->check_state_id,
            'check_state' => $record->check_state,
            'inotes' => $record->inotes,
            'cnotes' => $record->cnotes,
            'user_comment' => $record->user_comment,
            'images' => $record->images,
            'keywords' => $record->keywords,
            'tags' => $record->tags,
            'tag_id' => $record->tag_id,
            'lead' => $record->lead,
            'lead_id' => $record->lead_id,
            'website' => $record->website,
            'store' => $record->store,
            'days_in_stock' => $record->days_in_stock,
            'is_accepted' => $record->is_accepted,
            'is_rejected' => $record->is_rejected,
            'is_declined' => $record->is_declined,
            'latest_incoming_sms_date' => $record->latest_incoming_sms_date,
            'latest_incoming_sms_id' => $record->latest_incoming_sms_id,
            'latest_incoming_sms_is_read' => $record->latest_incoming_sms_is_read,
            'latest_response_notification' => $record->latest_response_notification,
            'color' => $record->color,
            'icon_color' => $record->icon_color,
            'timezone_id' => $record->timezone_id,
            'total_customer_received_transactions' => $record->total_customer_received_transactions,
            'total_customer_pending_transactions' => $record->total_customer_pending_transactions,
            'traffic_source' => $record->traffic_source,
            'traffic_name' => $record->traffic_name,
            'google_seo_client_id' => $record->google_seo_client_id,
            'created_at' => $record->created_at,
            'updated_at' => now(),
        ];

        TransactionWarehouse::updateOrCreate(
            [
                'legacy_id' => $record->id,
                'legacy_store_id' => $legacyStoreId,
            ],
            $data
        );
    }
}
