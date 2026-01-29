<?php

namespace App\Services\Notifications\Channels;

use App\Models\NotificationChannel;
use App\Models\NotificationLog;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PushDriver extends AbstractNotificationDriver
{
    public function getType(): string
    {
        return NotificationChannel::TYPE_PUSH;
    }

    public function isConfigured(): bool
    {
        $provider = $this->getSetting('provider');

        return match ($provider) {
            'firebase' => ! empty($this->getSetting('firebase_server_key')),
            'onesignal' => ! empty($this->getSetting('onesignal_app_id'))
                && ! empty($this->getSetting('onesignal_api_key')),
            default => false,
        };
    }

    public function send(string $recipient, string $content, array $options = []): NotificationLog
    {
        $log = $this->createLog($recipient, $content, $options);

        if (! $this->isConfigured()) {
            $log->markAsFailed('Push channel not configured');

            return $log;
        }

        try {
            $provider = $this->getSetting('provider');
            $title = $options['title'] ?? $this->store->name;

            $externalId = match ($provider) {
                'firebase' => $this->sendViaFirebase($recipient, $title, $content, $options),
                'onesignal' => $this->sendViaOneSignal($recipient, $title, $content, $options),
                default => throw new \Exception("Unknown push provider: {$provider}"),
            };

            $log->markAsSent($externalId);
        } catch (\Exception $e) {
            Log::error('Failed to send push notification', [
                'recipient' => $recipient,
                'error' => $e->getMessage(),
                'store_id' => $this->store->id,
            ]);

            $log->markAsFailed($e->getMessage());
        }

        return $log;
    }

    protected function sendViaFirebase(string $deviceToken, string $title, string $body, array $options = []): ?string
    {
        $serverKey = $this->getSetting('firebase_server_key');

        $payload = [
            'to' => $deviceToken,
            'notification' => [
                'title' => $title,
                'body' => $body,
                'sound' => 'default',
            ],
            'data' => $options['data'] ?? [],
        ];

        $response = Http::withHeaders([
            'Authorization' => 'key='.$serverKey,
            'Content-Type' => 'application/json',
        ])->post('https://fcm.googleapis.com/fcm/send', $payload);

        if ($response->successful() && $response->json('success') === 1) {
            return $response->json('multicast_id');
        }

        throw new \Exception('Firebase error: '.($response->json('results.0.error') ?? 'Unknown error'));
    }

    protected function sendViaOneSignal(string $playerId, string $title, string $body, array $options = []): ?string
    {
        $appId = $this->getSetting('onesignal_app_id');
        $apiKey = $this->getSetting('onesignal_api_key');

        $payload = [
            'app_id' => $appId,
            'include_player_ids' => [$playerId],
            'headings' => ['en' => $title],
            'contents' => ['en' => $body],
            'data' => $options['data'] ?? [],
        ];

        $response = Http::withHeaders([
            'Authorization' => 'Basic '.$apiKey,
            'Content-Type' => 'application/json',
        ])->post('https://onesignal.com/api/v1/notifications', $payload);

        if ($response->successful() && ! empty($response->json('id'))) {
            return $response->json('id');
        }

        throw new \Exception('OneSignal error: '.json_encode($response->json('errors') ?? []));
    }
}
