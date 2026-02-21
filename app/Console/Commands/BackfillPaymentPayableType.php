<?php

namespace App\Console\Commands;

use App\Models\Memo;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Repair;
use Illuminate\Console\Command;

class BackfillPaymentPayableType extends Command
{
    protected $signature = 'payments:backfill-payable-type
                            {--store-id= : Store ID to backfill}
                            {--dry-run : Show what would be updated without making changes}';

    protected $description = 'Backfill payable_type for payments based on order_number prefix (MEM=Memo, REP=Repair)';

    public function handle(): int
    {
        $storeId = $this->option('store-id') ? (int) $this->option('store-id') : null;
        $isDryRun = $this->option('dry-run');

        if ($isDryRun) {
            $this->warn('DRY RUN MODE - No changes will be made');
        }

        // Query payments that have Order as payable_type
        $query = Payment::query()
            ->where('payable_type', Order::class)
            ->whereNotNull('order_id');

        if ($storeId) {
            $query->where('store_id', $storeId);
            $this->info("Processing payments for store ID: {$storeId}");
        } else {
            $this->info('Processing payments for all stores');
        }

        $payments = $query->with('payable')->get();
        $this->info("Found {$payments->count()} payments with Order payable_type");

        $memoCount = 0;
        $repairCount = 0;
        $skipped = 0;

        foreach ($payments as $payment) {
            $order = $payment->payable;
            if (! $order) {
                $skipped++;

                continue;
            }

            // Check the order's invoice_number for MEM/REP prefix
            $invoiceNumber = strtoupper($order->invoice_number ?? '');

            if (str_starts_with($invoiceNumber, 'MEM')) {
                // Find matching Memo
                $memo = Memo::where('store_id', $payment->store_id)
                    ->where('memo_number', $order->invoice_number)
                    ->first();

                if ($memo) {
                    if ($isDryRun) {
                        $this->line("  Would update payment #{$payment->id}: Order -> Memo (#{$memo->memo_number})");
                    } else {
                        $payment->update([
                            'payable_type' => Memo::class,
                            'payable_id' => $memo->id,
                        ]);
                        $this->line("  Updated payment #{$payment->id}: Order -> Memo (#{$memo->memo_number})");
                    }
                    $memoCount++;
                } else {
                    $this->warn("  Could not find Memo for invoice_number: {$order->invoice_number}");
                    $skipped++;
                }
            } elseif (str_starts_with($invoiceNumber, 'REP')) {
                // Find matching Repair
                $repair = Repair::where('store_id', $payment->store_id)
                    ->where('repair_number', $order->invoice_number)
                    ->first();

                if ($repair) {
                    if ($isDryRun) {
                        $this->line("  Would update payment #{$payment->id}: Order -> Repair (#{$repair->repair_number})");
                    } else {
                        $payment->update([
                            'payable_type' => Repair::class,
                            'payable_id' => $repair->id,
                        ]);
                        $this->line("  Updated payment #{$payment->id}: Order -> Repair (#{$repair->repair_number})");
                    }
                    $repairCount++;
                } else {
                    $this->warn("  Could not find Repair for invoice_number: {$order->invoice_number}");
                    $skipped++;
                }
            }
        }

        $this->newLine();
        $this->info('=== Backfill Summary ===');
        $this->line("Payments updated to Memo: {$memoCount}");
        $this->line("Payments updated to Repair: {$repairCount}");
        $this->line("Skipped: {$skipped}");

        if ($isDryRun) {
            $this->warn('This was a dry run. No changes were made.');
        }

        return 0;
    }
}
