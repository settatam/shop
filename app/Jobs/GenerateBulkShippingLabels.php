<?php

namespace App\Jobs;

use App\Models\ShippingLabel;
use App\Models\Store;
use App\Models\Transaction;
use App\Services\Shipping\ShippingLabelService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

/**
 * Job for generating shipping labels for multiple online transactions in bulk.
 * Specifically for online buys workflow (stores 43/44).
 */
class GenerateBulkShippingLabels implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<int>  $transactionIds
     * @param  array<string, mixed>  $options  Shipping options (service_type, packaging_type, etc.)
     */
    public function __construct(
        public Store $store,
        public array $transactionIds,
        public string $labelType = 'outbound', // 'outbound' or 'return'
        public array $options = [],
        public ?int $userId = null,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(ShippingLabelService $shippingLabelService): void
    {
        // Validate store has online buys workflow
        if (! $this->store->hasOnlineBuysWorkflow()) {
            Log::warning('Bulk shipping labels attempted for store without online buys workflow', [
                'store_id' => $this->store->id,
            ]);

            return;
        }

        $transactions = Transaction::where('store_id', $this->store->id)
            ->whereIn('id', $this->transactionIds)
            ->where('type', Transaction::TYPE_MAIL_IN)
            ->with(['customer', 'shippingAddress'])
            ->get();

        $results = [
            'success' => [],
            'failed' => [],
        ];

        foreach ($transactions as $transaction) {
            try {
                if (! $transaction->customer) {
                    $results['failed'][] = [
                        'transaction_id' => $transaction->id,
                        'transaction_number' => $transaction->transaction_number,
                        'error' => 'No customer associated with transaction',
                    ];

                    continue;
                }

                // Check if label already exists
                $existingLabel = $this->labelType === 'outbound'
                    ? $transaction->outboundLabel
                    : $transaction->returnLabel;

                if ($existingLabel && $existingLabel->status === ShippingLabel::STATUS_CREATED) {
                    $results['success'][] = [
                        'transaction_id' => $transaction->id,
                        'transaction_number' => $transaction->transaction_number,
                        'label_id' => $existingLabel->id,
                        'tracking_number' => $existingLabel->tracking_number,
                        'skipped' => true,
                    ];

                    continue;
                }

                // Generate label
                $label = $this->labelType === 'outbound'
                    ? $shippingLabelService->createOutboundLabel($transaction, $this->options)
                    : $shippingLabelService->createReturnLabel($transaction, $this->options);

                // Update transaction tracking info
                if ($this->labelType === 'outbound') {
                    $transaction->update([
                        'outbound_tracking_number' => $label->tracking_number,
                        'outbound_carrier' => $label->carrier,
                    ]);
                } else {
                    $transaction->update([
                        'return_tracking_number' => $label->tracking_number,
                        'return_carrier' => $label->carrier,
                    ]);
                }

                $results['success'][] = [
                    'transaction_id' => $transaction->id,
                    'transaction_number' => $transaction->transaction_number,
                    'label_id' => $label->id,
                    'tracking_number' => $label->tracking_number,
                    'skipped' => false,
                ];
            } catch (\Exception $e) {
                Log::error('Failed to generate shipping label', [
                    'transaction_id' => $transaction->id,
                    'type' => $this->labelType,
                    'error' => $e->getMessage(),
                ]);

                $results['failed'][] = [
                    'transaction_id' => $transaction->id,
                    'transaction_number' => $transaction->transaction_number,
                    'error' => $e->getMessage(),
                ];
            }
        }

        // Store results for later retrieval
        $this->storeResults($results);

        // If all labels were generated, create a combined PDF or ZIP
        if (! empty($results['success'])) {
            $this->createBulkDownload($results['success'], $shippingLabelService);
        }
    }

    /**
     * Store job results for retrieval.
     *
     * @param  array<string, array<array<string, mixed>>>  $results
     */
    protected function storeResults(array $results): void
    {
        $key = $this->getResultsKey();
        $results['completed_at'] = now()->toISOString();
        $results['store_id'] = $this->store->id;
        $results['user_id'] = $this->userId;
        $results['label_type'] = $this->labelType;

        Storage::put($key, json_encode($results));
    }

    /**
     * Create a ZIP file containing all generated labels.
     *
     * @param  array<array<string, mixed>>  $successResults
     */
    protected function createBulkDownload(array $successResults, ShippingLabelService $shippingLabelService): void
    {
        $labelIds = collect($successResults)
            ->where('skipped', false)
            ->pluck('label_id')
            ->filter()
            ->toArray();

        if (empty($labelIds)) {
            return;
        }

        $labels = ShippingLabel::whereIn('id', $labelIds)->get();
        $zipPath = $this->getZipPath();
        $tempPath = storage_path('app/'.$zipPath);

        // Ensure directory exists
        $dir = dirname($tempPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $zip = new ZipArchive;
        if ($zip->open($tempPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            Log::error('Failed to create ZIP file for bulk labels', ['path' => $tempPath]);

            return;
        }

        foreach ($labels as $label) {
            $pdf = $shippingLabelService->getLabelPdf($label);
            if ($pdf) {
                $filename = sprintf(
                    '%s-%s-%s.pdf',
                    $this->labelType,
                    $label->shippable->transaction_number ?? $label->id,
                    $label->tracking_number
                );
                $zip->addFromString($filename, $pdf);
            }
        }

        $zip->close();

        // Update results with ZIP path
        $key = $this->getResultsKey();
        if (Storage::exists($key)) {
            $results = json_decode(Storage::get($key), true);
            $results['zip_path'] = $zipPath;
            Storage::put($key, json_encode($results));
        }
    }

    /**
     * Get the storage key for results.
     */
    protected function getResultsKey(): string
    {
        return sprintf(
            'bulk-labels/%s/%s-results.json',
            $this->store->id,
            $this->job?->getJobId() ?? now()->timestamp
        );
    }

    /**
     * Get the path for the ZIP file.
     */
    protected function getZipPath(): string
    {
        return sprintf(
            'bulk-labels/%s/labels-%s.zip',
            $this->store->id,
            now()->format('Y-m-d-His')
        );
    }
}
