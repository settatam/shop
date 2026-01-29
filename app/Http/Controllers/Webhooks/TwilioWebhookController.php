<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\NotificationChannel;
use App\Models\NotificationLog;
use App\Models\StoreIntegration;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class TwilioWebhookController extends Controller
{
    /**
     * Handle incoming SMS webhook from Twilio.
     */
    public function handleIncomingSms(Request $request): Response
    {
        $from = $request->input('From');
        $to = $request->input('To');
        $body = $request->input('Body');
        $messageSid = $request->input('MessageSid');

        Log::info('Twilio incoming SMS webhook', [
            'from' => $from,
            'to' => $to,
            'body' => $body,
            'message_sid' => $messageSid,
        ]);

        // Find the store integration by the receiving phone number
        $integration = $this->findIntegrationByPhoneNumber($to);

        if (! $integration) {
            Log::warning('Twilio webhook: No store integration found for phone number', ['to' => $to]);

            return $this->twilioResponse();
        }

        // Find the customer by the sending phone number
        $customer = Customer::where('store_id', $integration->store_id)
            ->where(function ($query) use ($from) {
                $query->where('phone_number', $from)
                    ->orWhere('phone_number', $this->normalizePhoneNumber($from));
            })
            ->first();

        if (! $customer) {
            Log::info('Twilio webhook: No customer found for phone number', [
                'from' => $from,
                'store_id' => $integration->store_id,
            ]);

            return $this->twilioResponse();
        }

        // Find the most recent online transaction for this customer
        $transaction = Transaction::where('store_id', $integration->store_id)
            ->where('customer_id', $customer->id)
            ->where(function ($query) {
                $query->where('type', Transaction::TYPE_MAIL_IN)
                    ->orWhere('source', Transaction::SOURCE_ONLINE);
            })
            ->whereNotIn('status', [
                Transaction::STATUS_COMPLETED,
                Transaction::STATUS_CANCELLED,
            ])
            ->orderBy('created_at', 'desc')
            ->first();

        // Log the incoming message
        NotificationLog::create([
            'store_id' => $integration->store_id,
            'channel' => NotificationChannel::TYPE_SMS,
            'direction' => NotificationLog::DIRECTION_INBOUND,
            'recipient' => $to,
            'recipient_type' => 'store',
            'notifiable_type' => $transaction ? Transaction::class : Customer::class,
            'notifiable_id' => $transaction?->id ?? $customer->id,
            'recipient_model_type' => Customer::class,
            'recipient_model_id' => $customer->id,
            'content' => $body,
            'status' => NotificationLog::STATUS_DELIVERED,
            'external_id' => $messageSid,
            'sent_at' => now(),
            'delivered_at' => now(),
            'data' => [
                'from' => $from,
                'to' => $to,
                'twilio_data' => $request->only([
                    'AccountSid',
                    'ApiVersion',
                    'NumMedia',
                    'NumSegments',
                    'SmsStatus',
                ]),
            ],
        ]);

        return $this->twilioResponse();
    }

    /**
     * Handle SMS status callback from Twilio.
     */
    public function handleStatusCallback(Request $request): Response
    {
        $messageSid = $request->input('MessageSid');
        $messageStatus = $request->input('MessageStatus');

        Log::info('Twilio status callback', [
            'message_sid' => $messageSid,
            'status' => $messageStatus,
        ]);

        // Find the notification log by external_id
        $log = NotificationLog::where('external_id', $messageSid)->first();

        if ($log) {
            $status = match ($messageStatus) {
                'delivered' => NotificationLog::STATUS_DELIVERED,
                'failed', 'undelivered' => NotificationLog::STATUS_FAILED,
                default => $log->status,
            };

            $updateData = ['status' => $status];

            if ($messageStatus === 'delivered') {
                $updateData['delivered_at'] = now();
            }

            if (in_array($messageStatus, ['failed', 'undelivered'])) {
                $updateData['error_message'] = $request->input('ErrorMessage') ?? "Message {$messageStatus}";
            }

            $log->update($updateData);
        }

        return $this->twilioResponse();
    }

    /**
     * Find store integration by Twilio phone number.
     */
    protected function findIntegrationByPhoneNumber(string $phoneNumber): ?StoreIntegration
    {
        $normalized = $this->normalizePhoneNumber($phoneNumber);

        return StoreIntegration::where('provider', StoreIntegration::PROVIDER_TWILIO)
            ->where('status', StoreIntegration::STATUS_ACTIVE)
            ->get()
            ->first(function ($integration) use ($phoneNumber, $normalized) {
                $integrationPhone = $integration->getPhoneNumber();

                return $integrationPhone === $phoneNumber
                    || $integrationPhone === $normalized
                    || $this->normalizePhoneNumber($integrationPhone) === $normalized;
            });
    }

    /**
     * Normalize phone number for comparison.
     */
    protected function normalizePhoneNumber(string $phone): string
    {
        // Remove all non-numeric characters except leading +
        $normalized = preg_replace('/[^\d+]/', '', $phone);

        // Add +1 if US number without country code
        if (strlen($normalized) === 10 && ! str_starts_with($normalized, '+')) {
            $normalized = '+1'.$normalized;
        }

        // Add + if missing from international number
        if (strlen($normalized) === 11 && ! str_starts_with($normalized, '+')) {
            $normalized = '+'.$normalized;
        }

        return $normalized;
    }

    /**
     * Return an empty TwiML response.
     */
    protected function twilioResponse(?string $message = null): Response
    {
        $twiml = '<?xml version="1.0" encoding="UTF-8"?><Response>';

        if ($message) {
            $twiml .= '<Message>'.htmlspecialchars($message).'</Message>';
        }

        $twiml .= '</Response>';

        return response($twiml, 200, [
            'Content-Type' => 'text/xml',
        ]);
    }
}
