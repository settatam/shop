<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateLayawayFromWizardRequest;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Layaway;
use App\Models\LayawaySchedule;
use App\Models\Product;
use App\Models\Warehouse;
use App\Services\ActivityLogFormatter;
use App\Services\Layaways\LayawayService;
use App\Services\StoreContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LayawayController extends Controller
{
    public function __construct(
        protected StoreContext $storeContext,
        protected LayawayService $layawayService,
    ) {}

    public function index(Request $request): Response|RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return redirect()->route('dashboard')
                ->with('error', 'Please select a store first.');
        }

        return Inertia::render('layaways/Index', [
            'statuses' => $this->getStatuses(),
            'termOptions' => $this->getTermOptions(),
            'paymentTypes' => $this->getPaymentTypes(),
        ]);
    }

    public function show(Layaway $layaway): Response|RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store || $layaway->store_id !== $store->id) {
            abort(404);
        }

        $layaway->load([
            'customer.leadSource',
            'user',
            'warehouse',
            'items.product.images',
            'schedules',
            'order',
            'payments.user',
            'notes.user',
        ]);

        return Inertia::render('layaways/Show', [
            'layaway' => $this->formatLayaway($layaway),
            'statuses' => $this->getStatuses(),
            'paymentMethods' => $this->getPaymentMethods(),
            'activityLogs' => Inertia::defer(fn () => app(ActivityLogFormatter::class)->formatForSubject($layaway)),
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
        $storeUsers = $store->storeUsers()
            ->with(['user', 'role'])
            ->get()
            ->filter(fn ($storeUser) => $storeUser->is_owner || $storeUser->hasPermission('layaways.create'))
            ->map(fn ($storeUser) => [
                'id' => $storeUser->id,
                'name' => $storeUser->user?->name ?? $storeUser->name ?? 'Unknown',
            ])
            ->values();

        // Get the current user's store user ID
        $currentStoreUserId = auth()->user()?->currentStoreUser()?->id;

        // Get categories for filtering products
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

        return Inertia::render('layaways/CreateWizard', [
            'storeUsers' => $storeUsers,
            'currentStoreUserId' => $currentStoreUserId,
            'categories' => $categories,
            'termOptions' => $this->getTermOptions(),
            'paymentTypes' => $this->getPaymentTypes(),
            'paymentFrequencies' => $this->getPaymentFrequencies(),
            'warehouses' => $warehouses,
            'defaultWarehouseId' => $defaultWarehouseId,
            'defaultTaxRate' => $store->default_tax_rate ?? 0,
        ]);
    }

    public function storeFromWizard(CreateLayawayFromWizardRequest $request): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return redirect()->route('dashboard')
                ->with('error', 'Please select a store first.');
        }

        $data = $request->validated();
        $data['store_id'] = $store->id;

        $layaway = $this->layawayService->createFromWizard($data);

        return redirect()->route('web.layaways.show', $layaway)
            ->with('success', 'Layaway created successfully.');
    }

    public function update(Request $request, Layaway $layaway): RedirectResponse
    {
        $this->authorizeLayaway($layaway);

        $validated = $request->validate([
            'admin_notes' => 'nullable|string|max:5000',
            'term_days' => 'nullable|integer|in:30,60,90,120',
            'tax_rate' => 'nullable|numeric|min:0|max:1',
        ]);

        $layaway->update($validated);

        if (isset($validated['tax_rate'])) {
            $layaway->calculateTotals();
        }

        return back()->with('success', 'Layaway updated successfully.');
    }

    public function destroy(Layaway $layaway): RedirectResponse
    {
        $this->authorizeLayaway($layaway);

        if (! $layaway->isPending()) {
            return back()->with('error', 'Only pending layaways can be deleted.');
        }

        // Release all reserved items
        foreach ($layaway->items as $item) {
            $item->release();
        }

        $layaway->delete();

        return redirect()->route('web.layaways.index')
            ->with('success', 'Layaway deleted successfully.');
    }

    // Status Transition Methods

    public function activate(Layaway $layaway): RedirectResponse
    {
        $this->authorizeLayaway($layaway);

        if (! $layaway->isPending()) {
            return back()->with('error', 'Can only activate pending layaways.');
        }

        if ($layaway->total_paid < $layaway->minimum_deposit) {
            return back()->with('error', 'Minimum deposit has not been met.');
        }

        $this->layawayService->activate($layaway);

        return back()->with('success', 'Layaway activated.');
    }

    public function complete(Layaway $layaway): RedirectResponse
    {
        $this->authorizeLayaway($layaway);

        if (! $layaway->isActive()) {
            return back()->with('error', 'Can only complete active layaways.');
        }

        if (! $layaway->isFullyPaid()) {
            return back()->with('error', 'Layaway has outstanding balance.');
        }

        $this->layawayService->complete($layaway);

        return back()->with('success', 'Layaway completed. Order created.');
    }

    public function cancel(Request $request, Layaway $layaway): RedirectResponse
    {
        $this->authorizeLayaway($layaway);

        if ($layaway->isCompleted()) {
            return back()->with('error', 'Cannot cancel a completed layaway.');
        }

        $restockingFee = $request->input('restocking_fee');

        $this->layawayService->cancel($layaway, $restockingFee);

        return back()->with('success', 'Layaway cancelled. Items released back to inventory.');
    }

    public function receivePayment(Request $request, Layaway $layaway): RedirectResponse
    {
        $this->authorizeLayaway($layaway);

        if (! $layaway->canReceivePayment()) {
            return back()->with('error', 'This layaway cannot receive payments.');
        }

        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'payment_method' => 'required|string|in:cash,card,check,bank_transfer,store_credit',
            'reference' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:1000',
        ]);

        $this->layawayService->recordPayment($layaway, $validated['amount'], $validated);

        return back()->with('success', 'Payment received.');
    }

    public function addItem(Request $request, Layaway $layaway): RedirectResponse
    {
        $this->authorizeLayaway($layaway);

        if (! $layaway->isPending()) {
            return back()->with('error', 'Items can only be added to pending layaways.');
        }

        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'price' => 'nullable|numeric|min:0',
            'quantity' => 'nullable|integer|min:1',
        ]);

        $this->layawayService->addItem($layaway, $validated);

        return back()->with('success', 'Item added to layaway.');
    }

    public function removeItem(Layaway $layaway, int $itemId): RedirectResponse
    {
        $this->authorizeLayaway($layaway);

        $item = $layaway->items()->findOrFail($itemId);

        $this->layawayService->removeItem($item);

        return back()->with('success', 'Item removed from layaway.');
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
            'ids.*' => 'integer|exists:layaways,id',
        ]);

        $layaways = Layaway::where('store_id', $store->id)
            ->whereIn('id', $validated['ids'])
            ->get();

        $count = $layaways->count();

        match ($validated['action']) {
            'delete' => $layaways->each(function ($layaway) {
                if ($layaway->isPending()) {
                    foreach ($layaway->items as $item) {
                        $item->release();
                    }
                    $layaway->delete();
                }
            }),
            'cancel' => $layaways->each(function ($layaway) {
                if (! $layaway->isCompleted()) {
                    $this->layawayService->cancel($layaway);
                }
            }),
        };

        $actionLabel = match ($validated['action']) {
            'delete' => 'deleted',
            'cancel' => 'cancelled',
        };

        return redirect()->route('web.layaways.index')
            ->with('success', "{$count} layaway(s) {$actionLabel} successfully.");
    }

    // Search APIs for Wizard

    public function searchProducts(Request $request): JsonResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return response()->json(['products' => []], 200);
        }

        $query = $request->get('query', '');
        $categoryId = $request->get('category_id');

        $products = Product::where('store_id', $store->id)
            ->where('quantity', '>', 0)
            ->when($query, function ($q) use ($query) {
                $q->where(function ($sub) use ($query) {
                    $sub->where('title', 'like', "%{$query}%")
                        ->orWhere('description', 'like', "%{$query}%")
                        ->orWhereHas('variants', function ($variantQuery) use ($query) {
                            $variantQuery->where('sku', 'like', "%{$query}%");
                        });
                });
            })
            ->when($categoryId, fn ($q) => $q->where('category_id', $categoryId))
            ->with(['category', 'images', 'variants'])
            ->limit(20)
            ->get()
            ->map(function ($product) {
                $variant = $product->variants->first();

                return [
                    'id' => $product->id,
                    'title' => $product->title,
                    'sku' => $variant?->sku,
                    'description' => $product->description,
                    'price' => $variant?->price ?? 0,
                    'cost' => $variant?->cost ?? 0,
                    'quantity' => $product->quantity,
                    'category' => $product->category?->name,
                    'image' => $product->images->first()?->url,
                ];
            });

        return response()->json(['products' => $products]);
    }

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
                        ->orWhere('phone', 'like', "%{$query}%");
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
                'phone' => $customer->phone,
            ]);

        return response()->json(['customers' => $customers]);
    }

    protected function authorizeLayaway(Layaway $layaway): void
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store || $layaway->store_id !== $store->id) {
            abort(404);
        }
    }

    /**
     * Format layaway for frontend.
     *
     * @return array<string, mixed>
     */
    protected function formatLayaway(Layaway $layaway): array
    {
        return [
            'id' => $layaway->id,
            'layaway_number' => $layaway->layaway_number,
            'status' => $layaway->status,
            'payment_type' => $layaway->payment_type,
            'term_days' => $layaway->term_days,

            // Amounts
            'subtotal' => $layaway->subtotal,
            'tax_rate' => $layaway->tax_rate,
            'tax_amount' => $layaway->tax_amount,
            'total' => $layaway->total,
            'deposit_amount' => $layaway->deposit_amount,
            'minimum_deposit' => $layaway->minimum_deposit,
            'total_paid' => $layaway->total_paid,
            'balance_due' => $layaway->balance_due,
            'cancellation_fee' => $layaway->cancellation_fee,

            // Terms
            'minimum_deposit_percent' => $layaway->minimum_deposit_percent,
            'cancellation_fee_percent' => $layaway->cancellation_fee_percent,

            // Dates
            'start_date' => $layaway->start_date?->toISOString(),
            'due_date' => $layaway->due_date?->toISOString(),
            'days_remaining' => $layaway->days_remaining,
            'completed_at' => $layaway->completed_at?->toISOString(),
            'cancelled_at' => $layaway->cancelled_at?->toISOString(),
            'created_at' => $layaway->created_at->toISOString(),
            'updated_at' => $layaway->updated_at->toISOString(),

            // Progress
            'progress_percentage' => $layaway->getProgressPercentage(),
            'is_overdue' => $layaway->isOverdue(),

            // Status helpers
            'is_pending' => $layaway->isPending(),
            'is_active' => $layaway->isActive(),
            'is_completed' => $layaway->isCompleted(),
            'is_cancelled' => $layaway->isCancelled(),
            'is_defaulted' => $layaway->isDefaulted(),
            'is_flexible' => $layaway->isFlexible(),
            'is_scheduled' => $layaway->isScheduled(),
            'is_fully_paid' => $layaway->isFullyPaid(),
            'can_receive_payment' => $layaway->canReceivePayment(),

            'admin_notes' => $layaway->admin_notes,

            // Relationships
            'customer' => $layaway->customer ? [
                'id' => $layaway->customer->id,
                'first_name' => $layaway->customer->first_name,
                'last_name' => $layaway->customer->last_name,
                'full_name' => $layaway->customer->full_name,
                'email' => $layaway->customer->email,
                'phone' => $layaway->customer->phone,
            ] : null,
            'user' => $layaway->user ? [
                'id' => $layaway->user->id,
                'name' => $layaway->user->name,
            ] : null,
            'warehouse' => $layaway->warehouse ? [
                'id' => $layaway->warehouse->id,
                'name' => $layaway->warehouse->name,
            ] : null,
            'items' => $layaway->items->map(fn ($item) => [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'sku' => $item->sku,
                'title' => $item->title,
                'description' => $item->description,
                'quantity' => $item->quantity,
                'price' => $item->price,
                'line_total' => $item->line_total,
                'is_reserved' => $item->is_reserved,
                'product' => $item->product ? [
                    'id' => $item->product->id,
                    'title' => $item->product->title,
                    'sku' => $item->product->sku,
                    'image' => $item->product->images->first()?->url ?? null,
                ] : null,
            ]),
            'schedules' => $layaway->schedules->map(fn ($schedule) => [
                'id' => $schedule->id,
                'installment_number' => $schedule->installment_number,
                'due_date' => $schedule->due_date->toISOString(),
                'amount_due' => $schedule->amount_due,
                'amount_paid' => $schedule->amount_paid,
                'remaining_amount' => $schedule->remaining_amount,
                'status' => $schedule->status,
                'is_overdue' => $schedule->isOverdue(),
                'paid_at' => $schedule->paid_at?->toISOString(),
            ]),
            'next_scheduled_payment' => $layaway->getNextScheduledPayment() ? [
                'id' => $layaway->getNextScheduledPayment()->id,
                'due_date' => $layaway->getNextScheduledPayment()->due_date->toISOString(),
                'amount_due' => $layaway->getNextScheduledPayment()->amount_due,
                'remaining_amount' => $layaway->getNextScheduledPayment()->remaining_amount,
            ] : null,
            'order' => $layaway->order ? [
                'id' => $layaway->order->id,
                'order_id' => $layaway->order->order_id,
            ] : null,
            'payments' => ($layaway->payments ?? collect())->map(fn ($payment) => [
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
            'note_entries' => ($layaway->notes ?? collect())->map(fn ($note) => [
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
            ['value' => Layaway::STATUS_PENDING, 'label' => 'Pending'],
            ['value' => Layaway::STATUS_ACTIVE, 'label' => 'Active'],
            ['value' => Layaway::STATUS_COMPLETED, 'label' => 'Completed'],
            ['value' => Layaway::STATUS_CANCELLED, 'label' => 'Cancelled'],
            ['value' => Layaway::STATUS_DEFAULTED, 'label' => 'Defaulted'],
        ];
    }

    /**
     * Get available term options.
     *
     * @return array<array<string, mixed>>
     */
    protected function getTermOptions(): array
    {
        return [
            ['value' => Layaway::TERM_30_DAYS, 'label' => '30 Days'],
            ['value' => Layaway::TERM_60_DAYS, 'label' => '60 Days'],
            ['value' => Layaway::TERM_90_DAYS, 'label' => '90 Days'],
            ['value' => Layaway::TERM_120_DAYS, 'label' => '120 Days'],
        ];
    }

    /**
     * Get available payment types.
     *
     * @return array<array<string, string>>
     */
    protected function getPaymentTypes(): array
    {
        return [
            ['value' => Layaway::PAYMENT_TYPE_FLEXIBLE, 'label' => 'Flexible (Any amount, anytime)'],
            ['value' => Layaway::PAYMENT_TYPE_SCHEDULED, 'label' => 'Scheduled (Fixed installments)'],
        ];
    }

    /**
     * Get available payment frequencies.
     *
     * @return array<array<string, string>>
     */
    protected function getPaymentFrequencies(): array
    {
        return [
            ['value' => LayawaySchedule::FREQUENCY_WEEKLY, 'label' => 'Weekly'],
            ['value' => LayawaySchedule::FREQUENCY_BIWEEKLY, 'label' => 'Every 2 Weeks'],
            ['value' => LayawaySchedule::FREQUENCY_MONTHLY, 'label' => 'Monthly'],
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
            ['value' => 'card', 'label' => 'Credit/Debit Card'],
            ['value' => 'check', 'label' => 'Check'],
            ['value' => 'bank_transfer', 'label' => 'Bank Transfer'],
            ['value' => 'store_credit', 'label' => 'Store Credit'],
        ];
    }
}
