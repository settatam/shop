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

        $this->info('Checking migrated transactions with incorrect payment methods...');

        // Find all transactions with legacy payment addresses
        $transactions = Transaction::query()
            ->whereNotNull('payment_details')
            ->whereRaw("JSON_EXTRACT(payment_details, '$.legacy_payment_addresses') IS NOT NULL OR JSON_EXTRACT(payment_details, '$.legacy_payment_address') IS NOT NULL")
            ->get(['id', 'transaction_number', 'payment_method', 'payment_details']);

        $totalFixed = 0;

        foreach ($transactions as $transaction) {
            // payment_details may already be an array (if cast) or a JSON string
            $paymentDetails = is_array($transaction->payment_details)
                ? $transaction->payment_details
                : json_decode($transaction->payment_details, true);

            // Handle both old format (single) and new format (multiple)
            $paymentAddresses = $paymentDetails['legacy_payment_addresses']
                ?? ($paymentDetails['legacy_payment_address'] ? [$paymentDetails['legacy_payment_address']] : []);

            if (empty($paymentAddresses)) {
                continue;
            }

            // Map all payment type IDs to methods
            $methods = collect($paymentAddresses)
                ->map(fn ($pa) => $this->mapPaymentMethodById((int) ($pa['payment_type_id'] ?? 0)))
                ->filter()
                ->unique()
                ->values()
                ->toArray();

            $correctMethod = count($methods) > 0 ? implode(',', $methods) : null;

            // Check if current payment_method is different
            if ($transaction->payment_method === $correctMethod) {
                continue;
            }

            $oldMethod = $transaction->payment_method ?? 'NULL';

            if ($dryRun) {
                $this->line("Would update {$transaction->transaction_number}: {$oldMethod} -> {$correctMethod}");
            } else {
                $transaction->update(['payment_method' => $correctMethod]);
                $this->line("Updated {$transaction->transaction_number}: {$oldMethod} -> {$correctMethod}");
            }

            $totalFixed++;
        }

        if ($totalFixed === 0) {
            $this->info('No transactions need fixing.');
        } else {
            $action = $dryRun ? 'would be fixed' : 'fixed';
            $this->info("Total: {$totalFixed} transactions {$action}.");
        }

        return self::SUCCESS;
    }

    /**
     * Map a legacy payment type ID to the new payment method constant.
     */
    protected function mapPaymentMethodById(int $paymentTypeId): ?string
    {
        return match ($paymentTypeId) {
            1 => Transaction::PAYMENT_CHECK,
            2 => Transaction::PAYMENT_PAYPAL,
            3 => Transaction::PAYMENT_ACH,
            4 => Transaction::PAYMENT_VENMO,
            5 => Transaction::PAYMENT_STORE_CREDIT,
            6 => Transaction::PAYMENT_CASH,
            default => null,
        };
    }
}
