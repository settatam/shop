<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Models\Memo;
use App\Models\Payment;
use App\Services\Invoices\InvoiceService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillMemoInvoices extends Command
{
    protected $signature = 'app:backfill-memo-invoices {--dry-run : Show what would be created without making changes}';

    protected $description = 'Create missing invoices and payments for completed memos';

    public function handle(InvoiceService $invoiceService): int
    {
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->info('Running in dry-run mode - no changes will be made.');
        }

        // Find memos with payment_received status that don't have invoices
        $memos = Memo::where('status', Memo::STATUS_PAYMENT_RECEIVED)
            ->whereDoesntHave('invoice')
            ->with(['vendor', 'items'])
            ->get();

        if ($memos->isEmpty()) {
            $this->info('No memos need backfilling.');

            return Command::SUCCESS;
        }

        $this->info("Found {$memos->count()} memo(s) that need invoices and payments.");

        $createdInvoices = 0;
        $createdPayments = 0;

        foreach ($memos as $memo) {
            $this->line('');
            $this->info("Processing: {$memo->memo_number}");
            $this->line("  Vendor: {$memo->vendor?->name}");
            $effectiveTotal = (float) $memo->grand_total > 0 ? $memo->grand_total : $memo->total;
            $this->line('  Total: $'.number_format($effectiveTotal, 2));
            $this->line('  Total Paid: $'.number_format($memo->total_paid ?? 0, 2));

            if ($dryRun) {
                $this->line('  [DRY RUN] Would create invoice and payment');
                $createdInvoices++;
                $createdPayments++;

                continue;
            }

            DB::transaction(function () use ($memo, $invoiceService, &$createdInvoices, &$createdPayments) {
                // Create invoice
                $invoice = $invoiceService->createFromMemo($memo);
                $createdInvoices++;
                $this->line("  Created invoice: {$invoice->invoice_number}");

                // Determine payment amount (use grand_total if > 0, otherwise total)
                $paymentAmount = (float) $memo->grand_total > 0 ? (float) $memo->grand_total : (float) ($memo->total ?? 0);

                if ($paymentAmount > 0) {
                    // Create a completed payment record
                    $payment = Payment::create([
                        'store_id' => $memo->store_id,
                        'payable_type' => Memo::class,
                        'payable_id' => $memo->id,
                        'invoice_id' => $invoice->id,
                        'memo_id' => $memo->id,
                        'customer_id' => null, // Memos are vendor-based
                        'user_id' => $memo->user_id,
                        'payment_method' => Payment::METHOD_EXTERNAL, // Legacy payment
                        'status' => Payment::STATUS_COMPLETED,
                        'amount' => $paymentAmount,
                        'currency' => 'USD',
                        'notes' => 'Backfilled payment for completed memo',
                        'paid_at' => $memo->updated_at, // Use memo completion time
                    ]);

                    $createdPayments++;
                    $this->line("  Created payment: #{$payment->id} for \$".number_format($paymentAmount, 2));

                    // Update invoice totals
                    $invoice->update([
                        'total_paid' => $paymentAmount,
                        'balance_due' => 0,
                        'status' => Invoice::STATUS_PAID,
                        'paid_at' => $memo->updated_at,
                    ]);
                }
            });
        }

        $this->line('');
        $this->info('Backfill complete!');
        $this->line("  Invoices created: {$createdInvoices}");
        $this->line("  Payments created: {$createdPayments}");

        return Command::SUCCESS;
    }
}
