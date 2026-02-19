<?php

namespace App\Services\Transactions;

use App\Models\Activity;
use App\Models\ActivityLog;
use App\Models\Transaction;
use App\Services\StoreContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Payment service specifically for online buys workflow (stores 43/44).
 * Handles bulk payment operations for mail-in/online transactions.
 */
class TransactionPaymentService
{
    public function __construct(
        protected StoreContext $storeContext,
    ) {}

    /**
     * Mark multiple online transactions as paid.
     * Only processes transactions from stores with online buys workflow enabled.
     *
     * @param  array<int>  $transactionIds
     * @return array{success: int, failed: int, errors: array<string>}
     */
    public function bulkMarkPaid(array $transactionIds): array
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return [
                'success' => 0,
                'failed' => count($transactionIds),
                'errors' => ['No store context available.'],
            ];
        }

        // Only allow for stores with online buys workflow
        if (! $store->hasOnlineBuysWorkflow()) {
            return [
                'success' => 0,
                'failed' => count($transactionIds),
                'errors' => ['This feature is only available for online buys workflow.'],
            ];
        }

        // Only process online/mail-in transactions
        $transactions = Transaction::where('store_id', $store->id)
            ->whereIn('id', $transactionIds)
            ->where('type', Transaction::TYPE_MAIL_IN)
            ->get();

        $success = 0;
        $failed = 0;
        $errors = [];

        DB::beginTransaction();

        try {
            foreach ($transactions as $transaction) {
                $result = $this->markTransactionPaid($transaction);

                if ($result['success']) {
                    $success++;
                } else {
                    $failed++;
                    $errors[] = "{$transaction->transaction_number}: {$result['error']}";
                }
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();

            return [
                'success' => 0,
                'failed' => count($transactionIds),
                'errors' => [$e->getMessage()],
            ];
        }

        // Note how many were skipped because they weren't online transactions
        $skipped = count($transactionIds) - $transactions->count();
        if ($skipped > 0) {
            $errors[] = "{$skipped} transaction(s) skipped because they are not online/mail-in transactions.";
        }

        return [
            'success' => $success,
            'failed' => $failed,
            'errors' => $errors,
        ];
    }

    /**
     * Mark a single online transaction as paid.
     *
     * @return array{success: bool, error: string|null}
     */
    public function markTransactionPaid(Transaction $transaction): array
    {
        // Only process online transactions
        if (! $transaction->isOnline()) {
            return [
                'success' => false,
                'error' => 'Only online/mail-in transactions can be processed through this service.',
            ];
        }

        if (! $this->canMarkAsPaid($transaction)) {
            return [
                'success' => false,
                'error' => 'Transaction is not in a valid state to be marked as paid.',
            ];
        }

        $oldStatus = $transaction->status;

        $transaction->update([
            'status' => Transaction::STATUS_PAYMENT_PROCESSED,
            'payment_processed_at' => now(),
        ]);

        ActivityLog::log(
            Activity::TRANSACTIONS_PAYMENT_PROCESSED,
            $transaction,
            null,
            [
                'old_status' => $oldStatus,
                'new_status' => Transaction::STATUS_PAYMENT_PROCESSED,
                'source' => 'bulk_operation',
            ],
            "Transaction {$transaction->transaction_number} marked as paid (bulk operation)"
        );

        $transaction->recordStatusChange(
            $oldStatus,
            Transaction::STATUS_PAYMENT_PROCESSED,
            'Marked as paid via bulk operation'
        );

        return ['success' => true, 'error' => null];
    }

    /**
     * Check if an online transaction can be marked as paid.
     */
    protected function canMarkAsPaid(Transaction $transaction): bool
    {
        return in_array($transaction->status, [
            Transaction::STATUS_OFFER_ACCEPTED,
            Transaction::STATUS_PAYMENT_PENDING,
        ]);
    }

    /**
     * Get online transactions that are ready for payment.
     *
     * @return Collection<int, Transaction>
     */
    public function getTransactionsReadyForPayment(): Collection
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store || ! $store->hasOnlineBuysWorkflow()) {
            return new Collection;
        }

        return Transaction::where('store_id', $store->id)
            ->where('type', Transaction::TYPE_MAIL_IN)
            ->whereIn('status', [
                Transaction::STATUS_OFFER_ACCEPTED,
                Transaction::STATUS_PAYMENT_PENDING,
            ])
            ->with(['customer', 'latestOffer'])
            ->orderBy('offer_accepted_at')
            ->get();
    }

    /**
     * Get payment summary for multiple online transactions.
     *
     * @param  array<int>  $transactionIds
     * @return array{total_amount: float, transaction_count: int, by_payment_method: array<string, float>}
     */
    public function getPaymentSummary(array $transactionIds): array
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store || ! $store->hasOnlineBuysWorkflow()) {
            return [
                'total_amount' => 0,
                'transaction_count' => 0,
                'by_payment_method' => [],
            ];
        }

        $transactions = Transaction::where('store_id', $store->id)
            ->whereIn('id', $transactionIds)
            ->where('type', Transaction::TYPE_MAIL_IN)
            ->whereNotNull('final_offer')
            ->get();

        $byMethod = $transactions->groupBy('payment_method')
            ->map(fn ($group) => $group->sum('final_offer'));

        return [
            'total_amount' => $transactions->sum('final_offer'),
            'transaction_count' => $transactions->count(),
            'by_payment_method' => $byMethod->toArray(),
        ];
    }
}
