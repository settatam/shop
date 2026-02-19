<?php

namespace App\Services\Payments;

use App\Models\PayoutExport;
use App\Models\Transaction;
use App\Services\StoreContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use League\Csv\Writer;
use SplTempFileObject;

/**
 * Service for exporting payment files for online buys workflow (stores 43/44).
 * Supports CSV, Excel, and PayPal batch format exports.
 */
class PayoutExportService
{
    public function __construct(
        protected StoreContext $storeContext,
    ) {}

    /**
     * Export transactions to CSV format.
     *
     * @param  array<string, mixed>  $filters
     * @return array{filename: string, path: string, record_count: int, export: PayoutExport}
     */
    public function exportToCsv(array $filters = []): array
    {
        $transactions = $this->getTransactionsForExport($filters);
        $filename = $this->generateFilename('csv');
        $path = $this->getExportPath($filename);

        $csv = Writer::createFromFileObject(new SplTempFileObject);

        // Add header row
        $csv->insertOne([
            'transaction_id',
            'transaction_number',
            'customer_name',
            'email',
            'phone',
            'payout_amount',
            'payment_method',
            'status',
            'offer_accepted_at',
        ]);

        // Add data rows
        foreach ($transactions as $transaction) {
            $csv->insertOne([
                $transaction->id,
                $transaction->transaction_number,
                $transaction->customer?->full_name ?? '',
                $transaction->customer?->email ?? '',
                $transaction->customer?->phone_number ?? '',
                $transaction->final_offer ?? 0,
                $transaction->payment_method ?? '',
                $transaction->status,
                $transaction->offer_accepted_at?->format('Y-m-d H:i:s') ?? '',
            ]);
        }

        // Store the file
        Storage::put($path, $csv->toString());

        // Create export record
        $export = $this->createExportRecord($filename, PayoutExport::FORMAT_CSV, $transactions->count(), $filters);

        return [
            'filename' => $filename,
            'path' => $path,
            'record_count' => $transactions->count(),
            'export' => $export,
        ];
    }

    /**
     * Export transactions in PayPal batch format for direct upload.
     *
     * @param  array<string, mixed>  $filters
     * @return array{filename: string, path: string, record_count: int, export: PayoutExport}
     */
    public function exportToPayPalBatch(array $filters = []): array
    {
        $transactions = $this->getTransactionsForExport($filters);

        // Filter to only PayPal payment methods
        $paypalTransactions = $transactions->filter(function ($transaction) {
            return $transaction->payment_method === Transaction::PAYMENT_PAYPAL
                && $transaction->customer?->email;
        });

        $filename = $this->generateFilename('paypal_batch.csv');
        $path = $this->getExportPath($filename);

        $csv = Writer::createFromFileObject(new SplTempFileObject);

        // PayPal batch payout format
        $csv->insertOne([
            'Recipient Email',
            'Recipient First Name',
            'Recipient Last Name',
            'Amount',
            'Currency',
            'Note',
            'Recipient ID Type',
            'Recipient Unique ID',
        ]);

        foreach ($paypalTransactions as $transaction) {
            // Get PayPal email from payment details or customer email
            $paypalEmail = $this->getPayPalEmailFromTransaction($transaction);

            $csv->insertOne([
                $paypalEmail,
                $transaction->customer?->first_name ?? '',
                $transaction->customer?->last_name ?? '',
                number_format((float) $transaction->final_offer, 2, '.', ''),
                'USD',
                "Payment for {$transaction->transaction_number}",
                'EMAIL',
                $transaction->transaction_number,
            ]);
        }

        Storage::put($path, $csv->toString());

        $export = $this->createExportRecord($filename, PayoutExport::FORMAT_PAYPAL, $paypalTransactions->count(), $filters);

        return [
            'filename' => $filename,
            'path' => $path,
            'record_count' => $paypalTransactions->count(),
            'export' => $export,
        ];
    }

    /**
     * Get PayPal email from transaction payment details or customer.
     */
    protected function getPayPalEmailFromTransaction(Transaction $transaction): string
    {
        $details = $transaction->payment_details ?? [];

        // Check for PayPal-specific email in payment details
        if (isset($details['payments']) && is_array($details['payments'])) {
            foreach ($details['payments'] as $payment) {
                if (($payment['method'] ?? '') === 'paypal') {
                    return $payment['details']['paypal_email'] ?? $payment['details']['email'] ?? '';
                }
            }
        }

        // Check for legacy format
        if (isset($details['paypal_email'])) {
            return $details['paypal_email'];
        }

        // Fall back to customer email
        return $transaction->customer?->email ?? '';
    }

    /**
     * Get transactions for export based on filters.
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, Transaction>
     */
    protected function getTransactionsForExport(array $filters): Collection
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store || ! $store->hasOnlineBuysWorkflow()) {
            return collect();
        }

        $query = Transaction::where('store_id', $store->id)
            ->where('type', Transaction::TYPE_MAIL_IN)
            ->with(['customer']);

        // Filter by status
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        } else {
            // Default to transactions ready for payment
            $query->whereIn('status', [
                Transaction::STATUS_OFFER_ACCEPTED,
                Transaction::STATUS_PAYMENT_PENDING,
            ]);
        }

        // Filter by date range
        if (! empty($filters['date_from'])) {
            $query->whereDate('offer_accepted_at', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('offer_accepted_at', '<=', $filters['date_to']);
        }

        // Filter by payment method
        if (! empty($filters['payment_method'])) {
            $query->where('payment_method', $filters['payment_method']);
        }

        // Filter by specific IDs
        if (! empty($filters['transaction_ids'])) {
            $query->whereIn('id', $filters['transaction_ids']);
        }

        return $query->orderBy('offer_accepted_at')->get();
    }

    /**
     * Create an export record.
     *
     * @param  array<string, mixed>  $filters
     */
    protected function createExportRecord(string $filename, string $format, int $recordCount, array $filters): PayoutExport
    {
        $store = $this->storeContext->getCurrentStore();

        return PayoutExport::create([
            'store_id' => $store->id,
            'user_id' => auth()->id(),
            'filename' => $filename,
            'format' => $format,
            'record_count' => $recordCount,
            'filters' => $filters,
            'date_from' => $filters['date_from'] ?? null,
            'date_to' => $filters['date_to'] ?? null,
        ]);
    }

    /**
     * Generate a unique filename for export.
     */
    protected function generateFilename(string $extension): string
    {
        return sprintf('payout-export-%s.%s', now()->format('Y-m-d-His'), $extension);
    }

    /**
     * Get the storage path for exports.
     */
    protected function getExportPath(string $filename): string
    {
        $store = $this->storeContext->getCurrentStore();

        return sprintf('exports/payouts/%s/%s', $store->id, $filename);
    }

    /**
     * Download an export file.
     */
    public function download(PayoutExport $export): ?string
    {
        $path = $this->getExportPath($export->filename);

        if (! Storage::exists($path)) {
            return null;
        }

        return Storage::get($path);
    }

    /**
     * Get export history for the current store.
     *
     * @return Collection<int, PayoutExport>
     */
    public function getExportHistory(int $limit = 20): Collection
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return collect();
        }

        return PayoutExport::where('store_id', $store->id)
            ->with('user')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }
}
