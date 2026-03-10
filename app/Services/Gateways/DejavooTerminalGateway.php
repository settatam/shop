<?php

namespace App\Services\Gateways;

use App\Contracts\TerminalGatewayInterface;
use App\Models\PaymentTerminal;
use App\Services\Gateways\Results\CancelResult;
use App\Services\Gateways\Results\CheckoutResult;
use App\Services\Gateways\Results\CheckoutStatus;
use App\Services\Gateways\Results\PairResult;
use App\Services\Gateways\Results\PaymentResult;
use App\Services\Gateways\Results\RefundResult;
use App\Services\Gateways\Results\VoidResult;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class DejavooTerminalGateway implements TerminalGatewayInterface
{
    protected string $baseUrl;

    protected string $apiVersion;

    public function __construct()
    {
        $this->baseUrl = config('payment-gateways.dejavoo.base_url') ?: 'https://api.spinpos.net';
        $this->apiVersion = config('payment-gateways.dejavoo.api_version') ?: 'v2';
    }

    /**
     * Get the full API URL for an endpoint.
     */
    protected function getApiUrl(string $endpoint = ''): string
    {
        return rtrim($this->baseUrl, '/').'/'.$this->apiVersion.($endpoint ? '/'.ltrim($endpoint, '/') : '');
    }

    /**
     * Get auth key from terminal settings.
     */
    protected function getAuthKey(PaymentTerminal $terminal): ?string
    {
        return $terminal->getSetting('auth_key');
    }

    /**
     * Get register ID from terminal settings, falling back to device_id (Terminal ID).
     */
    protected function getRegisterId(PaymentTerminal $terminal): ?string
    {
        return $terminal->getSetting('register_id') ?: $terminal->device_id;
    }

    /**
     * Get the Terminal ID (TPN).
     */
    protected function getTerminalId(PaymentTerminal $terminal): ?string
    {
        return $terminal->device_id;
    }

    /**
     * Check if terminal has required credentials configured.
     * Required: auth_key and device_id (Terminal ID)
     */
    protected function isTerminalConfigured(PaymentTerminal $terminal): bool
    {
        return $this->baseUrl
            && $this->getAuthKey($terminal)
            && $this->getTerminalId($terminal);
    }

    public function charge(float $amount, array $paymentMethod, array $options = []): PaymentResult
    {
        throw new RuntimeException('Dejavoo charge not implemented. Use terminal checkout for in-person payments.');
    }

    public function refund(string $paymentId, float $amount): RefundResult
    {
        // TODO: Implement Dejavoo refund via API
        throw new RuntimeException('Dejavoo refund not yet implemented.');
    }

    public function void(string $paymentId): VoidResult
    {
        // TODO: Implement Dejavoo void via API
        throw new RuntimeException('Dejavoo void not yet implemented.');
    }

    public function getPayment(string $paymentId): ?array
    {
        return null;
    }

    /**
     * Send a sale request to the Dejavoo terminal via the SPIn API.
     * This is a blocking call — it waits until the customer completes the transaction.
     */
    public function createCheckout(PaymentTerminal $terminal, float $amount, array $options = []): CheckoutResult
    {
        if ($terminal->gateway !== PaymentTerminal::GATEWAY_DEJAVOO) {
            return CheckoutResult::failure('Terminal is not a Dejavoo terminal');
        }

        if (! $terminal->isActive()) {
            return CheckoutResult::failure('Terminal is not active');
        }

        if (! $this->isTerminalConfigured($terminal)) {
            return CheckoutResult::failure('Terminal credentials not configured. Please set the Terminal ID and Auth Key in terminal settings.');
        }

        $authKey = $this->getAuthKey($terminal);
        $terminalId = $this->getTerminalId($terminal);
        $registerId = $this->getRegisterId($terminal);
        $timeout = $options['timeout'] ?? config('payment-gateways.terminal.default_timeout', 300);

        $payload = [
            'Amount' => number_format($amount, 2, '.', ''),
            'TipAmount' => number_format($options['tip_amount'] ?? 0, 2, '.', ''),
            'ExternalAmount' => '',
            'PaymentType' => $options['payment_type'] ?? 'Credit',
            'ReferenceId' => ($options['reference'] ?? '').'_'.time(),
            'CaptureSignature' => true,
            'InvoiceNumber' => $options['invoice_number'] ?? '',
            'CallbackInfo' => ['Url' => ''],
            'PrintReceipt' => 'Both',
            'GetReceipt' => 'Both',
            'Tpn' => $terminalId,
            'AuthKey' => $authKey,
            'SPInProxyTimeout' => $timeout,
            'GetExtendedData' => true,
        ];

        Log::info('Dejavoo: Sending payment to terminal', [
            'terminal_id' => $terminalId,
            'amount' => $amount,
            'reference' => $options['reference'] ?? '',
        ]);

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->timeout($timeout + 30)
                ->post($this->getApiUrl('Payment/Sale'), $payload);

            $body = $response->json();

            Log::info('Dejavoo: Response received', [
                'terminal_id' => $terminalId,
                'status_code' => $response->status(),
                'body' => $body,
            ]);

            return $this->parseCheckoutResponse($body, $terminalId, $timeout);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::error('Dejavoo: Connection failed', [
                'terminal_id' => $terminalId,
                'error' => $e->getMessage(),
            ]);

            return CheckoutResult::failure('Could not connect to the terminal. Please check your network connection and try again.');
        } catch (\Exception $e) {
            Log::error('Dejavoo: Unexpected error', [
                'terminal_id' => $terminalId,
                'error' => $e->getMessage(),
            ]);

            return CheckoutResult::failure('Terminal payment failed: '.$e->getMessage());
        }
    }

    /**
     * Parse the Dejavoo API response into a CheckoutResult.
     */
    protected function parseCheckoutResponse(array $body, string $terminalId, int $timeout): CheckoutResult
    {
        $resultCode = data_get($body, 'GeneralResponse.ResultCode');
        $message = data_get($body, 'GeneralResponse.Message', '');
        $detailedMessage = data_get($body, 'GeneralResponse.DetailedMessage', '');

        if ($resultCode === '0') {
            $authCode = data_get($body, 'AuthCode', '');
            $pnRefId = data_get($body, 'PNReferenceId', '');
            $checkoutId = $authCode ?: $pnRefId ?: 'dj_'.uniqid();

            return CheckoutResult::success(
                checkoutId: $checkoutId,
                status: 'completed',
                expiresAt: Carbon::now()->addSeconds($timeout),
                gatewayResponse: [
                    'auth_code' => $authCode,
                    'pn_reference_id' => $pnRefId,
                    'reference_id' => data_get($body, 'ReferenceId'),
                    'card_type' => data_get($body, 'CardData.CardType'),
                    'card_last4' => data_get($body, 'CardData.Last4'),
                    'card_first4' => data_get($body, 'CardData.First4'),
                    'entry_type' => data_get($body, 'CardData.EntryType'),
                    'amount' => data_get($body, 'Amounts.Amount'),
                    'total_amount' => data_get($body, 'Amounts.TotalAmount'),
                    'tip_amount' => data_get($body, 'Amounts.TipAmount'),
                    'message' => $message,
                    'detailed_message' => $detailedMessage,
                    'terminal_id' => $terminalId,
                    'serial_number' => data_get($body, 'SerialNumber'),
                    'batch_number' => data_get($body, 'BatchNumber'),
                    'transaction_number' => data_get($body, 'TransactionNumber'),
                ],
            );
        }

        $errorMessage = $detailedMessage ?: $message ?: 'Terminal payment failed';

        return CheckoutResult::failure(
            errorMessage: $errorMessage,
            errorCode: data_get($body, 'GeneralResponse.StatusCode'),
            gatewayResponse: $body,
        );
    }

    public function getCheckoutStatus(string $checkoutId): CheckoutStatus
    {
        // Dejavoo uses a blocking API — status is determined at createCheckout time.
        // If polling reaches here, the checkout is still being processed.
        return new CheckoutStatus(
            checkoutId: $checkoutId,
            status: CheckoutStatus::STATUS_PENDING
        );
    }

    /**
     * Cancel a pending transaction on the Dejavoo terminal.
     * Sends a Cancel request via SPIn API to interrupt the terminal.
     */
    public function cancelCheckout(string $checkoutId, ?PaymentTerminal $terminal = null): CancelResult
    {
        if (! $terminal) {
            return CancelResult::success($checkoutId);
        }

        $authKey = $this->getAuthKey($terminal);
        $terminalId = $this->getTerminalId($terminal);

        if (! $authKey || ! $terminalId) {
            return CancelResult::success($checkoutId);
        }

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->timeout(15)
                ->post($this->getApiUrl('Payment/Cancel'), [
                    'Tpn' => $terminalId,
                    'AuthKey' => $authKey,
                ]);

            Log::info('Dejavoo: Cancel response', [
                'terminal_id' => $terminalId,
                'status_code' => $response->status(),
                'body' => $response->json(),
            ]);
        } catch (\Exception $e) {
            Log::warning('Dejavoo: Cancel request failed', [
                'terminal_id' => $terminalId,
                'error' => $e->getMessage(),
            ]);
        }

        return CancelResult::success($checkoutId);
    }

    public function listDevices(string $locationId): array
    {
        return [];
    }

    public function pairDevice(string $deviceCode, array $options = []): PairResult
    {
        return PairResult::success(
            deviceId: $deviceCode,
            deviceName: $options['name'] ?? 'Dejavoo Terminal',
            status: 'paired',
            capabilities: ['card', 'contactless', 'chip', 'swipe']
        );
    }
}
