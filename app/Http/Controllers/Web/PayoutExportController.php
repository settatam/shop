<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\PayoutExport;
use App\Services\Payments\PayoutExportService;
use App\Services\StoreContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Controller for exporting payment/payout data for online buys workflow.
 * Only available for stores with online buys workflow (43/44).
 */
class PayoutExportController extends Controller
{
    public function __construct(
        protected StoreContext $storeContext,
        protected PayoutExportService $exportService,
    ) {}

    /**
     * Display the export page with history.
     */
    public function index(): Response|RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return redirect()->route('dashboard')
                ->with('error', 'Please select a store first.');
        }

        if (! $store->hasOnlineBuysWorkflow()) {
            return redirect()->route('web.transactions.index')
                ->with('error', 'Payment exports are only available for online buys workflow.');
        }

        $exports = PayoutExport::where('store_id', $store->id)
            ->with('user')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn ($export) => [
                'id' => $export->id,
                'filename' => $export->filename,
                'format' => $export->format,
                'format_label' => $export->format_label,
                'record_count' => $export->record_count,
                'date_from' => $export->date_from?->format('M j, Y'),
                'date_to' => $export->date_to?->format('M j, Y'),
                'user' => $export->user ? [
                    'id' => $export->user->id,
                    'name' => $export->user->name,
                ] : null,
                'created_at' => $export->created_at->toISOString(),
                'created_at_formatted' => $export->created_at->format('M j, Y g:i A'),
            ]);

        return Inertia::render('transactions/PayoutExports', [
            'exports' => $exports,
            'formats' => [
                ['value' => PayoutExport::FORMAT_CSV, 'label' => 'CSV'],
                ['value' => PayoutExport::FORMAT_EXCEL, 'label' => 'Excel'],
                ['value' => PayoutExport::FORMAT_PAYPAL, 'label' => 'PayPal Batch'],
            ],
        ]);
    }

    /**
     * Export transactions to CSV.
     */
    public function exportCsv(Request $request): StreamedResponse|RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return redirect()->route('dashboard')
                ->with('error', 'Please select a store first.');
        }

        if (! $store->hasOnlineBuysWorkflow()) {
            return redirect()->route('web.transactions.index')
                ->with('error', 'Payment exports are only available for online buys workflow.');
        }

        $validated = $request->validate([
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'status' => 'nullable|string',
            'payment_status' => 'nullable|string|in:pending,processed,paid',
        ]);

        $this->exportService->forStore($store);

        $result = $this->exportService->exportToCsv($validated);

        if (! $result['success']) {
            return back()->with('error', $result['error']);
        }

        return response()->streamDownload(function () use ($result) {
            echo $result['content'];
        }, $result['filename'], [
            'Content-Type' => 'text/csv',
        ]);
    }

    /**
     * Export transactions to PayPal batch format.
     */
    public function exportPayPal(Request $request): StreamedResponse|RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return redirect()->route('dashboard')
                ->with('error', 'Please select a store first.');
        }

        if (! $store->hasOnlineBuysWorkflow()) {
            return redirect()->route('web.transactions.index')
                ->with('error', 'Payment exports are only available for online buys workflow.');
        }

        $validated = $request->validate([
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'status' => 'nullable|string',
            'payment_status' => 'nullable|string|in:pending,processed,paid',
        ]);

        $this->exportService->forStore($store);

        $result = $this->exportService->exportToPayPalBatch($validated);

        if (! $result['success']) {
            return back()->with('error', $result['error']);
        }

        return response()->streamDownload(function () use ($result) {
            echo $result['content'];
        }, $result['filename'], [
            'Content-Type' => 'text/csv',
        ]);
    }

    /**
     * Download a previous export.
     */
    public function download(PayoutExport $export): StreamedResponse|RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store || $export->store_id !== $store->id) {
            abort(404);
        }

        $path = "payout-exports/{$store->id}/{$export->filename}";

        if (! Storage::exists($path)) {
            return back()->with('error', 'Export file not found. It may have been deleted.');
        }

        $contentType = match ($export->format) {
            PayoutExport::FORMAT_EXCEL => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            default => 'text/csv',
        };

        return Storage::download($path, $export->filename, [
            'Content-Type' => $contentType,
        ]);
    }

    /**
     * Delete an export record.
     */
    public function destroy(PayoutExport $export): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store || $export->store_id !== $store->id) {
            abort(404);
        }

        // Delete the file if it exists
        $path = "payout-exports/{$store->id}/{$export->filename}";
        if (Storage::exists($path)) {
            Storage::delete($path);
        }

        $export->delete();

        return back()->with('success', 'Export deleted.');
    }

    /**
     * Preview transactions that would be included in an export.
     */
    public function preview(Request $request): JsonResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return response()->json(['error' => 'Please select a store first.'], 400);
        }

        if (! $store->hasOnlineBuysWorkflow()) {
            return response()->json(['error' => 'Payment exports only available for online buys workflow.'], 400);
        }

        $validated = $request->validate([
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'status' => 'nullable|string',
            'payment_status' => 'nullable|string|in:pending,processed,paid',
        ]);

        $this->exportService->forStore($store);

        $transactions = $this->exportService->getFilteredTransactions($validated);

        $summary = [
            'count' => $transactions->count(),
            'total_amount' => $transactions->sum('final_offer'),
            'payment_methods' => $transactions->groupBy('payment_method')
                ->map(fn ($group) => [
                    'count' => $group->count(),
                    'total' => $group->sum('final_offer'),
                ])
                ->toArray(),
        ];

        $preview = $transactions->take(10)->map(fn ($t) => [
            'id' => $t->id,
            'transaction_number' => $t->transaction_number,
            'customer_name' => $t->customer?->full_name ?? 'Unknown',
            'amount' => $t->final_offer,
            'payment_method' => $t->payment_method,
            'status' => $t->status,
        ]);

        return response()->json([
            'summary' => $summary,
            'preview' => $preview,
            'has_more' => $transactions->count() > 10,
        ]);
    }
}
