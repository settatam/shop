<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Requests\Portal\UpdatePayoutPreferenceRequest;
use App\Models\Activity;
use App\Models\ActivityLog;
use App\Models\Transaction;
use App\Models\TransactionOffer;
use App\Services\Offers\MultiOfferService;
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
            'items.images',
            'items.category',
            'offers.user',
            'latestOffer',
            'payouts',
            'statusHistories' => fn ($q) => $q->orderBy('created_at', 'asc'),
            'images', // Transaction-level attachments
        ]);

        $statuses = Transaction::getAvailableStatuses();

        // Get pending offers for multi-offer system
        $pendingOffers = $transaction->offers
            ->where('status', TransactionOffer::STATUS_PENDING)
            ->sortBy(fn ($o) => match ($o->tier) {
                'best' => 1,
                'better' => 2,
                'good' => 3,
                default => 4,
            })
            ->values()
            ->map(fn ($offer) => [
                'id' => $offer->id,
                'amount' => $offer->amount,
                'tier' => $offer->tier,
                'tier_label' => $offer->tier_label,
                'reasoning' => $offer->reasoning,
                'images' => $offer->images,
                'expires_at' => $offer->expires_at?->toISOString(),
                'expires_at_formatted' => $offer->expires_at?->format('F j, Y'),
                'is_expired' => $offer->isExpired(),
                'created_at' => $offer->created_at->toISOString(),
            ]);

        // Format transaction with images
        $formattedTransaction = $transaction->toArray();
        $formattedTransaction['pending_offers'] = $pendingOffers;
        $formattedTransaction['has_multi_offers'] = $pendingOffers->count() > 1;
        $formattedTransaction['items'] = $transaction->items->map(fn ($item) => [
            'id' => $item->id,
            'title' => $item->title,
            'description' => $item->description,
            'category' => $item->category ? [
                'id' => $item->category->id,
                'name' => $item->category->name,
            ] : null,
            'price' => $item->price,
            'buy_price' => $item->buy_price,
            'images' => $item->images->map(fn ($img) => [
                'id' => $img->id,
                'url' => $img->url,
                'thumbnail_url' => $img->thumbnail_url,
            ]),
        ]);

        // Get images from transaction attachments
        $formattedTransaction['attachments'] = $transaction->images->map(fn ($img) => [
            'id' => $img->id,
            'url' => $img->url,
            'thumbnail_url' => $img->thumbnail_url,
        ]);

        return Inertia::render('portal/TransactionShow', [
            'transaction' => $formattedTransaction,
            'statuses' => $statuses,
        ]);
    }

    /**
     * Accept a specific offer from multiple offers.
     */
    public function acceptSpecificOffer(
        Request $request,
        string $storeSlug,
        Transaction $transaction,
        MultiOfferService $multiOfferService
    ): RedirectResponse {
        $customer = auth('customer')->user();
        $storeId = app(StoreContext::class)->getCurrentStoreId();

        if ($transaction->customer_id !== $customer->id || $transaction->store_id !== $storeId) {
            abort(403);
        }

        $validated = $request->validate([
            'offer_id' => 'required|exists:transaction_offers,id',
        ]);

        $offer = TransactionOffer::findOrFail($validated['offer_id']);

        if ($offer->transaction_id !== $transaction->id) {
            return back()->withErrors(['offer' => 'Invalid offer.']);
        }

        $result = $multiOfferService->acceptOffer(
            $transaction,
            $offer,
            customerId: $customer->id
        );

        if (! $result['success']) {
            return back()->withErrors(['offer' => $result['error']]);
        }

        return back()->with('success', 'Offer accepted successfully.');
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
