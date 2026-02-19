<?php

namespace App\Mail;

use App\Models\Transaction;
use App\Models\TransactionOffer;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class OfferReasoningEmail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     *
     * @param  array<string, mixed>  $emailData
     */
    public function __construct(
        public Transaction $transaction,
        public TransactionOffer $offer,
        public array $emailData,
        public ?string $customSubject = null,
    ) {}

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $subject = $this->customSubject
            ?? "Your Offer for Transaction {$this->transaction->transaction_number}";

        return new Envelope(
            subject: $subject,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.offer-reasoning',
            with: [
                'transaction' => $this->transaction,
                'offer' => $this->offer,
                'storeName' => $this->emailData['store_name'],
                'storeLogo' => $this->emailData['store_logo'],
                'customerName' => $this->emailData['customer_name'],
                'transactionNumber' => $this->emailData['transaction_number'],
                'offerAmount' => $this->emailData['offer_amount'],
                'tier' => $this->emailData['tier'],
                'tierLabel' => $this->emailData['tier_label'],
                'reasoning' => $this->emailData['reasoning'],
                'images' => $this->emailData['images'],
                'portalUrl' => $this->emailData['portal_url'],
                'expiresAt' => $this->emailData['expires_at'],
                'itemCount' => $this->emailData['item_count'],
                'itemsSummary' => $this->emailData['items_summary'],
            ],
        );
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build(): self
    {
        // Embed images if any
        foreach ($this->emailData['images'] ?? [] as $image) {
            if (! empty($image['path']) && Storage::exists($image['path'])) {
                $this->embedData(
                    Storage::get($image['path']),
                    basename($image['path']),
                    $image['cid'] ?? 'image-'.$image['id']
                );
            }
        }

        return $this;
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
