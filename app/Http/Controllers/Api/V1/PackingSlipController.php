<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Memo;
use App\Models\Repair;
use App\Models\Transaction;
use App\Services\PackingSlips\PackingSlipPdfService;
use App\Services\StoreContext;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class PackingSlipController extends Controller
{
    public function __construct(
        protected PackingSlipPdfService $packingSlipService,
        protected StoreContext $storeContext,
    ) {}

    /**
     * Download packing slip PDF for a memo.
     */
    public function downloadMemo(Memo $memo): Response|SymfonyResponse
    {
        $this->authorizeAccess($memo);

        return $this->packingSlipService->download($memo);
    }

    /**
     * Stream packing slip PDF for a memo (for printing).
     */
    public function streamMemo(Memo $memo): Response|SymfonyResponse
    {
        $this->authorizeAccess($memo);

        return $this->packingSlipService->stream($memo);
    }

    /**
     * Download packing slip PDF for a repair.
     */
    public function downloadRepair(Repair $repair): Response|SymfonyResponse
    {
        $this->authorizeAccess($repair);

        return $this->packingSlipService->download($repair);
    }

    /**
     * Stream packing slip PDF for a repair (for printing).
     */
    public function streamRepair(Repair $repair): Response|SymfonyResponse
    {
        $this->authorizeAccess($repair);

        return $this->packingSlipService->stream($repair);
    }

    /**
     * Download packing slip PDF for a transaction.
     */
    public function downloadTransaction(Transaction $transaction): Response|SymfonyResponse
    {
        $this->authorizeAccess($transaction);

        return $this->packingSlipService->download($transaction);
    }

    /**
     * Stream packing slip PDF for a transaction (for printing).
     */
    public function streamTransaction(Transaction $transaction): Response|SymfonyResponse
    {
        $this->authorizeAccess($transaction);

        return $this->packingSlipService->stream($transaction);
    }

    /**
     * Authorize access to the model based on store context.
     */
    protected function authorizeAccess(Memo|Repair|Transaction $model): void
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store || $model->store_id !== $store->id) {
            abort(404);
        }
    }
}
