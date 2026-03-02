<?php

namespace App\Services\Channels;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppService
{
    /**
     * Send a message via WhatsApp Business Cloud API.
     *
     * @param  array{phone_number_id: string, access_token: string}  $credentials
     */
    public function sendMessage(array $credentials, string $recipientPhone, string $message): bool
    {
        $phoneNumberId = $credentials['phone_number_id'] ?? '';
        $accessToken = $credentials['access_token'] ?? '';

        if (! $phoneNumberId || ! $accessToken) {
            Log::warning('WhatsApp credentials missing', ['phone_number_id' => $phoneNumberId]);

            return false;
        }

        $response = Http::withToken($accessToken)
            ->post("https://graph.facebook.com/v21.0/{$phoneNumberId}/messages", [
                'messaging_product' => 'whatsapp',
                'to' => $recipientPhone,
                'type' => 'text',
                'text' => [
                    'body' => $message,
                ],
            ]);

        if ($response->failed()) {
            Log::error('WhatsApp send failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return false;
        }

        return true;
    }

    /**
     * Parse an incoming WhatsApp webhook payload.
     *
     * @return array{phone: string, message: string, message_id: string}|null
     */
    public function parseIncomingMessage(array $payload): ?array
    {
        $entry = $payload['entry'][0] ?? null;
        if (! $entry) {
            return null;
        }

        $changes = $entry['changes'][0] ?? null;
        if (! $changes || ($changes['field'] ?? '') !== 'messages') {
            return null;
        }

        $value = $changes['value'] ?? [];
        $messages = $value['messages'] ?? [];

        if (empty($messages)) {
            return null;
        }

        $msg = $messages[0];

        if (($msg['type'] ?? '') !== 'text') {
            return null;
        }

        return [
            'phone' => $msg['from'] ?? '',
            'message' => $msg['text']['body'] ?? '',
            'message_id' => $msg['id'] ?? '',
        ];
    }
}
