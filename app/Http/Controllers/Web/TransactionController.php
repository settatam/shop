<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateBuyTransactionRequest;
use App\Models\Category;
use App\Models\NotificationChannel;
use App\Models\NotificationLog;
use App\Models\PrinterSetting;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\TransactionOffer;
use App\Models\Warehouse;
use App\Services\ActivityLogFormatter;
use App\Services\Notifications\NotificationManager;
use App\Services\Payments\PayPalPayoutsService;
use App\Services\Shipping\ShippingLabelService;
use App\Services\StoreContext;
use App\Services\Transactions\TransactionService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TransactionController extends Controller
{
    public function __construct(
        protected StoreContext $storeContext,
        protected TransactionService $transactionService,
        protected ShippingLabelService $shippingLabelService,
    ) {}

    public function index(Request $request): Response|RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return redirect()->route('dashboard')
                ->with('error', 'Please select a store first.');
        }

        return Inertia::render('transactions/Index', [
            'statuses' => $this->getStatuses(),
            'types' => $this->getTypes(),
        ]);
    }

    public function create(): Response|RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return redirect()->route('dashboard')
                ->with('error', 'Please select a store first.');
        }

        // Create a new in-house transaction
        $transaction = Transaction::create([
            'store_id' => $store->id,
            'user_id' => auth()->id(),
            'status' => Transaction::STATUS_PENDING,
            'type' => Transaction::TYPE_IN_STORE,
        ]);

        return redirect()->route('web.transactions.show', $transaction);
    }

    public function store(Request $request): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return redirect()->route('dashboard')
                ->with('error', 'Please select a store first.');
        }

        $validated = $request->validate([
            'customer_id' => 'nullable|exists:customers,id',
            'type' => 'required|string|in:in_house,mail_in',
            'customer_notes' => 'nullable|string|max:1000',
            'internal_notes' => 'nullable|string|max:1000',
            'bin_location' => 'nullable|string|max:255',
        ]);

        $transaction = Transaction::create([
            'store_id' => $store->id,
            'user_id' => auth()->id(),
            'customer_id' => $validated['customer_id'] ?? null,
            'status' => Transaction::STATUS_PENDING,
            'type' => $validated['type'],
            'customer_notes' => $validated['customer_notes'] ?? null,
            'internal_notes' => $validated['internal_notes'] ?? null,
            'bin_location' => $validated['bin_location'] ?? null,
        ]);

        return redirect()->route('web.transactions.show', $transaction)
            ->with('success', 'Transaction created successfully.');
    }

    public function show(Transaction $transaction): Response|RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store || $transaction->store_id !== $store->id) {
            abort(404);
        }

        $transaction->load([
            'customer.addresses',
            'shippingAddress',
            'user',
            'assignedUser',
            'items.images',
            'items.category',
            'statusHistories.user',
            'offers.user',
            'offers.respondedByUser',
            'offers.respondedByCustomer',
            'outboundLabel',
            'returnLabel',
            'payouts',
            'notes.user',
        ]);

        // Get statuses based on transaction type
        $statuses = $transaction->isOnline()
            ? $this->getOnlineStatuses()
            : $this->getInHouseStatuses();

        // Get team members for assignment (online transactions only)
        $teamMembers = [];
        if ($transaction->isOnline()) {
            $teamMembers = $store->storeUsers()
                ->with('user')
                ->get()
                ->map(fn ($storeUser) => [
                    'id' => $storeUser->user_id,
                    'name' => $storeUser->user?->name ?? 'Unknown',
                ])
                ->filter(fn ($member) => $member['id'] !== null)
                ->values();
        }

        // Get categories as flat list with hierarchy data for tree traversal
        $categories = Category::where('store_id', $store->id)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn ($category) => [
                'id' => $category->id,
                'name' => $category->name,
                'full_path' => $category->full_path,
                'parent_id' => $category->parent_id,
                'level' => $category->level,
                'template_id' => $category->template_id,
            ]);

        // Get SMS messages for online transactions
        $smsMessages = [];
        if ($transaction->isOnline()) {
            $smsMessages = NotificationLog::where('notifiable_type', Transaction::class)
                ->where('notifiable_id', $transaction->id)
                ->where('channel', NotificationChannel::TYPE_SMS)
                ->orderByDesc('created_at')
                ->get()
                ->map(fn ($log) => [
                    'id' => $log->id,
                    'content' => $log->content,
                    'channel' => $log->channel,
                    'direction' => $log->direction ?? 'outbound',
                    'status' => $log->status,
                    'recipient' => $log->recipient,
                    'sent_at' => $log->sent_at?->toISOString(),
                    'delivered_at' => $log->delivered_at?->toISOString(),
                    'created_at' => $log->created_at->toISOString(),
                ]);
        }

        // Get customer addresses for online transactions
        $customerAddresses = [];
        if ($transaction->isOnline() && $transaction->customer) {
            $customerAddresses = $transaction->customer->addresses
                ->map(fn ($address) => [
                    'id' => $address->id,
                    'full_name' => $address->full_name,
                    'company' => $address->company,
                    'address' => $address->address,
                    'address2' => $address->address2,
                    'city' => $address->city,
                    'state_id' => $address->state_id,
                    'country_id' => $address->country_id,
                    'zip' => $address->zip,
                    'phone' => $address->phone,
                    'is_default' => $address->is_default,
                    'is_shipping' => $address->is_shipping,
                    'one_line_address' => $address->one_line_address,
                    'is_valid_for_shipping' => $address->isValidForShipping(),
                ]);
        }

        return Inertia::render('transactions/Show', [
            'transaction' => $this->formatTransaction($transaction),
            'statuses' => $statuses,
            'paymentMethods' => $this->getPaymentMethods(),
            'teamMembers' => $teamMembers,
            'shippingOptions' => $this->getShippingOptions(),
            'customerAddresses' => $customerAddresses,
            'categories' => $categories,
            'preciousMetals' => $this->getPreciousMetals(),
            'conditions' => $this->getConditions(),
            'smsMessages' => $smsMessages,
            'activityLogs' => Inertia::defer(fn () => app(ActivityLogFormatter::class)->formatForSubject($transaction)),
        ]);
    }

    /**
     * Get FedEx shipping options for the frontend.
     *
     * @return array<string, mixed>
     */
    protected function getShippingOptions(): array
    {
        return [
            'service_types' => ShippingLabelService::getServiceTypes(),
            'packaging_types' => ShippingLabelService::getPackagingTypes(),
            'default_package' => ShippingLabelService::getDefaultPackageDimensions(),
            'is_configured' => $this->shippingLabelService->isConfigured(),
        ];
    }

    public function edit(Transaction $transaction): Response|RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store || $transaction->store_id !== $store->id) {
            abort(404);
        }

        $transaction->load(['customer', 'user', 'items']);

        return Inertia::render('transactions/Edit', [
            'transaction' => $this->formatTransaction($transaction),
            'statuses' => $this->getStatuses(),
            'paymentMethods' => $this->getPaymentMethods(),
        ]);
    }

    public function update(Request $request, Transaction $transaction): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store || $transaction->store_id !== $store->id) {
            abort(404);
        }

        $validated = $request->validate([
            'customer_id' => 'nullable|exists:customers,id',
            'customer_notes' => 'nullable|string|max:1000',
            'internal_notes' => 'nullable|string|max:1000',
            'bin_location' => 'nullable|string|max:255',
            'preliminary_offer' => 'nullable|numeric|min:0',
        ]);

        $transaction->update($validated);

        return redirect()->route('web.transactions.show', $transaction)
            ->with('success', 'Transaction updated successfully.');
    }

    public function destroy(Transaction $transaction): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store || $transaction->store_id !== $store->id) {
            abort(404);
        }

        $transaction->delete();

        return redirect()->route('web.transactions.index')
            ->with('success', 'Transaction deleted successfully.');
    }

    public function submitOffer(Request $request, Transaction $transaction): RedirectResponse
    {
        $this->authorizeTransaction($transaction);

        if (! $transaction->canSubmitOffer()) {
            return back()->with('error', 'Cannot submit offer for this transaction.');
        }

        $validated = $request->validate([
            'offer' => 'required|numeric|min:0',
            'notes' => 'nullable|string|max:1000',
            'send_notification' => 'nullable|boolean',
        ]);

        $this->transactionService->createOffer(
            $transaction,
            (float) $validated['offer'],
            $validated['notes'] ?? null,
            (bool) ($validated['send_notification'] ?? false)
        );

        $message = 'Offer sent to customer.';
        if ($validated['send_notification'] ?? false) {
            $message .= ' Notification sent.';
        }

        return back()->with('success', $message);
    }

    public function acceptOffer(Request $request, Transaction $transaction): RedirectResponse
    {
        $this->authorizeTransaction($transaction);

        $validated = $request->validate([
            'offer_id' => 'required|exists:transaction_offers,id',
        ]);

        $offer = TransactionOffer::findOrFail($validated['offer_id']);

        if ($offer->transaction_id !== $transaction->id) {
            return back()->with('error', 'Offer does not belong to this transaction.');
        }

        if (! $offer->isPending()) {
            return back()->with('error', 'This offer has already been responded to.');
        }

        $this->transactionService->acceptOfferWithTracking($transaction, $offer);

        return back()->with('success', 'Offer accepted.');
    }

    public function declineOffer(Request $request, Transaction $transaction): RedirectResponse
    {
        $this->authorizeTransaction($transaction);

        $validated = $request->validate([
            'offer_id' => 'required|exists:transaction_offers,id',
            'reason' => 'nullable|string|max:500',
        ]);

        $offer = TransactionOffer::findOrFail($validated['offer_id']);

        if ($offer->transaction_id !== $transaction->id) {
            return back()->with('error', 'Offer does not belong to this transaction.');
        }

        if (! $offer->isPending()) {
            return back()->with('error', 'This offer has already been responded to.');
        }

        $this->transactionService->declineOfferWithTracking(
            $transaction,
            $offer,
            $validated['reason'] ?? null
        );

        return back()->with('success', 'Offer declined.');
    }

    public function processPayment(Request $request, Transaction $transaction): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store || $transaction->store_id !== $store->id) {
            abort(404);
        }

        if (! $transaction->canProcessPayment()) {
            return back()->with('error', 'Cannot process payment for this transaction.');
        }

        $validated = $request->validate([
            'payments' => 'required|array|min:1',
            'payments.*.method' => 'required|string|in:cash,check,store_credit,ach,paypal,venmo,wire_transfer',
            'payments.*.amount' => 'required|numeric|min:0',
            'payments.*.details' => 'nullable|array',
        ]);

        // Validate total payments equal the final offer
        $totalPayments = collect($validated['payments'])->sum('amount');
        $finalOffer = (float) $transaction->final_offer;

        if (abs($totalPayments - $finalOffer) > 0.01) {
            return back()->with('error', 'Total payments must equal the offer amount.');
        }

        // Build payment details for each payment
        $paymentsData = [];
        foreach ($validated['payments'] as $payment) {
            $method = $payment['method'];
            $details = $payment['details'] ?? [];

            $paymentEntry = [
                'method' => $method,
                'amount' => $payment['amount'],
                'details' => null,
            ];

            // Build payment-specific details
            if ($method === 'paypal' && ! empty($details['paypal_email'])) {
                $paymentEntry['details'] = [
                    'type' => 'paypal',
                    'email' => $details['paypal_email'],
                ];
            } elseif ($method === 'venmo' && ! empty($details['venmo_handle'])) {
                $paymentEntry['details'] = [
                    'type' => 'venmo',
                    'username' => $details['venmo_handle'],
                ];
            } elseif (in_array($method, ['ach', 'wire_transfer'])) {
                $paymentEntry['details'] = [
                    'type' => $method,
                    'bank_name' => $details['bank_name'] ?? null,
                    'account_name' => $details['account_name'] ?? null,
                    'account_number' => $details['account_number'] ?? null,
                    'routing_number' => $details['routing_number'] ?? null,
                ];
            } elseif ($method === 'check') {
                $paymentEntry['details'] = [
                    'type' => 'check',
                    'mailing_name' => $details['mailing_name'] ?? null,
                    'mailing_address' => $details['mailing_address'] ?? null,
                    'mailing_city' => $details['mailing_city'] ?? null,
                    'mailing_state' => $details['mailing_state'] ?? null,
                    'mailing_zip' => $details['mailing_zip'] ?? null,
                ];
            }

            $paymentsData[] = $paymentEntry;
        }

        $this->transactionService->processMultiplePayments($transaction, $paymentsData);

        return back()->with('success', 'Payment processed successfully.');
    }

    public function bulkAction(Request $request): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return redirect()->route('dashboard')
                ->with('error', 'Please select a store first.');
        }

        $validated = $request->validate([
            'action' => 'required|string',
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|exists:transactions,id',
            'config' => 'nullable|array',
            'config.target_status_id' => 'nullable|integer|exists:statuses,id',
            'config.tag_id' => 'nullable|integer|exists:tags,id',
        ]);

        $transactions = Transaction::where('store_id', $store->id)
            ->whereIn('id', $validated['ids'])
            ->get();

        $count = $transactions->count();
        $config = $validated['config'] ?? [];

        $actionLabel = match ($validated['action']) {
            'delete' => $this->handleBulkDelete($transactions),
            'change_status' => $this->handleBulkChangeStatus($transactions, $config),
            'add_tag' => $this->handleBulkAddTag($transactions, $config),
            'remove_tag' => $this->handleBulkRemoveTag($transactions, $config),
            default => 'processed',
        };

        return redirect()->route('web.transactions.index')
            ->with('success', "{$count} transaction(s) {$actionLabel}.");
    }

    public function export(Request $request): StreamedResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            abort(403, 'Please select a store first.');
        }

        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|exists:transactions,id',
        ]);

        $transactions = Transaction::with(['customer', 'items', 'statusModel', 'tags'])
            ->where('store_id', $store->id)
            ->whereIn('id', $validated['ids'])
            ->get();

        $filename = 'transactions-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($transactions) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, [
                'Transaction #',
                'Customer',
                'Type',
                'Status',
                'Items Count',
                'Est. Value',
                'Offer',
                'Payment Method',
                'Tags',
                'Bin Location',
                'Created',
                'Updated',
            ]);

            foreach ($transactions as $transaction) {
                $statusName = $transaction->statusModel?->name
                    ?? ucfirst(str_replace('_', ' ', $transaction->status ?? 'Unknown'));

                fputcsv($handle, [
                    $transaction->transaction_number,
                    $transaction->customer?->full_name ?? '',
                    $transaction->type,
                    $statusName,
                    $transaction->items->count(),
                    $transaction->total_value,
                    $transaction->final_offer ?? 0,
                    $transaction->payment_method ?? '',
                    $transaction->tags->pluck('name')->implode(', '),
                    $transaction->bin_location ?? '',
                    $transaction->created_at->format('Y-m-d H:i:s'),
                    $transaction->updated_at->format('Y-m-d H:i:s'),
                ]);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }

    /**
     * @param  Collection<int, Transaction>  $transactions
     */
    protected function handleBulkDelete(Collection $transactions): string
    {
        $transactions->each->delete();

        return 'deleted';
    }

    /**
     * @param  Collection<int, Transaction>  $transactions
     * @param  array<string, mixed>  $config
     */
    protected function handleBulkChangeStatus(Collection $transactions, array $config): string
    {
        $targetStatusId = $config['target_status_id'] ?? null;
        $targetStatusName = $config['target_status_name'] ?? 'new status';

        if (! $targetStatusId) {
            return 'no status specified';
        }

        $transactions->each(function ($transaction) use ($targetStatusId) {
            $transaction->update(['status_id' => $targetStatusId]);
        });

        return "moved to {$targetStatusName}";
    }

    /**
     * @param  Collection<int, Transaction>  $transactions
     * @param  array<string, mixed>  $config
     */
    protected function handleBulkAddTag(Collection $transactions, array $config): string
    {
        $tagId = $config['tag_id'] ?? null;

        if (! $tagId) {
            return 'no tag specified';
        }

        $transactions->each(function ($transaction) use ($tagId) {
            $transaction->tags()->syncWithoutDetaching([$tagId]);
        });

        return 'tagged';
    }

    /**
     * @param  Collection<int, Transaction>  $transactions
     * @param  array<string, mixed>  $config
     */
    protected function handleBulkRemoveTag(Collection $transactions, array $config): string
    {
        $tagId = $config['tag_id'] ?? null;

        if (! $tagId) {
            return 'no tag specified';
        }

        $transactions->each(function ($transaction) use ($tagId) {
            $transaction->tags()->detach($tagId);
        });

        return 'tag removed';
    }

    public function changeStatus(Request $request, Transaction $transaction): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store || $transaction->store_id !== $store->id) {
            abort(404);
        }

        $validated = $request->validate([
            'status' => 'required|string|in:'.implode(',', array_keys(Transaction::getAvailableStatuses())),
        ]);

        if (! $transaction->canChangeStatusTo($validated['status'])) {
            return back()->with('error', 'Cannot change to this status.');
        }

        $transaction->update(['status' => $validated['status']]);

        $statusLabel = Transaction::getAvailableStatuses()[$validated['status']];

        return back()->with('success', "Status changed to {$statusLabel}.");
    }

    // Online Transaction Workflow Methods

    public function confirmKitRequest(Transaction $transaction): RedirectResponse
    {
        $this->authorizeTransaction($transaction);

        if (! $transaction->isOnline()) {
            return back()->with('error', 'This action is only available for online transactions.');
        }

        $transaction->confirmKitRequest();

        return back()->with('success', 'Kit request confirmed.');
    }

    public function rejectKitRequest(Transaction $transaction): RedirectResponse
    {
        $this->authorizeTransaction($transaction);

        if (! $transaction->isOnline()) {
            return back()->with('error', 'This action is only available for online transactions.');
        }

        $transaction->rejectKitRequest();

        return back()->with('success', 'Kit request rejected.');
    }

    public function holdKitRequest(Transaction $transaction): RedirectResponse
    {
        $this->authorizeTransaction($transaction);

        if (! $transaction->isOnline()) {
            return back()->with('error', 'This action is only available for online transactions.');
        }

        $transaction->holdKitRequest();

        return back()->with('success', 'Kit request placed on hold.');
    }

    public function markKitSent(Request $request, Transaction $transaction): RedirectResponse
    {
        $this->authorizeTransaction($transaction);

        if (! $transaction->isOnline()) {
            return back()->with('error', 'This action is only available for online transactions.');
        }

        $validated = $request->validate([
            'tracking_number' => ['required', 'string', 'max:100'],
            'carrier' => ['nullable', 'string', 'max:50'],
        ]);

        $transaction->markKitSent(
            $validated['tracking_number'],
            $validated['carrier'] ?? 'fedex'
        );

        return back()->with('success', 'Kit marked as sent.');
    }

    public function markKitDelivered(Transaction $transaction): RedirectResponse
    {
        $this->authorizeTransaction($transaction);

        if (! $transaction->isOnline()) {
            return back()->with('error', 'This action is only available for online transactions.');
        }

        $transaction->markKitDelivered();

        return back()->with('success', 'Kit marked as delivered.');
    }

    public function markItemsReceived(Transaction $transaction): RedirectResponse
    {
        $this->authorizeTransaction($transaction);

        if (! $transaction->isOnline()) {
            return back()->with('error', 'This action is only available for online transactions.');
        }

        $transaction->markItemsReceived();

        return back()->with('success', 'Items marked as received.');
    }

    public function markItemsReviewed(Transaction $transaction): RedirectResponse
    {
        $this->authorizeTransaction($transaction);

        if (! $transaction->isOnline()) {
            return back()->with('error', 'This action is only available for online transactions.');
        }

        $transaction->markItemsReviewed();

        return back()->with('success', 'Items marked as reviewed.');
    }

    public function requestReturn(Transaction $transaction): RedirectResponse
    {
        $this->authorizeTransaction($transaction);

        if (! $transaction->isOnline()) {
            return back()->with('error', 'This action is only available for online transactions.');
        }

        $transaction->requestReturn();

        return back()->with('success', 'Return requested.');
    }

    public function markReturnShipped(Request $request, Transaction $transaction): RedirectResponse
    {
        $this->authorizeTransaction($transaction);

        if (! $transaction->isOnline()) {
            return back()->with('error', 'This action is only available for online transactions.');
        }

        $validated = $request->validate([
            'tracking_number' => ['required', 'string', 'max:100'],
            'carrier' => ['nullable', 'string', 'max:50'],
        ]);

        $transaction->markReturnShipped(
            $validated['tracking_number'],
            $validated['carrier'] ?? 'fedex'
        );

        return back()->with('success', 'Return marked as shipped.');
    }

    public function markItemsReturned(Transaction $transaction): RedirectResponse
    {
        $this->authorizeTransaction($transaction);

        if (! $transaction->isOnline()) {
            return back()->with('error', 'This action is only available for online transactions.');
        }

        $transaction->markItemsReturned();

        return back()->with('success', 'Items marked as returned.');
    }

    public function assignTransaction(Request $request, Transaction $transaction): RedirectResponse
    {
        $this->authorizeTransaction($transaction);

        $validated = $request->validate([
            'assigned_to' => ['nullable', 'exists:users,id'],
        ]);

        $transaction->update(['assigned_to' => $validated['assigned_to']]);

        return back()->with('success', 'Transaction assigned successfully.');
    }

    // Shipping Address Methods

    public function updateShippingAddress(Request $request, Transaction $transaction): RedirectResponse
    {
        $this->authorizeTransaction($transaction);

        if (! $transaction->isOnline()) {
            return back()->with('error', 'Shipping addresses are only available for online transactions.');
        }

        $validated = $request->validate([
            'shipping_address_id' => ['nullable', 'exists:addresses,id'],
        ]);

        // Verify the address belongs to this customer or transaction
        if ($validated['shipping_address_id']) {
            $address = \App\Models\Address::find($validated['shipping_address_id']);
            if (! $address || $address->store_id !== $transaction->store_id) {
                return back()->with('error', 'Invalid address selected.');
            }
        }

        $transaction->update(['shipping_address_id' => $validated['shipping_address_id']]);

        return back()->with('success', 'Shipping address updated.');
    }

    // Shipping Label Methods

    public function createOutboundLabel(Request $request, Transaction $transaction): RedirectResponse
    {
        $this->authorizeTransaction($transaction);

        if (! $transaction->isOnline()) {
            return back()->with('error', 'Shipping labels are only available for online transactions.');
        }

        if (! $this->shippingLabelService->isConfigured()) {
            return back()->with('error', 'FedEx shipping is not configured. Please add FedEx credentials in settings.');
        }

        $options = $request->validate([
            'service_type' => 'nullable|string|in:'.implode(',', array_keys(ShippingLabelService::getServiceTypes())),
            'packaging_type' => 'nullable|string|in:'.implode(',', array_keys(ShippingLabelService::getPackagingTypes())),
            'weight' => 'nullable|numeric|min:0.1|max:150',
            'length' => 'nullable|numeric|min:1|max:108',
            'width' => 'nullable|numeric|min:1|max:108',
            'height' => 'nullable|numeric|min:1|max:108',
        ]);

        try {
            $label = $this->shippingLabelService->createOutboundLabel($transaction, $options);

            // Update transaction tracking info
            $transaction->update([
                'outbound_tracking_number' => $label->tracking_number,
                'outbound_carrier' => $label->carrier,
            ]);

            return back()->with('success', "Outbound label created. Tracking: {$label->tracking_number}");
        } catch (\Exception $e) {
            return back()->with('error', 'Failed to create shipping label: '.$e->getMessage());
        }
    }

    public function printOutboundLabel(Transaction $transaction)
    {
        $this->authorizeTransaction($transaction);

        $label = $transaction->outboundLabel;

        if (! $label) {
            abort(404, 'No outbound label found for this transaction.');
        }

        $pdf = $this->shippingLabelService->getLabelPdf($label);

        if (! $pdf) {
            abort(404, 'Label PDF not found.');
        }

        return response($pdf)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'inline; filename="outbound-label-'.$transaction->transaction_number.'.pdf"');
    }

    public function getOutboundLabelZpl(Transaction $transaction)
    {
        $this->authorizeTransaction($transaction);

        $label = $transaction->outboundLabel;

        return response()->json([
            'zpl' => $label?->label_zpl,
            'tracking_number' => $label?->tracking_number,
        ]);
    }

    public function createReturnLabel(Request $request, Transaction $transaction): RedirectResponse
    {
        $this->authorizeTransaction($transaction);

        if (! $transaction->isOnline()) {
            return back()->with('error', 'Shipping labels are only available for online transactions.');
        }

        if (! $this->shippingLabelService->isConfigured()) {
            return back()->with('error', 'FedEx shipping is not configured. Please add FedEx credentials in settings.');
        }

        $options = $request->validate([
            'service_type' => 'nullable|string|in:'.implode(',', array_keys(ShippingLabelService::getServiceTypes())),
            'packaging_type' => 'nullable|string|in:'.implode(',', array_keys(ShippingLabelService::getPackagingTypes())),
            'weight' => 'nullable|numeric|min:0.1|max:150',
            'length' => 'nullable|numeric|min:1|max:108',
            'width' => 'nullable|numeric|min:1|max:108',
            'height' => 'nullable|numeric|min:1|max:108',
        ]);

        try {
            $label = $this->shippingLabelService->createReturnLabel($transaction, $options);

            // Update transaction tracking info
            $transaction->update([
                'return_tracking_number' => $label->tracking_number,
                'return_carrier' => $label->carrier,
            ]);

            return back()->with('success', "Return label created. Tracking: {$label->tracking_number}");
        } catch (\Exception $e) {
            return back()->with('error', 'Failed to create return label: '.$e->getMessage());
        }
    }

    public function printReturnLabel(Transaction $transaction)
    {
        $this->authorizeTransaction($transaction);

        $label = $transaction->returnLabel;

        if (! $label) {
            abort(404, 'No return label found for this transaction.');
        }

        $pdf = $this->shippingLabelService->getLabelPdf($label);

        if (! $pdf) {
            abort(404, 'Label PDF not found.');
        }

        return response($pdf)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'inline; filename="return-label-'.$transaction->transaction_number.'.pdf"');
    }

    public function getReturnLabelZpl(Transaction $transaction)
    {
        $this->authorizeTransaction($transaction);

        $label = $transaction->returnLabel;

        return response()->json([
            'zpl' => $label?->label_zpl,
            'tracking_number' => $label?->tracking_number,
        ]);
    }

    // Kit Rejection and Return Methods

    public function rejectKit(Request $request, Transaction $transaction): RedirectResponse
    {
        $this->authorizeTransaction($transaction);

        if (! $transaction->isOnline()) {
            return back()->with('error', 'This action is only available for online transactions.');
        }

        $validated = $request->validate([
            'reason' => 'nullable|string|max:1000',
        ]);

        try {
            $this->transactionService->rejectKit($transaction, $validated['reason'] ?? null);

            return back()->with('success', 'Kit rejected.');
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function initiateReturn(Transaction $transaction): RedirectResponse
    {
        $this->authorizeTransaction($transaction);

        if (! $transaction->isOnline()) {
            return back()->with('error', 'This action is only available for online transactions.');
        }

        try {
            $this->transactionService->initiateReturn($transaction);

            return back()->with('success', 'Return initiated. Please create a return shipping label.');
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    // Rollback / Reset Methods

    public function resetToItemsReviewed(Transaction $transaction): RedirectResponse
    {
        $this->authorizeTransaction($transaction);

        if (! $transaction->isOnline()) {
            return back()->with('error', 'This action is only available for online transactions.');
        }

        try {
            $this->transactionService->resetToItemsReviewed($transaction);

            return back()->with('success', 'Transaction reset to items reviewed. You can now submit a new offer.');
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function reopenOffer(Transaction $transaction): RedirectResponse
    {
        $this->authorizeTransaction($transaction);

        if (! $transaction->isOnline()) {
            return back()->with('error', 'This action is only available for online transactions.');
        }

        try {
            $this->transactionService->reopenOffer($transaction);

            return back()->with('success', 'Offer reopened. The customer can now respond to the offer again.');
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function cancelReturn(Transaction $transaction): RedirectResponse
    {
        $this->authorizeTransaction($transaction);

        if (! $transaction->isOnline()) {
            return back()->with('error', 'This action is only available for online transactions.');
        }

        try {
            $this->transactionService->cancelReturn($transaction);

            return back()->with('success', 'Return cancelled.');
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function undoPayment(Transaction $transaction): RedirectResponse
    {
        $this->authorizeTransaction($transaction);

        try {
            $this->transactionService->undoPayment($transaction);

            return back()->with('success', 'Payment undone. Transaction is back to offer accepted state.');
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    // PayPal Payout Methods

    public function sendPayout(Request $request, Transaction $transaction): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();
        $this->authorizeTransaction($transaction);

        if (! $transaction->isPaymentProcessed()) {
            return back()->with('error', 'Payment must be processed before sending payouts.');
        }

        $validated = $request->validate([
            'recipient_value' => 'required|string|max:255',
            'amount' => 'required|numeric|min:0.01',
            'wallet' => 'required|string|in:PAYPAL,VENMO',
            'note' => 'nullable|string|max:500',
        ]);

        $paypalService = PayPalPayoutsService::forStore($store);

        if (! $paypalService->isConfigured()) {
            return back()->with('error', 'PayPal is not configured for this store. Please add PayPal credentials in settings.');
        }

        try {
            $payout = $paypalService->sendTransactionPayout(
                $transaction,
                $validated['recipient_value'],
                (float) $validated['amount'],
                $validated['wallet'],
                $validated['note'] ?? null
            );

            if ($payout->isFailed()) {
                return back()->with('error', 'Payout failed: '.$payout->error_message);
            }

            return back()->with('success', 'Payout initiated successfully. Batch ID: '.$payout->payout_batch_id);
        } catch (\Exception $e) {
            return back()->with('error', 'Failed to send payout: '.$e->getMessage());
        }
    }

    public function refreshPayoutStatus(Request $request, Transaction $transaction): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();
        $this->authorizeTransaction($transaction);

        $validated = $request->validate([
            'payout_id' => 'required|exists:transaction_payouts,id',
        ]);

        $payout = $transaction->payouts()->findOrFail($validated['payout_id']);

        $paypalService = PayPalPayoutsService::forStore($store);

        if (! $paypalService->isConfigured()) {
            return back()->with('error', 'PayPal is not configured for this store.');
        }

        try {
            $paypalService->refreshPayoutStatus($payout);

            return back()->with('success', 'Payout status refreshed: '.$payout->status);
        } catch (\Exception $e) {
            return back()->with('error', 'Failed to refresh payout status: '.$e->getMessage());
        }
    }

    // SMS Messaging

    public function sendSms(Request $request, Transaction $transaction): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();
        $this->authorizeTransaction($transaction);

        if (! $transaction->isOnline()) {
            return back()->with('error', 'SMS messaging is only available for online transactions.');
        }

        if (! $transaction->customer || ! $transaction->customer->phone_number) {
            return back()->with('error', 'Customer has no phone number on file.');
        }

        $validated = $request->validate([
            'message' => 'required|string|max:1600',
        ]);

        try {
            $notificationManager = new NotificationManager($store);
            $notificationManager->send(
                NotificationChannel::TYPE_SMS,
                $transaction->customer->phone_number,
                $validated['message'],
                [
                    'notifiable_type' => Transaction::class,
                    'notifiable_id' => $transaction->id,
                    'activity' => 'transactions.sms_sent',
                ]
            );

            return back()->with('success', 'SMS sent successfully.');
        } catch (\Exception $e) {
            return back()->with('error', 'Failed to send SMS: '.$e->getMessage());
        }
    }

    protected function authorizeTransaction(Transaction $transaction): void
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store || $transaction->store_id !== $store->id) {
            abort(404);
        }
    }

    public function printBarcode(Transaction $transaction): Response|RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store || $transaction->store_id !== $store->id) {
            abort(404);
        }

        $printerSettings = PrinterSetting::where('store_id', $store->id)
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get()
            ->map(fn (PrinterSetting $setting) => [
                'id' => $setting->id,
                'name' => $setting->name,
                'top_offset' => $setting->top_offset,
                'left_offset' => $setting->left_offset,
                'right_offset' => $setting->right_offset,
                'text_size' => $setting->text_size,
                'barcode_height' => $setting->barcode_height,
                'line_height' => $setting->line_height,
                'label_width' => $setting->label_width,
                'label_height' => $setting->label_height,
                'is_default' => $setting->is_default,
            ]);

        return Inertia::render('transactions/PrintBarcode', [
            'transaction' => [
                'id' => $transaction->id,
                'transaction_number' => $transaction->transaction_number,
                'type' => $transaction->type,
                'customer' => $transaction->customer ? [
                    'full_name' => $transaction->customer->full_name,
                ] : null,
                'created_at' => $transaction->created_at->toISOString(),
            ],
            'printerSettings' => $printerSettings,
        ]);
    }

    public function printInvoice(Transaction $transaction): Response|RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store || $transaction->store_id !== $store->id) {
            abort(404);
        }

        $transaction->load(['customer', 'user', 'items.images', 'items.category', 'payouts']);

        $formattedTransaction = $this->formatTransaction($transaction);

        // Add payment details from the transaction's payment_details JSON
        // This stores the customer's payment preference (how they want to be paid)
        $paymentDetails = $transaction->payment_details ?? [];
        $formattedTransaction['payments'] = [];

        if ($transaction->payment_method) {
            $formattedTransaction['payments'][] = [
                'id' => 1,
                'payment_method' => $transaction->payment_method,
                'amount' => $transaction->final_offer ?? $transaction->total_buy_price ?? 0,
                'details' => $paymentDetails,
            ];
        }

        // Add customer address fields if customer exists
        if ($transaction->customer) {
            $formattedTransaction['customer'] = array_merge($formattedTransaction['customer'] ?? [], [
                'company_name' => $transaction->customer->company_name,
                'address' => $transaction->customer->address,
                'address2' => $transaction->customer->address2,
                'city' => $transaction->customer->city,
                'state' => null, // state_id is a foreign key without a states table
                'zip' => $transaction->customer->zip,
                'phone' => $transaction->customer->phone_number,
            ]);
        }

        return Inertia::render('transactions/PrintInvoice', [
            'transaction' => $formattedTransaction,
            'store' => [
                'name' => $store->name,
                'logo' => $store->logo,
                'address' => $store->address,
                'address2' => $store->address2,
                'city' => $store->city,
                'state' => $store->state,
                'zip' => $store->zip,
                'phone' => $store->phone,
                'email' => $store->customer_email ?? $store->account_email,
            ],
            'barcode' => null, // Barcode generation can be added later
        ]);
    }

    /**
     * Format transaction for frontend.
     *
     * @return array<string, mixed>
     */
    protected function formatTransaction(Transaction $transaction): array
    {
        $data = [
            'id' => $transaction->id,
            'transaction_number' => $transaction->transaction_number,
            'status' => $transaction->status,
            'type' => $transaction->type,
            'source' => $transaction->source,
            'preliminary_offer' => $transaction->preliminary_offer,
            'final_offer' => $transaction->final_offer,
            'estimated_value' => $transaction->estimated_value,
            'payment_method' => $transaction->payment_method,
            'payment_details' => $transaction->payment_details,
            'bin_location' => $transaction->bin_location,
            'customer_notes' => $transaction->customer_notes,
            'internal_notes' => $transaction->internal_notes,
            'offer_given_at' => $transaction->offer_given_at?->toISOString(),
            'offer_accepted_at' => $transaction->offer_accepted_at?->toISOString(),
            'payment_processed_at' => $transaction->payment_processed_at?->toISOString(),
            'created_at' => $transaction->created_at->toISOString(),
            'updated_at' => $transaction->updated_at->toISOString(),
            'item_count' => $transaction->item_count,
            'total_dwt' => $transaction->total_dwt,
            'total_value' => $transaction->total_value,
            'total_buy_price' => $transaction->total_buy_price,
            'can_submit_offer' => $transaction->canSubmitOffer(),
            'can_accept_offer' => $transaction->canAcceptOffer(),
            'can_process_payment' => $transaction->canProcessPayment(),
            'can_be_cancelled' => $transaction->canBeCancelled(),
            'is_in_store' => $transaction->isInStore(),
            'is_online' => $transaction->isOnline(),
            'shipping_address_id' => $transaction->shipping_address_id,
            'shipping_address' => $transaction->shippingAddress ? [
                'id' => $transaction->shippingAddress->id,
                'full_name' => $transaction->shippingAddress->full_name,
                'company' => $transaction->shippingAddress->company,
                'address' => $transaction->shippingAddress->address,
                'address2' => $transaction->shippingAddress->address2,
                'city' => $transaction->shippingAddress->city,
                'state_id' => $transaction->shippingAddress->state_id,
                'country_id' => $transaction->shippingAddress->country_id,
                'zip' => $transaction->shippingAddress->zip,
                'phone' => $transaction->shippingAddress->phone,
                'one_line_address' => $transaction->shippingAddress->one_line_address,
            ] : null,
            'customer' => $transaction->customer ? [
                'id' => $transaction->customer->id,
                'first_name' => $transaction->customer->first_name,
                'last_name' => $transaction->customer->last_name,
                'full_name' => $transaction->customer->full_name,
                'email' => $transaction->customer->email,
                'phone_number' => $transaction->customer->phone_number,
                'address' => $transaction->customer->address,
                'address2' => $transaction->customer->address2,
                'city' => $transaction->customer->city,
                'zip' => $transaction->customer->zip,
                'has_addresses' => $transaction->customer->addresses->isNotEmpty(),
            ] : null,
            'user' => $transaction->user ? [
                'id' => $transaction->user->id,
                'name' => $transaction->user->name,
            ] : null,
            'assigned_user' => $transaction->assignedUser ? [
                'id' => $transaction->assignedUser->id,
                'name' => $transaction->assignedUser->name,
            ] : null,
            'items' => $transaction->items->map(fn ($item) => [
                'id' => $item->id,
                'title' => $item->title,
                'description' => $item->description,
                'category' => $item->category ? [
                    'id' => $item->category->id,
                    'name' => $item->category->name,
                ] : null,
                'metal_type' => $item->metal_type,
                'karat' => $item->karat,
                'weight' => $item->weight,
                'dwt' => $item->dwt,
                'price' => $item->price,
                'buy_price' => $item->buy_price,
                'notes' => $item->notes,
                'is_added_to_inventory' => $item->is_added_to_inventory,
                'reviewed_at' => $item->reviewed_at?->toISOString(),
                'images' => $item->images->map(fn ($image) => [
                    'id' => $image->id,
                    'url' => $image->url,
                    'thumbnail_url' => $image->thumbnail_url,
                    'is_primary' => $image->is_primary,
                ])->sortByDesc('is_primary')->values(),
            ]),
            'status_history' => $transaction->statusHistories->map(fn ($history) => [
                'id' => $history->id,
                'from_status' => $history->from_status,
                'to_status' => $history->to_status,
                'from_status_label' => $history->from_status_label,
                'to_status_label' => $history->to_status_label,
                'notes' => $history->notes,
                'user' => $history->user ? [
                    'id' => $history->user->id,
                    'name' => $history->user->name,
                ] : null,
                'created_at' => $history->created_at->toISOString(),
            ]),
        ];

        // Add online transaction fields
        if ($transaction->isOnline()) {
            $data = array_merge($data, [
                'customer_description' => $transaction->customer_description,
                'customer_amount' => $transaction->customer_amount,
                'customer_categories' => $transaction->customer_categories,
                'outbound_tracking_number' => $transaction->outbound_tracking_number,
                'outbound_carrier' => $transaction->outbound_carrier,
                'return_tracking_number' => $transaction->return_tracking_number,
                'return_carrier' => $transaction->return_carrier,
                'kit_sent_at' => $transaction->kit_sent_at?->toISOString(),
                'kit_delivered_at' => $transaction->kit_delivered_at?->toISOString(),
                'items_received_at' => $transaction->items_received_at?->toISOString(),
                'items_reviewed_at' => $transaction->items_reviewed_at?->toISOString(),
                'return_shipped_at' => $transaction->return_shipped_at?->toISOString(),
                'return_delivered_at' => $transaction->return_delivered_at?->toISOString(),
            ]);

            // Add offers history
            if ($transaction->relationLoaded('offers')) {
                $data['offers'] = $transaction->offers->map(fn ($offer) => [
                    'id' => $offer->id,
                    'amount' => $offer->amount,
                    'status' => $offer->status,
                    'admin_notes' => $offer->admin_notes,
                    'customer_response' => $offer->customer_response,
                    'responded_at' => $offer->responded_at?->toISOString(),
                    'created_at' => $offer->created_at->toISOString(),
                    'user' => $offer->user ? [
                        'id' => $offer->user->id,
                        'name' => $offer->user->name,
                    ] : null,
                    'responded_by_user' => $offer->respondedByUser ? [
                        'id' => $offer->respondedByUser->id,
                        'name' => $offer->respondedByUser->name,
                    ] : null,
                    'responded_by_customer' => $offer->respondedByCustomer ? [
                        'id' => $offer->respondedByCustomer->id,
                        'name' => $offer->respondedByCustomer->full_name,
                    ] : null,
                    'responder_name' => $offer->getResponderName(),
                    'was_responded_by_customer' => $offer->wasRespondedByCustomer(),
                ]);

                // Get the pending offer for quick access
                $pendingOffer = $transaction->offers->firstWhere('status', 'pending');
                $data['pending_offer'] = $pendingOffer ? [
                    'id' => $pendingOffer->id,
                    'amount' => $pendingOffer->amount,
                    'admin_notes' => $pendingOffer->admin_notes,
                    'created_at' => $pendingOffer->created_at->toISOString(),
                ] : null;
            }

            // Add shipping labels
            if ($transaction->relationLoaded('outboundLabel')) {
                $outboundLabel = $transaction->outboundLabel;
                $data['outbound_label'] = $outboundLabel ? [
                    'id' => $outboundLabel->id,
                    'tracking_number' => $outboundLabel->tracking_number,
                    'carrier' => $outboundLabel->carrier,
                    'service_type' => $outboundLabel->service_type,
                    'status' => $outboundLabel->status,
                    'shipping_cost' => $outboundLabel->shipping_cost,
                    'tracking_url' => $outboundLabel->getTrackingUrl(),
                    'created_at' => $outboundLabel->created_at->toISOString(),
                ] : null;
            }

            if ($transaction->relationLoaded('returnLabel')) {
                $returnLabel = $transaction->returnLabel;
                $data['return_label'] = $returnLabel ? [
                    'id' => $returnLabel->id,
                    'tracking_number' => $returnLabel->tracking_number,
                    'carrier' => $returnLabel->carrier,
                    'service_type' => $returnLabel->service_type,
                    'status' => $returnLabel->status,
                    'shipping_cost' => $returnLabel->shipping_cost,
                    'tracking_url' => $returnLabel->getTrackingUrl(),
                    'created_at' => $returnLabel->created_at->toISOString(),
                ] : null;
            }

            // Add available rollback actions
            $data['rollback_actions'] = $this->transactionService->getAvailableRollbackActions($transaction);
        }

        // Add payouts
        if ($transaction->relationLoaded('payouts')) {
            $data['payouts'] = $transaction->payouts->map(fn ($payout) => [
                'id' => $payout->id,
                'provider' => $payout->provider,
                'recipient_type' => $payout->recipient_type,
                'recipient_value' => $payout->recipient_value,
                'recipient_wallet' => $payout->recipient_wallet,
                'amount' => $payout->amount,
                'currency' => $payout->currency,
                'status' => $payout->status,
                'payout_batch_id' => $payout->payout_batch_id,
                'payout_item_id' => $payout->payout_item_id,
                'transaction_id_external' => $payout->transaction_id_external,
                'error_code' => $payout->error_code,
                'error_message' => $payout->error_message,
                'tracking_url' => $payout->getTrackingUrl(),
                'processed_at' => $payout->processed_at?->toISOString(),
                'created_at' => $payout->created_at->toISOString(),
            ]);
        }

        // Add notes
        if ($transaction->relationLoaded('notes')) {
            $data['note_entries'] = $transaction->notes->map(fn ($note) => [
                'id' => $note->id,
                'content' => $note->content,
                'user' => $note->user ? [
                    'id' => $note->user->id,
                    'name' => $note->user->name,
                ] : null,
                'created_at' => $note->created_at->toISOString(),
                'updated_at' => $note->updated_at->toISOString(),
            ]);
        }

        return $data;
    }

    /**
     * Get available statuses.
     *
     * @return array<array<string, string>>
     */
    protected function getStatuses(): array
    {
        return collect(Transaction::getAvailableStatuses())
            ->map(fn ($label, $value) => ['value' => $value, 'label' => $label])
            ->values()
            ->all();
    }

    protected function getOnlineStatuses(): array
    {
        return collect(Transaction::getOnlineStatuses())
            ->map(fn ($label, $value) => ['value' => $value, 'label' => $label])
            ->values()
            ->all();
    }

    protected function getInHouseStatuses(): array
    {
        return collect(Transaction::getInHouseStatuses())
            ->map(fn ($label, $value) => ['value' => $value, 'label' => $label])
            ->values()
            ->all();
    }

    /**
     * Get available types.
     *
     * @return array<array<string, string>>
     */
    protected function getTypes(): array
    {
        return [
            ['value' => Transaction::TYPE_IN_STORE, 'label' => 'In-House'],
            ['value' => Transaction::TYPE_MAIL_IN, 'label' => 'Mail-In'],
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
            ['value' => Transaction::PAYMENT_CASH, 'label' => 'Cash'],
            ['value' => Transaction::PAYMENT_CHECK, 'label' => 'Check'],
            ['value' => Transaction::PAYMENT_STORE_CREDIT, 'label' => 'Store Credit'],
            ['value' => Transaction::PAYMENT_ACH, 'label' => 'ACH Transfer'],
            ['value' => Transaction::PAYMENT_PAYPAL, 'label' => 'PayPal'],
            ['value' => Transaction::PAYMENT_VENMO, 'label' => 'Venmo'],
            ['value' => Transaction::PAYMENT_WIRE_TRANSFER, 'label' => 'Wire Transfer'],
        ];
    }

    /**
     * Get available precious metals.
     *
     * @return array<array<string, string>>
     */
    protected function getPreciousMetals(): array
    {
        return [
            ['value' => TransactionItem::METAL_GOLD_10K, 'label' => '10K Gold'],
            ['value' => TransactionItem::METAL_GOLD_14K, 'label' => '14K Gold'],
            ['value' => TransactionItem::METAL_GOLD_18K, 'label' => '18K Gold'],
            ['value' => TransactionItem::METAL_GOLD_22K, 'label' => '22K Gold'],
            ['value' => TransactionItem::METAL_GOLD_24K, 'label' => '24K Gold'],
            ['value' => TransactionItem::METAL_SILVER, 'label' => 'Silver'],
            ['value' => TransactionItem::METAL_PLATINUM, 'label' => 'Platinum'],
            ['value' => TransactionItem::METAL_PALLADIUM, 'label' => 'Palladium'],
        ];
    }

    /**
     * Get available item conditions.
     *
     * @return array<array<string, string>>
     */
    protected function getConditions(): array
    {
        return [
            ['value' => TransactionItem::CONDITION_NEW, 'label' => 'New'],
            ['value' => TransactionItem::CONDITION_LIKE_NEW, 'label' => 'Like New'],
            ['value' => TransactionItem::CONDITION_USED, 'label' => 'Used'],
            ['value' => TransactionItem::CONDITION_DAMAGED, 'label' => 'Damaged'],
        ];
    }

    /**
     * Display the buy wizard form.
     */
    public function createWizard(): Response|RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return redirect()->route('dashboard')
                ->with('error', 'Please select a store first.');
        }

        // Get store users for the employee dropdown (only those authorized to create orders)
        $storeUsers = $store->storeUsers()
            ->with(['user', 'role'])
            ->get()
            ->filter(fn ($storeUser) => $storeUser->is_owner || $storeUser->hasPermission('orders.create'))
            ->map(fn ($storeUser) => [
                'id' => $storeUser->id,
                'name' => $storeUser->user?->name ?? $storeUser->name ?? 'Unknown',
            ])
            ->values();

        // Get the current user's store user ID
        $currentStoreUserId = auth()->user()?->currentStoreUser()?->id;

        // Get categories for the item form (tree traversal)
        $categories = Category::where('store_id', $store->id)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn ($category) => [
                'id' => $category->id,
                'name' => $category->name,
                'full_path' => $category->full_path,
                'parent_id' => $category->parent_id,
                'level' => $category->level,
                'template_id' => $category->template_id,
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

        return Inertia::render('transactions/CreateWizard', [
            'storeUsers' => $storeUsers,
            'currentStoreUserId' => $currentStoreUserId,
            'categories' => $categories,
            'preciousMetals' => $this->getPreciousMetals(),
            'conditions' => $this->getConditions(),
            'paymentMethods' => $this->getPaymentMethods(),
            'warehouses' => $warehouses,
            'defaultWarehouseId' => $defaultWarehouseId,
        ]);
    }

    /**
     * Store a transaction from the buy wizard.
     */
    public function storeFromWizard(CreateBuyTransactionRequest $request): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return redirect()->route('dashboard')
                ->with('error', 'Please select a store first.');
        }

        $data = $request->validated();
        $data['store_id'] = $store->id;

        $result = $this->transactionService->createFromWizard($data);
        $transaction = $result['transaction'];
        $payoutResults = $result['payout_results'];

        $message = 'Transaction created successfully.';
        if (! empty($payoutResults)) {
            $successCount = collect($payoutResults)->filter(fn ($r) => $r->success)->count();
            $failCount = count($payoutResults) - $successCount;

            if ($successCount > 0) {
                $message .= " {$successCount} PayPal payout(s) initiated.";
            }
            if ($failCount > 0) {
                $failedMessages = collect($payoutResults)
                    ->filter(fn ($r) => ! $r->success)
                    ->map(fn ($r) => $r->errorMessage)
                    ->implode('; ');
                $message .= " {$failCount} PayPal payout(s) failed: {$failedMessages}";
            }
        }

        return redirect()->route('web.transactions.show', $transaction)
            ->with('success', $message);
    }
}
