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
        // Dejavoo terminals require in-person checkout flow, not direct charge
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
        // TODO: Implement Dejavoo get payment via API
        return null;
    }

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

        // TODO: Implement actual Dejavoo SPIn API call
        // POST to $this->apiUrl with XML payload containing:
        // - AuthKey: $authKey
        // - TerminalId (TPN): $terminalId
        // - RegisterId: $registerId (optional, defaults to terminal ID)
        // - Amount: $amount
        // - PaymentType: 'Credit' or 'Debit'
        // - TransType: 'Sale'

        // For now, return a mock successful result for testing
        $checkoutId = 'dj_checkout_'.uniqid();
        $timeout = $options['timeout'] ?? config('payment-gateways.terminal.default_timeout', 300);

        return CheckoutResult::success(
            checkoutId: $checkoutId,
            status: 'pending',
            expiresAt: Carbon::now()->addSeconds($timeout),
            gatewayResponse: [
                'terminal_id' => $terminalId,
                'register_id' => $registerId,
                'auth_key' => substr($authKey, 0, 4).'****', // Masked for security
            ]
        );
    }

    public function getCheckoutStatus(string $checkoutId): CheckoutStatus
    {
        // TODO: Implement Dejavoo Terminal checkout status polling via API
        // For now, return pending status
        return new CheckoutStatus(
            checkoutId: $checkoutId,
            status: CheckoutStatus::STATUS_PENDING
        );
    }

    public function cancelCheckout(string $checkoutId): CancelResult
    {
        // TODO: Implement Dejavoo Terminal checkout cancellation via API
        return CancelResult::success($checkoutId);
    }

    public function listDevices(string $locationId): array
    {
        // Dejavoo doesn't have a device listing API - devices are configured manually
        return [];
    }

    public function pairDevice(string $deviceCode, array $options = []): PairResult
    {
        // Dejavoo terminals are configured manually with auth_key and register_id
        // Return success with the provided device code as the device ID
        return PairResult::success(
            deviceId: $deviceCode,
            deviceName: $options['name'] ?? 'Dejavoo Terminal',
            status: 'paired',
            capabilities: ['card', 'contactless', 'chip', 'swipe']
        );
    }
}
