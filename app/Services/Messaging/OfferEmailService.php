<?php

namespace App\Services\Messaging;

use App\Models\NotificationChannel;
use App\Models\NotificationLog;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\TransactionOffer;
use App\Services\StoreContext;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * Service for sending offer emails with embedded images for online buys workflow.
 */
class OfferEmailService
{
    protected ?Store $store = null;

    public function __construct(
        protected StoreContext $storeContext,
    ) {}

    /**
     * Set the store context.
     */
    public function forStore(Store $store): self
    {
        $this->store = $store;

        return $this;
    }

    /**
     * Get the current store.
     */
    protected function getStore(): ?Store
    {
        return $this->store ?? $this->storeContext->getCurrentStore();
    }

    /**
     * Send an offer email with reasoning and images.
     *
     * @param  array<int>  $imageIds  IDs of images to include
     * @return array{success: bool, error: string|null}
     */
    public function sendOfferEmail(
        Transaction $transaction,
        TransactionOffer $offer,
        string $reasoning,
        array $imageIds = [],
        ?string $customSubject = null
    ): array {
        $store = $this->getStore() ?? $transaction->store;

        if (! $transaction->customer?->email) {
            return [
                'success' => false,
                'error' => 'Customer has no email address on file.',
            ];
        }

        try {
            // Update offer with reasoning and images
            $offer->update([
                'reasoning' => $reasoning,
                'images' => $imageIds,
            ]);

            // Get images for embedding
            $images = $this->getImagesForEmbedding($transaction, $imageIds);

            // Build email content
            $emailData = $this->buildEmailData($transaction, $offer, $reasoning, $images);

            // Send via mailable
            Mail::to($transaction->customer->email)
                ->send(new \App\Mail\OfferReasoningEmail(
                    $transaction,
                    $offer,
                    $emailData,
                    $customSubject
                ));

            // Log the notification
            $this->logNotification($transaction, $offer, $transaction->customer->email);

            return [
                'success' => true,
                'error' => null,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get images for embedding in email.
     *
     * @param  array<int>  $imageIds
     * @return array<array{id: int, url: string, cid: string}>
     */
    protected function getImagesForEmbedding(Transaction $transaction, array $imageIds): array
    {
        if (empty($imageIds)) {
            return [];
        }

        $images = [];

        // Get images from transaction items
        foreach ($transaction->items as $item) {
            foreach ($item->images as $image) {
                if (in_array($image->id, $imageIds)) {
                    $images[] = [
                        'id' => $image->id,
                        'url' => $image->url,
                        'path' => $image->path,
                        'cid' => 'image-'.$image->id,
                        'item_title' => $item->title,
                    ];
                }
            }
        }

        // Get images from transaction attachments
        foreach ($transaction->images as $image) {
            if (in_array($image->id, $imageIds)) {
                $images[] = [
                    'id' => $image->id,
                    'url' => $image->url,
                    'path' => $image->path,
                    'cid' => 'image-'.$image->id,
                    'item_title' => 'Attachment',
                ];
            }
        }

        return $images;
    }

    /**
     * Build email data for the template.
     *
     * @param  array<array<string, mixed>>  $images
     * @return array<string, mixed>
     */
    protected function buildEmailData(
        Transaction $transaction,
        TransactionOffer $offer,
        string $reasoning,
        array $images
    ): array {
        $store = $transaction->store;
        $portalUrl = config('app.portal_domain');

        return [
            'store_name' => $store->name,
            'store_logo' => $store->logo ? Storage::disk('do_spaces')->url($store->logo) : null,
            'customer_name' => $transaction->customer->first_name ?? 'Customer',
            'transaction_number' => $transaction->transaction_number,
            'offer_amount' => number_format((float) $offer->amount, 2),
            'tier' => $offer->tier,
            'tier_label' => $offer->tier_label,
            'reasoning' => $reasoning,
            'images' => $images,
            'portal_url' => "https://{$store->slug}.{$portalUrl}/p/transactions/{$transaction->id}",
            'expires_at' => $offer->expires_at?->format('F j, Y'),
            'item_count' => $transaction->items->count(),
            'items_summary' => $this->buildItemsSummary($transaction),
        ];
    }

    /**
     * Build a summary of transaction items.
     *
     * @return array<array{title: string, category: string|null}>
     */
    protected function buildItemsSummary(Transaction $transaction): array
    {
        return $transaction->items->map(fn ($item) => [
            'title' => $item->title,
            'category' => $item->category?->name,
        ])->toArray();
    }

    /**
     * Log the email notification.
     */
    protected function logNotification(Transaction $transaction, TransactionOffer $offer, string $email): void
    {
        NotificationLog::create([
            'store_id' => $transaction->store_id,
            'channel' => NotificationChannel::TYPE_EMAIL,
            'recipient' => $email,
            'content' => "Offer email sent for {$transaction->transaction_number} - Amount: \${$offer->amount}",
            'status' => NotificationLog::STATUS_SENT,
            'sent_at' => now(),
            'notifiable_type' => Transaction::class,
            'notifiable_id' => $transaction->id,
            'activity' => 'transactions.offer_email_sent',
            'metadata' => [
                'offer_id' => $offer->id,
                'offer_amount' => $offer->amount,
                'has_reasoning' => ! empty($offer->reasoning),
                'image_count' => count($offer->images ?? []),
            ],
        ]);
    }

    /**
     * Preview the email content without sending.
     *
     * @param  array<int>  $imageIds
     * @return array<string, mixed>
     */
    public function previewEmail(
        Transaction $transaction,
        TransactionOffer $offer,
        string $reasoning,
        array $imageIds = []
    ): array {
        $images = $this->getImagesForEmbedding($transaction, $imageIds);

        return $this->buildEmailData($transaction, $offer, $reasoning, $images);
    }

    /**
     * Get available images for a transaction (items + attachments).
     *
     * @return array<array{id: int, url: string, thumbnail_url: string, source: string, item_title: string|null}>
     */
    public function getAvailableImages(Transaction $transaction): array
    {
        $images = [];

        // Images from items
        foreach ($transaction->items as $item) {
            foreach ($item->images as $image) {
                $images[] = [
                    'id' => $image->id,
                    'url' => $image->url,
                    'thumbnail_url' => $image->thumbnail_url,
                    'source' => 'item',
                    'item_title' => $item->title,
                ];
            }
        }

        // Transaction attachments
        foreach ($transaction->images as $image) {
            $images[] = [
                'id' => $image->id,
                'url' => $image->url,
                'thumbnail_url' => $image->thumbnail_url,
                'source' => 'attachment',
                'item_title' => null,
            ];
        }

        return $images;
    }
}
