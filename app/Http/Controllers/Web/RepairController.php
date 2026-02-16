<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateRepairFromWizardRequest;
use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Repair;
use App\Models\RepairItem;
use App\Models\Vendor;
use App\Models\Warehouse;
use App\Services\ActivityLogFormatter;
use App\Services\Repairs\RepairService;
use App\Services\StoreContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RepairController extends Controller
{
    public function __construct(
        protected StoreContext $storeContext,
        protected RepairService $repairService,
    ) {}

    public function index(Request $request): Response|RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return redirect()->route('dashboard')
                ->with('error', 'Please select a store first.');
        }

        $vendors = Vendor::where('store_id', $store->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(fn ($vendor) => [
                'value' => $vendor->id,
                'label' => $vendor->display_name,
            ]);

        return Inertia::render('repairs/Index', [
            'statuses' => $this->getStatuses(),
            'vendors' => $vendors,
        ]);
    }

    public function show(Repair $repair): Response|RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store || $repair->store_id !== $store->id) {
            abort(404);
        }

        $repair->load([
            'customer.leadSource',
            'vendor',
            'user',
            'items.product',
            'items.category',
            'order',
            'invoice',
            'payments.user',
            'notes.user',
        ]);

        return Inertia::render('repairs/Show', [
            'repair' => $this->formatRepair($repair),
            'statuses' => $this->getStatuses(),
            'paymentMethods' => $this->getPaymentMethods(),
            'activityLogs' => Inertia::defer(fn () => app(ActivityLogFormatter::class)->formatForSubject($repair)),
        ]);
    }

    public function createWizard(): Response|RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return redirect()->route('dashboard')
                ->with('error', 'Please select a store first.');
        }

        // Get store users for the employee dropdown
        // Show only staff who are marked as assignable
        $storeUsers = $store->storeUsers()
            ->with(['user', 'role'])
            ->whereNotNull('user_id')
            ->where('can_be_assigned', true)
            ->get()
            ->map(fn ($storeUser) => [
                'id' => $storeUser->id,
                'name' => $storeUser->user?->name ?? $storeUser->full_name ?? 'Unknown',
            ])
            ->sortBy('name')
            ->values();

        // Get the current user's store user ID
        $currentStoreUserId = auth()->user()?->currentStoreUser()?->id;

        // Get categories for items
        $categories = Category::where('store_id', $store->id)
            ->orderBy('name')
            ->get()
            ->map(fn ($category) => [
                'value' => $category->id,
                'label' => $category->name,
            ]);

        // Get warehouses for the warehouse dropdown
        $warehouses = Warehouse::where('store_id', $store->id)
            ->orderBy('name')
            ->get()
            ->map(fn ($warehouse) => [
                'value' => $warehouse->id,
                'label' => $warehouse->name,
                'tax_rate' => $warehouse->tax_rate,
            ]);

        // Get the current user's default warehouse ID
        $currentStoreUser = auth()->user()?->currentStoreUser();
        $defaultWarehouseId = $currentStoreUser?->default_warehouse_id;

        return Inertia::render('repairs/CreateWizard', [
            'storeUsers' => $storeUsers,
            'currentStoreUserId' => $currentStoreUserId,
            'categories' => $categories,
            'warehouses' => $warehouses,
            'defaultWarehouseId' => $defaultWarehouseId,
            'defaultTaxRate' => $store->default_tax_rate ?? 0,
        ]);
    }

    public function storeFromWizard(CreateRepairFromWizardRequest $request): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return redirect()->route('dashboard')
                ->with('error', 'Please select a store first.');
        }

        $data = $request->validated();
        $data['store_id'] = $store->id;

        $repair = $this->repairService->createFromWizard($data);

        return redirect()->route('web.repairs.show', $repair)
            ->with('success', 'Repair created successfully.');
    }

    public function update(Request $request, Repair $repair): RedirectResponse
    {
        $this->authorizeRepair($repair);

        $validated = $request->validate([
            'description' => 'nullable|string|max:5000',
            'service_fee' => 'nullable|numeric|min:0',
            'tax_rate' => 'nullable|numeric|min:0|max:1',
            'shipping_cost' => 'nullable|numeric|min:0',
            'discount' => 'nullable|numeric|min:0',
            'vendor_id' => 'nullable|exists:vendors,id',
        ]);

        $repair->update($validated);

        if (isset($validated['tax_rate']) || isset($validated['service_fee']) || isset($validated['shipping_cost']) || isset($validated['discount'])) {
            $repair->calculateTotals();
        }

        return back()->with('success', 'Repair updated successfully.');
    }

    public function destroy(Repair $repair): RedirectResponse
    {
        $this->authorizeRepair($repair);

        if (! $repair->isPending()) {
            return back()->with('error', 'Only pending repairs can be deleted.');
        }

        // Return all items to stock before deleting
        $repair->items->each(function (RepairItem $item) {
            $item->returnToStock();
        });

        $repair->delete();

        return redirect()->route('web.repairs.index')
            ->with('success', 'Repair deleted successfully.');
    }

    // Status Transition Methods

    public function sendToVendor(Repair $repair): RedirectResponse
    {
        $this->authorizeRepair($repair);

        if (! $repair->canBeSentToVendor()) {
            return back()->with('error', 'Repair cannot be sent to vendor in its current state.');
        }

        if (! $repair->vendor_id) {
            return back()->with('error', 'Please assign a vendor before sending.');
        }

        $this->repairService->sendToVendor($repair);

        return back()->with('success', 'Repair sent to vendor.');
    }

    public function markReceived(Repair $repair): RedirectResponse
    {
        $this->authorizeRepair($repair);

        if (! $repair->canBeMarkedAsReceived()) {
            return back()->with('error', 'Repair cannot be marked as received in its current state.');
        }

        $this->repairService->markReceivedByVendor($repair);

        return back()->with('success', 'Repair marked as received by vendor.');
    }

    public function markCompleted(Repair $repair): RedirectResponse
    {
        $this->authorizeRepair($repair);

        if (! $repair->canBeCompleted()) {
            return back()->with('error', 'Repair cannot be marked as completed in its current state.');
        }

        $this->repairService->markCompleted($repair);

        return back()->with('success', 'Repair marked as completed.');
    }

    public function receivePayment(Request $request, Repair $repair): RedirectResponse
    {
        $this->authorizeRepair($repair);

        if (! $repair->canReceivePayment()) {
            return back()->with('error', 'Repair is not ready to receive payment.');
        }

        $validated = $request->validate([
            'payment_method' => 'required|string|in:cash,check,wire,ach,store_credit,credit_card',
            'payment_details' => 'nullable|array',
        ]);

        // Create a sale order and mark payment received
        $this->repairService->createSaleOrder($repair);

        return back()->with('success', 'Payment received. Invoice created.');
    }

    public function cancel(Repair $repair): RedirectResponse
    {
        $this->authorizeRepair($repair);

        if (! $repair->canBeCancelled()) {
            return back()->with('error', 'Repair cannot be cancelled in its current state.');
        }

        $this->repairService->cancel($repair);

        return back()->with('success', 'Repair cancelled.');
    }

    public function changeStatus(Request $request, Repair $repair): RedirectResponse
    {
        $this->authorizeRepair($repair);

        $validated = $request->validate([
            'status' => 'required|string|in:'.implode(',', Repair::STATUSES),
        ]);

        $oldStatus = $repair->status;
        $newStatus = $validated['status'];

        if ($oldStatus === $newStatus) {
            return back();
        }

        $repair->update(['status' => $newStatus]);

        // Log the status change
        ActivityLog::log(
            'repairs.update',
            $repair,
            auth()->user(),
            [
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
            ],
            "Status changed from {$oldStatus} to {$newStatus}"
        );

        $statusLabels = [
            Repair::STATUS_PENDING => 'Pending',
            Repair::STATUS_SENT_TO_VENDOR => 'Sent to Vendor',
            Repair::STATUS_RECEIVED_BY_VENDOR => 'Received by Vendor',
            Repair::STATUS_COMPLETED => 'Completed',
            Repair::STATUS_PAYMENT_RECEIVED => 'Payment Received',
            Repair::STATUS_REFUNDED => 'Refunded',
            Repair::STATUS_CANCELLED => 'Cancelled',
            Repair::STATUS_ARCHIVED => 'Archived',
        ];

        return back()->with('success', "Status changed to {$statusLabels[$newStatus]}.");
    }

    public function bulkAction(Request $request): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return redirect()->route('dashboard')
                ->with('error', 'Please select a store first.');
        }

        $validated = $request->validate([
            'action' => 'required|string|in:delete,cancel',
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|exists:repairs,id',
        ]);

        $repairs = Repair::where('store_id', $store->id)
            ->whereIn('id', $validated['ids'])
            ->get();

        $count = $repairs->count();

        match ($validated['action']) {
            'delete' => $repairs->each(function ($repair) {
                if ($repair->isPending()) {
                    $repair->items->each(function (RepairItem $item) {
                        $item->returnToStock();
                    });
                    $repair->delete();
                }
            }),
            'cancel' => $repairs->each(function ($repair) {
                if ($repair->canBeCancelled()) {
                    $repair->cancel();
                }
            }),
        };

        $actionLabel = match ($validated['action']) {
            'delete' => 'deleted',
            'cancel' => 'cancelled',
        };

        return redirect()->route('web.repairs.index')
            ->with('success', "{$count} repair(s) {$actionLabel} successfully.");
    }

    // Item Management

    public function updateItem(Request $request, Repair $repair, RepairItem $item): RedirectResponse
    {
        $this->authorizeRepair($repair);

        if ($item->repair_id !== $repair->id) {
            return back()->with('error', 'Item does not belong to this repair.');
        }

        $validated = $request->validate([
            'vendor_cost' => 'nullable|numeric|min:0',
            'customer_cost' => 'nullable|numeric|min:0',
            'title' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:2000',
        ]);

        $this->repairService->updateItem($item, $validated);

        return back()->with('success', 'Item updated successfully.');
    }

    public function removeItem(Repair $repair, RepairItem $item): RedirectResponse
    {
        $this->authorizeRepair($repair);

        if ($item->repair_id !== $repair->id) {
            return back()->with('error', 'Item does not belong to this repair.');
        }

        $this->repairService->removeItem($item);

        return back()->with('success', 'Item removed successfully.');
    }

    // Search APIs for Wizard

    public function searchCustomers(Request $request): JsonResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return response()->json(['customers' => []], 200);
        }

        $query = $request->get('query', '');

        $customers = Customer::where('store_id', $store->id)
            ->when($query, function ($q) use ($query) {
                $q->where(function ($sub) use ($query) {
                    $sub->where('first_name', 'like', "%{$query}%")
                        ->orWhere('last_name', 'like', "%{$query}%")
                        ->orWhere('email', 'like', "%{$query}%")
                        ->orWhere('phone_number', 'like', "%{$query}%")
                        ->orWhere('company_name', 'like', "%{$query}%");
                });
            })
            ->limit(20)
            ->get()
            ->map(fn ($customer) => [
                'id' => $customer->id,
                'first_name' => $customer->first_name,
                'last_name' => $customer->last_name,
                'full_name' => $customer->full_name,
                'email' => $customer->email,
                'phone_number' => $customer->phone_number,
                'company_name' => $customer->company_name,
            ]);

        return response()->json(['customers' => $customers]);
    }

    public function searchVendors(Request $request): JsonResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return response()->json(['vendors' => []], 200);
        }

        $query = $request->get('query', '');

        $vendors = Vendor::where('store_id', $store->id)
            ->where('is_active', true)
            ->when($query, function ($q) use ($query) {
                $q->where(function ($sub) use ($query) {
                    $sub->where('name', 'like', "%{$query}%")
                        ->orWhere('company_name', 'like', "%{$query}%")
                        ->orWhere('email', 'like', "%{$query}%")
                        ->orWhere('code', 'like', "%{$query}%");
                });
            })
            ->limit(20)
            ->get()
            ->map(fn ($vendor) => [
                'id' => $vendor->id,
                'name' => $vendor->name,
                'company_name' => $vendor->company_name,
                'display_name' => $vendor->display_name,
                'email' => $vendor->email,
                'phone' => $vendor->phone,
            ]);

        return response()->json(['vendors' => $vendors]);
    }

    protected function authorizeRepair(Repair $repair): void
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store || $repair->store_id !== $store->id) {
            abort(404);
        }
    }

    /**
     * Format repair for frontend.
     *
     * @return array<string, mixed>
     */
    protected function formatRepair(Repair $repair): array
    {
        return [
            'id' => $repair->id,
            'repair_number' => $repair->repair_number,
            'status' => $repair->status,
            'subtotal' => $repair->subtotal,
            'tax' => $repair->tax,
            'tax_rate' => $repair->tax_rate,
            'service_fee' => $repair->service_fee,
            'shipping_cost' => $repair->shipping_cost,
            'discount' => $repair->discount,
            'total' => $repair->total,
            'grand_total' => $repair->grand_total,

            // Payment adjustment fields (for CollectPaymentModal)
            // These use converted values compatible with PaymentService
            'charge_taxes' => $repair->charge_taxes ?? true,
            'tax_type' => 'percent',
            // Convert tax_rate from decimal (0.08) to percentage (8) for payment modal
            'payment_tax_rate' => $repair->tax_rate > 0 && $repair->tax_rate < 1
                ? $repair->tax_rate * 100
                : (float) $repair->tax_rate,
            'discount_value' => (float) ($repair->discount_value ?? 0),
            'discount_unit' => $repair->discount_unit ?? 'fixed',
            'discount_reason' => $repair->discount_reason,
            'service_fee_value' => (float) ($repair->service_fee_value ?? 0),
            'service_fee_unit' => $repair->service_fee_unit ?? 'fixed',
            'service_fee_reason' => $repair->service_fee_reason,
            'description' => $repair->description,
            'is_appraisal' => $repair->is_appraisal,
            'repair_days' => $repair->repair_days,
            'vendor_total' => $repair->vendor_total,
            'customer_total' => $repair->customer_total,
            'total_paid' => $repair->total_paid,
            'balance_due' => $repair->balance_due,

            // Dates
            'date_sent_to_vendor' => $repair->date_sent_to_vendor?->toISOString(),
            'date_received_by_vendor' => $repair->date_received_by_vendor?->toISOString(),
            'date_completed' => $repair->date_completed?->toISOString(),
            'created_at' => $repair->created_at->toISOString(),
            'updated_at' => $repair->updated_at->toISOString(),

            // Status helpers
            'is_pending' => $repair->isPending(),
            'is_sent_to_vendor' => $repair->isSentToVendor(),
            'is_received_by_vendor' => $repair->isReceivedByVendor(),
            'is_completed' => $repair->isCompleted(),
            'is_payment_received' => $repair->isPaymentReceived(),
            'is_cancelled' => $repair->isCancelled(),
            'is_fully_paid' => $repair->isFullyPaid(),

            // Action helpers
            'can_be_sent_to_vendor' => $repair->canBeSentToVendor(),
            'can_be_marked_as_received' => $repair->canBeMarkedAsReceived(),
            'can_be_completed' => $repair->canBeCompleted(),
            'can_receive_payment' => $repair->canReceivePayment(),
            'can_be_cancelled' => $repair->canBeCancelled(),

            // Relationships
            'customer' => $repair->customer ? [
                'id' => $repair->customer->id,
                'first_name' => $repair->customer->first_name,
                'last_name' => $repair->customer->last_name,
                'full_name' => $repair->customer->full_name,
                'email' => $repair->customer->email,
                'phone_number' => $repair->customer->phone_number,
                'company_name' => $repair->customer->company_name,
            ] : null,
            'vendor' => $repair->vendor ? [
                'id' => $repair->vendor->id,
                'name' => $repair->vendor->name,
                'company_name' => $repair->vendor->company_name,
                'display_name' => $repair->vendor->display_name,
                'email' => $repair->vendor->email,
                'phone' => $repair->vendor->phone,
            ] : null,
            'user' => $repair->user ? [
                'id' => $repair->user->id,
                'name' => $repair->user->name,
            ] : null,
            'items' => $repair->items->map(fn ($item) => [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'category_id' => $item->category_id,
                'sku' => $item->sku,
                'title' => $item->title,
                'description' => $item->description,
                'vendor_cost' => $item->vendor_cost,
                'customer_cost' => $item->customer_cost,
                'status' => $item->status,
                'dwt' => $item->dwt,
                'precious_metal' => $item->precious_metal,
                'profit' => $item->profit,
                'category' => $item->category ? [
                    'id' => $item->category->id,
                    'name' => $item->category->name,
                ] : null,
                'product' => $item->product ? [
                    'id' => $item->product->id,
                    'title' => $item->product->title,
                ] : null,
            ]),
            'order' => $repair->order ? [
                'id' => $repair->order->id,
                'order_number' => $repair->order->order_number,
            ] : null,
            'invoice' => $repair->invoice ? [
                'id' => $repair->invoice->id,
                'invoice_number' => $repair->invoice->invoice_number,
                'status' => $repair->invoice->status,
                'total' => $repair->invoice->total,
                'balance_due' => $repair->invoice->balance_due,
            ] : null,
            'payments' => $repair->payments->map(fn ($payment) => [
                'id' => $payment->id,
                'amount' => $payment->amount,
                'payment_method' => $payment->payment_method,
                'status' => $payment->status,
                'reference' => $payment->reference,
                'notes' => $payment->notes,
                'paid_at' => $payment->paid_at?->toISOString(),
                'user' => $payment->user ? [
                    'id' => $payment->user->id,
                    'name' => $payment->user->name,
                ] : null,
            ]),
            'note_entries' => $repair->notes->map(fn ($note) => [
                'id' => $note->id,
                'content' => $note->content,
                'user' => $note->user ? [
                    'id' => $note->user->id,
                    'name' => $note->user->name,
                ] : null,
                'created_at' => $note->created_at->toISOString(),
                'updated_at' => $note->updated_at->toISOString(),
            ]),
        ];
    }

    /**
     * Get available statuses.
     *
     * @return array<array<string, string>>
     */
    protected function getStatuses(): array
    {
        return [
            ['value' => Repair::STATUS_PENDING, 'label' => 'Pending'],
            ['value' => Repair::STATUS_SENT_TO_VENDOR, 'label' => 'Sent to Vendor'],
            ['value' => Repair::STATUS_RECEIVED_BY_VENDOR, 'label' => 'Received by Vendor'],
            ['value' => Repair::STATUS_COMPLETED, 'label' => 'Completed'],
            ['value' => Repair::STATUS_PAYMENT_RECEIVED, 'label' => 'Payment Received'],
            ['value' => Repair::STATUS_REFUNDED, 'label' => 'Refunded'],
            ['value' => Repair::STATUS_CANCELLED, 'label' => 'Cancelled'],
            ['value' => Repair::STATUS_ARCHIVED, 'label' => 'Archived'],
        ];
    }

    /**
     * Get available payment methods.
     *
     * @return array<array<string, string>>
     */
    protected function getPaymentMethods(): array
    {
        return [
            ['value' => 'cash', 'label' => 'Cash'],
            ['value' => 'check', 'label' => 'Check'],
            ['value' => 'credit_card', 'label' => 'Credit Card'],
            ['value' => 'wire', 'label' => 'Wire Transfer'],
            ['value' => 'ach', 'label' => 'ACH Transfer'],
            ['value' => 'store_credit', 'label' => 'Store Credit'],
        ];
    }
}
