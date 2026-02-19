<?php

namespace App\Services\Offers;

use App\Models\Activity;
use App\Models\ActivityLog;
use App\Models\Transaction;
use App\Models\TransactionOffer;
use App\Services\Messaging\OfferEmailService;
use App\Services\Messaging\SmsService;
use App\Services\Notifications\NotificationManager;
use App\Services\StoreContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Service for creating and managing multiple offer tiers for online buys workflow.
 * Supports good/better/best tier system with reasoning and expiration.
 */
class MultiOfferService
{
    public function __construct(
        protected StoreContext $storeContext,
        protected OfferEmailService $offerEmailService,
        protected SmsService $smsService,
    ) {}

    /**
     * Create multiple offers for a transaction.
     *
     * @param  array<array{amount: float, tier: string, reasoning: string|null, images: array|null, expires_at: string|null}>  $offers
     * @return array{success: bool, offers: Collection<int, TransactionOffer>, error: string|null}
     */
    public function createMultipleOffers(
        Transaction $transaction,
        array $offers,
        bool $sendNotification = false
    ): array {
        $store = $transaction->store;

        if (! $store->hasOnlineBuysWorkflow()) {
            return [
                'success' => false,
                'offers' => collect(),
                'error' => 'Multi-offer feature is only available for online buys workflow.',
            ];
        }

        if (! $transaction->isOnline()) {
            return [
                'success' => false,
                'offers' => collect(),
                'error' => 'Multi-offer feature is only available for online transactions.',
            ];
        }

        if (! $this->canSubmitOffers($transaction)) {
            return [
                'success' => false,
                'offers' => collect(),
                'error' => 'Cannot submit offers for this transaction in its current state.',
            ];
        }

        // Validate offers
        $validation = $this->validateOffers($offers);
        if (! $validation['valid']) {
            return [
                'success' => false,
                'offers' => collect(),
                'error' => $validation['error'],
            ];
        }

        return DB::transaction(function () use ($transaction, $offers, $sendNotification) {
            // Supersede any existing pending offers
            $this->supersedePendingOffers($transaction);

            // Create new offers
            $createdOffers = collect();

            foreach ($offers as $offerData) {
                $offer = TransactionOffer::create([
                    'transaction_id' => $transaction->id,
                    'user_id' => auth()->id(),
                    'amount' => $offerData['amount'],
                    'tier' => $offerData['tier'],
                    'reasoning' => $offerData['reasoning'] ?? null,
                    'images' => $offerData['images'] ?? null,
                    'status' => TransactionOffer::STATUS_PENDING,
                    'expires_at' => ! empty($offerData['expires_at'])
                        ? \Carbon\Carbon::parse($offerData['expires_at'])
                        : null,
                    'admin_notes' => $offerData['admin_notes'] ?? null,
                ]);

                $createdOffers->push($offer);
            }

            // Update transaction status
            $transaction->update([
                'status' => Transaction::STATUS_OFFER_GIVEN,
                'offer_given_at' => now(),
            ]);

            // Log activity
            $this->logMultiOfferCreation($transaction, $createdOffers);

            // Send notification if requested
            if ($sendNotification) {
                $this->sendOfferNotification($transaction, $createdOffers);
            }

            return [
                'success' => true,
                'offers' => $createdOffers,
                'error' => null,
            ];
        });
    }

    /**
     * Accept a specific offer from the tier options.
     *
     * @return array{success: bool, error: string|null}
     */
    public function acceptOffer(
        Transaction $transaction,
        TransactionOffer $offer,
        ?int $customerId = null,
        ?int $userId = null
    ): array {
        if ($offer->transaction_id !== $transaction->id) {
            return [
                'success' => false,
                'error' => 'Offer does not belong to this transaction.',
            ];
        }

        if (! $offer->isPending()) {
            return [
                'success' => false,
                'error' => 'This offer has already been responded to.',
            ];
        }

        if ($offer->isExpired()) {
            return [
                'success' => false,
                'error' => 'This offer has expired.',
            ];
        }

        return DB::transaction(function () use ($transaction, $offer, $customerId, $userId) {
            // Accept the selected offer
            $offer->accept($userId, $customerId);

            // Decline all other pending offers for this transaction
            $this->declineOtherOffers($transaction, $offer->id, $customerId, $userId);

            // Update transaction
            $transaction->update([
                'status' => Transaction::STATUS_OFFER_ACCEPTED,
                'final_offer' => $offer->amount,
                'offer_accepted_at' => now(),
            ]);

            // Log activity
            $responderName = $customerId ? 'Customer' : (auth()->user()?->name ?? 'Admin');
            ActivityLog::log(
                Activity::TRANSACTIONS_ACCEPT_OFFER,
                $transaction,
                null,
                [
                    'offer_id' => $offer->id,
                    'amount' => $offer->amount,
                    'tier' => $offer->tier,
                    'tier_label' => $offer->tier_label,
                ],
                "{$responderName} accepted the {$offer->tier_label} offer of \${$offer->amount}"
            );

            $transaction->recordStatusChange(
                Transaction::STATUS_OFFER_GIVEN,
                Transaction::STATUS_OFFER_ACCEPTED,
                "Accepted {$offer->tier_label} offer: \${$offer->amount}"
            );

            return [
                'success' => true,
                'error' => null,
            ];
        });
    }

    /**
     * Get all pending offers for a transaction.
     *
     * @return Collection<int, TransactionOffer>
     */
    public function getPendingOffers(Transaction $transaction): Collection
    {
        return $transaction->offers()
            ->where('status', TransactionOffer::STATUS_PENDING)
            ->get()
            ->sortBy(fn ($offer) => match ($offer->tier) {
                'best' => 1,
                'better' => 2,
                'good' => 3,
                default => 4,
            })
            ->values();
    }

    /**
     * Check if transaction can have offers submitted.
     */
    protected function canSubmitOffers(Transaction $transaction): bool
    {
        return in_array($transaction->status, [
            Transaction::STATUS_ITEMS_RECEIVED,
            Transaction::STATUS_ITEMS_REVIEWED,
            Transaction::STATUS_OFFER_DECLINED,
        ]);
    }

    /**
     * Validate offer data.
     *
     * @param  array<array<string, mixed>>  $offers
     * @return array{valid: bool, error: string|null}
     */
    protected function validateOffers(array $offers): array
    {
        if (empty($offers)) {
            return ['valid' => false, 'error' => 'At least one offer is required.'];
        }

        if (count($offers) > 3) {
            return ['valid' => false, 'error' => 'Maximum of 3 offer tiers allowed.'];
        }

        $tiers = [];
        foreach ($offers as $index => $offer) {
            if (empty($offer['amount']) || $offer['amount'] <= 0) {
                return ['valid' => false, 'error' => 'Offer '.($index + 1).' must have a valid amount.'];
            }

            if (empty($offer['tier'])) {
                return ['valid' => false, 'error' => 'Offer '.($index + 1).' must have a tier.'];
            }

            if (! in_array($offer['tier'], ['good', 'better', 'best'])) {
                return ['valid' => false, 'error' => "Invalid tier: {$offer['tier']}"];
            }

            if (in_array($offer['tier'], $tiers)) {
                return ['valid' => false, 'error' => "Duplicate tier: {$offer['tier']}"];
            }

            $tiers[] = $offer['tier'];
        }

        return ['valid' => true, 'error' => null];
    }

    /**
     * Supersede all pending offers for a transaction.
     */
    protected function supersedePendingOffers(Transaction $transaction): void
    {
        $transaction->offers()
            ->where('status', TransactionOffer::STATUS_PENDING)
            ->update(['status' => TransactionOffer::STATUS_SUPERSEDED]);
    }

    /**
     * Decline all offers except the accepted one.
     */
    protected function declineOtherOffers(
        Transaction $transaction,
        int $acceptedOfferId,
        ?int $customerId = null,
        ?int $userId = null
    ): void {
        $transaction->offers()
            ->where('id', '!=', $acceptedOfferId)
            ->where('status', TransactionOffer::STATUS_PENDING)
            ->each(function ($offer) use ($customerId, $userId) {
                $offer->update([
                    'status' => TransactionOffer::STATUS_DECLINED,
                    'responded_by_user_id' => $userId,
                    'responded_by_customer_id' => $customerId,
                    'responded_at' => now(),
                    'customer_response' => 'Declined (another offer was accepted)',
                ]);
            });
    }

    /**
     * Log multi-offer creation.
     *
     * @param  Collection<int, TransactionOffer>  $offers
     */
    protected function logMultiOfferCreation(Transaction $transaction, Collection $offers): void
    {
        $offerSummary = $offers->map(fn ($o) => "{$o->tier_label}: \${$o->amount}")->implode(', ');

        ActivityLog::log(
            Activity::TRANSACTIONS_SUBMIT_OFFER,
            $transaction,
            null,
            [
                'offer_count' => $offers->count(),
                'offers' => $offers->map(fn ($o) => [
                    'id' => $o->id,
                    'amount' => $o->amount,
                    'tier' => $o->tier,
                ])->toArray(),
            ],
            "Multiple offers sent: {$offerSummary}"
        );

        $transaction->recordStatusChange(
            $transaction->status,
            Transaction::STATUS_OFFER_GIVEN,
            "Multiple offers sent: {$offerSummary}"
        );
    }

    /**
     * Send notification about the offers.
     *
     * @param  Collection<int, TransactionOffer>  $offers
     */
    protected function sendOfferNotification(Transaction $transaction, Collection $offers): void
    {
        $store = $transaction->store;

        if (! $store) {
            return;
        }

        // Try to send SMS notification
        if ($transaction->customer?->phone_number && $this->smsService->isConfigured()) {
            $this->smsService->forStore($store);

            $variables = $this->smsService->buildVariablesFromTransaction($transaction);
            $bestOffer = $offers->firstWhere('tier', 'best') ?? $offers->first();
            $variables['offer_amount'] = number_format((float) $bestOffer->amount, 2);

            $template = $this->smsService->getTemplates()->firstWhere('id', 'offer_sent');
            if ($template) {
                $message = $this->smsService->renderTemplate($template['content'], $variables);
                $this->smsService->sendToTransaction($transaction, $message);
            }
        }

        // Also try email via notification manager
        try {
            $notificationManager = new NotificationManager($store);
            $notificationManager->trigger('transactions.offer_sent', [
                'transaction' => $transaction,
                'offers' => $offers,
                'customer' => $transaction->customer,
            ], $transaction);
        } catch (\Exception $e) {
            // Log but don't fail if notification fails
            \Illuminate\Support\Facades\Log::warning('Failed to send multi-offer notification', [
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
