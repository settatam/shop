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
    protected ?string $apiUrl;

    protected ?string $apiKey;

    protected ?string $merchantId;

    public function __construct()
    {
        $this->apiUrl = config('payment-gateways.dejavoo.api_url');
        $this->apiKey = config('payment-gateways.dejavoo.api_key');
        $this->merchantId = config('payment-gateways.dejavoo.merchant_id');
    }

    public function charge(float $amount, array $paymentMethod, array $options = []): PaymentResult
    {
        if (! $this->isConfigured()) {
            return PaymentResult::failure('Dejavoo not configured');
        }

        // TODO: Implement Dejavoo payment charge via API
        throw new RuntimeException('Dejavoo charge not implemented. Use terminal checkout for in-person payments.');
    }

    public function refund(string $paymentId, float $amount): RefundResult
    {
        if (! $this->isConfigured()) {
            return RefundResult::failure('Dejavoo not configured');
        }

        // TODO: Implement Dejavoo refund via API
        throw new RuntimeException('Dejavoo refund not yet implemented.');
    }

    public function void(string $paymentId): VoidResult
    {
        if (! $this->isConfigured()) {
            return VoidResult::failure('Dejavoo not configured');
        }

        // TODO: Implement Dejavoo void via API
        throw new RuntimeException('Dejavoo void not yet implemented.');
    }

    public function getPayment(string $paymentId): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

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

        // TODO: Implement Dejavoo Terminal checkout creation via API
        // For now, return a mock successful result for testing
        $checkoutId = 'dj_checkout_'.uniqid();
        $timeout = $options['timeout'] ?? config('payment-gateways.terminal.default_timeout', 300);

        return CheckoutResult::success(
            checkoutId: $checkoutId,
            status: 'pending',
            expiresAt: Carbon::now()->addSeconds($timeout),
            gatewayResponse: [
                'device_id' => $terminal->device_id,
                'merchant_id' => $this->merchantId,
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
        if (! $this->isConfigured()) {
            return [];
        }

        // TODO: Implement Dejavoo device listing via API
        return [];
    }

    public function pairDevice(string $deviceCode, array $options = []): PairResult
    {
        if (! $this->isConfigured()) {
            return PairResult::failure('Dejavoo not configured');
        }

        // TODO: Implement Dejavoo device pairing via API
        // For now, return a mock successful result for testing
        $deviceId = 'dj_device_'.uniqid();

        return PairResult::success(
            deviceId: $deviceId,
            deviceName: $options['name'] ?? 'Dejavoo Terminal',
            status: 'paired',
            capabilities: ['card', 'contactless']
        );
    }

    protected function isConfigured(): bool
    {
        return $this->apiUrl && $this->apiKey && $this->merchantId;
    }
}
