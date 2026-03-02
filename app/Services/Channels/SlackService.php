<?php

namespace App\Services\Channels;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SlackService
{
    /**
     * Send a message via Slack Web API.
     *
     * @param  array{bot_token: string}  $credentials
     * @param  string  $channelAndThread  Format: "channel_id:thread_ts" or just "channel_id"
     */
    public function sendMessage(array $credentials, string $channelAndThread, string $text): bool
    {
        $botToken = $credentials['bot_token'] ?? '';

        if (! $botToken) {
            Log::warning('Slack bot token missing');

            return false;
        }

        $parts = explode(':', $channelAndThread, 2);
        $channelId = $parts[0];
        $threadTs = $parts[1] ?? null;

        $payload = [
            'channel' => $channelId,
            'text' => $text,
        ];

        if ($threadTs) {
            $payload['thread_ts'] = $threadTs;
        }

        $response = Http::withToken($botToken)
            ->post('https://slack.com/api/chat.postMessage', $payload);

        if ($response->failed() || ! ($response->json('ok') ?? false)) {
            Log::error('Slack send failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return false;
        }

        return true;
    }

    /**
     * Parse an incoming Slack Events API message.
     *
     * @return array{channel: string, thread_ts: string|null, text: string, user: string}|null
     */
    public function parseIncomingMessage(array $payload): ?array
    {
        $event = $payload['event'] ?? null;

        if (! $event || ($event['type'] ?? '') !== 'message') {
            return null;
        }

        // Ignore bot messages
        if (isset($event['bot_id']) || ($event['subtype'] ?? '') === 'bot_message') {
            return null;
        }

        return [
            'channel' => $event['channel'] ?? '',
            'thread_ts' => $event['thread_ts'] ?? $event['ts'] ?? null,
            'text' => $event['text'] ?? '',
            'user' => $event['user'] ?? '',
        ];
    }
}
