<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Requests\Portal\UpdatePayoutPreferenceRequest;
use App\Models\Activity;
use App\Models\ActivityLog;
use App\Models\Transaction;
use App\Services\StoreContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PortalTransactionController extends Controller
{
    public function index(): Response
    {
        $customer = auth('customer')->user();
        $storeId = app(StoreContext::class)->getCurrentStoreId();

        $transactions = Transaction::withoutGlobalScopes()
            ->where('customer_id', $customer->id)
            ->where('store_id', $storeId)
            ->with(['latestOffer', 'items'])
            ->orderByDesc('created_at')
            ->paginate(15);

        return Inertia::render('portal/Transactions', [
            'transactions' => $transactions,
        ]);
    }

    public function show(string $storeSlug, Transaction $transaction): Response|RedirectResponse
    {
        $customer = auth('customer')->user();
        $storeId = app(StoreContext::class)->getCurrentStoreId();

        if ($transaction->customer_id !== $customer->id || $transaction->store_id !== $storeId) {
            abort(403);
        }

        $transaction->load([
            'items',
            'offers',
            'latestOffer',
            'payouts',
            'statusHistories' => fn ($q) => $q->orderBy('created_at', 'asc'),
        ]);

        $statuses = Transaction::getAvailableStatuses();

        return Inertia::render('portal/TransactionShow', [
            'transaction' => $transaction,
            'statuses' => $statuses,
        ]);
    }

    public function acceptOffer(string $storeSlug, Transaction $transaction): RedirectResponse
    {
        $customer = auth('customer')->user();
        $storeId = app(StoreContext::class)->getCurrentStoreId();

        if ($transaction->customer_id !== $customer->id || $transaction->store_id !== $storeId) {
            abort(403);
        }

        if (! $transaction->canAcceptOffer()) {
            return back()->withErrors(['offer' => 'This offer cannot be accepted.']);
        }

        $latestOffer = $transaction->latestOffer;

        if ($latestOffer && $latestOffer->isPending()) {
            $latestOffer->accept(customerId: $customer->id);
        }

        $transaction->acceptOffer();

        return back()->with('success', 'Offer accepted successfully.');
    }

    public function updatePayoutPreference(UpdatePayoutPreferenceRequest $request, string $storeSlug, Transaction $transaction): RedirectResponse
    {
        $customer = auth('customer')->user();
        $storeId = app(StoreContext::class)->getCurrentStoreId();

        if ($transaction->customer_id !== $customer->id || $transaction->store_id !== $storeId) {
            abort(403);
        }

        if (! in_array($transaction->status, [
            Transaction::STATUS_OFFER_ACCEPTED,
            Transaction::STATUS_PAYMENT_PENDING,
        ])) {
            return back()->withErrors(['status' => 'Payout preference cannot be updated for this transaction.']);
        }

        $payments = $request->validated()['payments'];

        $oldPaymentMethod = $transaction->payment_method;
        $oldPaymentDetails = $transaction->payment_details;

        $paymentDetails = collect($payments)->map(function (array $payment) {
            return [
                'method' => $payment['method'],
                'amount' => $payment['amount'],
                'details' => $payment['details'] ?? [],
            ];
        })->all();

        $primaryMethod = $payments[0]['method'];

        $transaction->update([
            'payment_method' => $primaryMethod,
            'payment_details' => $paymentDetails,
        ]);

        $methodLabels = collect($payments)->pluck('method')->map(fn (string $m) => ucfirst($m))->implode(', ');

        ActivityLog::log(
            Activity::TRANSACTIONS_UPDATE_PAYOUT_PREFERENCE,
            $transaction,
            $customer,
            [
                'old' => ['payment_method' => $oldPaymentMethod, 'payment_details' => $oldPaymentDetails],
                'new' => ['payment_method' => $primaryMethod, 'payment_details' => $paymentDetails],
            ],
            "Customer updated payout preference to {$methodLabels}"
        );

        $transaction->recordStatusChange(
            $transaction->status,
            $transaction->status,
            "Customer updated payout preference to {$methodLabels}"
        );

        return back()->with('success', 'Payout preference updated successfully.');
    }

    public function declineOffer(Request $request, string $storeSlug, Transaction $transaction): RedirectResponse
    {
        $customer = auth('customer')->user();
        $storeId = app(StoreContext::class)->getCurrentStoreId();

        if ($transaction->customer_id !== $customer->id || $transaction->store_id !== $storeId) {
            abort(403);
        }

        if (! $transaction->isOfferGiven()) {
            return back()->withErrors(['offer' => 'This offer cannot be declined.']);
        }

        $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $latestOffer = $transaction->latestOffer;

        if ($latestOffer && $latestOffer->isPending()) {
            $latestOffer->decline($request->reason, customerId: $customer->id);
        }

        $transaction->declineOffer($request->reason);

        return back()->with('success', 'Offer declined.');
    }
}
