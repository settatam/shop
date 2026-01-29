<?php

namespace App\Services\Transactions;

use App\Models\Activity;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StoreUser;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\TransactionOffer;
use App\Services\Notifications\NotificationManager;
use App\Services\Payments\PayoutResult;
use App\Services\Payments\PayPalPayoutsService;
use App\Services\StoreContext;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class TransactionService
{
    public function __construct(
        protected StoreContext $storeContext,
        protected PayPalPayoutsService $payPalPayoutsService,
    ) {}

    public function create(array $data): Transaction
    {
        return DB::transaction(function () use ($data) {
            $warehouseId = $data['warehouse_id'] ?? $this->storeContext->getDefaultWarehouseId();

            $transaction = Transaction::create([
                'store_id' => $this->storeContext->getCurrentStore()?->id ?? $data['store_id'],
                'warehouse_id' => $warehouseId,
                'customer_id' => $data['customer_id'] ?? null,
                'user_id' => $data['user_id'] ?? auth()->id(),
                'type' => $data['type'] ?? Transaction::TYPE_IN_HOUSE,
                'preliminary_offer' => $data['preliminary_offer'] ?? null,
                'estimated_value' => $data['estimated_value'] ?? null,
                'bin_location' => $data['bin_location'] ?? null,
                'customer_notes' => $data['customer_notes'] ?? null,
            ]);

            if (! empty($data['items'])) {
                foreach ($data['items'] as $itemData) {
                    $this->addItem($transaction, $itemData);
                }
            }

            // Log activity
            ActivityLog::log(
                Activity::TRANSACTIONS_CREATE,
                $transaction,
                null,
                [
                    'transaction_number' => $transaction->transaction_number,
                    'type' => $transaction->type,
                ],
                "Transaction {$transaction->transaction_number} created"
            );

            return $transaction->fresh(['customer', 'user', 'items']);
        });
    }

    /**
     * Create a transaction from the buy wizard form data.
     *
     * @param  array<string, mixed>  $data
     * @return array{transaction: Transaction, payout_results: array<PayoutResult>}
     */
    public function createFromWizard(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $storeId = $this->storeContext->getCurrentStore()?->id ?? $data['store_id'];

            // Get or create customer
            $customerId = $data['customer_id'] ?? null;
            if (! $customerId && ! empty($data['customer'])) {
                $customer = Customer::create([
                    'store_id' => $storeId,
                    'first_name' => $data['customer']['first_name'],
                    'last_name' => $data['customer']['last_name'],
                    'company_name' => $data['customer']['company_name'] ?? null,
                    'email' => $data['customer']['email'] ?? null,
                    'phone_number' => $data['customer']['phone_number'] ?? null,
                    'address' => $data['customer']['address'] ?? null,
                    'address2' => $data['customer']['address2'] ?? null,
                    'city' => $data['customer']['city'] ?? null,
                    'state_id' => $data['customer']['state_id'] ?? null,
                    'zip' => $data['customer']['zip'] ?? null,
                    'country_id' => $data['customer']['country_id'] ?? null,
                ]);
                $customerId = $customer->id;
            }

            // Get the user ID from store_user
            $storeUser = StoreUser::find($data['store_user_id']);
            $userId = $storeUser?->user_id ?? auth()->id();

            // Calculate totals from items
            $totalBuyPrice = collect($data['items'])->sum('buy_price');
            $totalEstimatedValue = collect($data['items'])->sum('price');

            // Process payments array
            $payments = $data['payments'] ?? [];
            $paymentMethods = collect($payments)->pluck('method')->unique()->values()->all();

            // Determine payment_method field value
            $paymentMethod = count($paymentMethods) === 1
                ? $paymentMethods[0]
                : 'multiple';

            // Determine warehouse_id
            $warehouseId = $data['warehouse_id'] ?? $this->storeContext->getDefaultWarehouseId();

            // Create transaction - for in-house buys, payment is processed immediately
            $now = now();
            $transaction = Transaction::create([
                'store_id' => $storeId,
                'warehouse_id' => $warehouseId,
                'customer_id' => $customerId,
                'user_id' => $userId,
                'type' => Transaction::TYPE_IN_HOUSE,
                'status' => Transaction::STATUS_PAYMENT_PROCESSED,
                'preliminary_offer' => $totalBuyPrice,
                'final_offer' => $totalBuyPrice,
                'estimated_value' => $totalEstimatedValue ?: null,
                'payment_method' => $paymentMethod,
                'payment_details' => ['payments' => $payments],
                'customer_notes' => $data['customer_notes'] ?? null,
                'internal_notes' => $data['internal_notes'] ?? null,
                'offer_given_at' => $now,
                'offer_accepted_at' => $now,
                'payment_processed_at' => $now,
            ]);

            // Add items
            foreach ($data['items'] as $itemData) {
                $this->addItem($transaction, $itemData);
            }

            // Handle PayPal payouts for any PayPal payments
            $payoutResults = [];
            foreach ($payments as $index => $payment) {
                if ($payment['method'] === Transaction::PAYMENT_PAYPAL) {
                    $paypalEmail = $payment['details']['paypal_email'] ?? null;
                    if ($paypalEmail && $this->payPalPayoutsService->isConfigured()) {
                        $payoutResult = $this->payPalPayoutsService->sendPayout(
                            recipientEmail: $paypalEmail,
                            amount: (float) $payment['amount'],
                            currency: 'USD',
                            note: "Payment for transaction {$transaction->transaction_number}"
                        );

                        $payoutResults[] = $payoutResult;

                        if ($payoutResult->success) {
                            // Update the payment details with payout info
                            $updatedPayments = $transaction->payment_details['payments'];
                            $updatedPayments[$index]['payout'] = [
                                'batch_id' => $payoutResult->batchId,
                                'item_id' => $payoutResult->payoutItemId,
                                'status' => $payoutResult->status,
                            ];
                            $transaction->update([
                                'payment_details' => ['payments' => $updatedPayments],
                            ]);
                        }
                    }
                }
            }

            // Log activity
            ActivityLog::log(
                Activity::TRANSACTIONS_CREATE,
                $transaction,
                null,
                [
                    'transaction_number' => $transaction->transaction_number,
                    'type' => $transaction->type,
                    'final_offer' => $transaction->final_offer,
                    'payment_method' => $transaction->payment_method,
                    'customer_name' => $transaction->customer?->full_name,
                    'item_count' => count($data['items'] ?? []),
                ],
                "Buy transaction {$transaction->transaction_number} created"
            );

            return [
                'transaction' => $transaction->fresh(['customer', 'user', 'items']),
                'payout_results' => $payoutResults,
            ];
        });
    }

    public function addItem(Transaction $transaction, array $data): TransactionItem
    {
        $item = $transaction->items()->create([
            'category_id' => $data['category_id'] ?? null,
            'sku' => $data['sku'] ?? null,
            'title' => $data['title'] ?? null,
            'description' => $data['description'] ?? null,
            'price' => $data['price'] ?? null,
            'buy_price' => $data['buy_price'] ?? null,
            'dwt' => $data['dwt'] ?? null,
            'precious_metal' => $data['precious_metal'] ?? null,
            'condition' => $data['condition'] ?? null,
        ]);

        // Log activity
        ActivityLog::log(
            activity: 'item_added',
            subject: $transaction,
            properties: [
                'item_id' => $item->id,
                'title' => $item->title,
                'buy_price' => $item->buy_price,
            ],
            description: 'Added item: '.($item->title ?? 'Untitled'),
        );

        return $item;
    }

    public function updateItem(TransactionItem $item, array $data): TransactionItem
    {
        // Capture old values for change tracking
        $oldValues = [
            'title' => $item->title,
            'description' => $item->description,
            'category_id' => $item->category_id,
            'sku' => $item->sku,
            'price' => $item->price,
            'buy_price' => $item->buy_price,
            'dwt' => $item->dwt,
            'precious_metal' => $item->precious_metal,
            'condition' => $item->condition,
        ];

        $item->update([
            'category_id' => $data['category_id'] ?? $item->category_id,
            'sku' => $data['sku'] ?? $item->sku,
            'title' => $data['title'] ?? $item->title,
            'description' => $data['description'] ?? $item->description,
            'price' => $data['price'] ?? $item->price,
            'buy_price' => $data['buy_price'] ?? $item->buy_price,
            'dwt' => $data['dwt'] ?? $item->dwt,
            'precious_metal' => $data['precious_metal'] ?? $item->precious_metal,
            'condition' => $data['condition'] ?? $item->condition,
        ]);

        // Build changes array - track what actually changed
        $changes = [];
        $fieldLabels = [
            'title' => 'Title',
            'description' => 'Description',
            'category_id' => 'Category',
            'sku' => 'SKU',
            'price' => 'Estimated Value',
            'buy_price' => 'Buy Price',
            'dwt' => 'DWT',
            'precious_metal' => 'Metal Type',
            'condition' => 'Condition',
        ];

        foreach ($oldValues as $field => $oldValue) {
            $newValue = $item->$field;
            // Compare values (handle null and type differences)
            if ($this->valuesAreDifferent($oldValue, $newValue)) {
                $changes[$field] = [
                    'label' => $fieldLabels[$field] ?? $field,
                    'old' => $oldValue,
                    'new' => $newValue,
                ];
            }
        }

        // Build human-readable description of changes
        $changeDescriptions = [];
        foreach ($changes as $field => $change) {
            if (in_array($field, ['price', 'buy_price'])) {
                $oldFormatted = $change['old'] !== null ? '$'.number_format((float) $change['old'], 2) : 'empty';
                $newFormatted = $change['new'] !== null ? '$'.number_format((float) $change['new'], 2) : 'empty';
                $changeDescriptions[] = "{$change['label']}: {$oldFormatted} → {$newFormatted}";
            } elseif ($field === 'dwt') {
                $oldFormatted = $change['old'] !== null ? $change['old'].' dwt' : 'empty';
                $newFormatted = $change['new'] !== null ? $change['new'].' dwt' : 'empty';
                $changeDescriptions[] = "{$change['label']}: {$oldFormatted} → {$newFormatted}";
            } elseif ($field === 'description') {
                // Don't include full description text, just note it changed
                $changeDescriptions[] = "{$change['label']} updated";
            } elseif ($field === 'category_id') {
                $changeDescriptions[] = 'Category changed';
            } else {
                $oldFormatted = $change['old'] ?? 'empty';
                $newFormatted = $change['new'] ?? 'empty';
                $changeDescriptions[] = "{$change['label']}: {$oldFormatted} → {$newFormatted}";
            }
        }

        $description = 'Updated item: '.($item->title ?? $oldValues['title'] ?? 'Untitled');
        if (! empty($changeDescriptions)) {
            $description .= ' ('.implode(', ', $changeDescriptions).')';
        }

        // Log activity with detailed changes
        ActivityLog::log(
            activity: 'item_updated',
            subject: $item->transaction,
            properties: [
                'item_id' => $item->id,
                'title' => $item->title,
                'changes' => $changes,
            ],
            description: $description,
        );

        return $item->fresh();
    }

    /**
     * Compare two values to determine if they are different.
     */
    private function valuesAreDifferent(mixed $oldValue, mixed $newValue): bool
    {
        // Handle null comparisons
        if ($oldValue === null && $newValue === null) {
            return false;
        }

        // Handle numeric comparisons (float precision)
        if (is_numeric($oldValue) && is_numeric($newValue)) {
            return abs((float) $oldValue - (float) $newValue) > 0.001;
        }

        // Handle string comparisons (trim whitespace)
        if (is_string($oldValue) && is_string($newValue)) {
            return trim($oldValue) !== trim($newValue);
        }

        return $oldValue !== $newValue;
    }

    public function removeItem(TransactionItem $item): bool
    {
        if ($item->is_added_to_inventory) {
            throw new InvalidArgumentException('Cannot remove item that has been added to inventory.');
        }

        $transaction = $item->transaction;
        $title = $item->title;

        $deleted = $item->delete();

        if ($deleted) {
            // Log activity
            ActivityLog::log(
                activity: 'item_removed',
                subject: $transaction,
                properties: [
                    'title' => $title,
                ],
                description: 'Removed item: '.($title ?? 'Untitled'),
            );
        }

        return $deleted;
    }

    public function reviewItem(TransactionItem $item, int $userId): TransactionItem
    {
        $item->markAsReviewed($userId);

        // Log activity
        ActivityLog::log(
            activity: 'item_reviewed',
            subject: $item->transaction,
            properties: [
                'item_id' => $item->id,
                'title' => $item->title,
                'reviewed_at' => $item->reviewed_at->toISOString(),
            ],
            description: 'Reviewed item: '.($item->title ?? 'Untitled'),
        );

        return $item->fresh();
    }

    public function submitOffer(Transaction $transaction, float $offer): Transaction
    {
        if (! $transaction->canSubmitOffer()) {
            throw new InvalidArgumentException('Transaction is not in a state that allows submitting an offer.');
        }

        return $transaction->submitOffer($offer);
    }

    public function acceptOffer(Transaction $transaction): Transaction
    {
        if (! $transaction->canAcceptOffer()) {
            throw new InvalidArgumentException('Transaction is not in a state that allows accepting an offer.');
        }

        return $transaction->acceptOffer();
    }

    public function declineOffer(Transaction $transaction, ?string $reason = null): Transaction
    {
        if (! $transaction->canAcceptOffer()) {
            throw new InvalidArgumentException('Transaction is not in a state that allows declining an offer.');
        }

        return $transaction->declineOffer($reason);
    }

    /**
     * Create a new offer for a transaction, superseding any pending offers.
     *
     * @param  bool  $sendNotification  Whether to send email/SMS notification to customer
     */
    public function createOffer(
        Transaction $transaction,
        float $amount,
        ?string $notes = null,
        bool $sendNotification = false
    ): TransactionOffer {
        if (! $transaction->canSubmitOffer()) {
            throw new InvalidArgumentException('Transaction is not in a state that allows submitting an offer.');
        }

        $offer = DB::transaction(function () use ($transaction, $amount, $notes) {
            // Mark any existing pending offers as superseded
            $transaction->offers()
                ->where('status', TransactionOffer::STATUS_PENDING)
                ->update(['status' => TransactionOffer::STATUS_SUPERSEDED]);

            // Create new offer
            $offer = $transaction->offers()->create([
                'user_id' => auth()->id(),
                'amount' => $amount,
                'status' => TransactionOffer::STATUS_PENDING,
                'admin_notes' => $notes,
            ]);

            // Update transaction status and final offer
            $transaction->update([
                'status' => Transaction::STATUS_OFFER_GIVEN,
                'final_offer' => $amount,
                'offer_given_at' => now(),
            ]);

            // Log activity
            ActivityLog::log(
                Activity::TRANSACTIONS_SUBMIT_OFFER,
                $transaction,
                null,
                [
                    'offer_id' => $offer->id,
                    'amount' => $amount,
                    'transaction_number' => $transaction->transaction_number,
                ],
                "Offer of \${$amount} sent for transaction {$transaction->transaction_number}"
            );

            return $offer;
        });

        // Send notification outside the transaction to avoid delays
        if ($sendNotification && $transaction->customer) {
            $this->sendOfferNotification($transaction, $offer);
        }

        return $offer;
    }

    /**
     * Send offer notification to customer via email and/or SMS.
     */
    protected function sendOfferNotification(Transaction $transaction, TransactionOffer $offer): void
    {
        try {
            $transaction->load(['customer', 'store']);

            if (! $transaction->store) {
                return;
            }

            $notificationManager = new NotificationManager($transaction->store);
            $notificationManager->trigger('transactions.offer_sent', [
                'transaction' => $transaction,
                'offer' => $offer,
                'customer' => $transaction->customer,
                'amount' => number_format($offer->amount, 2),
                'transaction_number' => $transaction->transaction_number,
            ], $transaction);
        } catch (\Exception $e) {
            // Log but don't fail the offer creation
            report($e);
        }
    }

    /**
     * Accept a specific offer.
     *
     * @param  int|null  $customerId  Customer ID if accepted by customer (via portal)
     */
    public function acceptOfferWithTracking(Transaction $transaction, TransactionOffer $offer, ?int $customerId = null): Transaction
    {
        if (! $offer->isPending()) {
            throw new InvalidArgumentException('Only pending offers can be accepted.');
        }

        if ($offer->transaction_id !== $transaction->id) {
            throw new InvalidArgumentException('Offer does not belong to this transaction.');
        }

        return DB::transaction(function () use ($transaction, $offer, $customerId) {
            // Determine responder: customer or current admin user
            $userId = $customerId ? null : auth()->id();
            $offer->accept($userId, $customerId);

            $transaction->update([
                'status' => Transaction::STATUS_OFFER_ACCEPTED,
                'final_offer' => $offer->amount,
                'offer_accepted_at' => now(),
            ]);

            // Determine who accepted for logging
            $acceptedBy = $customerId ? 'customer' : 'admin';

            // Log activity
            ActivityLog::log(
                Activity::TRANSACTIONS_ACCEPT_OFFER,
                $transaction,
                null,
                [
                    'offer_id' => $offer->id,
                    'amount' => $offer->amount,
                    'transaction_number' => $transaction->transaction_number,
                    'accepted_by' => $acceptedBy,
                    'customer_id' => $customerId,
                ],
                "Offer of \${$offer->amount} accepted by {$acceptedBy} for transaction {$transaction->transaction_number}"
            );

            return $transaction->fresh();
        });
    }

    /**
     * Decline a specific offer with customer response.
     *
     * @param  int|null  $customerId  Customer ID if declined by customer (via portal)
     */
    public function declineOfferWithTracking(Transaction $transaction, TransactionOffer $offer, ?string $customerResponse = null, ?int $customerId = null): Transaction
    {
        if (! $offer->isPending()) {
            throw new InvalidArgumentException('Only pending offers can be declined.');
        }

        if ($offer->transaction_id !== $transaction->id) {
            throw new InvalidArgumentException('Offer does not belong to this transaction.');
        }

        return DB::transaction(function () use ($transaction, $offer, $customerResponse, $customerId) {
            // Determine responder: customer or current admin user
            $userId = $customerId ? null : auth()->id();
            $offer->decline($customerResponse, $userId, $customerId);

            $transaction->update([
                'status' => Transaction::STATUS_OFFER_DECLINED,
            ]);

            // Determine who declined for logging
            $declinedBy = $customerId ? 'customer' : 'admin';

            // Log activity
            ActivityLog::log(
                Activity::TRANSACTIONS_DECLINE_OFFER,
                $transaction,
                null,
                [
                    'offer_id' => $offer->id,
                    'amount' => $offer->amount,
                    'customer_response' => $customerResponse,
                    'transaction_number' => $transaction->transaction_number,
                    'declined_by' => $declinedBy,
                    'customer_id' => $customerId,
                ],
                "Offer of \${$offer->amount} declined by {$declinedBy} for transaction {$transaction->transaction_number}"
            );

            return $transaction->fresh();
        });
    }

    /**
     * Reject a kit (admin rejects items without making an offer).
     */
    public function rejectKit(Transaction $transaction, ?string $reason = null): Transaction
    {
        if (! in_array($transaction->status, [
            Transaction::STATUS_ITEMS_RECEIVED,
            Transaction::STATUS_ITEMS_REVIEWED,
        ])) {
            throw new InvalidArgumentException('Kit can only be rejected after items are received.');
        }

        $transaction->update([
            'status' => Transaction::STATUS_KIT_REQUEST_REJECTED,
            'internal_notes' => $reason ?? $transaction->internal_notes,
        ]);

        // Log activity
        ActivityLog::log(
            Activity::TRANSACTIONS_STATUS_CHANGE,
            $transaction,
            null,
            [
                'reason' => $reason,
                'transaction_number' => $transaction->transaction_number,
            ],
            "Kit rejected for transaction {$transaction->transaction_number}"
        );

        return $transaction->fresh();
    }

    /**
     * Initiate return of items to customer.
     */
    public function initiateReturn(Transaction $transaction): Transaction
    {
        if (! in_array($transaction->status, [
            Transaction::STATUS_OFFER_DECLINED,
            Transaction::STATUS_KIT_REQUEST_REJECTED,
        ])) {
            throw new InvalidArgumentException('Return can only be initiated after offer is declined or kit is rejected.');
        }

        $transaction->update([
            'status' => Transaction::STATUS_RETURN_REQUESTED,
        ]);

        // Log activity
        ActivityLog::log(
            Activity::TRANSACTIONS_STATUS_CHANGE,
            $transaction,
            null,
            [
                'transaction_number' => $transaction->transaction_number,
            ],
            "Return initiated for transaction {$transaction->transaction_number}"
        );

        return $transaction->fresh();
    }

    public function processPayment(Transaction $transaction, string $method): Transaction
    {
        if (! $transaction->canProcessPayment()) {
            throw new InvalidArgumentException('Transaction is not in a state that allows processing payment.');
        }

        $validMethods = [
            Transaction::PAYMENT_CASH,
            Transaction::PAYMENT_CHECK,
            Transaction::PAYMENT_STORE_CREDIT,
            Transaction::PAYMENT_ACH,
            Transaction::PAYMENT_PAYPAL,
            Transaction::PAYMENT_VENMO,
            Transaction::PAYMENT_WIRE_TRANSFER,
        ];

        if (! in_array($method, $validMethods)) {
            throw new InvalidArgumentException("Invalid payment method: {$method}");
        }

        return $transaction->processPayment($method);
    }

    /**
     * Process payment with additional payment details.
     *
     * @param  array<string, mixed>|null  $paymentDetails
     */
    public function processPaymentWithDetails(Transaction $transaction, string $method, ?array $paymentDetails = null): Transaction
    {
        if (! $transaction->canProcessPayment()) {
            throw new InvalidArgumentException('Transaction is not in a state that allows processing payment.');
        }

        $validMethods = [
            Transaction::PAYMENT_CASH,
            Transaction::PAYMENT_CHECK,
            Transaction::PAYMENT_STORE_CREDIT,
            Transaction::PAYMENT_ACH,
            Transaction::PAYMENT_PAYPAL,
            Transaction::PAYMENT_VENMO,
            Transaction::PAYMENT_WIRE_TRANSFER,
        ];

        if (! in_array($method, $validMethods)) {
            throw new InvalidArgumentException("Invalid payment method: {$method}");
        }

        $transaction->update([
            'status' => Transaction::STATUS_PAYMENT_PROCESSED,
            'payment_method' => $method,
            'payment_details' => $paymentDetails,
            'payment_processed_at' => now(),
        ]);

        // Log activity
        ActivityLog::log(
            Activity::TRANSACTIONS_STATUS_CHANGE,
            $transaction,
            null,
            [
                'payment_method' => $method,
                'amount' => $transaction->final_offer,
                'transaction_number' => $transaction->transaction_number,
            ],
            "Payment of \${$transaction->final_offer} processed via {$method} for transaction {$transaction->transaction_number}"
        );

        return $transaction->fresh();
    }

    /**
     * Process multiple payments for a transaction.
     *
     * @param  array<int, array{method: string, amount: float, details: array<string, mixed>|null}>  $payments
     */
    public function processMultiplePayments(Transaction $transaction, array $payments): Transaction
    {
        if (! $transaction->canProcessPayment()) {
            throw new InvalidArgumentException('Transaction is not in a state that allows processing payment.');
        }

        $validMethods = [
            Transaction::PAYMENT_CASH,
            Transaction::PAYMENT_CHECK,
            Transaction::PAYMENT_STORE_CREDIT,
            Transaction::PAYMENT_ACH,
            Transaction::PAYMENT_PAYPAL,
            Transaction::PAYMENT_VENMO,
            Transaction::PAYMENT_WIRE_TRANSFER,
        ];

        // Validate all payment methods
        foreach ($payments as $payment) {
            if (! in_array($payment['method'], $validMethods)) {
                throw new InvalidArgumentException("Invalid payment method: {$payment['method']}");
            }
        }

        // If single payment, use simple format
        if (count($payments) === 1) {
            $payment = $payments[0];

            $transaction->update([
                'status' => Transaction::STATUS_PAYMENT_PROCESSED,
                'payment_method' => $payment['method'],
                'payment_details' => $payment['details'],
                'payment_processed_at' => now(),
            ]);
        } else {
            // Multiple payments - store as array
            $primaryMethod = collect($payments)->sortByDesc('amount')->first()['method'];

            $transaction->update([
                'status' => Transaction::STATUS_PAYMENT_PROCESSED,
                'payment_method' => $primaryMethod, // Primary method is the one with highest amount
                'payment_details' => [
                    'multiple_payments' => true,
                    'payments' => $payments,
                ],
                'payment_processed_at' => now(),
            ]);
        }

        // Log activity
        $totalAmount = collect($payments)->sum('amount');
        $methodSummary = collect($payments)
            ->groupBy('method')
            ->map(fn ($group) => '$'.number_format($group->sum('amount'), 2))
            ->map(fn ($amount, $method) => ucfirst(str_replace('_', ' ', $method)).": {$amount}")
            ->implode(', ');

        ActivityLog::log(
            Activity::TRANSACTIONS_STATUS_CHANGE,
            $transaction,
            null,
            [
                'payment_methods' => collect($payments)->pluck('method')->unique()->toArray(),
                'total_amount' => $totalAmount,
                'transaction_number' => $transaction->transaction_number,
            ],
            "Payment of \${$totalAmount} processed for transaction {$transaction->transaction_number} ({$methodSummary})"
        );

        return $transaction->fresh();
    }

    public function cancel(Transaction $transaction): Transaction
    {
        if (! $transaction->canBeCancelled()) {
            throw new InvalidArgumentException('Transaction cannot be cancelled in its current state.');
        }

        return $transaction->cancel();
    }

    /**
     * Reset transaction back to items reviewed state.
     * This allows making a new offer after the previous flow was interrupted.
     */
    public function resetToItemsReviewed(Transaction $transaction): Transaction
    {
        $allowedStatuses = [
            Transaction::STATUS_OFFER_GIVEN,
            Transaction::STATUS_OFFER_DECLINED,
            Transaction::STATUS_KIT_REQUEST_REJECTED,
            Transaction::STATUS_RETURN_REQUESTED,
        ];

        if (! in_array($transaction->status, $allowedStatuses)) {
            throw new InvalidArgumentException('Transaction cannot be reset from current state.');
        }

        return DB::transaction(function () use ($transaction) {
            // Cancel any pending offers
            $transaction->offers()
                ->where('status', TransactionOffer::STATUS_PENDING)
                ->update(['status' => TransactionOffer::STATUS_SUPERSEDED]);

            $previousStatus = $transaction->status;
            $transaction->update([
                'status' => Transaction::STATUS_ITEMS_REVIEWED,
            ]);

            // Log activity
            ActivityLog::log(
                Activity::TRANSACTIONS_STATUS_CHANGE,
                $transaction,
                null,
                [
                    'previous_status' => $previousStatus,
                    'new_status' => Transaction::STATUS_ITEMS_REVIEWED,
                    'transaction_number' => $transaction->transaction_number,
                ],
                "Transaction {$transaction->transaction_number} reset to items reviewed"
            );

            return $transaction->fresh();
        });
    }

    /**
     * Reopen an offer - go back to offer given state from accepted/declined.
     */
    public function reopenOffer(Transaction $transaction): Transaction
    {
        $allowedStatuses = [
            Transaction::STATUS_OFFER_ACCEPTED,
            Transaction::STATUS_OFFER_DECLINED,
        ];

        if (! in_array($transaction->status, $allowedStatuses)) {
            throw new InvalidArgumentException('Cannot reopen offer from current state.');
        }

        // Check for a valid offer to reopen
        $latestOffer = $transaction->offers()
            ->whereIn('status', [TransactionOffer::STATUS_ACCEPTED, TransactionOffer::STATUS_DECLINED])
            ->latest()
            ->first();

        if (! $latestOffer) {
            throw new InvalidArgumentException('No offer found to reopen.');
        }

        return DB::transaction(function () use ($transaction, $latestOffer) {
            // Reset the offer back to pending
            $latestOffer->update([
                'status' => TransactionOffer::STATUS_PENDING,
                'responded_at' => null,
                'customer_response' => null,
            ]);

            $previousStatus = $transaction->status;
            $transaction->update([
                'status' => Transaction::STATUS_OFFER_GIVEN,
                'offer_accepted_at' => null,
            ]);

            // Log activity
            ActivityLog::log(
                Activity::TRANSACTIONS_STATUS_CHANGE,
                $transaction,
                null,
                [
                    'previous_status' => $previousStatus,
                    'new_status' => Transaction::STATUS_OFFER_GIVEN,
                    'offer_id' => $latestOffer->id,
                    'transaction_number' => $transaction->transaction_number,
                ],
                "Offer reopened for transaction {$transaction->transaction_number}"
            );

            return $transaction->fresh();
        });
    }

    /**
     * Cancel a pending return and go back to the previous state.
     */
    public function cancelReturn(Transaction $transaction): Transaction
    {
        if ($transaction->status !== Transaction::STATUS_RETURN_REQUESTED) {
            throw new InvalidArgumentException('No pending return to cancel.');
        }

        return DB::transaction(function () use ($transaction) {
            // Determine what state to go back to
            // Check if there was a rejected kit or declined offer
            $latestOffer = $transaction->offers()
                ->whereIn('status', [TransactionOffer::STATUS_DECLINED, TransactionOffer::STATUS_SUPERSEDED])
                ->latest()
                ->first();

            $newStatus = $latestOffer
                ? Transaction::STATUS_OFFER_DECLINED
                : Transaction::STATUS_ITEMS_REVIEWED;

            $transaction->update([
                'status' => $newStatus,
            ]);

            // Log activity
            ActivityLog::log(
                Activity::TRANSACTIONS_STATUS_CHANGE,
                $transaction,
                null,
                [
                    'previous_status' => Transaction::STATUS_RETURN_REQUESTED,
                    'new_status' => $newStatus,
                    'transaction_number' => $transaction->transaction_number,
                ],
                "Return cancelled for transaction {$transaction->transaction_number}"
            );

            return $transaction->fresh();
        });
    }

    /**
     * Undo payment and go back to offer accepted state.
     * Note: This should be used carefully as it may have financial implications.
     */
    public function undoPayment(Transaction $transaction): Transaction
    {
        if ($transaction->status !== Transaction::STATUS_PAYMENT_PROCESSED) {
            throw new InvalidArgumentException('Transaction has not been paid.');
        }

        return DB::transaction(function () use ($transaction) {
            $previousPaymentMethod = $transaction->payment_method;
            $previousPaymentDetails = $transaction->payment_details;

            $transaction->update([
                'status' => Transaction::STATUS_OFFER_ACCEPTED,
                'payment_method' => null,
                'payment_details' => null,
                'payment_processed_at' => null,
            ]);

            // Log activity
            ActivityLog::log(
                Activity::TRANSACTIONS_STATUS_CHANGE,
                $transaction,
                null,
                [
                    'previous_status' => Transaction::STATUS_PAYMENT_PROCESSED,
                    'new_status' => Transaction::STATUS_OFFER_ACCEPTED,
                    'previous_payment_method' => $previousPaymentMethod,
                    'transaction_number' => $transaction->transaction_number,
                ],
                "Payment undone for transaction {$transaction->transaction_number}"
            );

            return $transaction->fresh();
        });
    }

    /**
     * Get the valid rollback actions for a transaction's current status.
     *
     * @return array<string, string>
     */
    public function getAvailableRollbackActions(Transaction $transaction): array
    {
        $actions = [];

        switch ($transaction->status) {
            case Transaction::STATUS_OFFER_GIVEN:
                $actions['reset_to_items_reviewed'] = 'Retract Offer';
                break;

            case Transaction::STATUS_OFFER_ACCEPTED:
                $actions['reopen_offer'] = 'Reopen Offer';
                break;

            case Transaction::STATUS_OFFER_DECLINED:
                $actions['reopen_offer'] = 'Reopen Offer';
                $actions['reset_to_items_reviewed'] = 'Reset to Items Reviewed';
                break;

            case Transaction::STATUS_KIT_REQUEST_REJECTED:
                $actions['reset_to_items_reviewed'] = 'Reset to Items Reviewed';
                break;

            case Transaction::STATUS_RETURN_REQUESTED:
                $actions['cancel_return'] = 'Cancel Return';
                $actions['reset_to_items_reviewed'] = 'Reset to Items Reviewed';
                break;

            case Transaction::STATUS_PAYMENT_PROCESSED:
                $actions['undo_payment'] = 'Undo Payment';
                break;
        }

        return $actions;
    }

    public function moveItemToInventory(TransactionItem $item, array $productData = []): Product
    {
        if (! $item->canBeAddedToInventory()) {
            throw new InvalidArgumentException('Item cannot be added to inventory.');
        }

        return DB::transaction(function () use ($item, $productData) {
            $store = $item->transaction->store;
            $transaction = $item->transaction;
            $title = $productData['title'] ?? $item->title ?? 'Product';
            $handle = \Illuminate\Support\Str::slug($title).'-'.uniqid();

            // Determine template from category
            $templateId = null;
            if ($item->category_id && $item->category) {
                $templateId = $item->category->template_id;
            }

            $product = Product::create([
                'store_id' => $store->id,
                'title' => $title,
                'description' => $productData['description'] ?? $item->description,
                'category_id' => $productData['category_id'] ?? $item->category_id,
                'template_id' => $templateId,
                'handle' => $handle,
                'is_published' => false, // Draft so user can review
            ]);

            $variant = ProductVariant::create([
                'product_id' => $product->id,
                'sku' => $productData['sku'] ?? $item->sku,
                'price' => $productData['price'] ?? $item->price,
                'cost' => $item->buy_price,
            ]);

            // Transfer images from transaction item to product
            $item->load('images');
            foreach ($item->images as $image) {
                $image->update([
                    'imageable_type' => Product::class,
                    'imageable_id' => $product->id,
                ]);
            }

            // Create inventory record
            $warehouseId = $transaction->warehouse_id
                ?? $this->storeContext->getDefaultWarehouseId();

            if ($warehouseId) {
                $inventory = \App\Models\Inventory::getOrCreate($store->id, $variant->id, $warehouseId);
                $inventory->receive(1, (float) $item->buy_price);
            }

            $item->markAsAddedToInventory($product->id);

            return $product->fresh(['variants', 'images']);
        });
    }

    public function calculatePreliminaryOffer(Transaction $transaction): float
    {
        return (float) $transaction->items()->sum('buy_price');
    }

    public function calculateTotals(Transaction $transaction): array
    {
        $items = $transaction->items;

        return [
            'item_count' => $items->count(),
            'total_dwt' => (float) $items->sum('dwt'),
            'total_value' => (float) $items->sum('price'),
            'total_buy_price' => (float) $items->sum('buy_price'),
        ];
    }
}
