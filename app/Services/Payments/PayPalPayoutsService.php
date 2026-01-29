<?php

namespace App\Services\Payments;

use App\Models\Store;
use App\Models\StoreIntegration;
use App\Models\Transaction;
use App\Models\TransactionPayout;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PayPalPayoutsService
{
    protected string $baseUrl;

    protected string $clientId;

    protected string $clientSecret;

    protected ?StoreIntegration $integration = null;

    public function __construct(?StoreIntegration $integration = null)
    {
        if ($integration) {
            $this->integration = $integration;
            $this->baseUrl = $integration->getApiBaseUrl();
            $this->clientId = $integration->getClientId() ?? '';
            $this->clientSecret = $integration->getClientSecret() ?? '';
        } else {
            $mode = config('services.paypal.mode', 'sandbox');
            $this->baseUrl = $mode === 'live'
                ? 'https://api-m.paypal.com'
                : 'https://api-m.sandbox.paypal.com';
            $this->clientId = config('services.paypal.client_id') ?? '';
            $this->clientSecret = config('services.paypal.client_secret') ?? '';
        }
    }

    /**
     * Create a service instance for a specific store.
     */
    public static function forStore(Store $store): self
    {
        $integration = $store->integrations()
            ->where('provider', StoreIntegration::PROVIDER_PAYPAL)
            ->where('status', StoreIntegration::STATUS_ACTIVE)
            ->first();

        return new self($integration);
    }

    /**
     * Get an OAuth 2.0 access token from PayPal.
     */
    public function getAccessToken(): ?string
    {
        $cacheKey = $this->integration
            ? "paypal_access_token_{$this->integration->id}"
            : 'paypal_access_token_global';

        return Cache::remember($cacheKey, 3000, function () {
            try {
                $response = Http::withBasicAuth($this->clientId, $this->clientSecret)
                    ->asForm()
                    ->post("{$this->baseUrl}/v1/oauth2/token", [
                        'grant_type' => 'client_credentials',
                    ]);

                if ($response->successful()) {
                    $this->integration?->recordUsage();

                    return $response->json('access_token');
                }

                Log::error('PayPal OAuth failed', [
                    'status' => $response->status(),
                    'body' => $response->json(),
                ]);

                return null;
            } catch (RequestException $e) {
                report($e);

                return null;
            }
        });
    }

    /**
     * Send a payout to a PayPal or Venmo account.
     */
    public function sendPayout(
        string $recipientValue,
        float $amount,
        string $currency = 'USD',
        ?string $note = null,
        ?string $senderBatchId = null,
        string $recipientType = 'EMAIL',
        ?string $wallet = null
    ): PayoutResult {
        $accessToken = $this->getAccessToken();

        if (! $accessToken) {
            return PayoutResult::failure('Failed to obtain PayPal access token', 'AUTH_ERROR');
        }

        $senderBatchId = $senderBatchId ?? 'Payout_'.now()->format('YmdHis').'_'.Str::random(6);
        $senderItemId = 'Item_'.Str::random(10);

        $item = [
            'recipient_type' => $recipientType,
            'amount' => [
                'value' => number_format($amount, 2, '.', ''),
                'currency' => $currency,
            ],
            'receiver' => $recipientValue,
            'note' => $note ?? 'Thank you for your business.',
            'sender_item_id' => $senderItemId,
        ];

        if ($wallet === TransactionPayout::WALLET_VENMO) {
            $item['recipient_wallet'] = 'VENMO';
        }

        $payload = [
            'sender_batch_header' => [
                'sender_batch_id' => $senderBatchId,
                'email_subject' => 'You have a payout!',
                'email_message' => $note ?? 'You have received a payout.',
            ],
            'items' => [$item],
        ];

        try {
            $response = Http::withToken($accessToken)
                ->asJson()
                ->post("{$this->baseUrl}/v1/payments/payouts", $payload);

            if ($response->successful()) {
                $data = $response->json();
                $batchId = $data['batch_header']['payout_batch_id'] ?? null;
                $batchStatus = $data['batch_header']['batch_status'] ?? 'PENDING';

                return PayoutResult::success(
                    batchId: $batchId,
                    payoutItemId: $senderItemId,
                    amount: $amount,
                    status: strtolower($batchStatus),
                    gatewayResponse: $data,
                );
            }

            $error = $response->json();
            $errorMessage = $error['message'] ?? $error['error_description'] ?? 'Unknown PayPal error';
            $errorCode = $error['name'] ?? $error['error'] ?? 'UNKNOWN_ERROR';

            Log::error('PayPal payout failed', [
                'error' => $error,
                'recipient' => $recipientValue,
                'amount' => $amount,
            ]);

            return PayoutResult::failure($errorMessage, $errorCode, $error);
        } catch (RequestException $e) {
            report($e);

            return PayoutResult::failure(
                'PayPal request failed: '.$e->getMessage(),
                'REQUEST_EXCEPTION',
                ['exception' => $e->getMessage()]
            );
        }
    }

    /**
     * Send payout for a transaction payment.
     */
    public function sendTransactionPayout(
        Transaction $transaction,
        string $recipientValue,
        float $amount,
        string $wallet = TransactionPayout::WALLET_PAYPAL,
        ?string $note = null
    ): TransactionPayout {
        $payout = TransactionPayout::create([
            'store_id' => $transaction->store_id,
            'transaction_id' => $transaction->id,
            'user_id' => auth()->id(),
            'provider' => TransactionPayout::PROVIDER_PAYPAL,
            'recipient_type' => TransactionPayout::RECIPIENT_TYPE_EMAIL,
            'recipient_value' => $recipientValue,
            'recipient_wallet' => $wallet,
            'amount' => $amount,
            'currency' => 'USD',
            'status' => TransactionPayout::STATUS_PROCESSING,
        ]);

        $result = $this->sendPayout(
            recipientValue: $recipientValue,
            amount: $amount,
            currency: 'USD',
            note: $note ?? "Payment for transaction #{$transaction->transaction_number}",
            wallet: $wallet
        );

        if ($result->success) {
            $payout->update([
                'payout_batch_id' => $result->batchId,
                'payout_item_id' => $result->payoutItemId,
                'status' => TransactionPayout::STATUS_PROCESSING,
                'api_response' => $result->gatewayResponse,
            ]);
        } else {
            $payout->markAsFailed(
                $result->errorCode ?? 'UNKNOWN',
                $result->errorMessage ?? 'Unknown error',
                $result->gatewayResponse
            );
        }

        return $payout;
    }

    /**
     * Get the status of a payout batch.
     *
     * @return array<string, mixed>|null
     */
    public function getPayoutStatus(string $batchId): ?array
    {
        $accessToken = $this->getAccessToken();

        if (! $accessToken) {
            return null;
        }

        try {
            $response = Http::withToken($accessToken)
                ->get("{$this->baseUrl}/v1/payments/payouts/{$batchId}");

            if ($response->successful()) {
                return $response->json();
            }

            return null;
        } catch (RequestException $e) {
            report($e);

            return null;
        }
    }

    /**
     * Refresh the status of a transaction payout from PayPal.
     */
    public function refreshPayoutStatus(TransactionPayout $payout): TransactionPayout
    {
        if (! $payout->payout_batch_id) {
            return $payout;
        }

        $status = $this->getPayoutStatus($payout->payout_batch_id);

        if (! $status) {
            return $payout;
        }

        $items = $status['items'] ?? [];
        foreach ($items as $item) {
            if (($item['payout_item']['sender_item_id'] ?? null) === $payout->payout_item_id ||
                ($item['payout_item_id'] ?? null) === $payout->payout_item_id) {
                $payout->updateFromPayPalStatus($item);
                break;
            }
        }

        return $payout;
    }

    /**
     * Check if PayPal credentials are configured.
     */
    public function isConfigured(): bool
    {
        return ! empty($this->clientId) && ! empty($this->clientSecret);
    }

    /**
     * Get the store integration if available.
     */
    public function getIntegration(): ?StoreIntegration
    {
        return $this->integration;
    }
}
