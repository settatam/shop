<?php

namespace App\Services\Channels;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ZoomService
{
    /**
     * Create a Zoom meeting using Server-to-Server OAuth.
     *
     * @param  array{account_id: string, client_id: string, client_secret: string}  $credentials
     * @return array{join_url: string, meeting_id: int}|null
     */
    public function createMeeting(array $credentials, string $topic): ?array
    {
        $accessToken = $this->getAccessToken($credentials);

        if (! $accessToken) {
            return null;
        }

        $response = Http::withToken($accessToken)
            ->post('https://api.zoom.us/v2/users/me/meetings', [
                'topic' => $topic,
                'type' => 1, // Instant meeting
                'settings' => [
                    'join_before_host' => true,
                    'waiting_room' => false,
                ],
            ]);

        if ($response->failed()) {
            Log::error('Zoom meeting creation failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        }

        $data = $response->json();

        return [
            'join_url' => $data['join_url'] ?? '',
            'meeting_id' => $data['id'] ?? 0,
        ];
    }

    /**
     * Get an OAuth access token from Zoom.
     *
     * @param  array{account_id: string, client_id: string, client_secret: string}  $credentials
     */
    protected function getAccessToken(array $credentials): ?string
    {
        $accountId = $credentials['account_id'] ?? '';
        $clientId = $credentials['client_id'] ?? '';
        $clientSecret = $credentials['client_secret'] ?? '';

        if (! $accountId || ! $clientId || ! $clientSecret) {
            Log::warning('Zoom credentials missing');

            return null;
        }

        $response = Http::withBasicAuth($clientId, $clientSecret)
            ->asForm()
            ->post('https://zoom.us/oauth/token', [
                'grant_type' => 'account_credentials',
                'account_id' => $accountId,
            ]);

        if ($response->failed()) {
            Log::error('Zoom OAuth failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        }

        return $response->json('access_token');
    }
}
