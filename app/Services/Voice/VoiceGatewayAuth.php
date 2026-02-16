<?php

namespace App\Services\Voice;

use App\Models\User;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Facades\Log;

class VoiceGatewayAuth
{
    protected string $secret;

    protected string $algorithm = 'HS256';

    protected int $expirationMinutes = 60;

    public function __construct()
    {
        $this->secret = config('services.voice_gateway.secret', env('VOICE_GATEWAY_SECRET', ''));
    }

    /**
     * Generate a JWT token for the voice gateway.
     */
    public function generateToken(User $user, int $storeId): string
    {
        $payload = [
            'iss' => config('app.url'),
            'sub' => $user->id,
            'store_id' => $storeId,
            'user_email' => $user->email,
            'user_name' => $user->name,
            'iat' => time(),
            'exp' => time() + ($this->expirationMinutes * 60),
        ];

        return JWT::encode($payload, $this->secret, $this->algorithm);
    }

    /**
     * Validate a gateway token and extract payload.
     *
     * @return array{user_id: int, store_id: int, user_email: string, user_name: string}|null
     */
    public function validateToken(string $token): ?array
    {
        try {
            $decoded = JWT::decode($token, new Key($this->secret, $this->algorithm));

            return [
                'user_id' => $decoded->sub,
                'store_id' => $decoded->store_id,
                'user_email' => $decoded->user_email,
                'user_name' => $decoded->user_name,
            ];
        } catch (\Exception $e) {
            Log::warning('Voice gateway token validation failed', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Generate a short-lived session token for WebRTC signaling.
     */
    public function generateSessionToken(string $sessionId, int $userId, int $storeId): string
    {
        $payload = [
            'iss' => config('app.url'),
            'sub' => $userId,
            'store_id' => $storeId,
            'session_id' => $sessionId,
            'type' => 'session',
            'iat' => time(),
            'exp' => time() + 300, // 5 minutes for session setup
        ];

        return JWT::encode($payload, $this->secret, $this->algorithm);
    }

    /**
     * Validate a session token.
     *
     * @return array{user_id: int, store_id: int, session_id: string}|null
     */
    public function validateSessionToken(string $token): ?array
    {
        try {
            $decoded = JWT::decode($token, new Key($this->secret, $this->algorithm));

            if (($decoded->type ?? '') !== 'session') {
                return null;
            }

            return [
                'user_id' => $decoded->sub,
                'store_id' => $decoded->store_id,
                'session_id' => $decoded->session_id,
            ];
        } catch (\Exception $e) {
            Log::warning('Voice session token validation failed', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
