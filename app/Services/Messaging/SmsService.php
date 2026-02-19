<?php

namespace App\Services\Messaging;

use App\Models\NotificationChannel;
use App\Models\NotificationLog;
use App\Models\Store;
use App\Models\Transaction;
use App\Services\Notifications\NotificationManager;
use App\Services\StoreContext;
use Illuminate\Support\Collection;

/**
 * Dedicated SMS service for online buys workflow.
 * Provides template-based messaging, character counting, and delivery tracking.
 */
class SmsService
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
     * Send a custom SMS message.
     *
     * @param  array<string, mixed>  $options
     * @return array{success: bool, log: NotificationLog|null, error: string|null}
     */
    public function send(string $phoneNumber, string $message, array $options = []): array
    {
        $store = $this->getStore();

        if (! $store) {
            return [
                'success' => false,
                'log' => null,
                'error' => 'No store context available.',
            ];
        }

        if (! $this->isConfigured()) {
            return [
                'success' => false,
                'log' => null,
                'error' => 'SMS is not configured for this store.',
            ];
        }

        // Clean and validate phone number
        $phoneNumber = $this->normalizePhoneNumber($phoneNumber);

        if (! $this->isValidPhoneNumber($phoneNumber)) {
            return [
                'success' => false,
                'log' => null,
                'error' => 'Invalid phone number format.',
            ];
        }

        try {
            $notificationManager = new NotificationManager($store);

            $log = $notificationManager->send(
                NotificationChannel::TYPE_SMS,
                $phoneNumber,
                $message,
                $options
            );

            return [
                'success' => true,
                'log' => $log,
                'error' => null,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'log' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Send an SMS to a transaction's customer.
     *
     * @param  array<string, mixed>  $options
     * @return array{success: bool, log: NotificationLog|null, error: string|null}
     */
    public function sendToTransaction(Transaction $transaction, string $message, array $options = []): array
    {
        if (! $transaction->customer?->phone_number) {
            return [
                'success' => false,
                'log' => null,
                'error' => 'Customer has no phone number on file.',
            ];
        }

        $options = array_merge($options, [
            'notifiable_type' => Transaction::class,
            'notifiable_id' => $transaction->id,
            'activity' => 'transactions.sms_sent',
        ]);

        return $this->send(
            $transaction->customer->phone_number,
            $message,
            $options
        );
    }

    /**
     * Check if SMS is configured for the store.
     */
    public function isConfigured(): bool
    {
        $store = $this->getStore();

        if (! $store) {
            return false;
        }

        return NotificationChannel::where('store_id', $store->id)
            ->where('type', NotificationChannel::TYPE_SMS)
            ->where('is_enabled', true)
            ->exists();
    }

    /**
     * Get available SMS templates for the store.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function getTemplates(): Collection
    {
        $store = $this->getStore();

        if (! $store) {
            return collect();
        }

        // Return common templates for online buys workflow
        return collect([
            [
                'id' => 'offer_sent',
                'name' => 'Offer Sent',
                'content' => 'Hi {{customer_name}}, we\'ve reviewed your items and sent you an offer. View details: {{portal_link}}',
                'variables' => ['customer_name', 'portal_link', 'offer_amount'],
            ],
            [
                'id' => 'payment_sent',
                'name' => 'Payment Sent',
                'content' => 'Hi {{customer_name}}, your payment of ${{amount}} has been processed for transaction {{transaction_number}}.',
                'variables' => ['customer_name', 'amount', 'transaction_number'],
            ],
            [
                'id' => 'items_received',
                'name' => 'Items Received',
                'content' => 'Hi {{customer_name}}, we\'ve received your items for transaction {{transaction_number}}. We\'ll review them shortly.',
                'variables' => ['customer_name', 'transaction_number'],
            ],
            [
                'id' => 'reminder',
                'name' => 'Offer Reminder',
                'content' => 'Hi {{customer_name}}, just a reminder that your offer is waiting for your response. View: {{portal_link}}',
                'variables' => ['customer_name', 'portal_link'],
            ],
            [
                'id' => 'kit_shipped',
                'name' => 'Kit Shipped',
                'content' => 'Hi {{customer_name}}, your shipping kit is on the way! Track it: {{tracking_url}}',
                'variables' => ['customer_name', 'tracking_url', 'tracking_number'],
            ],
        ]);
    }

    /**
     * Render a template with variables.
     *
     * @param  array<string, string>  $variables
     */
    public function renderTemplate(string $template, array $variables): string
    {
        foreach ($variables as $key => $value) {
            $template = str_replace('{{'.$key.'}}', $value, $template);
        }

        return $template;
    }

    /**
     * Build template variables from a transaction.
     *
     * @return array<string, string>
     */
    public function buildVariablesFromTransaction(Transaction $transaction): array
    {
        $store = $transaction->store;
        $portalUrl = config('app.portal_domain');

        return [
            'customer_name' => $transaction->customer?->first_name ?? 'Customer',
            'customer_full_name' => $transaction->customer?->full_name ?? 'Customer',
            'transaction_number' => $transaction->transaction_number,
            'offer_amount' => number_format((float) $transaction->final_offer, 2),
            'amount' => number_format((float) $transaction->final_offer, 2),
            'portal_link' => "https://{$store->slug}.{$portalUrl}/p/transactions/{$transaction->id}",
            'tracking_number' => $transaction->outbound_tracking_number ?? '',
            'tracking_url' => $transaction->outboundLabel?->getTrackingUrl() ?? '',
            'return_tracking_number' => $transaction->return_tracking_number ?? '',
        ];
    }

    /**
     * Get character count and segment info for a message.
     *
     * @return array{characters: int, segments: int, max_per_segment: int, encoding: string}
     */
    public function getMessageInfo(string $message): array
    {
        $characters = mb_strlen($message);

        // Check if message needs UCS-2 encoding (non-GSM characters)
        $needsUcs2 = $this->containsNonGsmCharacters($message);

        if ($needsUcs2) {
            // UCS-2: 70 characters per segment (67 for multi-part)
            $maxPerSegment = $characters <= 70 ? 70 : 67;
            $segments = $characters <= 70 ? 1 : (int) ceil($characters / 67);
            $encoding = 'UCS-2';
        } else {
            // GSM-7: 160 characters per segment (153 for multi-part)
            $maxPerSegment = $characters <= 160 ? 160 : 153;
            $segments = $characters <= 160 ? 1 : (int) ceil($characters / 153);
            $encoding = 'GSM-7';
        }

        return [
            'characters' => $characters,
            'segments' => $segments,
            'max_per_segment' => $maxPerSegment,
            'encoding' => $encoding,
        ];
    }

    /**
     * Check if message contains non-GSM characters.
     */
    protected function containsNonGsmCharacters(string $message): bool
    {
        // GSM 7-bit basic character set
        $gsmChars = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞ ÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";

        for ($i = 0; $i < mb_strlen($message); $i++) {
            $char = mb_substr($message, $i, 1);
            if (mb_strpos($gsmChars, $char) === false && $char !== "\n" && $char !== "\r") {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalize a phone number.
     */
    protected function normalizePhoneNumber(string $phone): string
    {
        // Remove all non-numeric characters except +
        $phone = preg_replace('/[^0-9+]/', '', $phone);

        // Add +1 if US number without country code
        if (strlen($phone) === 10) {
            $phone = '+1'.$phone;
        } elseif (strlen($phone) === 11 && str_starts_with($phone, '1')) {
            $phone = '+'.$phone;
        }

        return $phone;
    }

    /**
     * Validate phone number format.
     */
    protected function isValidPhoneNumber(string $phone): bool
    {
        // Basic validation: starts with + and has 11-15 digits
        return preg_match('/^\+[0-9]{10,15}$/', $phone) === 1;
    }

    /**
     * Get SMS delivery history for a transaction.
     *
     * @return Collection<int, NotificationLog>
     */
    public function getHistoryForTransaction(Transaction $transaction): Collection
    {
        return NotificationLog::where('notifiable_type', Transaction::class)
            ->where('notifiable_id', $transaction->id)
            ->where('channel', NotificationChannel::TYPE_SMS)
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * Shorten a URL for SMS.
     * For now, returns the URL as-is. Can be extended to use a URL shortener service.
     */
    public function shortenUrl(string $url): string
    {
        // TODO: Integrate with a URL shortener service (bit.ly, etc.)
        return $url;
    }
}
