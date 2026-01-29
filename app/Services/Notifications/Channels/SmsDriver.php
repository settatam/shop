<?php

namespace App\Services\Notifications\Channels;

use App\Models\NotificationChannel;
use App\Models\NotificationLog;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SmsDriver extends AbstractNotificationDriver
{
    public function getType(): string
    {
        return NotificationChannel::TYPE_SMS;
    }

    public function isConfigured(): bool
    {
        $provider = $this->getSetting('provider');

        return match ($provider) {
            'twilio' => ! empty($this->getSetting('twilio_sid'))
                && ! empty($this->getSetting('twilio_token'))
                && ! empty($this->getSetting('twilio_from')),
            'nexmo', 'vonage' => ! empty($this->getSetting('api_key'))
                && ! empty($this->getSetting('api_secret')),
            default => false,
        };
    }

    public function send(string $recipient, string $content, array $options = []): NotificationLog
    {
        $log = $this->createLog($recipient, $content, $options);

        if (! $this->isConfigured()) {
            $log->markAsFailed('SMS channel not configured');

            return $log;
        }

        try {
            $provider = $this->getSetting('provider');

            $externalId = match ($provider) {
                'twilio' => $this->sendViaTwilio($recipient, $content),
                'nexmo', 'vonage' => $this->sendViaNexmo($recipient, $content),
                default => throw new \Exception("Unknown SMS provider: {$provider}"),
            };

            $log->markAsSent($externalId);
        } catch (\Exception $e) {
            Log::error('Failed to send SMS notification', [
                'recipient' => $recipient,
                'error' => $e->getMessage(),
                'store_id' => $this->store->id,
            ]);

            $log->markAsFailed($e->getMessage());
        }

        return $log;
    }

    protected function sendViaTwilio(string $recipient, string $content): ?string
    {
        $sid = $this->getSetting('twilio_sid');
        $token = $this->getSetting('twilio_token');
        $from = $this->getSetting('twilio_from');

        $response = Http::withBasicAuth($sid, $token)
            ->asForm()
            ->post("https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json", [
                'To' => $recipient,
                'From' => $from,
                'Body' => $content,
            ]);

        if ($response->successful()) {
            return $response->json('sid');
        }

        throw new \Exception('Twilio error: '.$response->json('message', 'Unknown error'));
    }

    protected function sendViaNexmo(string $recipient, string $content): ?string
    {
        $apiKey = $this->getSetting('api_key');
        $apiSecret = $this->getSetting('api_secret');
        $from = $this->getSetting('from') ?? $this->store->name;

        $response = Http::post('https://rest.nexmo.com/sms/json', [
            'api_key' => $apiKey,
            'api_secret' => $apiSecret,
            'to' => $recipient,
            'from' => $from,
            'text' => $content,
        ]);

        if ($response->successful()) {
            $data = $response->json();
            if (isset($data['messages'][0]['status']) && $data['messages'][0]['status'] === '0') {
                return $data['messages'][0]['message-id'] ?? null;
            }

            throw new \Exception('Nexmo error: '.($data['messages'][0]['error-text'] ?? 'Unknown error'));
        }

        throw new \Exception('Nexmo API error');
    }
}
