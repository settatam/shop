<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillTransactionsFromLegacy extends Command
{
    protected $signature = 'transactions:backfill-from-legacy
                            {--dry-run : Show what would be updated without making changes}';

    protected $description = 'Backfill missing transaction fields from the legacy transactions_warehouse table';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->info('DRY RUN - no changes will be made.');
        }

        $transactions = DB::table('transactions')
            ->where('status', 'payment_processed')
            ->whereNull('deleted_at')
            ->select([
                'id', 'transaction_number', 'estimated_value', 'payment_method',
                'payment_processed_at', 'offer_given_at', 'offer_accepted_at',
                'items_received_at', 'items_reviewed_at', 'kit_sent_at',
            ])
            ->get();

        $this->info("Found {$transactions->count()} payment_processed transactions.");

        $legacyRows = DB::connection('legacy')
            ->table('transactions_warehouse')
            ->whereIn('transaction_id', $transactions->pluck('transaction_number')->map(fn ($tn) => (int) $tn)->toArray())
            ->whereNull('deleted_at')
            ->select([
                'transaction_id', 'estimated_value', 'payment_type', 'payment_date_time',
                'offer_given_date_time', 'offer_accepted_date_time', 'received_date_time',
                'reviewed_date_time', 'kit_sent_date_time',
            ])
            ->get()
            ->keyBy('transaction_id');

        $this->info("Matched {$legacyRows->count()} legacy warehouse records.");

        $updated = 0;
        $skipped = 0;

        foreach ($transactions as $txn) {
            $legacy = $legacyRows->get((int) $txn->transaction_number);
            if (! $legacy) {
                $skipped++;

                continue;
            }

            $updates = [];

            if ((empty($txn->estimated_value) || $txn->estimated_value == 0) && ! empty($legacy->estimated_value) && $legacy->estimated_value > 0) {
                $updates['estimated_value'] = $legacy->estimated_value;
            }

            if (empty($txn->payment_method) && ! empty($legacy->payment_type)) {
                $updates['payment_method'] = strtolower($legacy->payment_type);
            }

            if (is_null($txn->payment_processed_at) && ! is_null($legacy->payment_date_time)) {
                $updates['payment_processed_at'] = $legacy->payment_date_time;
            }

            if (is_null($txn->offer_given_at) && ! is_null($legacy->offer_given_date_time)) {
                $updates['offer_given_at'] = $legacy->offer_given_date_time;
            }

            if (is_null($txn->offer_accepted_at) && ! is_null($legacy->offer_accepted_date_time)) {
                $updates['offer_accepted_at'] = $legacy->offer_accepted_date_time;
            }

            if (is_null($txn->items_received_at) && ! is_null($legacy->received_date_time)) {
                $updates['items_received_at'] = $legacy->received_date_time;
            }

            if (is_null($txn->items_reviewed_at) && ! is_null($legacy->reviewed_date_time)) {
                $updates['items_reviewed_at'] = $legacy->reviewed_date_time;
            }

            if (is_null($txn->kit_sent_at) && ! is_null($legacy->kit_sent_date_time)) {
                $updates['kit_sent_at'] = $legacy->kit_sent_date_time;
            }

            if (! empty($updates)) {
                if ($dryRun) {
                    $this->line("  Would update transaction #{$txn->transaction_number}: ".implode(', ', array_keys($updates)));
                } else {
                    DB::table('transactions')->where('id', $txn->id)->update($updates);
                }
                $updated++;
            } else {
                $skipped++;
            }
        }

        $this->info("Done. Updated: {$updated}, Skipped: {$skipped}");

        return self::SUCCESS;
    }
}
