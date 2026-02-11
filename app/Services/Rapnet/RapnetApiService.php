<?php

namespace App\Services\Rapnet;

use App\Models\StoreIntegration;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RapnetApiService
{
    protected ?string $accessToken = null;

    protected ?Carbon $tokenExpiresAt = null;

    public function __construct(
        protected StoreIntegration $integration,
    ) {}

    /**
     * Get a valid access token, refreshing if necessary.
     */
    public function getAccessToken(): ?string
    {
        // Check if we have a valid cached token
        $credentials = $this->integration->credentials;
        $tokenExpiresAt = $credentials['token_expires_at'] ?? null;

        if (isset($credentials['access_token']) && $tokenExpiresAt) {
            if (Carbon::parse($tokenExpiresAt)->gt(Carbon::now())) {
                return $credentials['access_token'];
            }
        }

        // Fetch new token
        $authUrl = config('rapnet.auth_endpoint').'/api/get';

        try {
            $response = Http::asJson()->acceptJson()->post($authUrl, [
                'client_id' => $credentials['client_id'],
                'secret' => $credentials['client_secret'],
            ]);

            if ($response->successful()) {
                $tokens = $response->json();

                // Update stored credentials with new token
                $credentials['access_token'] = $tokens['access_token'];
                $credentials['token_expires_at'] = Carbon::now()
                    ->addSeconds($tokens['expires_in'] ?? 3600)
                    ->toIso8601String();

                $this->integration->update(['credentials' => $credentials]);

                return $tokens['access_token'];
            }

            Log::error('Rapnet auth failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        } catch (\Exception $e) {
            Log::error('Rapnet auth exception', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Fetch price list from Rapnet.
     *
     * @return array<int, array{shape: string, color: string, clarity: string, low_size: float, high_size: float, caratprice: float, date: string}>
     */
    public function getPriceList(string $shape = 'Round'): array
    {
        $token = $this->getAccessToken();
        if (! $token) {
            return [];
        }

        $endpoint = config('rapnet.endpoint').'/pricelist/api/Prices/list';
        $params = [
            'shape' => $shape,
            'csvnormalized' => 'true',
        ];

        try {
            $response = Http::withToken($token)
                ->get($endpoint, $params);

            if ($response->successful()) {
                return $response->json() ?? [];
            }

            Log::error('Rapnet price list fetch failed', [
                'shape' => $shape,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return [];
        } catch (\Exception $e) {
            Log::error('Rapnet price list exception', [
                'shape' => $shape,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Get round diamond price list.
     */
    public function getRoundPriceList(): array
    {
        return $this->getPriceList('Round');
    }

    /**
     * Get pear/fancy shape price list.
     */
    public function getPearPriceList(): array
    {
        return $this->getPriceList('Pear');
    }
}
