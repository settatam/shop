<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRepairVendorPaymentRequest;
use App\Models\Repair;
use App\Models\RepairVendorPayment;
use App\Models\Vendor;
use App\Services\StoreContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RepairVendorPaymentController extends Controller
{
    public function __construct(
        protected StoreContext $storeContext,
    ) {}

    public function index(Request $request): Response|RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return redirect()->route('dashboard')
                ->with('error', 'Please select a store first.');
        }

        $query = RepairVendorPayment::query()
            ->where('store_id', $store->id)
            ->with(['repair', 'vendor', 'user']);

        // Filter by vendor
        if ($request->filled('vendor_id')) {
            $query->where('vendor_id', $request->input('vendor_id'));
        }

        // Filter by date range
        if ($request->filled('date_from')) {
            $query->whereDate('payment_date', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('payment_date', '<=', $request->input('date_to'));
        }

        // Filter by repair number
        if ($request->filled('repair_number')) {
            $query->whereHas('repair', function ($q) use ($request) {
                $q->where('repair_number', 'like', '%'.$request->input('repair_number').'%');
            });
        }

        // Sort
        $sortField = $request->input('sort', 'payment_date');
        $sortDirection = $request->input('direction', 'desc');
        $allowedSortFields = ['payment_date', 'amount', 'created_at', 'check_number'];

        if (in_array($sortField, $allowedSortFields)) {
            $query->orderBy($sortField, $sortDirection === 'asc' ? 'asc' : 'desc');
        } else {
            $query->orderByDesc('payment_date');
        }

        $payments = $query->paginate(25)->withQueryString();

        // Get vendors for filter dropdown
        $vendors = Vendor::where('store_id', $store->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(fn ($vendor) => [
                'value' => $vendor->id,
                'label' => $vendor->display_name,
            ]);

        return Inertia::render('repairs/VendorPayments', [
            'payments' => $payments->through(fn ($payment) => $this->formatPayment($payment)),
            'vendors' => $vendors,
            'filters' => [
                'vendor_id' => $request->input('vendor_id'),
                'date_from' => $request->input('date_from'),
                'date_to' => $request->input('date_to'),
                'repair_number' => $request->input('repair_number'),
                'sort' => $sortField,
                'direction' => $sortDirection,
            ],
        ]);
    }

    public function store(StoreRepairVendorPaymentRequest $request, Repair $repair): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store || $repair->store_id !== $store->id) {
            abort(404);
        }

        $validated = $request->validated();

        // Handle file upload
        $attachmentPath = null;
        $attachmentName = null;

        if ($request->hasFile('attachment')) {
            $file = $request->file('attachment');
            $attachmentName = $file->getClientOriginalName();
            $attachmentPath = $file->store('vendor-payments/'.$store->id, 'local');
        }

        RepairVendorPayment::create([
            'store_id' => $store->id,
            'repair_id' => $repair->id,
            'vendor_id' => $repair->vendor_id,
            'user_id' => auth()->id(),
            'check_number' => $validated['check_number'] ?? null,
            'amount' => $validated['amount'],
            'vendor_invoice_amount' => $validated['vendor_invoice_amount'] ?? null,
            'reason' => $validated['reason'] ?? null,
            'payment_date' => $validated['payment_date'] ?? now()->toDateString(),
            'attachment_path' => $attachmentPath,
            'attachment_name' => $attachmentName,
        ]);

        return back()->with('success', 'Vendor payment recorded successfully.');
    }

    public function update(Request $request, RepairVendorPayment $payment): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store || $payment->store_id !== $store->id) {
            abort(404);
        }

        $validated = $request->validate([
            'check_number' => ['nullable', 'string', 'max:100'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:999999999.99'],
            'vendor_invoice_amount' => ['nullable', 'numeric', 'min:0', 'max:999999999.99'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'payment_date' => ['nullable', 'date'],
            'attachment' => ['nullable', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,gif,doc,docx'],
            'remove_attachment' => ['nullable', 'boolean'],
        ]);

        // Handle attachment removal
        if ($request->boolean('remove_attachment') && $payment->attachment_path) {
            $payment->deleteAttachment();
        }

        // Handle new file upload
        if ($request->hasFile('attachment')) {
            // Delete old attachment if exists
            if ($payment->attachment_path) {
                Storage::disk('local')->delete($payment->attachment_path);
            }

            $file = $request->file('attachment');
            $validated['attachment_name'] = $file->getClientOriginalName();
            $validated['attachment_path'] = $file->store('vendor-payments/'.$store->id, 'local');
        }

        unset($validated['attachment'], $validated['remove_attachment']);

        $payment->update($validated);

        return back()->with('success', 'Vendor payment updated successfully.');
    }

    public function destroy(RepairVendorPayment $payment): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store || $payment->store_id !== $store->id) {
            abort(404);
        }

        // Delete attachment if exists
        if ($payment->attachment_path) {
            Storage::disk('local')->delete($payment->attachment_path);
        }

        $payment->delete();

        return back()->with('success', 'Vendor payment deleted successfully.');
    }

    public function downloadAttachment(RepairVendorPayment $payment): StreamedResponse|RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store || $payment->store_id !== $store->id) {
            abort(404);
        }

        if (! $payment->attachment_path || ! Storage::disk('local')->exists($payment->attachment_path)) {
            return back()->with('error', 'Attachment not found.');
        }

        return Storage::disk('local')->download(
            $payment->attachment_path,
            $payment->attachment_name ?? 'attachment'
        );
    }

    /**
     * Format payment for frontend.
     *
     * @return array<string, mixed>
     */
    protected function formatPayment(RepairVendorPayment $payment): array
    {
        return [
            'id' => $payment->id,
            'repair_id' => $payment->repair_id,
            'vendor_id' => $payment->vendor_id,
            'check_number' => $payment->check_number,
            'amount' => $payment->amount,
            'vendor_invoice_amount' => $payment->vendor_invoice_amount,
            'reason' => $payment->reason,
            'payment_date' => $payment->payment_date?->toDateString(),
            'has_attachment' => $payment->hasAttachment(),
            'attachment_name' => $payment->attachment_name,
            'created_at' => $payment->created_at->toISOString(),
            'repair' => $payment->repair ? [
                'id' => $payment->repair->id,
                'repair_number' => $payment->repair->repair_number,
            ] : null,
            'vendor' => $payment->vendor ? [
                'id' => $payment->vendor->id,
                'name' => $payment->vendor->name,
                'display_name' => $payment->vendor->display_name,
            ] : null,
            'user' => $payment->user ? [
                'id' => $payment->user->id,
                'name' => $payment->user->name,
            ] : null,
        ];
    }
}
