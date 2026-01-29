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

class SquareTerminalGateway implements TerminalGatewayInterface
{
    protected ?string $accessToken;

    protected string $environment;

    public function __construct()
    {
        $this->accessToken = config('payment-gateways.square.access_token');
        $this->environment = config('payment-gateways.square.environment', 'sandbox');
    }

    public function charge(float $amount, array $paymentMethod, array $options = []): PaymentResult
    {
        if (! $this->accessToken) {
            return PaymentResult::failure('Square access token not configured');
        }

        // TODO: Implement Square payment charge via API
        throw new RuntimeException('Square charge not implemented. Use terminal checkout for in-person payments.');
    }

    public function refund(string $paymentId, float $amount): RefundResult
    {
        if (! $this->accessToken) {
            return RefundResult::failure('Square access token not configured');
        }

        // TODO: Implement Square refund via API
        throw new RuntimeException('Square refund not yet implemented.');
    }

    public function void(string $paymentId): VoidResult
    {
        if (! $this->accessToken) {
            return VoidResult::failure('Square access token not configured');
        }

        // TODO: Implement Square void via API
        throw new RuntimeException('Square void not yet implemented.');
    }

    public function getPayment(string $paymentId): ?array
    {
        if (! $this->accessToken) {
            return null;
        }

        // TODO: Implement Square get payment via API
        return null;
    }

    public function createCheckout(PaymentTerminal $terminal, float $amount, array $options = []): CheckoutResult
    {
        if ($terminal->gateway !== PaymentTerminal::GATEWAY_SQUARE) {
            return CheckoutResult::failure('Terminal is not a Square terminal');
        }

        if (! $terminal->isActive()) {
            return CheckoutResult::failure('Terminal is not active');
        }

        // TODO: Implement Square Terminal checkout creation via API
        // For now, return a mock successful result for testing
        $checkoutId = 'sq_checkout_'.uniqid();
        $timeout = $options['timeout'] ?? config('payment-gateways.terminal.default_timeout', 300);

        return CheckoutResult::success(
            checkoutId: $checkoutId,
            status: 'pending',
            expiresAt: Carbon::now()->addSeconds($timeout),
            gatewayResponse: [
                'device_id' => $terminal->device_id,
                'location_id' => $terminal->location_id,
            ]
        );
    }

    public function getCheckoutStatus(string $checkoutId): CheckoutStatus
    {
        // TODO: Implement Square Terminal checkout status polling via API
        // For now, return pending status
        return new CheckoutStatus(
            checkoutId: $checkoutId,
            status: CheckoutStatus::STATUS_PENDING
        );
    }

    public function cancelCheckout(string $checkoutId): CancelResult
    {
        // TODO: Implement Square Terminal checkout cancellation via API
        return CancelResult::success($checkoutId);
    }

    public function listDevices(string $locationId): array
    {
        if (! $this->accessToken) {
            return [];
        }

        // TODO: Implement Square device listing via API
        return [];
    }

    public function pairDevice(string $deviceCode, array $options = []): PairResult
    {
        if (! $this->accessToken) {
            return PairResult::failure('Square access token not configured');
        }

        // TODO: Implement Square device pairing via API
        // For now, return a mock successful result for testing
        $deviceId = 'sq_device_'.uniqid();

        return PairResult::success(
            deviceId: $deviceId,
            deviceName: $options['name'] ?? 'Square Terminal',
            status: 'paired',
            capabilities: ['card', 'contactless', 'manual_entry']
        );
    }
}
