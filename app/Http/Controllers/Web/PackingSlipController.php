<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Memo;
use App\Models\Repair;
use App\Models\Transaction;
use App\Services\PackingSlips\PackingSlipPdfService;
use Illuminate\Http\Response;

class PackingSlipController extends Controller
{
    public function __construct(
        protected PackingSlipPdfService $pdfService,
    ) {}

    public function streamMemo(Memo $memo): Response
    {
        return $this->pdfService->stream($memo);
    }

    public function downloadMemo(Memo $memo): Response
    {
        return $this->pdfService->download($memo);
    }

    public function streamRepair(Repair $repair): Response
    {
        return $this->pdfService->stream($repair);
    }

    public function downloadRepair(Repair $repair): Response
    {
        return $this->pdfService->download($repair);
    }

    public function streamTransaction(Transaction $transaction): Response
    {
        return $this->pdfService->stream($transaction);
    }

    public function downloadTransaction(Transaction $transaction): Response
    {
        return $this->pdfService->download($transaction);
    }
}
