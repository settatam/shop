<?php

namespace App\Services\PackingSlips;

use App\Models\Memo;
use App\Models\Repair;
use App\Models\Transaction;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Response;

class PackingSlipPdfService
{
    /**
     * Generate a packing slip PDF for a model.
     */
    public function generate(Model $model): \Barryvdh\DomPDF\PDF
    {
        $data = $this->prepareData($model);

        return Pdf::loadView('pdf.packing-slip', $data)
            ->setPaper('letter', 'portrait');
    }

    /**
     * Download the packing slip PDF.
     */
    public function download(Model $model): Response
    {
        $pdf = $this->generate($model);
        $filename = $this->getFilename($model);

        return $pdf->download($filename);
    }

    /**
     * Stream the packing slip PDF (for printing).
     */
    public function stream(Model $model): Response
    {
        $pdf = $this->generate($model);
        $filename = $this->getFilename($model);

        return $pdf->stream($filename);
    }

    /**
     * Prepare data for the packing slip template.
     *
     * @return array<string, mixed>
     */
    protected function prepareData(Model $model): array
    {
        return match (true) {
            $model instanceof Memo => $this->prepareMemoData($model),
            $model instanceof Repair => $this->prepairRepairData($model),
            $model instanceof Transaction => $this->prepareTransactionData($model),
            default => throw new \InvalidArgumentException('Unsupported model type for packing slip'),
        };
    }

    /**
     * Prepare data for a memo packing slip.
     *
     * @return array<string, mixed>
     */
    protected function prepareMemoData(Memo $memo): array
    {
        $memo->load(['store', 'vendor', 'items']);

        $store = $memo->store;
        $vendor = $memo->vendor;

        return [
            'documentNumber' => $memo->memo_number,
            'date' => $memo->created_at->format('F j, Y'),
            'store' => [
                'name' => $store->business_name ?? $store->name,
                'address' => $store->address,
                'address2' => $store->address2,
                'city' => $store->city,
                'state' => $store->state,
                'zip' => $store->zip,
                'phone' => $store->phone,
                'email' => $store->customer_email ?? $store->account_email,
            ],
            'recipient' => $vendor ? [
                'name' => $vendor->name,
                'company' => $vendor->company_name,
                'address' => $vendor->address,
                'address2' => $vendor->address2,
                'city' => $vendor->city,
                'state' => $vendor->state,
                'zip' => $vendor->zip,
                'phone' => $vendor->phone,
                'email' => $vendor->email,
            ] : null,
            'trackingNumber' => null,
            'carrier' => null,
            'shippingMethod' => null,
            'items' => $this->getMemoItems($memo),
            'totalItems' => $memo->items->sum('quantity'),
            'totalValue' => (float) $memo->items->sum(fn ($item) => $item->price * $item->quantity),
            'showPrices' => true,
            'notes' => $memo->notes,
            'packingInstructions' => 'Please verify all items upon receipt. Report any discrepancies within 24 hours.',
        ];
    }

    /**
     * Prepare data for a repair packing slip.
     *
     * @return array<string, mixed>
     */
    protected function prepairRepairData(Repair $repair): array
    {
        $repair->load(['store', 'customer', 'vendor', 'items']);

        $store = $repair->store;

        // Determine recipient based on repair status
        // If sending to vendor, use vendor as recipient
        // Otherwise, use customer (for return shipments)
        $isToVendor = in_array($repair->status, [
            Repair::STATUS_PENDING,
            Repair::STATUS_SENT_TO_VENDOR,
        ]);

        $recipient = null;
        if ($isToVendor && $repair->vendor) {
            $recipient = [
                'name' => $repair->vendor->name,
                'company' => $repair->vendor->company_name,
                'address' => $repair->vendor->address,
                'address2' => $repair->vendor->address2,
                'city' => $repair->vendor->city,
                'state' => $repair->vendor->state,
                'zip' => $repair->vendor->zip,
                'phone' => $repair->vendor->phone,
                'email' => $repair->vendor->email,
            ];
        } elseif ($repair->customer) {
            $recipient = [
                'name' => $repair->customer->full_name,
                'company' => $repair->customer->company_name,
                'address' => $repair->customer->address,
                'address2' => $repair->customer->address2,
                'city' => $repair->customer->city,
                'state' => $repair->customer->state,
                'zip' => $repair->customer->zip,
                'phone' => $repair->customer->phone_number,
                'email' => $repair->customer->email,
            ];
        }

        return [
            'documentNumber' => $repair->repair_number,
            'date' => $repair->created_at->format('F j, Y'),
            'store' => [
                'name' => $store->business_name ?? $store->name,
                'address' => $store->address,
                'address2' => $store->address2,
                'city' => $store->city,
                'state' => $store->state,
                'zip' => $store->zip,
                'phone' => $store->phone,
                'email' => $store->customer_email ?? $store->account_email,
            ],
            'recipient' => $recipient,
            'trackingNumber' => null,
            'carrier' => null,
            'shippingMethod' => null,
            'items' => $this->getRepairItems($repair),
            'totalItems' => $repair->items->count(),
            'totalValue' => (float) $repair->customer_total,
            'showPrices' => false, // Don't show prices on repair packing slips
            'notes' => $repair->customer_notes ?? $repair->internal_notes,
            'packingInstructions' => $isToVendor
                ? 'REPAIR ORDER - Handle with care. See repair notes for required work.'
                : 'Repaired items enclosed. Please inspect upon receipt.',
        ];
    }

    /**
     * Prepare data for a transaction packing slip.
     *
     * @return array<string, mixed>
     */
    protected function prepareTransactionData(Transaction $transaction): array
    {
        $transaction->load(['store', 'customer', 'items']);

        $store = $transaction->store;
        $customer = $transaction->customer;

        // For transactions, we're typically returning items to customer
        $recipient = $customer ? [
            'name' => $customer->full_name,
            'company' => $customer->company_name,
            'address' => $customer->address,
            'address2' => $customer->address2,
            'city' => $customer->city,
            'state' => $customer->state,
            'zip' => $customer->zip,
            'phone' => $customer->phone_number,
            'email' => $customer->email,
        ] : null;

        return [
            'documentNumber' => $transaction->transaction_number,
            'date' => $transaction->created_at->format('F j, Y'),
            'store' => [
                'name' => $store->business_name ?? $store->name,
                'address' => $store->address,
                'address2' => $store->address2,
                'city' => $store->city,
                'state' => $store->state,
                'zip' => $store->zip,
                'phone' => $store->phone,
                'email' => $store->customer_email ?? $store->account_email,
            ],
            'recipient' => $recipient,
            'trackingNumber' => $transaction->return_tracking_number,
            'carrier' => $transaction->return_carrier,
            'shippingMethod' => null,
            'items' => $this->getTransactionItems($transaction),
            'totalItems' => $transaction->items->count(),
            'totalValue' => (float) $transaction->final_offer ?? $transaction->total_buy_price,
            'showPrices' => true,
            'notes' => $transaction->customer_notes,
            'packingInstructions' => $transaction->isMailIn()
                ? 'Items returned per customer request. Please verify contents upon receipt.'
                : null,
        ];
    }

    /**
     * Get formatted line items for a memo.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function getMemoItems(Memo $memo): array
    {
        $items = [];

        foreach ($memo->items as $item) {
            $items[] = [
                'sku' => $item->sku,
                'title' => $item->title ?? $item->name ?? 'Item',
                'description' => $item->description,
                'quantity' => $item->quantity ?? 1,
                'unit_price' => $item->price ?? 0,
                'total' => ($item->quantity ?? 1) * ($item->price ?? 0),
            ];
        }

        return $items;
    }

    /**
     * Get formatted line items for a repair.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function getRepairItems(Repair $repair): array
    {
        $items = [];

        foreach ($repair->items as $item) {
            $items[] = [
                'sku' => null,
                'title' => $item->title ?? $item->name ?? 'Repair Item',
                'description' => $item->description ?? $item->repair_notes,
                'quantity' => 1,
                'unit_price' => $item->customer_price ?? 0,
                'total' => $item->customer_price ?? 0,
            ];
        }

        return $items;
    }

    /**
     * Get formatted line items for a transaction.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function getTransactionItems(Transaction $transaction): array
    {
        $items = [];

        foreach ($transaction->items as $item) {
            $items[] = [
                'sku' => null,
                'title' => $item->title ?? 'Item',
                'description' => trim(implode(' - ', array_filter([
                    $item->metal_type,
                    $item->karat,
                    $item->description,
                ]))),
                'quantity' => 1,
                'unit_price' => $item->buy_price ?? $item->price ?? 0,
                'total' => $item->buy_price ?? $item->price ?? 0,
            ];
        }

        return $items;
    }

    /**
     * Get the filename for the packing slip PDF.
     */
    protected function getFilename(Model $model): string
    {
        $number = match (true) {
            $model instanceof Memo => $model->memo_number,
            $model instanceof Repair => $model->repair_number,
            $model instanceof Transaction => $model->transaction_number,
            default => 'unknown',
        };

        return "packing-slip-{$number}.pdf";
    }
}
