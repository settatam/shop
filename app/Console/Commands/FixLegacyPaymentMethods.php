<?php

namespace App\Console\Commands;

use App\Models\Transaction;
use Illuminate\Console\Command;

class FixLegacyPaymentMethods extends Command
{
    protected $signature = 'fix:legacy-payment-methods {--dry-run : Show what would be updated without making changes}';

    protected $description = 'Fix payment methods for migrated legacy transactions using the correct ID mapping';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->info('DRY RUN - No changes will be made');
        }

        // Legacy code mapping (from PaymentType::getIdFromName):
        // 1 = check, 2 = paypal, 3 = ach, 4 = venmo, 5 = store_credit, 6 = cash
        $mapping = [
            1 => Transaction::PAYMENT_CHECK,
            2 => Transaction::PAYMENT_PAYPAL,
            3 => Transaction::PAYMENT_ACH,
            4 => Transaction::PAYMENT_VENMO,
            5 => Transaction::PAYMENT_STORE_CREDIT,
            6 => Transaction::PAYMENT_CASH,
        ];

        $this->info('Checking migrated transactions with incorrect payment methods...');

        $totalFixed = 0;

        foreach ($mapping as $legacyId => $correctMethod) {
            // Find transactions where legacy payment_type_id matches but payment_method is wrong
            $transactions = Transaction::query()
                ->whereNotNull('payment_details')
                ->whereRaw("JSON_EXTRACT(payment_details, '$.legacy_payment_address.payment_type_id') = ?", [$legacyId])
                ->where(function ($query) use ($correctMethod) {
                    $query->where('payment_method', '!=', $correctMethod)
                        ->orWhereNull('payment_method');
                })
                ->get(['id', 'transaction_number', 'payment_method', 'payment_details']);

            if ($transactions->isEmpty()) {
                continue;
            }

            $this->info("Found {$transactions->count()} transactions with payment_type_id={$legacyId} needing update to '{$correctMethod}'");

            foreach ($transactions as $transaction) {
                $oldMethod = $transaction->payment_method ?? 'NULL';

                if ($dryRun) {
                    $this->line("  Would update {$transaction->transaction_number}: {$oldMethod} -> {$correctMethod}");
                } else {
                    $transaction->update(['payment_method' => $correctMethod]);
                    $this->line("  Updated {$transaction->transaction_number}: {$oldMethod} -> {$correctMethod}");
                }

                $totalFixed++;
            }
        }

        if ($totalFixed === 0) {
            $this->info('No transactions need fixing.');
        } else {
            $action = $dryRun ? 'would be fixed' : 'fixed';
            $this->info("Total: {$totalFixed} transactions {$action}.");
        }

        return self::SUCCESS;
    }
}
